<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use App\Support\RedactedError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Logs must not leak gateway keys, phone numbers or login codes; old backups
 * must be cleaned up without ever deleting the newest or the verified one;
 * and every log line of a request carries its correlation ID.
 */
class OperationsHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_sms_gateway_call_is_logged_without_key_phone_or_code(): void
    {
        $owner = User::factory()->staff()->create(['status' => 'active']);
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($owner, 'sms', 'custom', [
            'enabled' => true, 'base_url' => 'https://sms.example/send', 'api_key' => 'LIVE-SMS-KEY-123', 'sender_id' => 'MADINA',
        ], $settings->snapshot()['version'], 'Enable the SMS gateway.');
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://sms.example/send?api_key=LIVE-SMS-KEY-123&number=01711223344&message=Your+code+is+482913'
        ));
        Log::spy();

        $result = app(SmsGatewayClient::class)->send('01711223344', 'Your code is 482913');

        $this->assertFalse($result['sent']);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $logged = json_encode($context);

            return $message === 'sms.gateway_failed'
                && str_contains($logged, 'timed out')
                && ! str_contains($logged, 'LIVE-SMS-KEY-123')
                && ! str_contains($logged, '01711223344')
                && ! str_contains($logged, '482913');
        });
    }

    public function test_redaction_masks_phone_numbers_and_url_credentials(): void
    {
        $text = RedactedError::text('Failed for https://user:secret@gw.example/api?key=abc while texting +8801811223344');

        $this->assertStringNotContainsString('secret', $text);
        $this->assertStringNotContainsString('key=abc', $text);
        $this->assertStringNotContainsString('1811223344', $text);
        $this->assertStringContainsString('01*******44', $text);
        $this->assertStringContainsString('gw.example/api?[redacted]', $text);
    }

    public function test_old_backups_are_pruned_but_the_newest_and_the_verified_one_are_kept(): void
    {
        Storage::fake('backups');
        config(['madina.backup_disk' => 'backups', 'madina.backup_retention_days' => 14, 'madina.backup_keep_minimum' => 3]);
        $disk = Storage::disk('backups');
        $names = [];
        foreach ([40, 30, 20, 10, 5, 2, 1] as $daysAgo) {
            $name = 'madina-'.now()->subDays($daysAgo)->format('Ymd-His').'-'.Str::uuid().'.zip';
            $disk->put($name, 'archive');
            touch($disk->path($name), now()->subDays($daysAgo)->getTimestamp());
            $names[$daysAgo] = $name;
        }
        $disk->put('notes.txt', 'not a backup');
        touch($disk->path('notes.txt'), now()->subDays(90)->getTimestamp());
        Cache::put('operations:verified-backup', ['file' => $names[40], 'verified_at' => now()->toIso8601String()]);

        $this->artisan('app:backup-prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertCount(8, $disk->files());

        $this->artisan('app:backup-prune')->assertSuccessful();

        foreach ([40, 10, 5, 2, 1] as $kept) {
            $this->assertTrue($disk->exists($names[$kept]), "Backup from {$kept} days ago was deleted.");
        }
        foreach ([30, 20] as $deleted) {
            $this->assertFalse($disk->exists($names[$deleted]), "Backup from {$deleted} days ago was kept.");
        }
        $this->assertTrue($disk->exists('notes.txt'));
    }

    public function test_the_correlation_id_is_shared_with_logs_and_queued_jobs(): void
    {
        $id = 'CORR-OPS-'.Str::upper(Str::random(10));

        $this->withHeader('X-Correlation-ID', $id)->get('/healthz')->assertOk()->assertHeader('X-Correlation-ID', $id);

        $this->assertSame($id, Context::get('correlation_id'));
    }
}

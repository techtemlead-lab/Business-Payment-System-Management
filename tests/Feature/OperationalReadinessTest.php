<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_readiness_detects_unsafe_preview_activation_and_strict_configuration(): void
    {
        config(['madina.financial_activation' => false, 'madina.preview_mode' => true]);
        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['checks']['database']);
        $this->assertTrue($result['checks']['database_timezone_utc']);
        $this->assertTrue($result['checks']['migrations_current']);
        $this->assertTrue($result['ready']);
        config(['madina.financial_activation' => true]);
        $this->assertFalse(app(DeploymentReadiness::class)->inspect()['checks']['preview_posting_disabled']);
        $this->assertFalse(app(DeploymentReadiness::class)->inspect(true)['checks']['production_environment']);
        $this->assertFalse(app(DeploymentReadiness::class)->inspect(true)['checks']['scheduler_recent']);
    }

    public function test_preview_mode_blocks_posting_even_if_activation_is_misconfigured(): void
    {
        config(['madina.financial_activation' => true, 'madina.preview_mode' => true]);
        $this->expectException(ValidationException::class);
        CommissionWallet::requireActive();
    }

    public function test_scheduled_release_is_disabled_by_default(): void
    {
        config(['madina.financial_activation' => true, 'madina.auto_release_matured' => false]);
        $this->artisan('finance:release-matured')->expectsOutputToContain('No balances changed')->assertSuccessful();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_backup_requires_a_secret_and_packages_database_and_uploads_with_verified_hashes(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('backups');
        config(['madina.backup_password' => '', 'madina.backup_disk' => 'backups']);
        $this->artisan('app:backup')->assertFailed();
        $this->assertSame([], Storage::disk('backups')->allFiles());
        config(['madina.backup_password' => str_repeat('Private-backup-secret-', 3)]);
        Storage::disk('local')->put('test-evidence/receipt.txt', 'Synthetic receipt evidence');
        Storage::disk('public')->put('test-media/logo.txt', 'Synthetic logo media');
        $this->artisan('app:backup')->assertSuccessful();
        $backups = Storage::disk('backups')->allFiles();
        $this->assertCount(1, $backups);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('backups')->path($backups[0])) === true);
        $zip->setPassword(config('madina.backup_password'));
        $this->assertSame('Synthetic receipt evidence', $zip->getFromName('private/test-evidence/receipt.txt'));
        $this->assertSame('Synthetic logo media', $zip->getFromName('public/test-media/logo.txt'));
        $dump = $zip->getFromName('database.dump');
        $this->assertTrue(Process::input($dump)->timeout(30)->run(['pg_restore', '--list'])->successful());
        $zip->close();
        $this->assertTrue(Cache::has('operations:verified-backup'));
        config(['madina.backup_password' => str_repeat('Incorrect-secret-', 3)]);
        $this->artisan('app:backup-verify', ['file' => $backups[0]])->assertFailed();
        $this->assertFalse(Cache::has('operations:verified-backup'));
        config(['madina.backup_password' => str_repeat('Private-backup-secret-', 3)]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('backups')->path($backups[0])));
        $zip->setPassword(config('madina.backup_password'));
        $this->assertTrue($zip->addFromString('private/test-evidence/receipt.txt', 'Modified evidence'));
        $this->assertTrue($zip->setEncryptionName('private/test-evidence/receipt.txt', ZipArchive::EM_AES_256));
        $this->assertTrue($zip->close());
        $this->artisan('app:backup-verify', ['file' => $backups[0]])->assertFailed();
        $this->assertFalse(Cache::has('operations:verified-backup'));
    }

    public function test_health_and_security_headers_are_available_without_exposing_diagnostics(): void
    {
        $this->withoutVite();
        $this->get('/up')->assertOk();
        $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
    }

    public function test_commission_hold_partner_search_resolves_duplicate_names_by_code(): void
    {
        $finance = User::factory()->staff('finance')->create();
        User::factory()->dealer()->create(['name' => 'Same Partner Name', 'dealer_code' => 'D-FIRST']);
        $target = User::factory()->dealer()->create(['name' => 'Same Partner Name', 'dealer_code' => 'D-TARGET']);
        $this->actingAs($finance)->getJson('/api/v1/operations/views/commission-holds?q=D-TARGET')
            ->assertOk()->assertJsonCount(1, 'data.partners')->assertJsonPath('data.partners.0.id', $target->id);
    }
}

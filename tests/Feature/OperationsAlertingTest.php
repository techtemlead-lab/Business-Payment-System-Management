<?php

namespace Tests\Feature;

use App\Support\DeploymentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Serious failures (a month close, backup, payout run or job) must reach a person, the outside heartbeat check
 * must hear from the server every few minutes, and the readiness page must say when neither is set up.
 */
class OperationsAlertingTest extends TestCase
{
    use RefreshDatabase;

    private function useTelegram(): void
    {
        config(['madina.alerts' => [
            'telegram_bot_token' => '123:test-token',
            'telegram_chat_id' => '-100200300',
            'emails' => '',
            'level' => 'critical',
            'repeat_minutes' => 60,
        ]]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_a_critical_problem_is_sent_to_the_telegram_group_once_per_hour(): void
    {
        $this->useTelegram();

        Log::channel('alerts')->critical('Scheduled automatic payout approval failed.', ['failed' => ['royalty:2026-10' => 'failed: no rank list']]);
        Log::channel('alerts')->critical('Scheduled automatic payout approval failed.', ['failed' => ['royalty:2026-10' => 'failed: no rank list']]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:test-token/sendMessage'
            && $request['chat_id'] === '-100200300'
            && str_contains($request['text'], 'CRITICAL: Scheduled automatic payout approval failed.')
            && str_contains($request['text'], 'royalty:2026-10'));
    }

    public function test_a_different_problem_is_sent_even_inside_the_hour(): void
    {
        $this->useTelegram();

        Log::channel('alerts')->critical('Scheduled application backup failed.');
        Log::channel('alerts')->critical('Daily money reconciliation failed.');

        Http::assertSentCount(2);
    }

    public function test_ordinary_errors_and_warnings_do_not_wake_anyone(): void
    {
        $this->useTelegram();

        Log::channel('alerts')->error('A courier answered slowly.');
        Log::channel('alerts')->warning('Heartbeat ping could not be sent.');

        Http::assertNothingSent();
    }

    public function test_a_phone_number_in_an_alert_is_masked(): void
    {
        $this->useTelegram();

        Log::channel('alerts')->critical('Background job failed for 01711223344.');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request['text'], '01711223344') && str_contains($request['text'], '********344'));
    }

    public function test_a_broken_telegram_never_breaks_the_job_that_logged(): void
    {
        config(['madina.alerts.telegram_bot_token' => '1:x', 'madina.alerts.telegram_chat_id' => '1', 'madina.alerts.emails' => '', 'madina.alerts.level' => 'critical', 'madina.alerts.repeat_minutes' => 60]);
        Http::fake(['api.telegram.org/*' => Http::response('down', 502)]);

        Log::channel('alerts')->critical('Scheduled application backup failed.');

        Http::assertSentCount(1);
    }

    public function test_an_alert_email_goes_to_each_listed_address(): void
    {
        config(['madina.alerts' => ['telegram_bot_token' => '', 'telegram_chat_id' => '', 'emails' => 'owner@madinanhc.test, it@madinanhc.test, not-an-address', 'level' => 'critical', 'repeat_minutes' => 60]]);
        config(['mail.default' => 'array']);
        Http::fake();

        Log::channel('alerts')->critical('Daily audit-trail integrity check failed.');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $recipients = array_map(fn ($address): string => $address->getAddress(), $messages[0]->getOriginalMessage()->getTo());
        $this->assertSame(['owner@madinanhc.test', 'it@madinanhc.test'], $recipients);
        Http::assertNothingSent();
    }

    public function test_the_test_alert_command_needs_an_alert_path_first(): void
    {
        config(['madina.alerts.telegram_bot_token' => '', 'madina.alerts.telegram_chat_id' => '', 'madina.alerts.emails' => '']);

        $this->artisan('ops:alert-test')->assertFailed();
    }

    public function test_the_test_alert_command_reaches_the_group(): void
    {
        $this->useTelegram();

        $this->artisan('ops:alert-test')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Test alert from the server'));
    }

    public function test_the_heartbeat_tells_the_outside_check_all_is_well(): void
    {
        Storage::fake('backups');
        config(['madina.heartbeat_url' => 'https://hc-ping.test/abc', 'madina.backups_enabled' => false, 'queue.default' => 'sync', 'madina.disk_alert_percent' => 101]);
        Http::fake(['hc-ping.test/*' => Http::response('OK')]);

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hc-ping.test/abc');
    }

    public function test_a_stopped_queue_worker_is_reported_and_the_outside_check_is_told_fail(): void
    {
        Storage::fake('backups');
        config(['madina.heartbeat_url' => 'https://hc-ping.test/abc', 'madina.backups_enabled' => false, 'queue.default' => 'database', 'madina.disk_alert_percent' => 101]);
        Cache::forget('operations:queue-heartbeat');
        Http::fake(['hc-ping.test/*' => Http::response('OK')]);
        Log::spy();

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hc-ping.test/abc/fail' && str_contains($request->body(), 'worker'));
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'background job worker'))->once();
    }

    public function test_a_full_disk_and_a_missing_nightly_backup_are_reported(): void
    {
        Storage::fake('backups');
        config(['madina.heartbeat_url' => '', 'madina.backups_enabled' => true, 'queue.default' => 'sync', 'madina.disk_alert_percent' => 0]);
        Log::spy();
        Http::fake();

        $this->artisan('ops:heartbeat')->assertFailed();

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'disk is almost full'))->atLeast()->once();
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'No backup from the last night'))->once();
        Http::assertNothingSent();
    }

    public function test_last_nights_backup_counts_as_fresh(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('madina-20261118-200000-'.'0b7c1a2e-1111-4222-8333-944455556666.zip', 'zip');
        config(['madina.heartbeat_url' => '', 'madina.backups_enabled' => true, 'queue.default' => 'sync', 'madina.disk_alert_percent' => 101]);

        $this->artisan('ops:heartbeat')->assertSuccessful();
    }

    public function test_readiness_warns_until_alerts_and_the_heartbeat_are_set_and_explains_it_in_bangla(): void
    {
        app()->setLocale('bn');
        config(['madina.alerts.telegram_bot_token' => '', 'madina.alerts.telegram_chat_id' => '', 'madina.alerts.emails' => '', 'madina.heartbeat_url' => '']);

        $result = app(DeploymentReadiness::class)->inspect();

        $this->assertContains('alerts_not_configured', $result['warnings']);
        $this->assertContains('heartbeat_not_configured', $result['warnings']);
        $this->assertStringContainsString('টেলিগ্রাম', $result['explanations']['alerts_not_configured']);

        $this->useTelegram();
        config(['madina.heartbeat_url' => 'https://hc-ping.test/abc']);
        $cleared = app(DeploymentReadiness::class)->inspect();

        $this->assertNotContains('alerts_not_configured', $cleared['warnings']);
        $this->assertNotContains('heartbeat_not_configured', $cleared['warnings']);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Database\Seeders\BangladeshPlaceCodeSeeder;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        // Intentionally leave preview_mode on while arming activation — that combo must fail the preview_posting_disabled check.
        config(['madina.financial_activation' => true]);
        $this->assertFalse(app(DeploymentReadiness::class)->inspect()['checks']['preview_posting_disabled']);
        $this->assertFalse(app(DeploymentReadiness::class)->inspect(true)['checks']['production_environment']);
        $this->assertFalse(app(DeploymentReadiness::class)->inspect(true)['checks']['scheduler_recent']);
    }

    public function test_application_readiness_warns_when_sms_and_mail_only_log(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'log',
            'madina.login_otp.enabled' => true,
            'mail.default' => 'log',
        ]);
        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertContains('sms_driver_logging_only', $result['warnings']);
        $this->assertContains('mail_driver_logging_only', $result['warnings']);
        $this->assertContains('login_otp_requires_real_sms_before_go_live', $result['warnings']);
        $this->assertContains('place_code_catalog_incomplete', $result['warnings']);
        $this->assertNotContains('area_dealer_assignments_missing', $result['warnings']);
        $this->assertContains('opening_balances_unverified', $result['warnings']);
        $this->assertContains('payment_collection_accounts_missing', $result['warnings']);
        $this->assertContains('payment_payout_accounts_missing', $result['warnings']);
        $this->assertContains('queue_driver_is_sync', $result['warnings']);
        $this->assertContains('scheduler_heartbeat_missing', $result['warnings']);
        $this->assertNotContains('queue_worker_heartbeat_missing', $result['warnings']);
        $this->assertContains('backups_not_configured', $result['warnings']);
        $this->assertContains('app_environment_not_production', $result['warnings']);
        $this->assertContains('app_url_not_https', $result['warnings']);
        $this->assertContains('session_cookies_not_hardened', $result['warnings']);
    }

    public function test_application_readiness_warns_when_debug_is_enabled_and_clears_when_disabled(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'madina.backups_enabled' => true,
            'madina.backup_password' => str_repeat('Private-backup-secret-', 3),
            'mail.default' => 'smtp',
            'mail.from.address' => 'ops@madina.example.test',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
            'queue.default' => 'database',
            'app.debug' => true,
            'app.env' => 'production',
            'app.url' => 'https://madina.example.test',
            'session.secure' => true,
            'session.encrypt' => true,
            'madina.trusted_proxies' => '10.0.0.0/8',
        ]);
        Cache::put('operations:scheduler-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5));
        Cache::put('operations:queue-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5));
        Cache::forget('operations:verified-backup');

        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertContains('debug_mode_enabled', $result['warnings']);
        $this->assertContains('verified_backup_missing', $result['warnings']);
        $this->assertNotContains('backups_not_configured', $result['warnings']);
        $this->assertNotContains('app_environment_not_production', $result['warnings']);
        $this->assertNotContains('app_url_not_https', $result['warnings']);
        $this->assertNotContains('session_cookies_not_hardened', $result['warnings']);
        $this->assertNotContains('trusted_proxies_not_configured', $result['warnings']);
        $this->assertNotContains('app_key_missing_or_weak', $result['warnings']);

        config(['app.debug' => false, 'madina.trusted_proxies' => '']);
        Cache::put('operations:verified-backup', now()->utc()->toIso8601String(), now()->addDay());
        $cleared = app(DeploymentReadiness::class)->inspect();
        $this->assertNotContains('debug_mode_enabled', $cleared['warnings']);
        $this->assertNotContains('verified_backup_missing', $cleared['warnings']);
        $this->assertContains('trusted_proxies_not_configured', $cleared['warnings']);
    }

    public function test_application_readiness_warns_when_application_key_is_missing(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'app.key' => '',
        ]);
        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertContains('app_key_missing_or_weak', $result['warnings']);
    }

    public function test_application_readiness_clears_queue_and_scheduler_heartbeat_warnings_when_workers_signal(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'ops@madina.example.test',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
            'queue.default' => 'database',
        ]);
        Cache::put('operations:scheduler-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5));
        Cache::put('operations:queue-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5));

        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertNotContains('queue_driver_is_sync', $result['warnings']);
        $this->assertNotContains('scheduler_heartbeat_missing', $result['warnings']);
        $this->assertNotContains('queue_worker_heartbeat_missing', $result['warnings']);
    }

    public function test_application_readiness_warns_when_durable_queue_worker_heartbeat_is_missing(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'ops@madina.example.test',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
            'queue.default' => 'database',
        ]);
        Cache::put('operations:scheduler-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5));
        Cache::forget('operations:queue-heartbeat');

        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertContains('queue_worker_heartbeat_missing', $result['warnings']);
        $this->assertNotContains('scheduler_heartbeat_missing', $result['warnings']);
    }

    public function test_application_readiness_clears_place_code_warning_when_catalog_is_seeded(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'ops@madina.example.test',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
        ]);
        $this->seed(BangladeshPlaceCodeSeeder::class);
        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertNotContains('place_code_catalog_incomplete', $result['warnings']);
        $this->assertContains('area_dealer_assignments_missing', $result['warnings']);
        $this->assertContains('opening_balances_unverified', $result['warnings']);
    }

    public function test_application_readiness_clears_opening_and_area_dealer_warnings_when_verified(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'ops@madina.example.test',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
        ]);
        $this->seed(BangladeshPlaceCodeSeeder::class);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DB::table('area_dealer_assignments')->insert([
            'user_id' => $dealer->id,
            'level' => 'division',
            'zone_name' => 'Dhaka',
            'division' => 'Dhaka',
            'monthly_quota' => 20000,
            'current_month_purchase' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableFinancialPosting();
        config(['madina.financial_activation' => false]);

        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertNotContains('place_code_catalog_incomplete', $result['warnings']);
        $this->assertNotContains('area_dealer_assignments_missing', $result['warnings']);
        $this->assertNotContains('opening_balances_unverified', $result['warnings']);
    }

    public function test_application_readiness_clears_payment_account_warnings_when_collection_and_payout_accounts_exist(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'ops@madina.example.test',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
        ]);
        $actor = User::factory()->staff('finance')->create();
        $now = now();
        DB::table('payment_accounts')->insert([
            [
                'label' => 'Company bKash',
                'channel' => 'bKash',
                'holder' => 'Madina Natural Health Care',
                'account_number' => '01700000000',
                'details' => null,
                'collects_payments' => true,
                'pays_out' => false,
                'is_active' => true,
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'label' => 'Company Bank Payout',
                'channel' => 'Bank',
                'holder' => 'Madina Natural Health Care',
                'account_number' => '1200000000',
                'details' => null,
                'collects_payments' => false,
                'pays_out' => true,
                'is_active' => true,
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertNotContains('payment_collection_accounts_missing', $result['warnings']);
        $this->assertNotContains('payment_payout_accounts_missing', $result['warnings']);
    }

    public function test_application_readiness_warns_when_smtp_is_local_dev_placeholder(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'http',
            'madina.login_otp.enabled' => false,
            'mail.default' => 'smtp',
            'mail.from.address' => 'hello@example.com',
            'mail.mailers.smtp.host' => 'mail',
        ]);
        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertContains('mail_from_address_is_example_placeholder', $result['warnings']);
        $this->assertContains('mail_smtp_host_is_local_development', $result['warnings']);
        $this->assertNotContains('mail_driver_logging_only', $result['warnings']);
        $this->assertNotContains('sms_driver_logging_only', $result['warnings']);
    }

    public function test_application_readiness_clears_sms_warnings_when_gateway_is_enabled(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.sms.driver' => 'log',
            'madina.login_otp.enabled' => true,
            'mail.default' => 'log',
        ]);
        $actor = User::factory()->staff('finance')->create();
        DB::table('business_settings')->updateOrInsert(
            ['key' => IntegrationSettings::SettingsKey],
            [
                'value' => json_encode([
                    'sms' => [
                        'bulk_sms_bd' => [
                            'enabled' => true,
                            'base_url' => 'http://bulksmsbd.net/api/smsapi',
                            'api_key' => 'test-sms-key',
                            'sender_id' => 'MADINA',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'version' => 1,
                'updated_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $result = app(DeploymentReadiness::class)->inspect();
        $this->assertTrue($result['ready']);
        $this->assertNotContains('sms_driver_logging_only', $result['warnings']);
        $this->assertNotContains('login_otp_requires_real_sms_before_go_live', $result['warnings']);
        $this->assertContains('mail_driver_logging_only', $result['warnings']);
    }

    public function test_preview_mode_blocks_posting_even_if_activation_is_misconfigured(): void
    {
        $this->enableFinancialPosting(preview: true);
        $this->expectException(ValidationException::class);
        CommissionWallet::requireActive();
    }

    public function test_preview_mode_delivery_fulfills_without_posting_when_activation_is_misconfigured(): void
    {
        $this->enableFinancialPosting(preview: true);
        $this->seed(CommissionRuleSeeder::class);
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 3]);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        $this->assertSame([], app(CommissionPosting::class)->process($order->fresh()));
        $this->assertDatabaseCount('commission_batches', 0);
        $this->assertDatabaseCount('commission_allocations', 0);
    }

    public function test_scheduled_release_is_disabled_by_default(): void
    {
        $this->enableFinancialPosting();
        config(['madina.auto_release_matured' => false]);
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

<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceStartupModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlaceCodeSeeder::class);
        config(['madina.financial_activation' => false]);
    }

    public function test_admin_can_select_fresh_startup_mode(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/startup-mode', [
            'mode' => 'fresh',
            'version' => 0,
            'reason' => 'Client starts with a fresh application and no past ledger import.',
        ])->assertRedirect();

        $startup = app(IntegrationSettings::class)->startupMode();
        $this->assertSame('fresh', $startup['mode']);
        $this->assertFalse((bool) config('madina.financial_activation'));
    }

    public function test_admin_can_select_import_startup_mode(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/startup-mode', [
            'mode' => 'import',
            'version' => 0,
            'reason' => 'Client will import opening balances from Excel reports.',
        ])->assertRedirect();

        $this->assertSame('import', app(IntegrationSettings::class)->startupMode()['mode']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/data-imports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DataImports')
                ->where('startup.mode', 'import'));
    }

    public function test_financial_readiness_exposes_startup_mode(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app(IntegrationSettings::class)->saveStartupMode(
            $admin,
            'fresh',
            0,
            'Fresh start selected during handover preparation.'
        );

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/financial-readiness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Operations')
                ->where('startupMode.mode', 'fresh')
                ->where('readiness.posting_enabled', false));
    }

    public function test_financial_readiness_exposes_deployment_go_live_warnings(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.sms.driver' => 'log',
            'madina.login_otp.enabled' => true,
            'mail.default' => 'smtp',
            'mail.from.address' => 'hello@example.com',
            'mail.mailers.smtp.host' => 'mail',
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/financial-readiness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Operations')
                ->has('deploymentStatus.warnings')
                ->whereContains('deploymentStatus.warnings', 'sms_driver_logging_only')
                ->whereContains('deploymentStatus.warnings', 'mail_from_address_is_example_placeholder')
                ->whereContains('deploymentStatus.warnings', 'mail_smtp_host_is_local_development')
                ->whereContains('deploymentStatus.warnings', 'login_otp_requires_real_sms_before_go_live'));
    }

    public function test_startup_mode_labels_localize_for_bangla_session(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/service-integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceIntegrations')
                ->where('startup.label', 'এখনো নির্বাচিত হয়নি')
                ->where('startup.description', 'এই ডিপ্লয়মেন্ট খালি শুরু হবে না কি পুরনো ব্যালেন্স ইমপোর্ট করবে—বেছে নিন। একা এটা আর্থিক পোস্টিং চালু করে না।')
                ->where('translations.portal.si_heading', 'সার্ভিস ইন্টিগ্রেশন')
                ->where('translations.portal.imp_heading', 'আপনার রেকর্ড একত্র করুন'));

        app(IntegrationSettings::class)->saveStartupMode(
            $admin,
            'import',
            0,
            'Import path selected during Bangla localization check.'
        );

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/data-imports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DataImports')
                ->where('startup.mode', 'import')
                ->where('startup.label', 'উদ্বোধনী ব্যালেন্স / এক্সেল ইতিহাস ইমপোর্ট'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/financial-readiness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Operations')
                ->where('translations.portal.ops_fld_reason', 'কারণ ও অনুমোদনের বিবরণ')
                ->where('translations.portal.ops_fld_wallet_available', 'যাচাইকৃত উপলব্ধ ওয়ালেট মোট (BDT)')
                ->where('translations.portal.ops_fld_brand_line', 'মাদিনা ন্যাচারাল হেলথ কেয়ার · BDT · Asia/Dhaka')
                ->where('translations.portal.ops_chk_wallet_available', 'ওয়ালেট উপলব্ধ')
                ->where('translations.portal.ops_chk_balanced_journals', 'ব্যালান্সড জার্নাল'));
    }
}

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
}

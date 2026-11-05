<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Staff\PartnerStaffLink;
use App\Domain\Staff\StaffPermissions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Staff with limited access must not be able to reroute login codes and reset
 * links, switch on unattended payouts, or reach money modules through a linked
 * partner desk. Changes they can make must leave a traceable audit record.
 */
class InsiderEscalationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['madina.financial_activation' => false]);
    }

    public function test_finance_staff_cannot_change_where_mail_and_sms_are_delivered(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/integrations/providers', [
            'group' => 'mail',
            'provider' => 'smtp',
            'version' => 0,
            'enabled' => true,
            'host' => 'mail.attacker.example',
            'port' => 587,
            'from_address' => 'noreply@madina.example',
            'reason' => 'Move mail delivery to a new server.',
        ])->assertForbidden();

        $this->assertNotSame('mail.attacker.example', app(IntegrationSettings::class)->provider('mail', 'smtp')['host'] ?? null);
    }

    public function test_super_admin_provider_change_audits_old_and_new_values_without_secrets(): void
    {
        $owner = User::factory()->staff()->create(['status' => 'active']);
        $settings = app(IntegrationSettings::class);

        $settings->saveProvider($owner, 'mail', 'smtp', [
            'enabled' => true, 'host' => 'smtp.old.example', 'port' => 587, 'password' => 'first-secret',
            'from_address' => 'noreply@madina.example',
        ], 0, 'Initial mail setup.');
        $settings->saveProvider($owner, 'mail', 'smtp', [
            'enabled' => true, 'host' => 'smtp.new.example', 'port' => 587, 'password' => 'second-secret',
            'from_address' => 'noreply@madina.example',
        ], 1, 'Switch to the new mail host.');

        $metadata = json_decode((string) DB::table('audit_events')->where('action', 'integrations.provider_saved')->orderByDesc('id')->value('metadata'), true);

        $this->assertSame(['before' => 'smtp.old.example', 'after' => 'smtp.new.example'], $metadata['changes']['host']);
        $this->assertSame(['secret_changed' => true], $metadata['changes']['password']);
        $this->assertStringNotContainsString('second-secret', json_encode($metadata));
        $this->assertArrayNotHasKey('port', $metadata['changes']);
    }

    public function test_only_an_owner_can_change_auto_approval(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        try {
            app(FinanceSettings::class)->saveAutoApproval($finance, true, ['salary' => true], 0, 'Turn on unattended payouts.');
            $this->fail('Finance staff changed auto-approval.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $owner = User::factory()->staff()->create(['status' => 'active']);
        app(FinanceSettings::class)->saveAutoApproval($owner, false, ['salary' => false], 0, 'Owner wants to check salary first.');

        $this->assertFalse(app(FinanceSettings::class)->autoApproval()['enabled']);
    }

    public function test_linked_partner_desk_with_accounts_access_gets_no_money_modules(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $dealer->forceFill(['linked_staff_role' => 'employee', 'linked_staff_permissions' => ['accounts', 'orders']])->save();
        $this->withSession(['workspace_mode' => 'staff', 'workspace_user_id' => $dealer->id])->get('/');
        session(['workspace_mode' => 'staff', 'workspace_user_id' => $dealer->id]);

        $linked = $dealer->fresh();
        $this->assertTrue($linked->usingStaffWorkspace());
        $this->assertSame(['accounts', 'orders'], app(PartnerStaffLink::class)->linkedCapabilities($linked));

        $matrix = app(StaffPermissions::class)->matrix($linked);
        $this->assertTrue($matrix['accounts']['edit']);
        $this->assertFalse($matrix['finance']['approve']);
        $this->assertFalse($matrix['payroll']['edit']);
    }

    public function test_super_admin_password_change_keeps_no_recoverable_copy(): void
    {
        $owner = User::factory()->staff()->create(['status' => 'active', 'password' => 'Original-Pass-2026']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/security/password', [
            'current_password' => 'Original-Pass-2026',
            'password' => 'Brand-New-Pass-2026',
            'password_confirmation' => 'Brand-New-Pass-2026',
        ])->assertSessionHasNoErrors();

        $this->assertNull($owner->fresh()->adminVisiblePassword());
        $this->assertNull(DB::table('users')->where('id', $owner->id)->value('admin_password_cipher'));
    }

    public function test_partner_phone_change_is_audited_with_old_and_new_number(): void
    {
        $this->seedClassicPlaceCodes();
        $owner = User::factory()->staff()->create(['status' => 'active']);
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711000001']);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'phone' => '01811000002',
            'reason' => 'Dealer changed SIM card.',
        ])->assertSessionHas('success');

        $metadata = json_decode((string) DB::table('audit_events')->where('action', 'account.updated')->where('subject', (string) $dealer->id)->value('metadata'), true);

        $this->assertSame(['before' => '01711000001', 'after' => '01811000002'], $metadata['changes']['phone']);
    }

    public function test_order_and_withdrawal_requests_are_rate_limited(): void
    {
        foreach (['commands/orders', 'commands/withdrawals', 'api/v1/orders', 'api/v1/withdrawals'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri && in_array('POST', $route->methods(), true));
            $this->assertNotNull($route, "Missing route {$uri}.");
            $this->assertContains('throttle:10,1', $route->gatherMiddleware(), "{$uri} has no per-minute limit.");
        }
    }
}

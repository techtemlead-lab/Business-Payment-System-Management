<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\PaymentGatewayClient;
use App\Domain\Orders\OrderListFilter;
use App\Domain\Staff\PartnerStaffLink;
use App\Models\Order;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuditRoundTwoHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_manager_cannot_give_a_partner_finance_access(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($manager)->onPortal('manage')
            ->post('/commands/accounts/'.$dealer->id.'/link-staff', [
                'linked_staff_role' => 'employee',
                'permissions' => ['orders', 'finance', 'order_review'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertNull($dealer->fresh()->linked_staff_role);
    }

    public function test_account_manager_cannot_link_the_finance_role_without_overrides(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create();

        $this->expectException(ValidationException::class);
        app(PartnerStaffLink::class)->link($manager, $dealer, 'finance');
    }

    public function test_account_manager_can_link_capabilities_they_hold(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create();

        $linked = app(PartnerStaffLink::class)->link($manager, $dealer, 'employee', ['orders']);

        $this->assertSame('employee', $linked->linked_staff_role);
        $this->assertSame(['orders'], app(PartnerStaffLink::class)->linkedCapabilities($linked));
    }

    public function test_previously_stored_owner_only_capabilities_are_ignored(): void
    {
        $dealer = User::factory()->dealer()->create();
        $dealer->forceFill([
            'linked_staff_role' => 'employee',
            'linked_staff_permissions' => ['orders', 'finance', 'payroll', 'made_up'],
        ])->save();

        $this->assertSame(['orders'], app(PartnerStaffLink::class)->linkedCapabilities($dealer->fresh()));
    }

    public function test_staff_cannot_approve_their_own_order(): void
    {
        $owner = User::factory()->staff()->create();
        $order = $this->order($owner, 'submitted');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/decision', [
                'status' => 'approved',
                'reason' => 'Self approval attempt',
            ])
            ->assertForbidden();

        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_gateway_rejects_completed_transaction_with_a_different_amount(): void
    {
        $this->enableRocket();
        Http::fake(['rocket.test/*' => Http::response(['status' => 'COMPLETED', 'amount' => '10.00'])]);

        $result = app(PaymentGatewayClient::class)->lookup('rocket', 'TRX-SMALL', '5000.00');

        $this->assertTrue($result['checked']);
        $this->assertFalse($result['matched']);
    }

    public function test_gateway_accepts_completed_transaction_with_the_same_amount(): void
    {
        $this->enableRocket();
        Http::fake(['rocket.test/*' => Http::response(['status' => 'COMPLETED', 'amount' => '5000'])]);

        $result = app(PaymentGatewayClient::class)->lookup('rocket', 'TRX-OK', '5000.00');

        $this->assertTrue($result['matched']);
    }

    public function test_gateway_does_not_assume_the_claimed_amount_when_provider_omits_it(): void
    {
        $this->enableRocket();
        Http::fake(['rocket.test/*' => Http::response(['status' => 'COMPLETED'])]);

        $result = app(PaymentGatewayClient::class)->lookup('rocket', 'TRX-NOAMOUNT', '5000.00');

        $this->assertFalse($result['matched']);
        $this->assertNull($result['amount']);
    }

    public function test_outstanding_is_never_negative(): void
    {
        $order = new Order(['total' => '100.00', 'paid' => '80.00', 'due_credit' => '40.00']);

        $this->assertSame('0.00', $order->outstanding());
    }

    public function test_staff_order_export_lists_buyer_details_and_follows_filters(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim Traders', 'district' => 'Rangpur']);
        $this->order($dealer, 'approved', number: 'ORD-KEEP-1');
        $this->order($dealer, 'rejected', number: 'ORD-SKIP-1');

        $csv = $this->actingAs($manager)->onPortal('manage')
            ->get('/exports/orders?status=approved&section=orders')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(__('portal.csv_col_dealer_code', [], 'en'), $csv);
        $this->assertStringContainsString('Rahim Traders', $csv);
        $this->assertStringContainsString('Rangpur', $csv);
        $this->assertStringContainsString('ORD-KEEP-1', $csv);
        $this->assertStringNotContainsString('ORD-SKIP-1', $csv);
    }

    public function test_order_export_neutralises_spreadsheet_formulas_in_names(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create(['name' => '=HYPERLINK("http://evil.test")']);
        $this->order($dealer, 'approved');

        $csv = $this->actingAs($manager)->onPortal('manage')->get('/exports/orders')->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_order_export_rejects_malformed_dates(): void
    {
        $manager = User::factory()->staff('account_manager')->create();

        $this->actingAs($manager)->onPortal('manage')
            ->get('/exports/orders?period=custom&from=not-a-date&to=2026-01-01')
            ->assertSessionHasErrors('from');
    }

    public function test_like_wildcards_in_search_are_escaped(): void
    {
        $this->assertSame('50\\%\\_x', OrderListFilter::escapeLike('50%_x'));
    }

    public function test_last_month_period_covers_the_previous_calendar_month(): void
    {
        $this->travelTo(now('Asia/Dhaka')->setDate(2026, 3, 15));

        $range = OrderListFilter::createdRange('last_month', null, null);

        $this->assertSame('2026-02-01', $range['from']->timezone('Asia/Dhaka')->toDateString());
        $this->assertSame('2026-02-28', $range['until']->timezone('Asia/Dhaka')->toDateString());
    }

    public function test_local_http_portals_do_not_force_https_upgrades(): void
    {
        $csp = (string) $this->onPortal('dealer')->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_https_portals_keep_https_upgrades(): void
    {
        $csp = (string) $this->onPortal('dealer')->get('https://'.PortalHost::host('dealer').'/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_withdrawal_detail_with_non_uuid_id_is_not_found(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals/not-a-uuid')->assertNotFound();
    }

    private function order(User $buyer, string $status, string $total = '1000.00', string $paid = '0.00', string $credit = '0.00', ?string $number = null): Order
    {
        return Order::create([
            'number' => $number ?? 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $paid,
            'due_credit' => $credit,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    private function enableRocket(): void
    {
        $owner = User::factory()->staff()->create();
        $settings = app(IntegrationSettings::class);
        $settings->saveProvider($owner, 'payments', 'rocket', [
            'enabled' => true,
            'base_url' => 'https://rocket.test',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'mode' => 'sandbox',
        ], $settings->snapshot()['version'], 'Gateway amount test');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAlerts;
use App\Domain\Commissions\CommissionPolicy;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\DashboardManagement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    public function test_salary_target_at_risk_only_appears_late_in_the_month(): void
    {
        $dealer = User::factory()->dealer()->create();
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'dealer']);

        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->assertSame([], $this->alertKinds($dealer));

        Carbon::setTestNow('2026-11-24 12:00:00');
        $this->assertSame(['salary_at_risk'], $this->alertKinds($dealer));
        $this->assertSame('10000.00', app(PartnerAlerts::class)->forUsers(collect([$dealer]))[$dealer->id]['alerts'][0]['values']['left']);
    }

    public function test_waived_salary_and_a_met_target_are_not_at_risk(): void
    {
        Carbon::setTestNow('2026-11-24 12:00:00');
        $bought = User::factory()->dealer()->create();
        $this->qualifyingPurchase($bought, '10000.00');

        $this->assertSame([], $this->alertKinds($bought));
    }

    public function test_rank_maintenance_at_risk_uses_the_settlement_rank_check(): void
    {
        Carbon::setTestNow('2026-11-24 12:00:00');
        $dealer = User::factory()->dealer()->create();
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'am', 'achieved_at' => now()->subYear()]);
        $this->qualifyingPurchase($dealer, '10000.00');

        $kinds = $this->alertKinds($dealer);
        $this->assertContains('rank_keep_at_risk', $kinds);
        $this->assertNotContains('salary_at_risk', $kinds);
    }

    public function test_rank_close_alert_appears_from_eighty_percent_of_the_mo_target(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $dealer = User::factory()->dealer()->create();
        $moTarget = (float) app(CommissionPolicy::class)->snapshot(false)['rules']['rank_royalty']['parameters']['mo_target'];
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'dealer', 'lifetime_purchase_total' => number_format($moTarget * 0.79, 2, '.', '')]);
        $this->assertSame([], $this->alertKinds($dealer));

        DealerRank::query()->where('user_id', $dealer->id)->update(['lifetime_purchase_total' => number_format($moTarget * 0.85, 2, '.', '')]);
        $alerts = app(PartnerAlerts::class)->forUsers(collect([$dealer->fresh()]))[$dealer->id]['alerts'];
        $this->assertSame('rank_close_purchases', $alerts[0]['kind']);
        $this->assertSame('mo', $alerts[0]['values']['rank']);
    }

    public function test_inactive_direct_partners_respect_the_setting_and_stop_at_direct_referrals(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $dealer = User::factory()->dealer()->create();
        $quiet = User::factory()->subDealer()->create(['sponsor_id' => $dealer->id, 'phone' => '01712345678', 'created_at' => now()->subDays(40)]);
        $busy = User::factory()->subDealer()->create(['sponsor_id' => $dealer->id, 'created_at' => now()->subDays(40)]);
        $this->order($busy, 'approved', now()->subDays(3));
        $cancelledOnly = User::factory()->subDealer()->create(['sponsor_id' => $dealer->id, 'phone' => '01712345679', 'created_at' => now()->subDays(40)]);
        $this->order($cancelledOnly, 'cancelled', now()->subDays(3));
        // A downline member two levels deep is never listed, so their number cannot reach this card.
        $deep = User::factory()->subDealer()->create(['sponsor_id' => $busy->id, 'phone' => '01712345670', 'created_at' => now()->subDays(40)]);
        ReferralTreePath::attachUser($dealer->id, null);
        ReferralTreePath::attachUser($busy->id, $dealer->id);
        ReferralTreePath::attachUser($deep->id, $busy->id);

        $card = app(PartnerAlerts::class)->forUsers(collect([$dealer]))[$dealer->id];
        $this->assertSame(30, $card['inactive_days']);
        $this->assertSame(['inactive_partners'], array_column($card['alerts'], 'kind'));
        $this->assertSame(2, $card['alerts'][0]['values']['count']);
        $this->assertEqualsCanonicalizing([$quiet->id, $cancelledOnly->id], array_column($card['inactive_partners'], 'id'));
        $this->assertContains('01712345678', array_column($card['inactive_partners'], 'phone'));
        $this->assertNotContains('01712345670', array_column($card['inactive_partners'], 'phone'));

        DashboardManagement::save([...DashboardManagement::get(), 'partner_inactive_days' => 90]);
        $this->assertSame([], app(PartnerAlerts::class)->forUsers(collect([$dealer]))[$dealer->id]['alerts']);
    }

    public function test_inactive_days_setting_is_validated(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/dashboards', ['settings' => ['partner_inactive_days' => '3']])
            ->assertSessionHasErrors('partner_inactive_days');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/dashboards', ['settings' => ['partner_inactive_days' => '45']])
            ->assertRedirect();
        $this->assertSame(45, DashboardManagement::get()['partner_inactive_days']);
    }

    public function test_dashboard_shows_the_card_and_the_daily_run_posts_each_notice_once(): void
    {
        Carbon::setTestNow('2026-11-24 12:00:00');
        $dealer = User::factory()->dealer()->create(['locale' => 'en']);
        User::factory()->subDealer()->create(['sponsor_id' => $dealer->id, 'phone' => '01712345678', 'created_at' => now()->subDays(40)]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.partner_tools.alerts.0.kind', 'salary_at_risk')
                ->where('data.partner_tools.alerts.1.kind', 'inactive_partners')
                ->where('data.partner_tools.inactive_partners.0.phone', '01712345678'));

        $this->artisan('partners:progress-alerts')->assertSuccessful();
        $this->artisan('partners:progress-alerts')->assertSuccessful();

        $notices = DB::table('account_notices')->where('user_id', $dealer->id)->pluck('body');
        $this->assertCount(2, $notices);
        foreach ($notices as $body) {
            $this->assertStringNotContainsString('01712345678', (string) $body);
        }
        $this->assertSame(0, DB::table('sms_logs')->count());
    }

    /** @return list<string> */
    private function alertKinds(User $dealer): array
    {
        return array_column(app(PartnerAlerts::class)->forUsers(collect([$dealer]))[(int) $dealer->id]['alerts'], 'kind');
    }

    private function qualifyingPurchase(User $buyer, string $amount): void
    {
        $order = $this->order($buyer, 'delivered', now());
        DB::table('commission_batches')->insert([
            'order_id' => $order->id,
            'account_type' => 'dealer',
            'period_month' => now(config('madina.business_timezone'))->format('Y-m'),
            'policy' => '{}',
            'cash_basis' => $amount,
            'mrp_basis' => $amount,
            'company_share' => $amount,
            'refunded_amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(User $buyer, string $status, Carbon $at): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);

        return $order;
    }
}

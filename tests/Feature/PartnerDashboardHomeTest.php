<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerDashboardHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_overview_includes_partner_home_profile_metrics_and_targets(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01712345678',
            'email' => 'dealer.home@example.test',
            'dealer_code' => 'MNHC-101',
            'profile' => ['place_code' => 'MNHC-101', 'area_code' => 'MNHC-101'],
        ]);
        ReferralTreePath::attachUser($dealer->id, null);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'overview')
                ->has('data.partner_home.profile')
                ->where('data.partner_home.profile.name', $dealer->name)
                ->where('data.partner_home.profile.phone', '01712345678')
                ->where('data.partner_home.profile.area_code', 'MNHC-101')
                ->has('data.partner_home.snapshot')
                ->has('data.partner_home.chart', 4)
                ->where('data.partner_home.chart.0.key', 'commission')
                ->where('data.partner_home.chart.1.key', 'own')
                ->where('data.partner_home.chart.2.key', 'paid')
                ->has('data.partner_home.targets')
                ->where('data.partner_home.targets.0.key', 'monthly_salary')
                ->where('data.partner_home.targets.1.key', 'rank_reward')
                ->where('data.partner_home.targets.2.key', 'bike_incentive'));
    }

    public function test_sub_dealer_home_shows_shop_name_and_skips_dealer_rank_bar(): void
    {
        $sub = User::factory()->create([
            'role' => 'sub_dealer',
            'status' => 'active',
            'phone' => '01812345678',
            'profile' => [
                'shop_name' => 'Green Care Pharmacy',
                'place_code' => 'MNHC-501-000001',
            ],
        ]);
        ReferralTreePath::attachUser($sub->id, null);

        $home = app(PartnerDashboardHome::class)->for($sub);

        $this->assertSame('Green Care Pharmacy', $home['profile']['shop_name']);
        $this->assertSame('MNHC-501-000001', $home['profile']['area_code']);
        $this->assertSame(['monthly_salary', 'rank_reward', 'bike_incentive'], array_column($home['targets'], 'key'));
    }

    public function test_partner_home_target_labels_follow_active_locale(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);

        app()->setLocale('en');
        $english = app(PartnerDashboardHome::class)->for($dealer);
        $this->assertSame(__('portal.target_monthly_salary_volume', [], 'en'), $english['targets'][0]['label']);
        $this->assertSame(__('portal.target_bike_incentive', [], 'en'), $english['targets'][2]['label']);

        app()->setLocale('bn');
        $bangla = app(PartnerDashboardHome::class)->for($dealer);
        $this->assertSame(__('portal.target_monthly_salary_volume', [], 'bn'), $bangla['targets'][0]['label']);
        $this->assertSame(__('portal.target_bike_incentive', [], 'bn'), $bangla['targets'][2]['label']);
        $this->assertNotSame($english['targets'][0]['label'], $bangla['targets'][0]['label']);
    }

    public function test_partner_home_counts_today_own_and_team_sales(): void
    {
        // After 18:00 UTC the Dhaka calendar day has already rolled forward; freeze there
        // so the business-day → UTC conversion is exercised, not skipped.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 20:15:00', 'UTC'));

        try {
            $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
            $downline = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $dealer->id]);
            ReferralTreePath::attachUser($dealer->id, null);
            ReferralTreePath::attachUser($downline->id, $dealer->id);

            $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'active' => true, 'stock' => 1000]);
            foreach ([$dealer, $downline] as $buyer) {
                $order = app(OrderService::class)->create($buyer, [
                    'items' => [['product_id' => $product->id, 'quantity' => 10]],
                    'idempotency_key' => (string) Str::uuid(),
                ]);
                $order->update(['paid' => $order->total, 'status' => 'delivered']);
            }

            $home = app(PartnerDashboardHome::class)->for($dealer->fresh());

            $this->assertSame('1000.00', $home['snapshot']['own_sales_today']);
            $this->assertSame('1000.00', $home['snapshot']['team_sales_today']);
            $this->assertSame('250000.00', CommissionPolicy::defaults()['rank_royalty']['parameters']['mo_target']);
            $rankTarget = collect($home['targets'])->firstWhere('key', 'rank_reward');
            $this->assertSame('250000.00', $rankTarget['target']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_admin_overview_receives_mixed_admin_home(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => '01911112222',
            'profile' => ['photo_url' => '/storage/staff-photos/9/owner.jpg'],
        ]);

        $this->actingAs($admin)->onPortal('sadmin')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/sadmin/Dashboard')
                ->has('data.admin_home.profile')
                ->where('data.admin_home.profile.phone', '01911112222')
                ->where('data.admin_home.profile.photo_url', '/storage/staff-photos/9/owner.jpg')
                ->has('data.admin_home.snapshot'));
    }
}

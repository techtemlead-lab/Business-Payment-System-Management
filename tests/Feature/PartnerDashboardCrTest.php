<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAreaLine;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-01 to CR-05: the new partner dashboard, its report tabs, the wallet name and the hidden product list. */
class PartnerDashboardCrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
    }

    private function partner(?User $sponsor, string $role = 'dealer', array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'sponsor_id' => $sponsor?->id, ...$attributes]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);

        return $user;
    }

    private function deliveredOrder(User $buyer): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 10]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => $order->total]);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    public function test_the_area_line_follows_the_partner_level(): void
    {
        $this->assertSame('রংপুর', PartnerAreaLine::format(['union' => null, 'upazila' => null, 'district' => null, 'division' => 'রংপুর']));
        $this->assertSame('দিনাজপুর', PartnerAreaLine::format(['union' => null, 'upazila' => null, 'district' => 'দিনাজপুর', 'division' => 'রংপুর']));
        $this->assertSame('পীরগঞ্জ, রংপুর, রংপুর', PartnerAreaLine::format(['union' => null, 'upazila' => 'পীরগঞ্জ', 'district' => 'রংপুর', 'division' => 'রংপুর']));
        $this->assertSame('ভেন্ডাবাড়ী, পীরগঞ্জ, রংপুর, রংপুর', PartnerAreaLine::format(['union' => 'ভেন্ডাবাড়ী', 'upazila' => 'পীরগঞ্জ', 'district' => 'রংপুর', 'division' => 'রংপুর']));
        $this->assertSame('', PartnerAreaLine::format(['union' => '—', 'upazila' => ' ', 'district' => null, 'division' => null]));
    }

    public function test_the_card_has_counts_and_the_shop_name_only_for_a_sub_dealer(): void
    {
        $dealer = $this->partner(null, 'dealer', ['profile' => ['shop_name' => 'Dealer Shop']]);
        $first = $this->partner($dealer);
        $this->partner($first);
        $subDealer = $this->partner($dealer, 'sub_dealer', ['profile' => ['shop_name' => 'Rina Pharmacy']]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_home.card.type', 'dealer')
            ->where('data.partner_home.card.shop_name', null)
            ->where('data.partner_home.card.direct', 2)
            ->where('data.partner_home.card.gen1', 2)
            ->where('data.partner_home.card.gen2', 1));
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_home.card.type', 'sub_dealer')
            ->where('data.partner_home.card.shop_name', 'Rina Pharmacy'));
    }

    public function test_the_three_totals_are_income_withdrawn_and_balance(): void
    {
        $dealer = $this->partner(null);
        $this->deliveredOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_home.totals.income', fn ($income): bool => (float) $income > 0)
            ->where('data.partner_home.totals.withdrawn', '0.00')
            ->has('data.partner_home.totals.balance'));
    }

    public function test_the_report_has_a_tab_for_each_head_and_the_generation_tabs_split_the_team(): void
    {
        $top = $this->partner(null);
        $middle = $this->partner($top);
        $bottom = $this->partner($middle);
        $this->deliveredOrder($bottom);

        $tab = fn (User $viewer, string $rtab) => $this->actingAs($viewer)->onPortal('dealer')->get('/?rtab='.$rtab);
        $tab($top, 'gen2')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('income_report.tab', 'gen2')
            ->has('income_report.tabs', 10)
            ->has('income_report.table.rows.data', 1)));
        $tab($top, 'gen1')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('income_report.table.rows.data', 0)));
        $tab($top, 'team_all')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('income_report.table.rows.data', 1)));
        $tab($middle, 'gen1')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('income_report.table.rows.data', 1)));
        $tab($middle, 'all')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('income_report.tab', 'all')->has('income_report.table.rows.data', 1)));
        $tab($middle, 'nonsense')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('income_report.tab', 'all')));
    }

    public function test_my_money_is_called_wallet_in_both_languages(): void
    {
        $this->assertSame('ওয়ালেট', __('my_money.title', [], 'bn'));
        $this->assertSame('ওয়ালেট', __('portal.nav_module_earnings', [], 'bn'));
        $this->assertSame('Wallet', __('my_money.title', [], 'en'));
        $this->assertSame('Wallet', __('partner_hub.money_title', [], 'en'));
    }

    public function test_the_partner_product_list_is_off_by_default_and_can_be_switched_back_on(): void
    {
        $dealer = $this->partner(null);
        config(['madina.partner_catalog_enabled' => false]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')->assertRedirect('/');
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertInertia(fn (Assert $page) => $page->where('dashboardControls.partner_catalog_off', true));
        // Ordering stays.
        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk();

        config(['madina.partner_catalog_enabled' => true]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')->assertOk();
    }
}

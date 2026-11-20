<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\OrderMoneyReport;
use App\Domain\Orders\OfficeDashboardTabs;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The order page says where the order's money went: the posted split, or a labelled preview that writes nothing. */
class OrderMoneyBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    private function purchase(User $buyer): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 10]);

        return app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()])->fresh();
    }

    private function paid(Order $order): Order
    {
        $order->forceFill(['paid' => $order->total])->save();

        return $order->fresh();
    }

    private function postCommission(Order $order): void
    {
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());
    }

    /** @return array<string, mixed> */
    private function breakdownProp(User $viewer, Order $order, string $portal): array
    {
        $found = [];
        $this->actingAs($viewer)->onPortal($portal)->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(function (Assert $page) use (&$found): void {
                $found = $page->toArray()['props']['moneyBreakdown'];
            });

        return $found;
    }

    private function cents(string $decimal): int
    {
        return FundCatalog::centsOf($decimal);
    }

    public function test_an_order_with_postings_shows_the_posted_lines_and_they_add_up_to_the_cash(): void
    {
        $owner = User::factory()->staff()->create();
        $sponsor = User::factory()->dealer()->create();
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $this->postCommission($order);

        $shown = $this->breakdownProp($owner, $order, 'sadmin');
        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);

        $this->assertSame('posted', $shown['mode']);
        $this->assertSame($detail['lines'], $shown['lines']);
        $this->assertSame('100.00', $shown['cash']);
        $this->assertSame('100.00', $shown['lines_total']);
        $this->assertTrue($shown['adds_up']);
        $referral = collect($shown['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'direct_referral'));
        $this->assertSame($sponsor->id, $referral['to']['id']);
    }

    public function test_an_order_without_postings_shows_a_preview_with_the_same_lines_the_posting_makes_and_writes_nothing(): void
    {
        $owner = User::factory()->staff()->create();
        $sponsor = User::factory()->dealer()->create();
        $order = $this->paid($this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id])));
        $snapshotBefore = $order->commission_policy_snapshot;

        $preview = $this->breakdownProp($owner, $order, 'sadmin');

        $this->assertSame('preview', $preview['mode']);
        $this->assertSame('100.00', $preview['cash']);
        $this->assertSame('100.00', $preview['lines_total']);
        $this->assertTrue($preview['adds_up']);
        $this->assertContains('preview', array_column($preview['lines'], 'status'));
        $this->assertSame([], array_diff(array_unique(array_column($preview['lines'], 'status')), ['preview']));
        $this->assertSame(0, DB::table('commission_batches')->count());
        $this->assertSame(0, DB::table('commission_allocations')->count());
        $this->assertSame($snapshotBefore, $order->fresh()->commission_policy_snapshot);

        // Posting the same order afterwards makes the very same lines, amounts and recipients.
        $this->postCommission($order);
        $posted = $this->breakdownProp($owner, $order, 'sadmin');
        $this->assertSame('posted', $posted['mode']);
        $shape = fn (array $lines): array => collect($lines)->map(fn (array $line): string => $line['type_label'].'|'.$line['amount'].'|'.$line['to']['name'])->sort()->values()->all();
        $this->assertSame($shape($posted['lines']), $shape($preview['lines']));
        // The bike and car parts go to the Bike fund and the Car fund, before and after posting.
        foreach ([$preview, $posted] as $shown) {
            $names = collect($shown['lines'])->pluck('to.name');
            $this->assertContains('Bike fund', $names);
            $this->assertContains('Car fund', $names);
            $this->assertNotContains('Car and bike fund', $names);
        }
    }

    public function test_the_preview_follows_the_posting_off_state_and_names_the_company_fund_for_a_share_with_no_person(): void
    {
        config(['madina.financial_activation' => false]);
        $owner = User::factory()->staff()->create();
        $order = $this->paid($this->purchase(User::factory()->dealer()->create(['sponsor_id' => null])));

        $preview = $this->breakdownProp($owner, $order, 'sadmin');

        $this->assertSame('preview', $preview['mode']);
        $this->assertSame('posting_off', $preview['reason']);
        $noReferrer = collect($preview['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'direct_referral_breakage'));
        $this->assertSame('fund', $noReferrer['to']['kind']);
        $this->assertSame('Company profit', $noReferrer['to']['name']);
    }

    public function test_a_partner_sees_only_their_own_lines_of_their_own_order(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]);
        $order = $this->paid($this->purchase($buyer));

        foreach (['preview', 'posted'] as $mode) {
            if ($mode === 'posted') {
                $this->postCommission($order);
            }
            $shown = $this->breakdownProp($buyer, $order, 'dealer');
            $this->assertSame($mode, $shown['mode']);
            $this->assertTrue($shown['own_only']);
            $this->assertNull($shown['lines_total']);
            $this->assertNotEmpty($shown['lines']);
            foreach ($shown['lines'] as $line) {
                $this->assertSame('partner', $line['to']['kind']);
                $this->assertSame($buyer->id, $line['to']['id']);
            }
        }

        // Someone else's order does not open at all, so its lines cannot leak.
        $this->actingAs($sponsor)->onPortal('dealer')->get('/orders/'.$order->id)->assertNotFound();
    }

    public function test_the_preview_uses_the_same_fund_rule_as_the_treasury_sql(): void
    {
        $cases = [
            ['own_dealer_cashback', 'available', []], ['direct_referral', 'available', []],
            ['direct_referral_breakage', 'unassigned_reserve', ['retained_by' => 'company_profit_fund']],
            ['direct_referral', 'pending', ['retained_by' => 'company_management_area_share']],
            ['area_union', 'unassigned_reserve', ['retained_by' => 'company_profit_fund']], ['area_district', 'available', []],
            ['area_management', 'pending', []], ['dealer_monthly_salary_pool', 'pending', []], ['sub_dealer_salary', 'pending', []],
            ['rank_royalty_pool', 'pending', []], ['rank_incentive_pool', 'pending', []], ['company_marketing_fund', 'pending', []],
            ['car_bike_incentive_fund', 'pending', []], ['car_bike_incentive_fund', 'pending', ['vehicle_pool' => 'bike']], ['car_bike_incentive_fund', 'pending', ['vehicle_pool' => 'car']], ['sub_dealer_tour', 'reserved', []], ['sub_dealer_extra_fund', 'pending', []],
            ['additional_rank_pool', 'pending', []], ['no_commission_to_company', 'available', []], ['d2d_gen2_breakage', 'unassigned_reserve', []],
            ['dealer_salary_settlement_reserve', 'available', []],
        ];
        foreach ($cases as [$category, $status, $metadata]) {
            $sql = DB::selectOne('SELECT '.FundCatalog::rowFundSql().' AS fund FROM (SELECT ?::text AS category, ?::text AS status, ?::jsonb AS metadata) AS funds', [$category, $status, json_encode((object) $metadata)]);
            $this->assertSame($sql->fund, OrderMoneyReport::fundKeyOf(['category' => $category, 'status' => $status, 'metadata' => $metadata]), $category.' / '.$status);
        }
    }

    public function test_an_order_with_nothing_to_split_says_so_instead_of_showing_a_table(): void
    {
        $owner = User::factory()->staff()->create();
        $order = $this->purchase(User::factory()->dealer()->create());
        $order->update(['status' => 'cancelled']);

        $shown = $this->breakdownProp($owner, $order, 'sadmin');

        $this->assertSame('none', $shown['mode']);
        $this->assertSame('closed', $shown['reason']);
        $this->assertSame([], $shown['lines']);
    }

    public function test_the_dashboard_orders_tab_has_the_orders_page_columns_money_and_no_account_column(): void
    {
        $owner = User::factory()->staff()->create();
        $buyer = User::factory()->dealer()->create(['name' => 'Rahim Store', 'phone' => '01711112222', 'district' => 'Rangpur', 'division' => 'Rangpur']);
        $order = $this->purchase($buyer);
        $order->forceFill(['paid' => '40.00'])->save();

        $tab = app(OfficeDashboardTabs::class)->forRequest($owner, Request::create('/', 'GET', ['tab' => 'orders']), null);

        $this->assertSame(['number', 'created_at', 'partner', 'code', 'partner_type', 'phone', 'address', 'items', 'mrp', 'total', 'paid', 'to_pay', 'status'], array_column($tab['columns'], 'key'));
        $this->assertNotContains('role', array_column($tab['columns'], 'key'));
        $row = $tab['rows'][0];
        $this->assertSame('Rahim Store', $row['partner']);
        $this->assertSame($buyer->dealer_code, $row['code']);
        $this->assertSame('01711112222', $row['phone']);
        $this->assertSame('Rangpur', $row['address']);
        $this->assertSame(1, $row['items']);
        $this->assertSame('200.00', $row['mrp']);
        $this->assertSame('100.00', $row['total']);
        $this->assertSame('40.00', $row['paid']);
        $this->assertSame('60.00', $row['to_pay']);
        $this->assertNotNull($row['partner_type']);
    }

    public function test_the_orders_page_rows_carry_mrp_and_address_and_an_empty_list_still_loads(): void
    {
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.orders.total', 0)->has('data.orders_table.sortable')->etc());

        $buyer = User::factory()->dealer()->create(['district' => 'Rangpur', 'division' => 'Rangpur']);
        $this->purchase($buyer);

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.orders.data.0.mrp_total', '200.00')
                ->where('data.orders.data.0.user.address_line', 'Rangpur')
                ->etc());
    }

    public function test_a_half_saved_policy_snapshot_never_breaks_the_order_page(): void
    {
        $owner = User::factory()->staff()->create();
        $order = $this->paid($this->purchase(User::factory()->dealer()->create()));
        $order->forceFill(['commission_policy_snapshot' => ['uplines' => []]])->save();

        $shown = $this->breakdownProp($owner, $order, 'sadmin');

        $this->assertContains($shown['mode'], ['preview', 'none']);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerIncomeHeads;
use App\Domain\Commissions\CommissionPageModel;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Client voice message, 2026-11-20: of the sub-dealer's purchase, the salary and the tour stay the sub-dealer's
 * and the extra part goes to a company fund of its own, "Sub-dealer extra" (owner decision: by default, for new
 * orders). Before, that part went to the sub-dealer rank fund. It is a rule value the owner can switch back; an
 * order keeps the rules it was captured with, and one captured before the setting existed keeps the old fund.
 *
 * The orders here use round numbers: a product with an MRP of 10,000; a dealer pays 7,000 and a sub-dealer 7,500.
 */
class SubDealerExtraFundTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    private function product(): Product
    {
        return Product::factory()->create(['mrp' => '10000.00', 'dealer_price' => '7000.00', 'sub_dealer_price' => '7500.00']);
    }

    private function newOrder(string $role): Order
    {
        $buyer = User::factory()->create(['role' => $role, 'status' => 'active']);

        return app(OrderService::class)->create($buyer, ['items' => [['product_id' => $this->product()->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
    }

    private function deliver(Order $order): Order
    {
        $order->update(['status' => 'delivered', 'paid' => $order->total]);
        app(CommissionPosting::class)->process($order);

        return $order->refresh();
    }

    private function posted(string $role): Order
    {
        return $this->deliver($this->newOrder($role));
    }

    /** @return list<array{string, string, string}> category, amount, status of every row of an order. */
    private function rows(Order $order): array
    {
        return DB::table('commission_allocations')->where('order_id', $order->id)->orderBy('id')->get()
            ->map(fn (object $row): array => [(string) $row->category, (string) $row->amount, (string) $row->status])->all();
    }

    /** @return array<string, string> */
    private function batch(Order $order): array
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();

        return ['cash' => (string) $batch->cash_basis, 'company' => (string) $batch->company_share, 'allocated' => (string) $batch->total_allocated, 'unallocated' => (string) $batch->unallocated];
    }

    private function amountOf(Order $order, string $category): string
    {
        return (string) (DB::table('commission_allocations')->where('order_id', $order->id)->where('category', $category)->sum('amount') ?: '0.00');
    }

    private function sendExtraToTheRankFund(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/commissions/rules', ['rules' => ['additional_rank' => ['parameters' => ['destination' => 'rank_fund']]]])->assertRedirect();
    }

    /** A rule set as it was stored before the setting existed: no destination in the sub-dealer extra rule. */
    private function withoutTheSetting(Order $order): void
    {
        $snapshot = $order->commission_policy_snapshot;
        unset($snapshot['rules']['additional_rank']['parameters']['destination']);
        $order->forceFill(['commission_policy_snapshot' => $snapshot])->save();
    }

    /**
     * The daily money checks that fail. Test orders here are marked paid without a bank receipt, so the receipt check
     * always fails; every other check must pass, and the failing list must not grow when the new fund is used.
     *
     * @return list<string>
     */
    private function failedChecks(): array
    {
        return FinancialReadiness::failedNames(app(FinancialReadiness::class)->checks());
    }

    private function fund(string $key): int
    {
        return app(CompanyFunds::class)->balances()[$key]['balance'];
    }

    public function test_by_default_the_extra_part_goes_to_the_new_company_fund(): void
    {
        $order = $this->posted('sub_dealer');

        $this->assertSame('3750.00', $this->batch($order)['allocated'], 'A sub-dealer purchase pays 50% of the price in commissions.');
        $this->assertSame('3000.00', $this->batch($order)['company'], 'The company keeps 30% of the MRP.');
        $this->assertSame('225.00', $this->amountOf($order, 'sub_dealer_salary'));
        $this->assertSame('225.00', $this->amountOf($order, 'sub_dealer_tour'));
        $this->assertSame('300.00', $this->amountOf($order, 'sub_dealer_extra_fund'));
        $this->assertSame('0.00', $this->amountOf($order, 'additional_rank_pool'));
        $this->assertSame(0, $this->fund('rank_reward'));
        $this->assertSame(30000, $this->fund('sub_dealer_extra'));
        $this->assertSame('extra_fund', CommissionPolicy::ExtraDestinationDefault);
        $this->assertSame('extra_fund', collect((new CommissionPageModel(app(CommissionPolicy::class)))->build()['heads'])->firstWhere('key', 'additional_rank')['destination']);
        $this->assertSame($this->batch($order)['cash'], '7500.00');
        $this->assertCount(21, app(FinancialReadiness::class)->checks());
        $this->assertSame(['order_receipts'], $this->failedChecks(), 'Only the bank receipt check fails, because the test order has no bank receipt.');
    }

    public function test_the_owner_can_send_it_back_to_the_rank_fund_and_only_the_extra_part_moves(): void
    {
        $default = $this->posted('sub_dealer');
        $defaultRows = $this->rows($default);

        $this->sendExtraToTheRankFund();
        $back = $this->posted('sub_dealer');

        $moved = array_map(fn (array $row): array => $row[0] === 'sub_dealer_extra_fund' ? ['additional_rank_pool', $row[1], $row[2]] : $row, $defaultRows);
        $this->assertSame($moved, $this->rows($back), 'Every row is the same except the name of the fund the extra part sits in.');
        $this->assertSame($this->batch($default), $this->batch($back), 'The totals do not move.');
        $this->assertSame('300.00', $this->amountOf($back, 'additional_rank_pool'));
        $this->assertSame('0.00', $this->amountOf($back, 'sub_dealer_extra_fund'));
        $this->assertSame(30000, $this->fund('rank_reward'));
        $this->assertSame(30000, $this->fund('sub_dealer_extra'), 'The first order stays in the new fund; the second is not added to it.');
        $this->assertSame(['order_receipts'], $this->failedChecks());
    }

    public function test_an_order_captured_before_the_deploy_still_goes_to_the_old_fund(): void
    {
        $old = $this->newOrder('sub_dealer');
        // An order captured before the setting existed has no value for it in its rules.
        $this->withoutTheSetting($old);

        $old = $this->deliver($old);

        $this->assertSame('300.00', $this->amountOf($old, 'additional_rank_pool'), 'Captured without the setting: the old rank fund.');
        $this->assertSame('0.00', $this->amountOf($old, 'sub_dealer_extra_fund'));
        $this->assertSame(30000, $this->fund('rank_reward'));
        $this->assertSame(0, $this->fund('sub_dealer_extra'));
        $this->assertSame('3750.00', $this->batch($old)['allocated']);
        $this->assertSame(['order_receipts'], $this->failedChecks());
    }

    public function test_an_order_already_posted_does_not_move_when_the_owner_changes_the_rule(): void
    {
        $posted = $this->posted('sub_dealer');
        $rows = $this->rows($posted);
        $snapshot = $posted->commission_policy_snapshot;

        $this->sendExtraToTheRankFund();

        $this->assertSame($rows, $this->rows($posted->refresh()));
        $this->assertSame($snapshot, $posted->commission_policy_snapshot);
        $this->assertSame(30000, $this->fund('sub_dealer_extra'));
        $this->assertSame(0, $this->fund('rank_reward'));
    }

    public function test_a_dealer_order_is_exactly_the_same_before_and_after(): void
    {
        $before = $this->posted('dealer');
        $beforeRows = $this->rows($before);

        $this->sendExtraToTheRankFund();
        $after = $this->posted('dealer');

        $this->assertSame($beforeRows, $this->rows($after));
        $this->assertSame($this->batch($before), $this->batch($after));
        $this->assertSame('0.00', $this->amountOf($after, 'sub_dealer_extra_fund'));
        $this->assertSame('0.00', $this->amountOf($after, 'additional_rank_pool'), 'A dealer never pays the sub-dealer parts.');
        $this->assertSame('2800.00', $this->batch($after)['allocated'], 'A dealer purchase pays 40% of the price.');
    }

    public function test_a_refund_takes_the_money_back_out_of_the_new_fund(): void
    {
        $order = $this->posted('sub_dealer');
        $this->assertSame(30000, $this->fund('sub_dealer_extra'));
        $failedBefore = $this->failedChecks();

        app(CommissionService::class)->reverseOrder($order);

        $this->assertSame(0, $this->fund('sub_dealer_extra'));
        $this->assertSame($failedBefore, $this->failedChecks(), 'A refund of an order that used the new fund adds no failing money check.');
    }

    public function test_the_new_fund_has_a_treasury_card_and_a_plain_name_in_both_languages(): void
    {
        $this->posted('sub_dealer');

        $this->assertContains('sub_dealer_extra', FundCatalog::keys());
        $this->assertContains('sub_dealer_extra', CompanyFunds::keys());
        $this->assertTrue(CompanyFunds::isSpendable('sub_dealer_extra'), 'It is a company fund like marketing: it can be spent through the spend flow.');
        $card = collect(app(TreasuryFunds::class)->cards(true))->firstWhere('key', 'sub_dealer_extra');
        $this->assertNotNull($card);
        $this->assertSame('300.00', $card['money_in']);
        foreach (['bn' => ['সাব-ডিলার এক্সট্রা', 'সাব-ডিলার এক্সট্রা ফান্ড'], 'en' => ['Sub-dealer extra', 'Sub-dealer extra fund']] as $locale => [$word, $fundName]) {
            app()->setLocale($locale);
            $this->assertSame($fundName, FundCatalog::name('sub_dealer_extra'));
            $this->assertSame($word, FundCatalog::typeLabel('sub_dealer_extra_fund'));
        }
    }

    public function test_the_rule_value_is_checked_and_switching_back_follows_the_draft_then_publish_flow(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/commissions/rules', ['rules' => ['additional_rank' => ['parameters' => ['destination' => 'somewhere']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('rules');

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/commissions/rules', ['rules' => ['additional_rank' => ['parameters' => ['destination' => 'rank_fund']]]])->assertRedirect();
        $this->assertSame('extra_fund', app(CommissionPolicy::class)->snapshot(false)['rules']['additional_rank']['parameters']['destination'], 'A draft changes nothing yet.');
        $this->assertSame('300.00', $this->amountOf($this->posted('sub_dealer'), 'sub_dealer_extra_fund'));

        $draft = DB::table('commission_policy_schedules')->where('origin', 'rule_edit')->where('status', 'draft')->value('id');
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$draft.'/review', ['decision' => 'approved', 'reason' => 'Checked against the budget'])->assertOk();

        $this->assertSame('rank_fund', app(CommissionPolicy::class)->snapshot(false)['rules']['additional_rank']['parameters']['destination']);
        $this->assertSame('300.00', $this->amountOf($this->posted('sub_dealer'), 'additional_rank_pool'));
    }

    public function test_the_page_groups_the_sub_dealer_only_heads_and_names_the_renamed_ones(): void
    {
        $heads = collect((new CommissionPageModel(app(CommissionPolicy::class)))->build()['heads']);

        $this->assertSame(['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'], $heads->where('block', 'sub_only')->pluck('key')->all());
        $this->assertCount(9, $heads->where('block', 'shared'));
        $this->assertArrayNotHasKey('sub_name', $heads->first());
        $bn = require lang_path('bn/commission_page.php');
        $en = require lang_path('en/commission_page.php');
        $this->assertSame('ডক্টর অনারারিয়াম', $bn['head_own_dealer_cashback']);
        $this->assertSame('Doctor Honorarium', $en['head_own_dealer_cashback']);
        $this->assertSame('স্যালারি', $bn['head_dealer_monthly_salary']);
        $this->assertSame('স্টাফ কমিশন', $bn['head_direct_referral']);
        $this->assertArrayNotHasKey('sub_name_direct_referral', $bn);
        $this->assertSame('সাব-ডিলার আলাদা', $bn['subblock_title']);
        $this->assertSame('সাব-ডিলার এক্সট্রা', $bn['head_additional_rank']);
    }

    public function test_the_rank_prizes_are_parts_in_taka_that_the_page_does_not_sum(): void
    {
        $head = collect((new CommissionPageModel(app(CommissionPolicy::class)))->build()['heads'])->firstWhere('key', 'rank_incentive');
        $rules = app(CommissionPolicy::class)->snapshot(false)['rules'];

        $this->assertSame(['mo', 'am', 'sm', 'me'], array_column($head['children'], 'key'));
        foreach ($head['children'] as $part) {
            $this->assertSame('money', $part['unit']);
            $this->assertSame($part['key'].'_bonus', $part['param']);
            $this->assertSame(number_format((float) $rules['rank_incentive']['parameters'][$part['param']], 2, '.', ''), $part['pct']);
        }
        $this->assertNull($head['children_total']);
        $this->assertTrue($head['children_match']);
    }

    public function test_the_partner_income_heads_use_the_new_names(): void
    {
        $bn = require lang_path('bn/cr21_income.php');
        $this->assertSame('ডক্টর অনারারিয়াম', $bn['head_own']);
        $this->assertSame('স্যালারি', $bn['head_salary']);
        $this->assertSame('সাব-ডিলার স্যালারি', $bn['head_salary_sub']);
        $this->assertSame('স্টাফ কমিশন', $bn['head_referral']);

        app()->setLocale('bn');
        $this->assertSame('স্যালারি', PartnerIncomeHeads::label('salary', null, 'dealer'));
        $this->assertSame('সাব-ডিলার স্যালারি', PartnerIncomeHeads::label('salary', null, 'sub_dealer'));
        $this->assertSame('স্টাফ কমিশন', PartnerIncomeHeads::label('referral', null, 'sub_dealer'));
        $this->assertSame('স্টাফ কমিশন', PartnerIncomeHeads::label('referral', null, 'dealer'));
        $this->assertSame('ডক্টর অনারারিয়াম', PartnerIncomeHeads::label('own', null, 'sub_dealer'));
    }
}

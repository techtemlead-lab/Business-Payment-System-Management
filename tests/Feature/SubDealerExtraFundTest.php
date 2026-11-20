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
 * and the extra part goes to a company fund of its own, "Sub-dealer extra". Today that part goes to the sub-dealer
 * rank fund. The change is a rule value, so it reaches new orders only and the default is today's behaviour.
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

    private function sendExtraToTheNewFund(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/commissions/rules', ['rules' => ['additional_rank' => ['parameters' => ['destination' => 'extra_fund']]]])->assertRedirect();
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

    public function test_today_the_extra_part_goes_to_the_sub_dealer_rank_fund(): void
    {
        $order = $this->posted('sub_dealer');

        $this->assertSame('3750.00', $this->batch($order)['allocated'], 'A sub-dealer purchase pays 50% of the price in commissions.');
        $this->assertSame('3000.00', $this->batch($order)['company'], 'The company keeps 30% of the MRP.');
        $this->assertSame('225.00', $this->amountOf($order, 'sub_dealer_salary'));
        $this->assertSame('225.00', $this->amountOf($order, 'sub_dealer_tour'));
        $this->assertSame('300.00', $this->amountOf($order, 'additional_rank_pool'));
        $this->assertSame('0.00', $this->amountOf($order, 'sub_dealer_extra_fund'));
        $this->assertSame(30000, $this->fund('rank_reward'));
        $this->assertSame(0, $this->fund('sub_dealer_extra'));
        $this->assertSame('rank_fund', collect((new CommissionPageModel(app(CommissionPolicy::class)))->build()['heads'])->firstWhere('key', 'additional_rank')['destination']);
    }

    public function test_after_the_rule_changes_a_new_order_sends_only_the_extra_part_to_the_new_fund(): void
    {
        $before = $this->posted('sub_dealer');
        $beforeRows = $this->rows($before);
        $failedBefore = $this->failedChecks();

        $this->sendExtraToTheNewFund();
        $after = $this->posted('sub_dealer');

        $moved = array_map(fn (array $row): array => $row[0] === 'additional_rank_pool' ? ['sub_dealer_extra_fund', $row[1], $row[2]] : $row, $beforeRows);
        $this->assertSame($moved, $this->rows($after), 'Every row is the same except the name of the fund the extra part sits in.');
        $this->assertSame($this->batch($before), $this->batch($after), 'The totals do not move.');
        $this->assertSame('300.00', $this->amountOf($after, 'sub_dealer_extra_fund'));
        $this->assertSame('0.00', $this->amountOf($after, 'additional_rank_pool'));
        $this->assertSame('225.00', $this->amountOf($after, 'sub_dealer_salary'));
        $this->assertSame('225.00', $this->amountOf($after, 'sub_dealer_tour'));
        $this->assertSame(30000, $this->fund('rank_reward'), 'The old order stays in the rank fund; the new one is not added to it.');
        $this->assertSame(30000, $this->fund('sub_dealer_extra'));
        $this->assertSame($failedBefore, $this->failedChecks(), 'Using the new fund adds no failing money check.');
        $this->assertSame(['order_receipts'], $this->failedChecks());
        $this->assertCount(18, app(FinancialReadiness::class)->checks());
    }

    public function test_an_order_already_posted_or_captured_before_the_change_does_not_move(): void
    {
        $old = $this->posted('sub_dealer');
        $beforeRows = $this->rows($old);
        $beforeSnapshot = $old->commission_policy_snapshot;
        $waiting = $this->newOrder('sub_dealer');
        // An order captured before the setting existed has no such value in its rules.
        $snapshot = $waiting->commission_policy_snapshot;
        unset($snapshot['rules']['additional_rank']['parameters']['destination']);
        $waiting->forceFill(['commission_policy_snapshot' => $snapshot])->save();

        $this->sendExtraToTheNewFund();

        $this->assertSame($beforeRows, $this->rows($old->refresh()));
        $this->assertSame($beforeSnapshot, $old->commission_policy_snapshot);
        $waiting = $this->deliver($waiting);
        $this->assertSame('300.00', $this->amountOf($waiting, 'additional_rank_pool'), 'An older snapshot keeps the old destination.');
        $this->assertSame('0.00', $this->amountOf($waiting, 'sub_dealer_extra_fund'));
    }

    public function test_a_dealer_order_is_exactly_the_same_before_and_after(): void
    {
        $before = $this->posted('dealer');
        $beforeRows = $this->rows($before);

        $this->sendExtraToTheNewFund();
        $after = $this->posted('dealer');

        $this->assertSame($beforeRows, $this->rows($after));
        $this->assertSame($this->batch($before), $this->batch($after));
        $this->assertSame('0.00', $this->amountOf($after, 'sub_dealer_extra_fund'));
        $this->assertSame('0.00', $this->amountOf($after, 'additional_rank_pool'), 'A dealer never pays the sub-dealer parts.');
        $this->assertSame('2800.00', $this->batch($after)['allocated'], 'A dealer purchase pays 40% of the price.');
    }

    public function test_a_refund_takes_the_money_back_out_of_the_new_fund(): void
    {
        $this->sendExtraToTheNewFund();
        $order = $this->posted('sub_dealer');
        $this->assertSame(30000, $this->fund('sub_dealer_extra'));
        $failedBefore = $this->failedChecks();

        app(CommissionService::class)->reverseOrder($order);

        $this->assertSame(0, $this->fund('sub_dealer_extra'));
        $this->assertSame($failedBefore, $this->failedChecks(), 'A refund of an order that used the new fund adds no failing money check.');
    }

    public function test_the_new_fund_has_a_treasury_card_and_a_plain_name_in_both_languages(): void
    {
        $this->sendExtraToTheNewFund();
        $this->posted('sub_dealer');

        $this->assertContains('sub_dealer_extra', FundCatalog::keys());
        $this->assertContains('sub_dealer_extra', CompanyFunds::keys());
        $this->assertTrue(CompanyFunds::isSpendable('sub_dealer_extra'), 'It is a company fund like marketing: it can be spent through the spend flow.');
        $card = collect(app(TreasuryFunds::class)->cards(true))->firstWhere('key', 'sub_dealer_extra');
        $this->assertNotNull($card);
        $this->assertSame('300.00', $card['money_in']);
        foreach (['bn' => 'সাব-ডিলার এক্সট্রা', 'en' => 'Sub-dealer extra'] as $locale => $word) {
            app()->setLocale($locale);
            $this->assertSame($word, FundCatalog::name('sub_dealer_extra'));
            $this->assertSame($word, FundCatalog::typeLabel('sub_dealer_extra_fund'));
        }
    }

    public function test_the_rule_value_is_checked_and_follows_the_draft_then_publish_flow(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/commissions/rules', ['rules' => ['additional_rank' => ['parameters' => ['destination' => 'somewhere']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('rules');

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/commissions/rules', ['rules' => ['additional_rank' => ['parameters' => ['destination' => 'extra_fund']]]])->assertRedirect();
        $this->assertSame('rank_fund', app(CommissionPolicy::class)->snapshot(false)['rules']['additional_rank']['parameters']['destination'], 'A draft changes nothing yet.');
        $this->assertSame('0.00', $this->amountOf($this->posted('sub_dealer'), 'sub_dealer_extra_fund'));

        $draft = DB::table('commission_policy_schedules')->where('origin', 'rule_edit')->where('status', 'draft')->value('id');
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$draft.'/review', ['decision' => 'approved', 'reason' => 'Checked against the budget'])->assertOk();

        $this->assertSame('extra_fund', app(CommissionPolicy::class)->snapshot(false)['rules']['additional_rank']['parameters']['destination']);
        $this->assertSame('300.00', $this->amountOf($this->posted('sub_dealer'), 'sub_dealer_extra_fund'));
    }

    public function test_the_page_groups_the_sub_dealer_only_heads_and_names_the_renamed_ones(): void
    {
        $heads = collect((new CommissionPageModel(app(CommissionPolicy::class)))->build()['heads']);

        $this->assertSame(['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'], $heads->where('block', 'sub_only')->pluck('key')->all());
        $this->assertCount(9, $heads->where('block', 'shared'));
        $this->assertSame(['direct_referral'], $heads->where('sub_name', true)->pluck('key')->all());
        $bn = require lang_path('bn/commission_page.php');
        $en = require lang_path('en/commission_page.php');
        $this->assertSame('ডক্টর অনারারিয়াম', $bn['head_own_dealer_cashback']);
        $this->assertSame('Doctor Honorarium', $en['head_own_dealer_cashback']);
        $this->assertSame('ডিলার একুইজিশন টার্গেট বোনাস', $bn['head_dealer_monthly_salary']);
        $this->assertSame('স্টাফ কমিশন', $bn['sub_name_direct_referral']);
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
        $this->assertSame('ডিলার একুইজিশন টার্গেট বোনাস', $bn['head_salary']);
        $this->assertSame('সাব-ডিলার স্যালারি', $bn['head_salary_sub']);
        $this->assertSame('স্টাফ কমিশন', $bn['head_referral_sub']);

        app()->setLocale('bn');
        $this->assertSame('ডিলার একুইজিশন টার্গেট বোনাস', PartnerIncomeHeads::label('salary', null, 'dealer'));
        $this->assertSame('সাব-ডিলার স্যালারি', PartnerIncomeHeads::label('salary', null, 'sub_dealer'));
        $this->assertSame('স্টাফ কমিশন', PartnerIncomeHeads::label('referral', null, 'sub_dealer'));
        $this->assertSame('রেফারেল আয় (সরাসরি)', PartnerIncomeHeads::label('referral', null, 'dealer'));
        $this->assertSame('ডক্টর অনারারিয়াম', PartnerIncomeHeads::label('own', null, 'sub_dealer'));
    }
}

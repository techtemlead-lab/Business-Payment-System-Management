<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\EventLogProjector;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\OrderMoneyReport;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Orders\OrderListFilter;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every figure on the new Treasury and partner money pages must add up: a fund's three numbers, an
 * order's lines against what was paid, a partner's statement against their wallet, and a card against
 * its filtered total. The owners' fund must never reach anyone but an owner on the owner panel.
 */
class TreasuryFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
        $this->owner = User::factory()->staff()->create();
    }

    private function allOf(array $query = []): Request
    {
        return Request::create('/x', 'GET', ['period' => '', ...$query]);
    }

    private function product(string $cash, string $mrp, ?User $buyer = null): Product
    {
        return Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash, 'sub_dealer_price' => $buyer?->role === 'sub_dealer' ? $cash : null, 'stock' => 50]);
    }

    private function placed(User $buyer, string $cash = '100.00', string $mrp = '200.00', int $quantity = 1): Order
    {
        return app(OrderService::class)->create($buyer, ['items' => [['product_id' => $this->product($cash, $mrp, $buyer)->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
    }

    private function receive(Order $order, string $amount, string $channel = 'bKash'): void
    {
        $reference = 'TRX'.Str::upper(Str::random(8));
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => $channel, 'reference' => $reference, 'amount' => $amount, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => $channel, 'reference' => $reference, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function delivered(User $buyer, string $cash = '100.00', string $mrp = '200.00'): Order
    {
        $order = $this->placed($buyer, $cash, $mrp);
        $this->receive($order, $cash);
        $order->update(['status' => 'delivered', 'paid' => $cash]);
        app(CommissionPosting::class)->process($order->fresh());

        return $order->fresh();
    }

    /** An order of two products where one parcel arrived and the other came back. */
    private function partlyDelivered(User $buyer, bool $postCommission = true): Order
    {
        $first = $this->product('100.00', '200.00', $buyer);
        $second = $this->product('50.00', '80.00', $buyer);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $first->id, 'quantity' => 1], ['product_id' => $second->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $this->receive($order, '150.00');
        $order->update(['status' => 'partly_delivered', 'paid' => '150.00']);
        $lines = $order->lines()->orderBy('id')->get();
        $this->parcel($order, $lines[0]->id, 'delivered');
        $this->parcel($order, $lines[1]->id, 'returned');
        if ($postCommission) {
            app(CommissionPosting::class)->processDeliveredPart($order->fresh());
        }

        return $order->fresh();
    }

    private function parcel(Order $order, int $lineId, string $status): void
    {
        DB::table('shipments')->insert(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => Str::random(10), 'status' => $status, 'lines' => json_encode([['line_id' => $lineId, 'quantity' => 1]]), 'actor_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertEveryFundAddsUp(string $when): void
    {
        $funds = app(TreasuryFunds::class);
        $balances = app(CompanyFunds::class)->balances();
        foreach ($funds->cards(true) as $card) {
            $key = $card['key'];
            $this->assertSame(FundCatalog::centsOf($card['money_in']) - FundCatalog::centsOf($card['money_out']), FundCatalog::centsOf($card['balance']), "$when: $key in minus out");
            $this->assertSame($card['money_in'], $funds->moneyIn($this->allOf(), $key)['totals']['sums']['amount'], "$when: $key rows in");
            $this->assertSame($card['money_out'], $funds->moneyOut($this->allOf(), $key)['totals']['sums']['amount'], "$when: $key rows out");
            if (in_array($key, CompanyFunds::keys(), true)) {
                $this->assertSame($balances[$key]['balance'], FundCatalog::centsOf($card['balance']), "$when: $key equals what the spend form allows");
            }
        }
    }

    /** @return array<string, string> */
    private function balanceOf(array $cards): array
    {
        return array_column($cards, 'balance', 'key');
    }

    private function card(string $fund, bool $showOwnerFunds = true): array
    {
        return app(TreasuryFunds::class)->card($fund, $showOwnerFunds);
    }

    public function test_every_fund_adds_up_after_orders(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $this->delivered(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]), '173.33', '333.33');
        $this->delivered(User::factory()->subDealer()->create(['sponsor_id' => $sponsor->id]), '150.37', '191.19');

        $this->assertEveryFundAddsUp('after orders');
        $this->assertSame('173.33', DB::table('orders')->orderBy('created_at')->value('paid'));
    }

    public function test_every_fund_adds_up_after_a_refund_with_no_paisa_drift(): void
    {
        $orders = [];
        foreach (['173.33', '99.99', '33.33'] as $cash) {
            $orders[] = $this->delivered(User::factory()->dealer()->create(), $cash, number_format((float) $cash * 2, 2, '.', ''));
        }
        foreach ($orders as $order) {
            app(CommissionReversal::class)->refund($order, 3333, 'Part sent back', 'R-'.Str::random(6), $this->owner->id);
        }

        $this->assertEveryFundAddsUp('after refunds');
    }

    public function test_a_closed_salary_month_nets_to_zero_and_every_fund_still_adds_up(): void
    {
        $this->delivered(User::factory()->dealer()->create());
        $salaryIn = $this->card('salary_pool')['money_in'];
        $this->assertNotSame('0.00', $salaryIn);

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));
        app(CommissionSettlement::class)->settle('2026-09', 'salary');
        app(CommissionSettlement::class)->settle('2026-09', 'royalty');

        foreach (['salary_pool', 'royalty_pool'] as $fund) {
            $card = $this->card($fund);
            $this->assertSame('0.00', $card['balance'], $fund);
            $this->assertSame($card['money_in'], $card['money_out'], $fund);
        }
        $this->assertSame($salaryIn, $this->card('salary_pool')['money_in']);
        $this->assertEveryFundAddsUp('after month close');
    }

    public function test_a_closed_month_shows_paid_and_moved_lines_that_add_up_to_the_card(): void
    {
        $partner = User::factory()->dealer()->create();
        $this->delivered(User::factory()->dealer()->create());
        $fund = CommissionAllocation::where('category', 'dealer_monthly_salary_pool')->sole();
        $paid = Money::cents($fund->amount) - 37;
        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $fund->id, 'user_id' => $partner->id, 'kind' => 'salary', 'period_month' => '2026-09', 'amount' => Money::decimal($paid), 'refunded_basis' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $settlement = app(CommissionSettlement::class);
        $settlement->toCompanyProfit($fund, 37, '2026-09', 'salary', null);
        $fund->update(['status' => 'settled', 'metadata' => [...$fund->metadata, 'settled_at' => now()->toIso8601String(), 'settled_period' => '2026-09', 'distributed' => Money::decimal($paid), 'reserved' => '0.37']]);

        $table = app(TreasuryFunds::class)->moneyOut($this->allOf(), 'salary_pool');
        $lines = collect($table['rows']->items());

        $this->assertSame(2, $lines->count());
        $this->assertSame(Money::decimal($paid), $lines->firstWhere('amount', Money::decimal($paid))['amount']);
        $this->assertSame('0.37', $lines->firstWhere('amount', '0.37')['amount']);
        $this->assertSame($this->card('salary_pool')['money_out'], $table['totals']['sums']['amount']);
        $this->assertEveryFundAddsUp('closed month');
    }

    public function test_a_closed_sub_dealer_rank_month_keeps_its_money_in_and_nets_to_zero(): void
    {
        $this->delivered(User::factory()->subDealer()->create(), '100.00', '120.00');
        $before = $this->card('rank_reward')['money_in'];
        $this->assertNotSame('0.00', $before);

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));
        app(ReserveService::class)->closeAdditionalRankFund('2026-09', $this->owner->id);

        $card = $this->card('rank_reward');
        $this->assertSame($before, $card['money_in']);
        $this->assertSame($before, $card['money_out']);
        $this->assertSame('0.00', $card['balance']);
        $this->assertEveryFundAddsUp('closed rank month');
    }

    public function test_the_old_money_cards_stay_hidden_while_nothing_came_in(): void
    {
        $keys = array_column(app(TreasuryFunds::class)->cards(true), 'key');

        $this->assertNotContains('salary_reserve', $keys);
        $this->assertNotContains('geographic_reserve', $keys);
        $this->assertContains('salary_pool', $keys);
    }

    public function test_order_detail_lines_equal_what_was_paid_with_every_partner_named(): void
    {
        $place = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $seatHolder = User::factory()->dealer()->inPlace($place)->create(['name' => 'Seat Holder']);
        AreaDealerAssignment::create(['user_id' => $seatHolder->id, 'level' => 'division', 'place_code_id' => $place->id, 'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => '0.00', 'is_active' => true]);
        $sponsor = User::factory()->dealer()->create(['name' => 'Rahim Traders']);
        $order = $this->delivered(User::factory()->dealer()->inPlace($place)->create(['sponsor_id' => $sponsor->id]), '100.00', '200.00');

        $detail = app(OrderMoneyReport::class)->detail($order, true);

        $this->assertSame('posted', $detail['posting']);
        $this->assertTrue($detail['adds_up']);
        $this->assertSame('100.00', $detail['lines_total']);
        $this->assertSame('100.00', $detail['order']['paid']);
        $this->assertSame('50.00', $detail['order']['percent_off']);
        $referral = collect($detail['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'direct_referral'));
        $this->assertSame(['kind' => 'partner', 'id' => $sponsor->id, 'name' => 'Rahim Traders', 'code' => $sponsor->dealer_code, 'area' => $referral['to']['area']], $referral['to']);
        $area = collect($detail['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'area_division'));
        $this->assertSame('Seat Holder', $area['to']['name']);
        $this->assertStringContainsString('Rangpur', (string) $area['to']['area']);
        $marketing = collect($detail['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'company_marketing_fund'));
        $this->assertSame(['kind' => 'fund', 'key' => 'marketing', 'name' => FundCatalog::name('marketing')], $marketing['to']);
    }

    public function test_an_order_not_delivered_yet_has_only_header_figures(): void
    {
        $order = $this->placed(User::factory()->dealer()->create());
        $this->receive($order, '100.00');
        $order->update(['status' => 'approved', 'paid' => '100.00']);

        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);

        $this->assertSame('waiting_delivery', $detail['posting']);
        $this->assertSame([], $detail['lines']);
        $this->assertNull($detail['basis']);
        $this->assertSame('200.00', $detail['order']['mrp_total']);
    }

    public function test_a_part_delivered_order_splits_only_the_delivered_part(): void
    {
        $order = $this->partlyDelivered(User::factory()->dealer()->create());

        $detail = app(OrderMoneyReport::class)->detail($order, true);

        $this->assertSame('part_delivered', $detail['posting']);
        $this->assertTrue($detail['adds_up']);
        $this->assertSame('100.00', $detail['lines_total']);
        $this->assertSame('100.00', $detail['order']['paid']);
        $this->assertSame('200.00', $detail['order']['mrp_total']);
        $this->assertSame('150.00', $detail['order']['order_total']);
    }

    public function test_a_refund_after_delivery_shows_the_original_and_what_went_back(): void
    {
        $order = $this->delivered(User::factory()->dealer()->create(), '100.00', '200.00');
        app(CommissionReversal::class)->refund($order, 2500, 'Goods returned', 'R-1', $this->owner->id);

        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);

        $this->assertTrue($detail['adds_up']);
        $this->assertSame('100.00', $detail['lines_total']);
        $this->assertSame('25.00', $detail['basis']['refunded']);
        $returned = array_sum(array_map(fn (array $line): int => Money::cents($line['returned']), $detail['lines']));
        $this->assertSame(2500, $returned);
    }

    public function test_held_and_forfeited_lines_still_add_up_and_the_moved_row_is_not_counted_twice(): void
    {
        $place = PlaceCode::factory()->division()->create(['name' => 'Sylhet']);
        $seatHolder = User::factory()->dealer()->inPlace($place)->create();
        AreaDealerAssignment::create(['user_id' => $seatHolder->id, 'level' => 'division', 'place_code_id' => $place->id, 'division' => 'Sylhet', 'zone_name' => 'Sylhet', 'monthly_quota' => '99999.00', 'is_active' => true]);
        $order = $this->delivered(User::factory()->dealer()->inPlace($place)->create());
        $detail = app(OrderMoneyReport::class)->detail($order, true);
        $held = collect($detail['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'area_division'));
        $this->assertSame('held', $held['status']);
        $this->assertTrue($detail['adds_up']);

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(1, 0));
        app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-09');

        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);
        $moved = collect($detail['lines'])->first(fn (array $line): bool => str_starts_with($line['key'], 'area_division'));
        $this->assertSame('moved', $moved['status']);
        $this->assertTrue($detail['adds_up']);
        $this->assertSame('100.00', $detail['lines_total']);
        $this->assertEveryFundAddsUp('after a forfeit');
    }

    public function test_no_commission_goes_to_the_company_profit_fund_with_the_staff_reason(): void
    {
        $buyer = User::factory()->dealer()->create();
        $order = $this->placed($buyer);
        $this->receive($order, '100.00');
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        $batch = DB::table('commission_batches')->insertGetId(['order_id' => $order->id, 'account_type' => 'dealer', 'period_month' => '2026-09', 'cash_basis' => '100.00', 'mrp_basis' => '200.00', 'company_share' => '60.00', 'total_allocated' => '40.00', 'unallocated' => '0.00', 'policy' => json_encode(['company_mrp_percent' => '30.00']), 'created_at' => now(), 'updated_at' => now()]);
        CommissionAllocation::create(['order_id' => $order->id, 'user_id' => null, 'category' => CompanyFunds::NoCommissionCategory, 'rate_percentage' => '40.00', 'cash_basis' => '100.00', 'amount' => '40.00', 'original_amount' => '40.00', 'status' => 'reserved', 'metadata' => ['reason' => 'Dealer agreed to take no commission', 'retained_by' => 'company_profit_fund']]);

        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);
        $this->assertSame('no_commission', $detail['posting']);
        $this->assertSame(['reason' => 'Dealer agreed to take no commission', 'amount' => '40.00'], $detail['no_commission']);
        $line = collect($detail['lines'])->first(fn (array $row): bool => str_starts_with($row['key'], CompanyFunds::NoCommissionCategory));
        $this->assertSame('company_profit', $line['to']['key']);
        $this->assertSame('Dealer agreed to take no commission', $line['reason']);
        $this->assertTrue($detail['adds_up']);

        $row = collect(app(TreasuryFunds::class)->moneyIn($this->allOf(), 'company_profit')['rows']->items())->firstWhere('order_number', $order->number);
        $this->assertSame('40.00', $row['amount']);
        $this->assertSame('Dealer agreed to take no commission', $row['reason']);
        $this->assertNotNull($batch);
        $this->assertEveryFundAddsUp('no commission');
    }

    public function test_no_commission_after_posting_shows_one_company_line_instead_of_the_partners(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $order = $this->delivered(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $first = CommissionAllocation::where('order_id', $order->id)->orderBy('id')->firstOrFail();
        $commission = (string) DB::table('commission_batches')->where('order_id', $order->id)->value('total_allocated');
        CommissionAllocation::where('order_id', $order->id)->where('category', '!=', 'company_commission_benefits_fund')->update(['status' => 'reversed', 'amount' => '0.00']);
        CommissionAllocation::create(['order_id' => $order->id, 'user_id' => null, 'category' => CompanyFunds::NoCommissionCategory, 'rate_percentage' => '40.00', 'cash_basis' => '100.00', 'amount' => $commission, 'original_amount' => $commission, 'status' => 'reserved',
            'metadata' => ['reason' => 'Dealer refused the rest', 'retained_by' => 'company_profit_fund', 'source_allocation_id' => $first->id, 'taken_back' => '0.00', 'still_owed' => '0.00']]);

        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);

        $this->assertSame('no_commission', $detail['posting']);
        $this->assertSame(['reason' => 'Dealer refused the rest', 'amount' => $commission], $detail['no_commission']);
        $this->assertTrue($detail['adds_up'], 'company share, owners share and the one fund line add up to what was paid');
        $this->assertFalse(collect($detail['lines'])->contains(fn (array $line): bool => str_starts_with($line['key'], 'direct_referral')));
        $line = collect($detail['lines'])->first(fn (array $row): bool => str_starts_with($row['key'], CompanyFunds::NoCommissionCategory));
        $this->assertSame('company_profit', $line['to']['key']);
        $this->assertSame('Dealer refused the rest', $line['reason']);
        $this->assertSame($commission, collect(app(TreasuryFunds::class)->moneyIn($this->allOf(), 'company_profit')['rows']->items())->firstWhere('reason', 'Dealer refused the rest')['amount']);
        $this->assertEveryFundAddsUp('no commission after posting');
    }

    public function test_the_order_report_counts_only_delivered_goods(): void
    {
        $delivered = $this->delivered(User::factory()->dealer()->create(), '100.00', '200.00');
        $part = $this->partlyDelivered(User::factory()->dealer()->create(), false);
        $notYet = $this->placed(User::factory()->dealer()->create(), '500.00', '900.00');
        $this->receive($notYet, '500.00');
        $notYet->update(['status' => 'approved', 'paid' => '500.00']);

        $table = app(OrderMoneyReport::class)->table($this->allOf(), null);

        $this->assertSame(2, $table['totals']['count']);
        $this->assertSame('400.00', $table['totals']['sums']['mrp_total']);
        $this->assertSame('200.00', $table['totals']['sums']['paid']);
        $rows = collect($table['rows']->items())->keyBy('id');
        $this->assertSame('100.00', $rows[$part->id]['paid']);
        $this->assertSame('200.00', $rows[$part->id]['mrp_total']);
        $this->assertSame('100.00', $rows[$delivered->id]['paid']);
    }

    public function test_lifetime_figures_count_delivered_goods_only(): void
    {
        $partner = User::factory()->dealer()->create();
        $this->delivered($partner, '100.00', '200.00');
        $this->partlyDelivered($partner, false);
        $notYet = $this->placed($partner, '500.00', '900.00');
        $this->receive($notYet, '500.00');
        $notYet->update(['status' => 'approved', 'paid' => '500.00']);

        $lifetime = app(PartnerMoneyBook::class)->summary($partner)['lifetime'];

        $this->assertSame('400.00', $lifetime['ordered_mrp']);
        $this->assertSame('200.00', $lifetime['cash_given']);
    }

    public function test_a_statement_closes_on_the_wallet_through_a_refund_and_a_later_debt_recovery(): void
    {
        $earner = User::factory()->dealer()->create();
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $earner->id]);
        $first = $this->delivered($buyer, '100.00', '200.00');
        $earned = Money::cents((string) DB::table('wallets')->where('user_id', $earner->id)->value('available'));
        $this->assertSame(1000, $earned);
        $payoutId = (string) Str::uuid();
        DB::transaction(function () use ($earner, $payoutId): void {
            CommissionWallet::lock();
            app(CommissionWallet::class)->journal('manual-payout:'.$payoutId, 'Withdrawn', [
                ['account' => 'wallet_liability', 'user_id' => $earner->id, 'debit' => 1000, 'credit' => 0],
                ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => 1000],
            ]);
            DB::table('wallets')->where('user_id', $earner->id)->update(['available' => '0.00']);
        });

        app(CommissionReversal::class)->refund($first, 10000, 'Whole order returned', 'R-2', $this->owner->id);
        $reversal = DB::table('commission_reversals')->where('user_id', $earner->id)->sole();
        $this->assertSame('10.00', (string) $reversal->unrecovered);

        $this->delivered($buyer, '50.00', '100.00');

        $this->assertStatementClosesOnWallet($earner);
        $this->assertSame('5.00', (string) DB::table('commission_reversals')->where('user_id', $earner->id)->value('unrecovered'), 'half of the debt was recovered from the next commission');
    }

    private function assertStatementClosesOnWallet(User $partner): void
    {
        $book = app(PartnerMoneyBook::class);
        $table = $book->table($this->allOf(), $partner);
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first();
        $expected = Money::decimal(Money::cents((string) $wallet->available) + Money::cents((string) $wallet->held));

        $this->assertSame($expected, $table['totals']['sums']['amount'], 'rows add up to the wallet');
        $this->assertSame($expected, $table['rows']->items()[0]['running'], 'closing running balance is the wallet');
        $this->assertSame($expected, $book->summary($partner)['total']);
    }

    public function test_a_statement_closes_on_the_wallet_with_held_waiting_and_released_money(): void
    {
        $place = PlaceCode::factory()->division()->create(['name' => 'Khulna']);
        $seatHolder = User::factory()->dealer()->inPlace($place)->create();
        AreaDealerAssignment::create(['user_id' => $seatHolder->id, 'level' => 'division', 'place_code_id' => $place->id, 'division' => 'Khulna', 'zone_name' => 'Khulna', 'monthly_quota' => '1.00', 'is_active' => true]);
        $buyer = User::factory()->dealer()->inPlace($place)->create(['sponsor_id' => $seatHolder->id]);
        $this->delivered($buyer);
        $this->delivered($seatHolder, '60.00', '120.00');
        $this->delivered($buyer);
        $this->assertStatementClosesOnWallet($seatHolder);
        $this->assertStatementClosesOnWallet($buyer);

        $book = app(PartnerMoneyBook::class);
        $cards = collect($book->incomeCards($seatHolder))->keyBy('key');
        $this->assertTrue($cards->has('referral'));
        $this->assertTrue($cards->has('area'));
    }

    public function test_every_card_equals_its_filtered_total(): void
    {
        $earner = User::factory()->dealer()->create();
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $earner->id]);
        $this->delivered($buyer, '100.00', '200.00');
        $second = $this->delivered($buyer, '73.33', '120.00');
        app(CommissionReversal::class)->refund($second, 1000, 'Part back', 'R-3', $this->owner->id);

        $book = app(PartnerMoneyBook::class);
        foreach ([$earner, $buyer] as $partner) {
            $cards = $book->incomeCards($partner);
            $this->assertNotSame([], $cards);
            foreach ($cards as $card) {
                $table = $book->table(Request::create('/x', 'GET', ['type' => $card['key']]), $partner);
                $this->assertSame($card['amount'], $table['totals']['sums']['amount'], $card['key']);
                $this->assertNull($table['rows']->items()[0]['running'], 'running total is hidden when a type is chosen');
            }
        }
    }

    public function test_the_statement_default_view_is_all_time_so_cards_and_totals_match(): void
    {
        $earner = User::factory()->dealer()->create();
        $this->delivered(User::factory()->dealer()->create(['sponsor_id' => $earner->id]));
        $this->travelTo(now()->addMonths(3));

        $book = app(PartnerMoneyBook::class);
        $table = $book->table(Request::create('/x', 'GET', ['type' => 'referral']), $earner);

        $this->assertSame($book->incomeCards($earner)[0]['amount'], $table['totals']['sums']['amount']);
    }

    public function test_the_owners_fund_is_hidden_from_finance_staff_everywhere(): void
    {
        $order = $this->delivered(User::factory()->dealer()->create(), '100.00', '150.00');
        $this->assertNotSame('0.00', $this->card('benefits')['money_in']);
        $finance = User::factory()->staff('finance')->create();
        $spend = DB::table('reserve_disbursements')->insertGetId(['program' => 'company_spend', 'fund_key' => 'benefits', 'amount' => '1.00', 'status' => 'paid', 'reason' => 'Owner spend', 'payee_name' => 'Family', 'request_key' => (string) Str::uuid(), 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'fund.spend.'.$spend, 'description' => 'Owners spend', 'actor_id' => $this->owner->id, 'created_at' => now()]);
        DB::table('journal_lines')->insert(['entry_id' => $entry, 'account' => 'company_fund_expense', 'debit' => '1.00', 'credit' => '0.00']);
        app(EventLogProjector::class)->rebuild();

        $this->actingAs($finance)->onPortal('manage')->get('/treasury/funds')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('TreasuryFunds')->where('cards', fn ($cards): bool => ! collect($cards)->contains('key', 'benefits')));
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/funds/benefits')->assertNotFound();
        $this->assertContains($this->actingAs($this->owner)->onPortal('manage')->get('/treasury/funds/benefits')->getStatusCode(), [403, 404], 'an owner away from the owner panel does not see it');

        $this->actingAs($finance)->onPortal('manage')->get('/treasury/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.lines', function ($lines): bool {
                $lines = collect($lines);
                $merged = $lines->firstWhere('key', 'company_share');

                return $lines->where('key', 'owners_share')->isEmpty() && $merged['percent'] === null && $lines->every(fn (array $line): bool => ($line['to']['key'] ?? null) !== 'benefits');
            })->where('detail.adds_up', true));

        foreach ($this->eventRows($finance, 'manage', '') as $row) {
            $this->assertNotSame('benefits', $row['fund_key']);
        }
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/events?period=')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('table.filters.2.options', fn ($options): bool => ! collect($options)->contains('value', 'benefits')));
    }

    /** @return list<array<string, mixed>> */
    private function eventRows(User $viewer, string $portal, string $period): array
    {
        $rows = [];
        $this->actingAs($viewer)->onPortal($portal)->get('/treasury/events?period='.$period)->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = $page->toArray()['props']['table']['rows']['data'];

                return $page;
            });

        return $rows;
    }

    public function test_an_owner_on_the_owner_panel_sees_the_owners_fund(): void
    {
        $order = $this->delivered(User::factory()->dealer()->create(), '100.00', '150.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/benefits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('TreasuryFund')->where('card.key', 'benefits')->where('card.owner_only', true));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.lines', fn ($lines): bool => collect($lines)->contains('key', 'owners_share')));

        $rows = $this->eventRows($this->owner, 'sadmin', '');
        $this->assertTrue(collect($rows)->contains('fund_key', 'benefits'));
    }

    public function test_only_people_with_the_right_can_open_each_page(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/treasury/funds')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/treasury/events')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/treasury/partners')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()->assertInertia(fn (Assert $page) => $page->component('MyMoney')->has('summary')->has('cards')->has('table')->has('withdraw')->has('certificates'));
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/partners')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreasuryPartners')->has('table.rows')->where('section', 'treasury')->has('tabs.waiting'));
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/partners/'.$this->owner->id)->assertNotFound();
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/partners/'.$dealer->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreasuryPartner')->where('partner.id', $dealer->id));
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/work')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreasuryWork')->has('tiles')->has('approvals_table')->has('heads')->has('budget_month'));
    }

    public function test_the_partner_home_loads_the_income_cards_after_the_page(): void
    {
        $earner = User::factory()->dealer()->create();
        $this->delivered(User::factory()->dealer()->create(['sponsor_id' => $earner->id]));

        $this->actingAs($earner)->onPortal('dealer')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('data.money_cards')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('data.money_cards.0.key', 'referral')->where('data.money_cards.0.amount', '10.00')));
    }

    public function test_this_week_starts_on_sunday_in_every_language(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Asia/Dhaka'));

        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $range = OrderListFilter::createdRange('week', null, null);
            $this->assertSame('2026-11-07 18:00:00', $range['from']->format('Y-m-d H:i:s'), $locale.' week starts on Sunday in Dhaka');
            $this->assertSame('2026-11-14 17:59:59', $range['until']->format('Y-m-d H:i:s'), $locale.' week ends on Saturday in Dhaka');
        }
    }

    public function test_the_partner_list_shows_wallet_and_lifetime_figures(): void
    {
        $partner = User::factory()->dealer()->create();
        $this->delivered($partner, '100.00', '200.00');
        $finance = User::factory()->staff('finance')->create();
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first();

        $this->actingAs($finance)->onPortal('manage')->get('/treasury/partners?q='.$partner->dealer_code)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.data.0.id', $partner->id)
                ->where('table.rows.data.0.available', (string) $wallet->available)
                ->where('table.rows.data.0.ordered_mrp', '200.00')
                ->where('table.rows.data.0.cash_given', '100.00'));
    }

    public function test_the_projector_writes_each_event_once_and_catches_up(): void
    {
        $projector = app(EventLogProjector::class);
        $order = $this->delivered(User::factory()->dealer()->create());

        $added = $projector->catchUp();
        $this->assertGreaterThan(0, $added);
        $this->assertSame(0, $projector->catchUp());
        $this->assertSame(1, DB::table('money_events')->where('action', 'order_placed')->where('order_id', $order->id)->count());
        $this->assertSame('100.00', (string) DB::table('money_events')->where('action', 'order_placed')->value('amount'));

        $this->delivered(User::factory()->dealer()->create());
        $this->assertGreaterThan(0, $projector->catchUp());
        $this->assertSame(2, DB::table('money_events')->where('action', 'order_placed')->count());

        $rebuilt = $projector->rebuild();
        $this->assertSame($rebuilt, DB::table('money_events')->count());
        $this->assertSame(0, $projector->catchUp());
    }

    public function test_verify_matches_the_records_after_a_catch_up(): void
    {
        $projector = app(EventLogProjector::class);
        $this->delivered(User::factory()->dealer()->create());
        $this->partlyDelivered(User::factory()->dealer()->create());

        $counts = $projector->verify();

        foreach ($counts as $source => $pair) {
            $this->assertSame($pair['source'], $pair['log'], $source);
        }
        $this->assertGreaterThan(0, $counts['orders']['source']);
        $this->assertGreaterThan(0, $counts['allocations']['source']);
    }

    public function test_the_event_log_page_is_up_to_date_without_waiting_for_the_scheduler(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->placed(User::factory()->dealer()->create());

        $rows = $this->eventRows($finance, 'manage', 'today');

        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => $row['action'] === 'order_placed' && $row['order_number'] === $order->number));
    }

    public function test_event_totals_appear_only_when_one_event_is_chosen(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $this->delivered(User::factory()->dealer()->create());

        $this->actingAs($finance)->onPortal('manage')->get('/treasury/events')->assertOk()->assertInertia(fn (Assert $page) => $page->where('table.totals.sums', null));
        $this->actingAs($finance)->onPortal('manage')->get('/treasury/events?action=order_placed')->assertOk()->assertInertia(fn (Assert $page) => $page->where('table.totals.sums.amount', '100.00'));
    }

    public function test_money_in_and_money_out_pages_open_for_every_visible_fund(): void
    {
        $this->delivered(User::factory()->subDealer()->create(), '100.00', '120.00');
        $finance = User::factory()->staff('finance')->create();

        foreach (FundCatalog::visibleKeys(false) as $key) {
            if (in_array($key, FundCatalog::HiddenWhileEmpty, true)) {
                continue;
            }
            $this->actingAs($finance)->onPortal('manage')->get('/treasury/funds/'.$key)->assertOk()->assertInertia(fn (Assert $page) => $page->where('card.key', $key)->where('view', 'in'));
            $this->actingAs($finance)->onPortal('manage')->get('/treasury/funds/'.$key.'?view=out')->assertOk()->assertInertia(fn (Assert $page) => $page->where('view', 'out'));
        }
    }
}

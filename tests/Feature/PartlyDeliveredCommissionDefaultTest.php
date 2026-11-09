<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\CompanyBranch;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Worked example used throughout: 5 units at ৳2,000 (MRP ৳3,000), ordered and paid ৳10,000.
 * 3 units arrive (৳6,000) and 2 come back (৳4,000).
 */
class PartlyDeliveredCommissionDefaultTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private User $sponsor;

    private User $dealer;

    private CompanyBranch $hub;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->reviewer = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->sponsor = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->sponsor->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->sponsor->id]);
        ReferralTreePath::attachUser($this->dealer->id, $this->sponsor->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 0]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $this->product->id, 'quantity' => 100, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id, 'batch_number' => 'B1', 'expires_on' => now()->addYear()->toDateString()]);
    }

    public function test_commission_posts_on_the_delivered_goods_when_the_returned_parcel_closes_the_order(): void
    {
        [$order, $parcels] = $this->sentOrder();

        $this->confirmDelivered($order, $parcels[0]);
        $this->assertSame(0, DB::table('commission_batches')->where('order_id', $order->id)->count(), 'Nothing posts while a parcel is still on the road.');

        $this->receiveBack($order, $parcels[1]);

        $this->assertSame('partly_delivered', $order->fresh()->status);
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $this->assertNotNull($batch);
        $this->assertSame('6000.00', (string) $batch->cash_basis);
        $this->assertSame('9000.00', (string) $batch->mrp_basis);
        $this->assertSame('0.00', (string) $batch->refunded_amount);
        $this->assertSame($this->sameSizedRows(3), $this->rows($order));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch());
    }

    public function test_commission_posts_when_the_delivered_parcel_closes_the_order(): void
    {
        [$order, $parcels] = $this->sentOrder();

        $this->receiveBack($order, $parcels[1]);
        $this->assertSame(0, DB::table('commission_batches')->where('order_id', $order->id)->count());

        $this->confirmDelivered($order, $parcels[0]);

        $this->assertSame('partly_delivered', $order->fresh()->status);
        $this->assertSame('6000.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('cash_basis'));
    }

    public function test_the_automatic_posting_counts_toward_volume_and_rank_on_the_delivered_part_only(): void
    {
        $this->partlyDelivered();

        $this->assertSame('6000.00', $this->monthly());
        $this->assertSame('6000.00', $this->teamVolume());
        $this->assertSame('6000.00', (string) DealerRank::query()->where('user_id', $this->dealer->id)->value('lifetime_purchase_total'));
    }

    public function test_goods_not_delivered_go_back_into_stock_as_before(): void
    {
        $before = (int) $this->product->fresh()->stock;

        $this->partlyDelivered();

        $this->assertSame($before - 3, (int) $this->product->fresh()->stock);
    }

    public function test_an_automatically_posted_order_does_not_wait_for_a_staff_decision(): void
    {
        $this->partlyDelivered();

        $this->assertSame([], app(MixedOutcomeDecisions::class)->pending(DB::table('orders')->select('id')));
    }

    public function test_the_order_history_says_the_commission_was_posted_automatically(): void
    {
        $order = $this->partlyDelivered();

        $event = DB::table('order_events')->where('order_id', $order->id)->where('action', MixedOutcomeDecisions::PostedAction)->first();

        $this->assertNotNull($event);
        $this->assertSame('6000.00', json_decode((string) $event->metadata, true)['delivered_value']);
    }

    public function test_closing_a_parcel_never_fails_because_commission_cannot_post_and_staff_still_see_the_order(): void
    {
        DB::table('financial_verifications')->delete();
        [$order, $parcels] = $this->sentOrder();

        $this->confirmDelivered($order, $parcels[0]);
        $this->receiveBack($order, $parcels[1]);

        $this->assertSame('partly_delivered', $order->fresh()->status);
        $this->assertSame(0, DB::table('commission_batches')->where('order_id', $order->id)->count());
        $this->assertSame([(int) $order->id], array_map(fn (object $row): int => (int) $row->order_id, app(MixedOutcomeDecisions::class)->pending(DB::table('orders')->select('id'))));
    }

    public function test_no_commission_on_an_order_that_never_posted_writes_the_money_to_the_company_profit_fund(): void
    {
        $profitBefore = $this->fund('company_profit');
        $order = $this->unpostedMixedOrder();
        $walletsBefore = $this->wallets();

        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer refused the goods');

        $sameSized = $this->sameSizedTotals(3);
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $this->assertNotNull($batch);
        $this->assertTrue((bool) $batch->no_commission);
        $this->assertSame('6000.00', (string) $batch->cash_basis);
        $this->assertSame('9000.00', (string) $batch->mrp_basis);
        $this->assertSame($sameSized['company_share'], (string) $batch->company_share);
        $this->assertSame($sameSized['total_allocated'], (string) $batch->total_allocated);
        $this->assertSame($sameSized['unallocated'], (string) $batch->unallocated);

        $row = CommissionAllocation::query()->where('order_id', $order->id)->where('category', CommissionPosting::NoCommissionCategory)->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame($sameSized['total_allocated'], (string) $row->amount);
        $this->assertSame('Dealer refused the goods', $row->metadata['reason']);
        $this->assertSame(CommissionPosting::CompanyProfit, $row->metadata['retained_by']);

        $this->assertEquals($walletsBefore, $this->wallets(), 'Nobody is paid.');
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'commissions.order.'.$order->id)->count());
        $this->assertSame($profitBefore + Money::cents($sameSized['total_allocated']), $this->fund('company_profit'));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_no_commission_after_the_automatic_posting_takes_the_money_back_and_keeps_the_books_right(): void
    {
        $order = $this->partlyDelivered();
        $posted = $this->batchFigures($order);
        $sponsorBefore = $this->available($this->sponsor);
        $profitBefore = $this->fund('company_profit');
        $companyShareBefore = $this->fund('company_share');
        $this->assertGreaterThan(0, $sponsorBefore);

        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer complained about the parcel');

        $this->assertSame(0, $this->available($this->sponsor));
        $this->assertSame(0, $this->available($this->dealer));
        $this->assertSame('reversed', CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->value('status'));

        $row = CommissionAllocation::query()->where('order_id', $order->id)->where('category', CommissionPosting::NoCommissionCategory)->firstOrFail();
        $this->assertSame($posted['total_allocated'], (string) $row->amount);
        $this->assertSame('Dealer complained about the parcel', $row->metadata['reason']);

        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $this->assertTrue((bool) $batch->no_commission);
        $this->assertSame('0.00', (string) $batch->refunded_amount, 'The goods were kept, so this is not a refund of the order.');
        $this->assertSame($companyShareBefore, $this->fund('company_share'), 'The company share of the delivered goods stays.');

        $retainedBefore = $posted['total_allocated'];
        $this->assertSame($profitBefore + Money::cents($retainedBefore) - $this->profitRowsOnTheOrderBefore($posted), $this->fund('company_profit'));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_no_commission_recovers_money_already_withdrawn_from_later_earnings(): void
    {
        $order = $this->partlyDelivered();
        $earned = $this->available($this->sponsor);
        $this->spendWholeWallet($this->sponsor, $earned);

        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer complained about the parcel');

        $this->assertSame(0, $this->available($this->sponsor));
        $this->assertSame($earned, Money::cents((string) DB::table('commission_reversals')->where('user_id', $this->sponsor->id)->sum('unrecovered')));
        $row = CommissionAllocation::query()->where('order_id', $order->id)->where('category', CommissionPosting::NoCommissionCategory)->firstOrFail();
        $this->assertSame(Money::decimal($earned), $row->metadata['still_owed']);
        $this->assertTrue(CommissionWallet::hasDebt($this->sponsor->id));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));

        $this->fullyDelivered(1);

        $this->assertSame(0, $this->available($this->sponsor), 'New earnings pay off what is owed first.');
        $this->assertLessThan($earned, Money::cents((string) DB::table('commission_reversals')->where('user_id', $this->sponsor->id)->sum('unrecovered')));
    }

    public function test_with_no_commission_the_order_counts_for_no_volume_or_rank(): void
    {
        $order = $this->partlyDelivered();
        $this->assertSame('6000.00', $this->monthly());

        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer complained about the parcel');

        $this->assertSame('0.00', $this->monthly());
        $this->assertSame('0.00', $this->teamVolume());
        $this->assertSame('0.00', (string) DealerRank::query()->where('user_id', $this->dealer->id)->value('lifetime_purchase_total'));

        $unposted = $this->unpostedMixedOrder();
        $this->decide($unposted, MixedOutcomeDecisions::NoCommission, 'Dealer refused the goods');

        $this->assertSame('0.00', $this->monthly());
        $this->assertSame('0.00', $this->teamVolume());
    }

    public function test_no_commission_can_be_chosen_only_once(): void
    {
        $order = $this->partlyDelivered();
        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer complained about the parcel');
        $rows = $this->rows($order);

        try {
            $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Second try at the same order');
            $this->fail('A second decision must be refused.');
        } catch (ValidationException) {
            $this->assertSame($rows, $this->rows($order));
        }
    }

    public function test_the_delivered_part_choice_is_refused_once_the_commission_has_posted(): void
    {
        $order = $this->partlyDelivered();
        $rows = $this->rows($order);

        $this->expectException(ValidationException::class);
        try {
            $this->decide($order, MixedOutcomeDecisions::DeliveredPart, 'Already posted, nothing to do');
        } finally {
            $this->assertSame($rows, $this->rows($order));
        }
    }

    public function test_the_partners_are_told_what_was_taken_back_and_why(): void
    {
        $order = $this->partlyDelivered();
        $earned = $this->available($this->sponsor);

        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer complained about the parcel');

        $notice = DB::table('account_notices')->where('user_id', $this->sponsor->id)->latest('id')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString($order->number, (string) $notice->body);
        $this->assertStringContainsString('Dealer complained about the parcel', (string) $notice->body);
        $this->assertStringContainsString(Money::grouped(Money::decimal($earned)), (string) $notice->body);
    }

    /** A later refund of the delivered goods does not count the order against volume or rank a second time. */
    public function test_a_later_refund_scales_the_fund_row_and_leaves_rank_alone(): void
    {
        $order = $this->partlyDelivered();
        $this->decide($order, MixedOutcomeDecisions::NoCommission, 'Dealer complained about the parcel');
        $row = CommissionAllocation::query()->where('order_id', $order->id)->where('category', CommissionPosting::NoCommissionCategory)->firstOrFail();
        DealerRank::query()->where('user_id', $this->dealer->id)->update(['lifetime_purchase_total' => '5000.00']);

        app(CommissionService::class)->reverseOrder($order->fresh(), 'Goods given back later', $this->owner->id);

        $this->assertSame('0.00', (string) $row->fresh()->amount);
        $this->assertSame('reversed', $row->fresh()->status);
        $this->assertSame('5000.00', (string) DealerRank::query()->where('user_id', $this->dealer->id)->value('lifetime_purchase_total'));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    /** @param array<string, string> $posted */
    private function profitRowsOnTheOrderBefore(array $posted): int
    {
        return Money::cents($posted['profit_before']);
    }

    private function decide(Order $order, string $choice, string $reason): void
    {
        app(MixedOutcomeDecisions::class)->decide($this->reviewer, $order->fresh(), $choice, $reason);
    }

    /**
     * An approved, paid order of 5 units with a 3-unit and a 2-unit parcel still on the road.
     *
     * @return array{0: Order, 1: list<int>}
     */
    private function sentOrder(): array
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        $this->receivePayment($order);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        foreach ([3, 2] as $quantity) {
            app(OrderService::class)->dispatch($this->owner, $order->fresh(), [
                'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
                'idempotency_key' => (string) Str::uuid(),
                'courier' => 'Test courier',
                'tracking' => 'TRK-'.Str::random(6),
                'branch_id' => $this->hub->id,
            ]);
        }

        return [$order->fresh(), DB::table('shipments')->where('order_id', $order->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all()];
    }

    private function partlyDelivered(): Order
    {
        [$order, $parcels] = $this->sentOrder();
        $this->confirmDelivered($order, $parcels[0]);
        $this->receiveBack($order, $parcels[1]);

        return $order->fresh();
    }

    /** A partly delivered order from before the automatic posting: the money waits for staff. */
    private function unpostedMixedOrder(): Order
    {
        [$order, $parcels] = $this->sentOrder();
        DB::table('shipments')->where('id', $parcels[0])->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
        DB::table('shipments')->where('id', $parcels[1])->update(['status' => 'returned', 'updated_at' => now()]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'partly_delivered']);

        return $order->fresh();
    }

    private function fullyDelivered(int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total, 'status' => 'delivered']);
        $this->receivePayment($order);
        app(CommissionPosting::class)->process($order->fresh(), $this->owner->id);

        return $order->fresh();
    }

    private function receivePayment(Order $order): void
    {
        $reference = 'TEST-'.Str::random(8);
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => $reference, 'amount' => $order->total, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => 'Bank', 'reference' => $reference, 'amount' => $order->total, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function confirmDelivered(Order $order, int $shipmentId): void
    {
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $shipmentId, 'Dealer confirmed');
    }

    private function receiveBack(Order $order, int $shipmentId): void
    {
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_status' => 'returned']);
        app(OrderService::class)->receiveReturnedParcel($this->owner, $order->fresh(), $shipmentId, 'Back at the hub');
    }

    private function spendWholeWallet(User $partner, int $amount): void
    {
        DB::transaction(function () use ($partner, $amount): void {
            CommissionWallet::lock();
            DB::table('wallets')->where('user_id', $partner->id)->decrement('available', Money::decimal($amount));
            app(CommissionWallet::class)->journal('test.withdraw.'.Str::uuid(), 'Withdrawn', [
                ['user_id' => $partner->id, 'account' => 'wallet_liability', 'debit' => $amount, 'credit' => 0],
                ['user_id' => null, 'account' => 'cash', 'debit' => 0, 'credit' => $amount],
            ]);
        });
    }

    /** @return array<string, string> */
    private function batchFigures(Order $order): array
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $profit = CommissionAllocation::query()->where('order_id', $order->id)->where('metadata->retained_by', CommissionPosting::CompanyProfit)->get()
            ->sum(fn (CommissionAllocation $row): int => Money::cents((string) $row->amount));

        return ['company_share' => (string) $batch->company_share, 'total_allocated' => (string) $batch->total_allocated, 'unallocated' => (string) $batch->unallocated, 'profit_before' => Money::decimal($profit)];
    }

    /** @return array<string, string> */
    private function sameSizedTotals(int $quantity): array
    {
        $figures = $this->batchFigures($this->fullyDeliveredInIsolation($quantity));
        DB::rollBack();

        return $figures;
    }

    /** @return list<string> */
    private function sameSizedRows(int $quantity): array
    {
        $rows = $this->rows($this->fullyDeliveredInIsolation($quantity));
        DB::rollBack();

        return $rows;
    }

    /** Posts a whole order inside a transaction the caller rolls back, so the example leaves no trace. */
    private function fullyDeliveredInIsolation(int $quantity): Order
    {
        DB::beginTransaction();

        return $this->fullyDelivered($quantity);
    }

    /** @return list<string> */
    private function rows(Order $order): array
    {
        return CommissionAllocation::query()->where('order_id', $order->id)->get()
            ->map(fn (CommissionAllocation $row): string => implode('|', [$row->category, (string) $row->user_id, (string) $row->amount, (string) $row->rate_percentage, $row->status]))
            ->sort()->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function wallets(): array
    {
        return DB::table('wallets')->orderBy('user_id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function available(User $user): int
    {
        return Money::cents((string) (DB::table('wallets')->where('user_id', $user->id)->value('available') ?? '0'));
    }

    private function fund(string $key): int
    {
        return app(CompanyFunds::class)->balances()[$key]['money_in'];
    }

    private function monthly(): string
    {
        return Money::decimal(app(CommissionQualification::class)->monthlyVolume($this->dealer->id, CommissionQualification::currentMonth()));
    }

    private function teamVolume(): string
    {
        return app(CommissionQualification::class)->milestone($this->sponsor->id, 'bike')['raw_team_volume'];
    }
}

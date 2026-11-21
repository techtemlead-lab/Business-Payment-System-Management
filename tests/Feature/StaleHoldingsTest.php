<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\ClawbackWriteOff;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\StaleHoldings;
use App\Domain\Orders\OrderService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner decision 2026-11-21: money that waits longer than the limit (30 days unless changed) is named on the
 * readiness page, in a readiness check and in one owner notice a day; refund debts of closed or suspended
 * partners are never written off by themselves, only by the Super Admin.
 */
class StaleHoldingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->seedClassicPlaceCodes();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
    }

    public function test_held_commission_and_closed_account_money_count_as_old_after_thirty_days(): void
    {
        $suspended = User::factory()->dealer()->create(['status' => 'suspended']);
        $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $suspended->id]));
        $closed = User::factory()->dealer()->create(['status' => 'suspended', 'closed_at' => now()]);
        $this->walletMoney($closed, 40000);
        $holdings = app(StaleHoldings::class);

        $this->travelTo(Carbon::parse('2026-11-30 10:00:00'));
        $this->assertSame([], $holdings->items());
        $this->assertTrue(app(FinancialReadiness::class)->checks()['stale_holdings']);

        $this->travelTo(Carbon::parse('2026-12-01 10:01:00'));
        $items = collect($holdings->items())->keyBy('kind');
        $this->assertSame('1000.00', $items['held_commission']['amount']);
        $this->assertSame($suspended->id, $items['held_commission']['user_id']);
        $this->assertSame('/accounts/'.$suspended->id, $items['held_commission']['href']);
        $this->assertSame('400.00', $items['closed_balance']['amount']);
        $this->assertSame(30, $items['closed_balance']['days']);

        $readiness = app(FinancialReadiness::class);
        $this->assertFalse($readiness->checks()['stale_holdings']);
        $this->assertCount(20, $readiness->checks());
        // Old money asks for a person; it does not make the books disagree (the test orders have no bank receipt).
        $this->assertSame(['order_receipts'], FinancialReadiness::failedNames(FinancialReadiness::bookChecks($readiness->checks())));
        $this->assertSame(['stale_holdings'], $readiness->inspect()['needs_attention']);
    }

    public function test_withdrawals_fund_spends_and_payment_claims_waiting_too_long_are_listed(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $approved = $this->withdrawal($partner, 'approved', '500.00');
        $this->withdrawal($partner, 'requested', '200.00');
        $order = $this->purchase($partner, deliver: false);
        DB::table('payment_claims')->insert(['order_id' => $order->id, 'channel' => 'bKash', 'reference' => 'TRX-'.Str::upper(Str::random(8)), 'amount' => '10000.00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reserve_disbursements')->insert(['program' => 'marketing', 'amount' => '750.00', 'status' => 'requested', 'request_key' => (string) Str::uuid(), 'created_by' => $this->owner->id, 'reason' => 'Banner', 'created_at' => now(), 'updated_at' => now()]);

        $this->travelTo(Carbon::parse('2026-12-05 10:00:00'));
        $summary = collect(app(StaleHoldings::class)->summary())->keyBy('kind');

        $this->assertSame(['kind' => 'withdrawal_approved_unpaid', 'count' => 1, 'amount' => '500.00'], $summary['withdrawal_approved_unpaid']);
        $this->assertSame('200.00', $summary['withdrawal_waiting']['amount']);
        $this->assertSame('10000.00', $summary['payment_claim_waiting']['amount']);
        $this->assertSame('750.00', $summary['fund_spend_waiting']['amount']);
        $this->assertContains('/withdrawals/'.$approved, array_column(app(StaleHoldings::class)->items(), 'href'));
        $this->assertContains('/orders/'.$order->id, array_column(app(StaleHoldings::class)->items(), 'href'));
    }

    public function test_the_owner_sets_the_days_and_nobody_else_can(): void
    {
        $closed = User::factory()->dealer()->create(['status' => 'suspended', 'closed_at' => now()]);
        $this->walletMoney($closed, 10000);
        $this->travelTo(Carbon::parse('2026-11-12 10:00:00'));
        $this->assertSame([], app(StaleHoldings::class)->items());

        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/stale-holdings/days', ['days' => 10, 'version' => 0])->assertForbidden();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stale-holdings/days', ['days' => 400, 'version' => 0])->assertSessionHasErrors('days');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/stale-holdings/days', ['days' => 10, 'version' => 0])->assertSessionHasNoErrors();

        $this->assertSame(10, app(StaleHoldings::class)->days());
        $this->assertSame(['closed_balance'], array_column(app(StaleHoldings::class)->items(), 'kind'));
        $this->assertDatabaseHas('audit_events', ['action' => 'finance.stale_holding_days_changed']);
    }

    public function test_the_owners_get_one_notice_a_day_with_amounts_and_where_to_act(): void
    {
        $this->artisan('finance:stale-holdings-notice')->expectsOutputToContain('No notice sent')->assertSuccessful();
        $this->assertSame(0, DB::table('account_notices')->count());

        $closed = User::factory()->dealer()->create(['status' => 'suspended', 'closed_at' => now()]);
        $this->walletMoney($closed, 123450);
        $staff = User::factory()->staff('finance')->create();
        $this->travelTo(Carbon::parse('2026-12-10 03:00:00'));

        $this->artisan('finance:stale-holdings-notice')->assertSuccessful();
        $this->artisan('finance:stale-holdings-notice')->expectsOutputToContain('already sent')->assertSuccessful();

        $notice = DB::table('account_notices')->where('user_id', $this->owner->id)->sole();
        $this->assertStringContainsString('1,234.50', $notice->body);
        $this->assertStringContainsString(__('money_core.stale_where_closed_balance', [], $this->owner->preferredLocale()), $notice->body);
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $staff->id)->count());

        // Next day: a new notice.
        $this->travelTo(Carbon::parse('2026-12-11 03:00:00'));
        $this->artisan('finance:stale-holdings-notice')->assertSuccessful();
        $this->assertSame(2, DB::table('account_notices')->where('user_id', $this->owner->id)->count());
    }

    public function test_a_suspended_partners_refund_debt_is_listed_and_only_the_super_admin_writes_it_off_once(): void
    {
        [$upline, $order] = $this->partnerWhoOwesFromARefund();
        $writeOffs = app(ClawbackWriteOff::class);
        $this->assertSame(100000, $writeOffs->owed($upline->id));
        $this->travelTo(Carbon::parse('2026-12-05 10:00:00'));
        $line = collect(app(StaleHoldings::class)->items())->firstWhere('kind', 'clawback_unrecovered');
        $this->assertSame('1000.00', $line['amount']);
        $this->assertSame($upline->id, $line['user_id']);

        $md = User::factory()->staff('md')->create();
        $this->actingAs($md)->onPortal('sadmin')->post('/commands/accounts/'.$upline->id.'/clawback/write-off', $this->writeOffPayload('1000.00'))->assertForbidden();
        $this->writeOff($upline, '900.00')->assertSessionHasErrors(['clawback' => __('money_core.writeoff_err_changed', ['amount' => '1,000.00'])]);

        $key = (string) Str::uuid();
        $this->writeOff($upline, '1000.00', $key)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->writeOff($upline, '1000.00', $key)->assertSessionHasNoErrors();
        $this->writeOff($upline, '1000.00')->assertSessionHasErrors(['clawback' => __('money_core.writeoff_err_nothing')]);

        $this->assertSame(0, $writeOffs->owed($upline->id));
        $this->assertSame('0.00', DB::table('commission_reversals')->where('user_id', $upline->id)->value('unrecovered'));
        $this->assertFalse(CommissionWallet::hasDebt($upline->id));
        $expense = DB::table('journal_lines')->where('account', ClawbackWriteOff::ExpenseAccount)->get();
        $this->assertCount(1, $expense);
        $this->assertSame('1000.00', $expense[0]->debit);
        $this->assertSame(1, DB::table('audit_events')->where('action', ClawbackWriteOff::AuditAction)->count());
        $this->assertNull(collect(app(StaleHoldings::class)->items())->firstWhere('kind', 'clawback_unrecovered'));
        // Only the bank receipt check fails, because the test order has no bank receipt.
        $this->assertSame(['order_receipts'], FinancialReadiness::failedNames(FinancialReadiness::bookChecks(app(FinancialReadiness::class)->checks())));

        // Money that reaches the partner later is not taken for the written-off debt.
        $upline->forceFill(['status' => 'active'])->save();
        DB::transaction(function () use ($upline): void {
            app(CommissionWallet::class)->journal('test.later.'.$upline->id, 'Later', [
                ['account' => 'commission_expense', 'user_id' => null, 'debit' => 5000, 'credit' => 0],
                ['account' => 'wallet_liability', 'user_id' => $upline->id, 'debit' => 0, 'credit' => 5000],
            ]);
            app(CommissionWallet::class)->credit($upline->id, 5000);
        });
        $this->assertSame('50.00', DB::table('wallets')->where('user_id', $upline->id)->value('available'));
    }

    public function test_an_active_partners_refund_debt_cannot_be_written_off(): void
    {
        [$upline] = $this->partnerWhoOwesFromARefund();
        $upline->forceFill(['status' => 'active'])->save();

        $this->writeOff($upline, '1000.00')->assertSessionHasErrors(['clawback' => __('money_core.writeoff_err_active')]);
        $this->assertSame(100000, app(ClawbackWriteOff::class)->owed($upline->id));
    }

    public function test_the_readiness_page_shows_the_old_money_list(): void
    {
        $closed = User::factory()->dealer()->create(['status' => 'suspended', 'closed_at' => now()]);
        $this->walletMoney($closed, 10000);
        $this->travelTo(Carbon::parse('2026-12-05 10:00:00'));
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        $props = $this->actingAs($this->owner)->onPortal('sadmin')->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/operations/financial-readiness?view=holdings')->assertOk()->json('props');

        $this->assertSame(30, $props['staleHoldings']['days']);
        $this->assertTrue($props['staleHoldings']['can_edit']);
        $this->assertTrue($props['staleHoldings']['can_write_off']);
        $this->assertSame('closed_balance', $props['staleHoldings']['items'][0]['kind']);
        $this->assertFalse($props['readiness']['checks']['stale_holdings']);
    }

    /** @return array{0: User, 1: Order} */
    private function partnerWhoOwesFromARefund(): array
    {
        $upline = User::factory()->dealer()->create(['status' => 'active']);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]));
        $this->assertSame('1000.00', DB::table('wallets')->where('user_id', $upline->id)->value('available'));
        // The partner was paid the money out, then their account was suspended, then the order was refunded.
        DB::transaction(function () use ($upline): void {
            app(CommissionWallet::class)->journal('test.payout.'.$upline->id, 'Paid out', [
                ['account' => 'wallet_liability', 'user_id' => $upline->id, 'debit' => 100000, 'credit' => 0],
                ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => 100000],
            ]);
            DB::table('wallets')->where('user_id', $upline->id)->update(['available' => '0.00']);
        });
        $upline->forceFill(['status' => 'suspended'])->save();
        app(CommissionService::class)->reverseOrder($order->fresh());

        return [$upline->fresh(), $order];
    }

    private function purchase(User $buyer, bool $deliver = true): Order
    {
        $product = Product::factory()->create(['mrp' => '20000.00', 'dealer_price' => '10000.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        if ($deliver) {
            $order->update(['paid' => '10000.00', 'status' => 'delivered']);
            app(CommissionService::class)->processOrder($order->fresh());
        }

        return $order;
    }

    private function walletMoney(User $partner, int $cents): void
    {
        DB::transaction(function () use ($partner, $cents): void {
            app(CommissionWallet::class)->journal('test.seed.'.$partner->id, 'Seed', [
                ['account' => 'commission_expense', 'user_id' => null, 'debit' => $cents, 'credit' => 0],
                ['account' => 'wallet_liability', 'user_id' => $partner->id, 'debit' => 0, 'credit' => $cents],
            ]);
            app(CommissionWallet::class)->credit($partner->id, $cents);
        });
    }

    private function withdrawal(User $partner, string $status, string $amount): string
    {
        $id = (string) Str::uuid();
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => $amount, 'status' => $status, 'channel' => 'bKash', 'destination' => '01700000000',
            'idempotency_key' => (string) Str::uuid(), 'approved_at' => $status === 'approved' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function writeOffPayload(string $amount, ?string $key = null): array
    {
        return ['reason' => 'Partner left and will not pay', 'confirm' => '1', 'expected_amount' => $amount, 'request_key' => $key ?? (string) Str::uuid()];
    }

    private function writeOff(User $partner, string $amount, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts/'.$partner->id.'/clawback/write-off', $this->writeOffPayload($amount, $key));
    }
}

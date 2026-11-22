<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\PrizeAdvances;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\StaleHoldings;
use App\Domain\Finance\VehiclePools;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\PrizeAdvance;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Owner decision 2026-11-22: a bike or car prize may be paid in advance out of its fund. It is a receivable from the
 * partner, never a wallet credit, and the real prize is later set against it instead of being paid twice.
 */
class PrizeAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    private User $leader;

    private float $leaderWalletAtStart = 0.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->finance = User::factory()->staff('finance')->create();
        $this->enableFinancialPosting();
        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $rule?->update(['is_active' => true, 'parameters' => [...($rule->parameters ?? []), 'bike_target' => '100.00', 'bike_leg_cap' => '100.00',
            'car_target' => '100.00', 'car_leg_cap' => '100.00', 'bike_award_amount' => '4.00', 'car_award_amount' => '4.00']]);

        $this->leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($this->leader->id, null);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $this->leader->id]);
        ReferralTreePath::attachUser($buyer->id, $this->leader->id);
        // 1% of ৳1,000 for each pool: ৳10.00 in the Bike fund and ৳10.00 in the Car fund.
        $order = Order::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'advance'), 'number' => 'ORD-ADVANCE',
            'user_id' => $buyer->id, 'status' => 'delivered', 'total' => '1000.00', 'paid' => '1000.00']);
        $product = Product::factory()->create(['mrp' => '2000.00', 'dealer_price' => '1000.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'mrp' => $product->mrp, 'unit_price' => '1000.00', 'total' => '1000.00']);
        app(CommissionService::class)->processOrder($order);
        $this->leaderWalletAtStart = (float) (DB::table('wallets')->where('user_id', $this->leader->id)->value('available') ?? 0);
    }

    private function give(string $amount, string $kind = 'bike', ?User $partner = null, ?string $key = null, ?User $actor = null): PrizeAdvance
    {
        return app(PrizeAdvances::class)->give($actor ?? $this->owner, ['partner_id' => ($partner ?? $this->leader)->id, 'kind' => $kind, 'amount' => $amount,
            'reason' => 'Accident, needs a bike now', 'given_on' => now('Asia/Dhaka')->toDateString(), 'request_key' => $key ?? (string) Str::uuid()]);
    }

    private function award(string $kind = 'bike'): int
    {
        $id = app(ReserveService::class)->request($this->finance, ['program' => $kind, 'user_id' => $this->leader->id, 'amount' => '4.00',
            'request_key' => (string) Str::uuid(), 'reason' => 'Target reached this month', 'payee_name' => null]);
        app(ReserveService::class)->review($this->owner, $id, 'approved', 'Approve the reward now');

        return $id;
    }

    /** @return array{bike: int, car: int} */
    private function pools(): array
    {
        return app(VehiclePools::class)->balances();
    }

    /** What reached the partner's wallet since the test began (the leader already earned his referral commission). */
    private function wallet(?User $partner = null): string
    {
        $partner ??= $this->leader;
        $now = (float) (DB::table('wallets')->where('user_id', $partner->id)->value('available') ?? 0);

        return number_format($now - ($partner->is($this->leader) ? $this->leaderWalletAtStart : 0.0), 2, '.', '');
    }

    private function receivable(?User $partner = null): string
    {
        return number_format((float) DB::table('journal_lines')->where('account', PrizeAdvances::ReceivableAccount)->where('user_id', ($partner ?? $this->leader)->id)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS owed')->value('owed'), 2, '.', '');
    }

    /** @return array<string, bool> */
    private function checks(): array
    {
        return app(FinancialReadiness::class)->inspect()['checks'];
    }

    private function assertBooksAgree(): void
    {
        $checks = $this->checks();
        foreach (['balanced_journals', 'wallet_liabilities', 'fund_balances', 'fund_spend_journals', 'settlement_wallets', 'nonnegative_balances'] as $check) {
            $this->assertTrue($checks[$check], "Readiness check {$check} failed.");
        }
    }

    public function test_an_advance_within_the_fund_takes_the_money_once_and_never_touches_the_wallet(): void
    {
        $advance = $this->give('4.00');

        $this->assertSame(['bike' => 600, 'car' => 1000], $this->pools());
        $this->assertSame(600, app(CompanyFunds::class)->balances()['bike']['balance']);
        $this->assertSame('0.00', $this->wallet());
        $this->assertSame('4.00', $this->receivable());
        $this->assertSame('4.00', (string) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.event_key', 'prize_advance.given.'.$advance->id)->where('account', PrizeAdvances::PaidFromAccount)->value('credit'));
        $this->assertDatabaseHas('prize_advances', ['id' => $advance->id, 'status' => 'open', 'kind' => 'bike', 'amount' => '4.00', 'approved_by' => $this->owner->id]);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $advance->disbursement_id, 'program' => PrizeAdvances::Program, 'fund_key' => 'bike', 'status' => 'paid', 'award_key' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'prize_advance.given', 'subject' => (string) $advance->id]);
        $this->assertBooksAgree();
    }

    public function test_an_advance_above_the_fund_balance_is_refused_and_nothing_moves(): void
    {
        try {
            $this->give('10.01');
            $this->fail('An advance above the fund must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(['bike' => 1000, 'car' => 1000], $this->pools());
        $this->assertDatabaseCount('prize_advances', 0);
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'like', 'prize_advance.%')->count());
    }

    public function test_a_double_click_gives_one_advance_and_a_changed_repeat_is_refused(): void
    {
        $key = (string) Str::uuid();
        $first = $this->give('4.00', key: $key);
        $again = $this->give('4.00', key: $key);

        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('prize_advances', 1);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'prize_advance.given.%')->count());
        $this->assertSame(['bike' => 600, 'car' => 1000], $this->pools());

        $this->expectException(HttpException::class);
        $this->give('3.00', key: $key);
    }

    public function test_only_the_super_admin_gives_or_closes_an_advance(): void
    {
        try {
            $this->give('4.00', actor: $this->finance);
            $this->fail('Finance must not give an advance.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $staff = User::factory()->staff('employee')->create();
        $payload = ['partner_id' => $this->leader->id, 'kind' => 'bike', 'amount' => '4.00', 'reason' => 'Accident, needs a bike now',
            'given_on' => now('Asia/Dhaka')->toDateString(), 'confirm' => true, 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/prize-advances', $payload)->assertForbidden();
        $this->actingAs($staff)->onPortal('manage')->post('/commands/prize-advances', $payload)->assertForbidden();
        $this->assertDatabaseCount('prize_advances', 0);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/prize-advances', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $advance = PrizeAdvance::query()->sole();
        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/prize-advances/'.$advance->id.'/write-off', ['note' => 'Gave up on it', 'confirm' => true, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame('open', $advance->fresh()->status);
    }

    public function test_an_advance_equal_to_the_prize_settles_it_with_no_new_money(): void
    {
        $advance = $this->give('4.00');
        $awardId = $this->award();

        $this->assertSame('0.00', $this->wallet());
        $this->assertSame(['bike' => 600, 'car' => 1000], $this->pools());
        $this->assertSame('0.00', $this->receivable());
        $this->assertDatabaseHas('prize_advances', ['id' => $advance->id, 'status' => 'settled', 'settled_amount' => '4.00', 'settled_by_award_id' => $awardId]);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $awardId, 'status' => 'credited', 'award_key' => 'bike:'.$this->leader->id]);
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'reserve.award.'.$awardId)->count());
        $this->assertSame(0, DB::table('commission_settlement_payouts')->where('kind', 'award_'.$awardId)->count());
        $this->assertSame('4.00', (string) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.event_key', 'prize_advance.settled.'.$advance->id.'.'.$awardId)->where('account', 'commission_expense')->value('debit'));
        $this->assertTrue((bool) DB::table('reward_milestone_progress')->where('user_id', $this->leader->id)->where('milestone_type', 'bike')->value('claimed'));
        $this->assertBooksAgree();
    }

    public function test_an_advance_smaller_than_the_prize_pays_only_the_rest(): void
    {
        $advance = $this->give('1.50');
        $awardId = $this->award();

        $this->assertSame('2.50', $this->wallet());
        $this->assertSame(['bike' => 600, 'car' => 1000], $this->pools());
        $this->assertSame('250', (string) (int) round(100 * (float) DB::table('commission_settlement_payouts')->where('kind', 'award_'.$awardId)->sum('amount')));
        $this->assertDatabaseHas('prize_advances', ['id' => $advance->id, 'status' => 'settled', 'settled_amount' => '1.50']);
        $this->assertSame('0.00', $this->receivable());
        $this->assertBooksAgree();
    }

    public function test_an_advance_larger_than_the_prize_stays_open_for_the_rest(): void
    {
        $advance = $this->give('6.00');
        $this->award();

        $this->assertSame('0.00', $this->wallet());
        $this->assertSame(['bike' => 400, 'car' => 1000], $this->pools());
        $this->assertDatabaseHas('prize_advances', ['id' => $advance->id, 'status' => 'open', 'settled_amount' => '4.00']);
        $this->assertSame('2.00', $this->receivable());
        $this->assertSame('2.00', app(PrizeAdvances::class)->open()[0]['left']);
        $this->assertBooksAgree();
    }

    public function test_settling_the_same_award_again_changes_nothing(): void
    {
        $advance = $this->give('1.50');
        $awardId = $this->award();
        $award = DB::table('reserve_disbursements')->where('id', $awardId)->first();

        DB::transaction(function () use ($award): void {
            CommissionWallet::lock();
            $this->assertSame(150, app(PrizeAdvances::class)->settleAgainstAward($award, 400, $this->owner->id));
        });
        try {
            app(ReserveService::class)->review($this->owner, $awardId, 'approved', 'Approve the reward again');
            $this->fail('An award is approved once.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'prize_advance.settled.%')->count());
        $this->assertSame('1.50', $advance->fresh()->settled_amount);
        $this->assertSame('2.50', $this->wallet());
    }

    public function test_a_partner_who_leaves_keeps_the_advance_open_until_the_owner_recovers_it(): void
    {
        $advance = $this->give('4.00');
        $this->leader->forceFill(['status' => 'suspended'])->save();
        $this->travel(31)->days();

        $line = collect(app(StaleHoldings::class)->items())->firstWhere('kind', 'prize_advance_open');
        $this->assertNotNull($line);
        $this->assertSame('4.00', $line['amount']);
        $this->assertSame('open', $advance->fresh()->status);

        $key = (string) Str::uuid();
        app(PrizeAdvances::class)->close($this->owner, $advance->id, 'recovered', 'Paid back at the office', $key, 'RCPT-77');
        app(PrizeAdvances::class)->close($this->owner, $advance->id, 'recovered', 'Paid back at the office', $key, 'RCPT-77');

        $this->assertDatabaseHas('prize_advances', ['id' => $advance->id, 'status' => 'recovered', 'closed_amount' => '4.00', 'recovery_reference' => 'RCPT-77', 'closed_by' => $this->owner->id]);
        $this->assertSame('4.00', (string) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.event_key', 'prize_advance.recovered.'.$advance->id)->where('account', PrizeAdvances::RecoveredToAccount)->value('debit'));
        $this->assertSame('0.00', $this->receivable());
        $this->assertNull(collect(app(StaleHoldings::class)->items())->firstWhere('kind', 'prize_advance_open'));
        $this->assertDatabaseHas('audit_events', ['action' => 'prize_advance.recovered', 'subject' => (string) $advance->id]);

        $this->expectException(ValidationException::class);
        app(PrizeAdvances::class)->close($this->owner, $advance->id, 'written_off', 'Second try', (string) Str::uuid());
    }

    public function test_the_owner_can_write_off_an_advance_once(): void
    {
        $advance = $this->give('4.00');
        $this->leader->forceFill(['status' => 'suspended', 'closed_at' => now()])->save();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/prize-advances/'.$advance->id.'/write-off', ['note' => 'He left the business', 'confirm' => true, 'request_key' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('prize_advances', ['id' => $advance->id, 'status' => 'written_off', 'closed_amount' => '4.00']);
        $this->assertSame('4.00', (string) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.event_key', 'prize_advance.written_off.'.$advance->id)->where('account', 'bad_debt_expense')->value('debit'));
        $this->assertSame('0.00', $this->receivable());
        $this->assertSame(['bike' => 600, 'car' => 1000], $this->pools());
        $this->assertBooksAgree();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/prize-advances/'.$advance->id.'/recover', ['reference' => 'RCPT-1', 'note' => 'Came back after all', 'confirm' => true, 'request_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('advance');
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'prize_advance.%')->where('event_key', 'not like', 'prize_advance.given.%')->count());
    }

    public function test_an_advance_does_not_count_as_the_prize_already_given(): void
    {
        $this->give('4.00');

        $id = app(ReserveService::class)->request($this->finance, ['program' => 'bike', 'user_id' => $this->leader->id, 'amount' => '4.00',
            'request_key' => (string) Str::uuid(), 'reason' => 'Target reached this month', 'payee_name' => null]);

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'requested', 'award_key' => 'bike:'.$this->leader->id]);
    }

    public function test_bike_and_car_advances_are_separate(): void
    {
        $bike = $this->give('4.00', 'bike');
        $car = $this->give('2.00', 'car');

        $this->assertSame(['bike' => 600, 'car' => 800], $this->pools());
        try {
            $this->give('1.00', 'bike');
            $this->fail('One open bike advance per partner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('partner_id', $exception->errors());
        }

        // The car prize settles only the car advance; the bike advance waits for the bike prize.
        $this->award('car');
        $this->assertSame('2.00', $this->wallet());
        $this->assertSame('settled', $car->fresh()->status);
        $this->assertSame('open', $bike->fresh()->status);
        $this->assertSame('0.00', $bike->fresh()->settled_amount);
        $this->assertSame(['bike' => 600, 'car' => 600], $this->pools());
        $this->assertBooksAgree();
    }

    public function test_a_sub_dealer_can_get_an_advance_and_an_inactive_partner_cannot(): void
    {
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        $advance = $this->give('3.00', 'car', $subDealer);

        $this->assertSame('3.00', $this->receivable($subDealer));
        $this->assertSame('0.00', $this->wallet($subDealer));
        $this->assertSame('car', $advance->kind);

        $closed = User::factory()->create(['role' => 'dealer', 'status' => 'suspended']);
        $this->expectException(ValidationException::class);
        $this->give('1.00', 'bike', $closed);
    }

    public function test_owners_and_finance_see_advances_and_the_partner_does_not(): void
    {
        $this->give('4.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/bike')->assertOk()
            ->assertInertia(fn ($page) => $page->where('advances.open.0.left', '4.00')->where('advances.can_give', true));
        $this->actingAs($this->finance)->onPortal('manage')->get('/treasury/prize-advances')->assertOk()
            ->assertInertia(fn ($page) => $page->component('TreasuryPrizeAdvances')->where('open.0.kind', 'bike')->where('can_give', false));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/bike?view=out')->assertOk()
            ->assertInertia(fn ($page) => $page->where('table.rows.data.0.what', __('prize_advances.out_what', ['kind' => __('prize_advances.kind_bike')])));

        $this->actingAs($this->leader)->onPortal('dealer')->get('/treasury/prize-advances')->assertForbidden();
        $this->assertSame('0.00', $this->wallet());
    }
}

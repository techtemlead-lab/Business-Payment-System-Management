<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\MonthlyPayoutTax;
use App\Domain\Payouts\PayoutReturns;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * "Money came back" after a payout: two people, the reverse journal, the wallet credited once,
 * the tax withheld given back, and the books still agreeing.
 */
class PayoutReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
    }

    public function test_a_paid_withdrawal_that_came_back_is_reversed_by_two_people_and_credited_once(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->paidWithdrawal($partner, '1000.00', withheld: '50.00');
        $first = User::factory()->staff('finance')->create();
        $second = User::factory()->staff('finance')->create();
        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('4000.00', $this->available($partner));

        $this->actingAs($first)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/returned', ['note' => 'bKash says the number is wrong'])
            ->assertSessionHasNoErrors();

        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame('paid', $row->status, 'One person alone does not move the money.');
        $this->assertSame($first->id, $row->return_requested_by);
        $this->assertSame('4000.00', $this->available($partner));
        $this->assertSame('wait_second', app(PayoutReturns::class)->state($row, $first));
        $this->assertSame('confirm', app(PayoutReturns::class)->state($row, $second));

        $this->actingAs($first)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/returned/confirm', ['note' => 'Checked it myself'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_return_same_person')]);

        $this->actingAs($second)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/returned/confirm', ['note' => 'Bank statement shows 950 back'])
            ->assertSessionHasNoErrors();

        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame(PayoutReturns::Status, $row->status);
        $this->assertSame($second->id, $row->returned_by);
        $this->assertNotNull($row->returned_at);
        $this->assertSame('5000.00', $this->available($partner), 'The whole amount, tax included, is back in the wallet.');
        $this->assertSame('0.00', (string) DB::table('wallets')->where('user_id', $partner->id)->value('held'));

        $lines = $this->journalLines(PayoutReturns::EventPrefix.$id);
        $this->assertEqualsCanonicalizing([
            ['manual_payout_clearing', null, '950.00', '0.00'],
            ['payout_withholding_payable', null, '50.00', '0.00'],
            ['wallet_liability', $partner->id, '0.00', '1000.00'],
        ], $lines);
        $this->assertSame($second->id, DB::table('journal_entries')->where('event_key', PayoutReturns::EventPrefix.$id)->value('actor_id'));

        // The original payout and its receipt stay as they were.
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
        $this->assertSame((array) $receipt, (array) DB::table('payout_receipts')->where('withdrawal_id', $id)->first());
        $this->assertSame($row->payment_reference, DB::table('withdrawals')->where('id', $id)->value('payment_reference'));

        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'return_requested', 'actor_id' => $first->id]);
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'returned', 'actor_id' => $second->id]);
        $audit = DB::table('audit_events')->where('action', 'withdrawal.returned')->where('subject', $id)->first();
        $this->assertSame('50.00', json_decode($audit->metadata, true)['withheld_amount']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $partner->id, 'title' => __('payouts.notice_returned_title', [], $partner->preferredLocale())]);

        $checks = app(FinancialReadiness::class)->checks();
        foreach (['balanced_journals', 'wallet_liabilities', 'withdrawal_reservations', 'withdrawal_journals', 'nonnegative_balances'] as $check) {
            $this->assertTrue($checks[$check], $check.' must still pass after the return.');
        }
    }

    public function test_pressing_twice_never_credits_the_wallet_twice(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->paidWithdrawal($partner, '1000.00');
        $first = User::factory()->staff('finance')->create();
        $second = User::factory()->staff('finance')->create();
        $returns = app(PayoutReturns::class);
        $returns->request($first, $id, 'Wrong number');

        try {
            $returns->request($first, $id, 'Wrong number');
            $this->fail('A second "came back" record must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(__('payouts.err_return_already_recorded'), $e->errors()['withdrawal'][0]);
        }
        $returns->confirm($second, $id, 'Checked the statement');
        try {
            $returns->confirm($second, $id, 'Checked the statement');
            $this->fail('A second confirmation must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(__('payouts.err_already_returned'), $e->errors()['withdrawal'][0]);
        }
        try {
            $returns->request($second, $id, 'Again');
            $this->fail('A returned withdrawal cannot come back again.');
        } catch (ValidationException $e) {
            $this->assertSame(__('payouts.err_already_returned'), $e->errors()['withdrawal'][0]);
        }

        $this->assertSame('5000.00', $this->available($partner));
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', PayoutReturns::EventPrefix.$id)->count());
    }

    public function test_the_owner_records_it_alone_and_a_big_amount_needs_the_owner(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = $this->partnerWithWallet('5000.00');
        $small = $this->paidWithdrawal($partner, '1000.00');

        $this->assertTrue(app(PayoutReturns::class)->request($owner, $small, 'Bounced back today'));
        $this->assertSame(PayoutReturns::Status, DB::table('withdrawals')->where('id', $small)->value('status'));
        $this->assertSame('5000.00', $this->available($partner));
        $audit = DB::table('audit_events')->where('action', 'withdrawal.returned')->where('subject', $small)->first();
        $this->assertTrue(json_decode($audit->metadata, true)['self_approved']);

        $big = $this->paidWithdrawal($partner, '2000.00', approver: $owner);
        DB::table('business_settings')->updateOrInsert(['key' => 'withdrawal_approval_limit'], ['value' => json_encode(['amount' => '1500.00']), 'version' => 1, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $first = User::factory()->staff('finance')->create();
        $second = User::factory()->staff('finance')->create();
        app(PayoutReturns::class)->request($first, $big, 'Wrong number');
        $row = DB::table('withdrawals')->where('id', $big)->first();
        $this->assertSame('wait_owner', app(PayoutReturns::class)->state($row, $second));
        $this->assertSame('confirm', app(PayoutReturns::class)->state($row, $owner));

        try {
            app(PayoutReturns::class)->confirm($second, $big, 'Checked');
            $this->fail('A big return needs the owner.');
        } catch (ValidationException) {
            $this->assertSame('paid', DB::table('withdrawals')->where('id', $big)->value('status'));
        }
        app(PayoutReturns::class)->confirm($owner, $big, 'Checked the statement');
        $this->assertSame(PayoutReturns::Status, DB::table('withdrawals')->where('id', $big)->value('status'));
        $this->assertSame('5000.00', $this->available($partner));
    }

    public function test_a_return_recorded_by_mistake_can_be_taken_back(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->paidWithdrawal($partner, '1000.00');
        $first = User::factory()->staff('finance')->create();
        app(PayoutReturns::class)->request($first, $id, 'Thought it bounced');

        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')
            ->post('/commands/withdrawals/'.$id.'/returned/cancel', ['note' => 'Partner got the money after all'])->assertSessionHasNoErrors();

        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame('paid', $row->status);
        $this->assertNull($row->return_requested_by);
        $this->assertSame('4000.00', $this->available($partner));
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'return_cancelled']);
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.return_cancelled', 'subject' => $id]);
    }

    public function test_money_for_a_suspended_or_closed_partner_goes_to_the_wallet_but_cannot_be_withdrawn(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->paidWithdrawal($partner, '1000.00');
        DB::table('users')->where('id', $partner->id)->update(['status' => 'suspended', 'closed_at' => now()]);
        app(PayoutReturns::class)->request(User::factory()->staff('finance')->create(), $id, 'Bounced back');
        app(PayoutReturns::class)->confirm(User::factory()->staff('finance')->create(), $id, 'Checked the statement');

        $this->assertSame('5000.00', $this->available($partner));
        $audit = DB::table('audit_events')->where('action', 'withdrawal.returned')->where('subject', $id)->first();
        $this->assertSame('closed', json_decode($audit->metadata, true)['partner_status']);

        $this->expectException(HttpException::class);
        app(ManualPayoutService::class)->request($partner->fresh(), [
            'amount' => '1000.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_only_a_paid_withdrawal_can_come_back_and_only_finance_records_it(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $approved = $this->approvedWithdrawal($partner, '1000.00');
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$approved.'/returned', ['note' => 'Bounced back'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_return_not_paid')]);
        $paid = $this->paidWithdrawal($partner, '1000.00');
        $reports = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);
        $this->actingAs($reports)->onPortal('manage')->post('/commands/withdrawals/'.$paid.'/returned', ['note' => 'Bounced back'])->assertForbidden();
        $this->actingAs($partner)->onPortal('dealer')->post('/commands/withdrawals/'.$paid.'/returned', ['note' => 'Bounced back'])->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$paid.'/returned', ['note' => ''])->assertSessionHasErrors('note');
        $this->assertNull(DB::table('withdrawals')->where('id', $paid)->value('return_requested_by'));
    }

    public function test_the_daily_check_needs_the_reverse_journal_for_a_returned_withdrawal_and_none_for_others(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->paidWithdrawal($partner, '1000.00');
        $this->assertTrue(app(FinancialReadiness::class)->checks()['withdrawal_journals']);

        DB::table('withdrawals')->where('id', $id)->update(['status' => PayoutReturns::Status]);
        $this->assertFalse(app(FinancialReadiness::class)->checks()['withdrawal_journals'], 'Returned without the reverse journal.');

        DB::table('withdrawals')->where('id', $id)->update(['status' => 'paid']);
        app(PayoutReturns::class)->request(User::factory()->staff('super_admin')->create(), $id, 'Bounced back');
        $this->assertTrue(app(FinancialReadiness::class)->checks()['withdrawal_journals']);

        DB::table('withdrawals')->where('id', $id)->update(['status' => 'paid']);
        $this->assertFalse(app(FinancialReadiness::class)->checks()['withdrawal_journals'], 'A reverse journal for a withdrawal that is not returned.');
    }

    public function test_a_returned_withdrawal_no_longer_counts_for_the_monthly_tax(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->paidWithdrawal($partner, '1000.00');
        $before = app(MonthlyPayoutTax::class)->forWithdrawal($partner->id, 100000);
        app(PayoutReturns::class)->request(User::factory()->staff('super_admin')->create(), $id, 'Bounced back');

        $after = app(MonthlyPayoutTax::class)->forWithdrawal($partner->id, 100000);

        $this->assertSame('2000.00', $before['month_total']);
        $this->assertSame('1000.00', $after['month_total']);
    }

    private function available(User $partner): string
    {
        return (string) DB::table('wallets')->where('user_id', $partner->id)->value('available');
    }

    /** @return list<array{string, ?int, string, string}> */
    private function journalLines(string $eventKey): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('e.event_key', $eventKey)
            ->get(['l.account', 'l.user_id', 'l.debit', 'l.credit'])
            ->map(fn (object $line): array => [$line->account, $line->user_id === null ? null : (int) $line->user_id, (string) $line->debit, (string) $line->credit])->all();
    }

    private function paidWithdrawal(User $partner, string $amount, string $withheld = '0.00', ?User $approver = null): string
    {
        $id = $this->approvedWithdrawal($partner, $amount, $approver);
        if ($withheld !== '0.00') {
            DB::table('withdrawals')->where('id', $id)->update(['withheld_amount' => $withheld, 'net_amount' => number_format((float) $amount - (float) $withheld, 2, '.', '')]);
        }
        app(ManualPayoutService::class)->settle(User::factory()->staff('finance')->create(), $id, ['method' => 'cash', 'received_by' => 'Partner himself'], null, null, now()->subHour()->toDateTimeString(), 'Cash at the office');
        $this->assertSame('paid', DB::table('withdrawals')->where('id', $id)->value('status'));

        return $id;
    }

    private function approvedWithdrawal(User $partner, string $amount, ?User $approver = null): string
    {
        $id = app(ManualPayoutService::class)->request($partner, [
            'amount' => $amount, 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
        app(ManualPayoutService::class)->review($approver ?? User::factory()->staff('finance')->create(), $id, 'approved', 'Checked by phone');

        return $id;
    }

    /** A partner whose wallet balance is on the books, so the daily money checks agree. */
    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], [
            'available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => 'test.opening.'.$dealer->id, 'description' => 'Opening balance', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'opening_equity', 'debit' => $available, 'credit' => '0.00'],
            ['entry_id' => $entry, 'user_id' => $dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => $available],
        ]);

        return $dealer;
    }
}

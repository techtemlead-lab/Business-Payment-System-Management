<?php

namespace Tests\Feature;

use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\WithdrawalMethods;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceiptImage;
use Tests\TestCase;

/**
 * Owner, 2026-11-27: there is no payment gateway. The office sends the money by hand (bKash, Nagad, Rocket) or
 * hands over cash, then presses ONE button, "Sent — mark paid": it approves the request when it still waits and
 * records the payment, with an optional transaction ID and screenshot. Every rule of the two separate steps stays.
 */
class WithdrawalOneStepPayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        config(['madina.withdrawal_methods_default' => WithdrawalMethods::all(), 'madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    public function test_the_owner_approves_and_pays_in_one_submit_with_both_audit_events_and_the_usual_ledger(): void
    {
        [$dealer, $id] = $this->request('bKash', '01711111111', '1500.00');
        $before = DB::table('withdrawals')->where('id', $id)->sole();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', [
            'payment_reference' => 'bkx7q2m9ta', 'receipt' => ReceiptImage::make(),
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $text): bool => str_contains($text, 'MN-PR-'));

        $w = DB::table('withdrawals')->where('id', $id)->sole();
        $this->assertSame('paid', $w->status);
        $this->assertSame($this->owner->id, (int) $w->reviewed_by);
        $this->assertSame($this->owner->id, (int) $w->paid_by);
        $this->assertNotNull($w->approved_at);
        $this->assertSame('BKX7Q2M9TA', $w->payment_reference);
        $this->assertNotNull($w->receipt_path);
        $this->assertFalse((bool) $w->receipt_due);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.approved')->where('subject', $id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.paid')->where('subject', $id)->count());
        $this->assertSame(['approved', 'paid'], DB::table('withdrawal_events')->where('withdrawal_id', $id)->whereIn('event', ['approved', 'paid'])->orderBy('id')->pluck('event')->all());
        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();
        $this->assertSame('bkash', $receipt->method);
        $this->assertSame('01711111111', decrypt($receipt->account_number));
        // Exactly the journal entry of the two-step path: wallet liability out, clearing and withheld tax in.
        $entry = DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->sole();
        $lines = DB::table('journal_lines')->where('entry_id', $entry->id)->get()->keyBy('account');
        $this->assertSame($before->amount, $lines['wallet_liability']->debit);
        $this->assertSame($before->net_amount ?? $before->amount, $lines['manual_payout_clearing']->credit);
        $this->assertSame($before->withheld_amount, $lines['payout_withholding_payable']->credit);
        $this->assertSame('0.00', DB::table('wallets')->where('user_id', $dealer->id)->value('held'));
        $this->assertSame('3500.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $this->assertCount(1, array_filter((array) config('madina.sms.sent', []), fn (array $sms): bool => ($sms['event'] ?? null) === 'withdrawal.paid'));
    }

    public function test_a_double_submit_pays_once_and_returns_the_same_receipt(): void
    {
        [, $id] = $this->request('Nagad', '01811111111', '800.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'NGD12345AB'])->assertSessionHasNoErrors();
        $number = (string) DB::table('payout_receipts')->where('withdrawal_id', $id)->value('number');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'NGD12345AB'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', __('payouts.flash_one_step_already', ['number' => $number]));
        $this->actingAs($this->owner)->onPortal('sadmin')->postJson('/commands/withdrawals/'.$id.'/sent-paid', [])
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.receipt', $number);

        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
        $this->assertSame(1, DB::table('payout_receipts')->where('withdrawal_id', $id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.paid')->where('subject', $id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.approved')->where('subject', $id)->count());
        $this->assertCount(1, array_filter((array) config('madina.sms.sent', []), fn (array $sms): bool => ($sms['event'] ?? null) === 'withdrawal.paid'));
    }

    public function test_the_transaction_id_and_the_screenshot_are_optional(): void
    {
        [, $nagad] = $this->request('Nagad', '01811111111', '600.00');
        [, $rocket] = $this->request('Rocket', '017111111112', '700.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$nagad.'/sent-paid', [])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$rocket.'/sent-paid', [])->assertSessionHasNoErrors();

        foreach ([$nagad => 'nagad', $rocket => 'other'] as $id => $method) {
            $w = DB::table('withdrawals')->where('id', $id)->sole();
            $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();
            $this->assertSame('paid', $w->status);
            $this->assertSame($method, $receipt->method);
            $this->assertNull($receipt->reference);
            // Without an ID the receipt number stands in; without a screenshot it can be attached later.
            $this->assertSame($receipt->number, $w->payment_reference);
            $this->assertTrue((bool) $w->receipt_due);
            $this->assertNull($w->receipt_path);
        }
        $this->assertSame('Rocket', DB::table('payout_receipts')->where('withdrawal_id', $rocket)->value('bank_name'));
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$nagad.'/attach-receipt', ['receipt' => ReceiptImage::make()])->assertSessionHasNoErrors();
        $this->assertFalse((bool) DB::table('withdrawals')->where('id', $nagad)->value('receipt_due'));
    }

    public function test_office_cash_is_paid_in_one_step_with_the_partner_as_the_receiver(): void
    {
        [$dealer, $id] = $this->request(WithdrawalMethods::Office, WithdrawalMethods::OfficeDestination, '1200.00');
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.office_payouts.0.id', $id)->where('data.office_payouts.0.status', 'requested')->where('data.office_payouts.0.one_step_state', 'ready'));

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', ['paid_at' => now('Asia/Dhaka')->subMinutes(5)->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();

        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();
        $this->assertSame('cash', $receipt->method);
        $this->assertSame($dealer->name, $receipt->received_by);
        $this->assertFalse((bool) DB::table('withdrawals')->where('id', $id)->value('receipt_due'));
    }

    /** Owner, 2026-11-27: finance staff approve and pay a withdrawal in one press, like the owner. */
    public function test_finance_staff_approve_and_pay_in_one_press(): void
    {
        $finance = User::factory()->staff('finance')->create();
        [, $id] = $this->request('bKash', '01711111111', '900.00');

        $this->actingAs($finance)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.payouts_table.rows.data.0.id', $id)->where('data.payouts_table.rows.data.0.one_step_state', 'ready'));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'BKX7Q2M9TA'])
            ->assertSessionHasNoErrors();

        $w = DB::table('withdrawals')->where('id', $id)->sole();
        $this->assertSame('paid', $w->status);
        $this->assertSame($finance->id, (int) $w->reviewed_by);
        $this->assertSame($finance->id, (int) $w->paid_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.approved', 'subject' => $id, 'actor_id' => $finance->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.paid', 'subject' => $id, 'actor_id' => $finance->id]);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
    }

    public function test_staff_who_approved_earlier_can_pay_it_themselves(): void
    {
        $finance = User::factory()->staff('finance')->create();
        [, $id] = $this->request('Nagad', '01711111112', '700.00');
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Number checked'])->assertSessionHasNoErrors();

        $this->actingAs($finance)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.payouts_table.rows.data.0.one_step_state', 'ready')->where('data.payouts_table.rows.data.0.payer_state', null));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/sent-paid', [])->assertSessionHasNoErrors();

        $this->assertSame('paid', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_staff_never_approve_and_pay_alone_so_a_second_person_presses_the_button(): void
    {
        // With the owner's switch off (MADINA_WITHDRAWAL_STAFF_PAY_OWN_APPROVAL=false), a second finance person pays.
        config(['madina.withdrawal_staff_pay_own_approval' => false]);
        $approver = User::factory()->staff('finance')->create();
        $payer = User::factory()->staff('finance')->create();
        [, $id] = $this->request('bKash', '01711111111', '900.00');

        $this->actingAs($approver)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.payouts_table.rows.data.0.id', $id)->where('data.payouts_table.rows.data.0.one_step_state', 'two_people'));
        $this->actingAs($approver)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'BKX7Q2M9TA'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_one_step_second_person')]);
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'withdrawal.approved')->where('subject', $id)->count());
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());

        // The old first step still works; the approver then cannot pay, the second person can, in one press.
        $this->actingAs($approver)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Number checked'])->assertSessionHasNoErrors();
        $this->actingAs($approver)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.payouts_table.rows.data.0.one_step_state', null)->where('data.payouts_table.rows.data.0.payer_state', 'second_person'));
        $this->actingAs($approver)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/sent-paid', [])->assertSessionHasErrors('withdrawal');
        $this->actingAs($payer)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.payouts_table.rows.data.0.one_step_state', 'ready'));
        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'BKX7Q2M9TA', 'note' => 'Sent from the office bKash'])
            ->assertSessionHasNoErrors();

        $w = DB::table('withdrawals')->where('id', $id)->sole();
        $this->assertSame('paid', $w->status);
        $this->assertSame($approver->id, (int) $w->reviewed_by);
        $this->assertSame($payer->id, (int) $w->paid_by);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'manual-payout:'.$id)->count());
    }

    public function test_the_owner_limit_still_holds_for_staff(): void
    {
        $finance = User::factory()->staff('finance')->create();
        [$dealer, $id] = $this->request(WithdrawalMethods::Office, WithdrawalMethods::OfficeDestination, '150000.00', '200000.00');

        $this->actingAs($finance)->onPortal('manage')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.payouts_table.rows.data.0.waits_for_owner', true)->where('data.payouts_table.rows.data.0.one_step_state', null));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/sent-paid', [])->assertSessionHasErrors('withdrawal');
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', [])->assertSessionHasNoErrors();
        $this->assertSame('paid', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertSame('50000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.approved', 'subject' => $id, 'actor_id' => $this->owner->id]);
    }

    public function test_a_hold_stops_the_one_step_and_moves_no_money(): void
    {
        [$dealer, $id] = $this->request('bKash', '01711111111', '1000.00');
        DB::table('withdrawals')->where('id', $id)->update(['on_hold_at' => now()]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'BKX7Q2M9TA'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_on_hold')]);

        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertSame('1000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('held'));
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['withdrawal.approved', 'withdrawal.paid'])->where('subject', $id)->count());
        $this->assertSame(0, DB::table('payout_receipts')->where('withdrawal_id', $id)->count());
    }

    public function test_a_bank_transfer_keeps_the_full_payment_form(): void
    {
        [, $id] = $this->request('Bank', '0123456789012', '1000.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/withdrawals/'.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('withdrawal.one_step_state', null));
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', ['payment_reference' => 'TRX-0001-AB'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_one_step_bank')]);
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_the_detail_page_offers_the_one_step_to_the_owner_and_the_partner_never_can_use_it(): void
    {
        [$dealer, $id] = $this->request('bKash', '01711111111', '500.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/withdrawals/'.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('withdrawal.one_step_state', 'ready')->where('withdrawal.destination_for_payment', '01711111111'));
        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals/'.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('withdrawal.one_step_state', null)->where('withdrawal.channel', 'bKash'));
        $this->assertContains($this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals/'.$id.'/sent-paid', [])->getStatusCode(), [403, 404]);
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_the_service_refuses_a_finished_or_rejected_request(): void
    {
        [, $id] = $this->request('bKash', '01711111111', '500.00');
        app(ManualPayoutService::class)->review($this->owner, $id, 'rejected', 'Wrong number');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/sent-paid', [])->assertSessionHasErrors('withdrawal');
        $this->assertSame('rejected', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('payout_receipts')->where('withdrawal_id', $id)->count());
    }

    /** @return array{0: User, 1: string} */
    private function request(string $channel, string $destination, string $amount, string $wallet = '5000.00'): array
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '0172'.random_int(1000000, 9999999)]);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => $wallet, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $id = app(ManualPayoutService::class)->request($dealer, ['amount' => $amount, 'channel' => $channel, 'destination' => $destination, 'idempotency_key' => (string) Str::uuid()]);

        return [$dealer, $id];
    }
}

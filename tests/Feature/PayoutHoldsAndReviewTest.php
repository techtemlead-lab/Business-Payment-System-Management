<?php

namespace Tests\Feature;

use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceiptImage;
use Tests\TestCase;

/**
 * Withdrawals after the 2026-11-18 company test: a suspended partner is not paid (MA-02, owner
 * decision D4), finance sees the facts before approving (MA-07), every approved payout has a payer
 * and an age (MA-06), and the transfer proof and TrxID are checked (MA-25).
 */
class PayoutHoldsAndReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
    }

    public function test_suspending_a_partner_puts_open_withdrawals_on_hold_and_reactivating_releases_them(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = $this->partnerWithWallet('5000.00');
        $requested = $this->withdraw($partner, '1000.00');
        $approved = $this->withdraw($partner, '1000.00');
        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $approved, 'approved', 'Checked by phone');

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$partner->id, ['status' => 'suspended', 'reason' => 'Fake orders under review'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('withdrawals')->where('id', $requested)->value('on_hold_at'));
        $this->assertNotNull(DB::table('withdrawals')->where('id', $approved)->value('on_hold_at'));
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $requested, 'event' => 'on_hold']);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$partner->id, ['status' => 'active', 'reason' => 'Review finished'])
            ->assertSessionHasNoErrors();

        $this->assertNull(DB::table('withdrawals')->where('id', $requested)->value('on_hold_at'));
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $approved, 'event' => 'hold_released']);
    }

    public function test_a_suspended_partners_withdrawal_cannot_be_approved_or_paid_but_can_be_rejected(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $reviewer = User::factory()->staff('finance')->create();
        $payer = User::factory()->staff('finance')->create();
        $toApprove = $this->withdraw($partner, '1000.00');
        $toPay = $this->withdraw($partner, '1000.00');
        app(ManualPayoutService::class)->review($reviewer, $toPay, 'approved', 'Checked by phone');
        // Suspended without the hold flag (for example straight in the database): the status check under the lock still stops it.
        DB::table('users')->where('id', $partner->id)->update(['status' => 'suspended']);

        $this->actingAs($reviewer)->onPortal('manage')->post('/commands/withdrawals/'.$toApprove.'/review', ['decision' => 'approved', 'reason' => 'Looks fine'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_partner_not_active')]);
        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$toPay.'/record-payment', [
            'method' => 'cash', 'received_by' => 'Partner himself', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Cash',
        ])->assertSessionHasErrors(['withdrawal' => __('payouts.err_partner_not_active')]);
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $toApprove)->value('status'));
        $this->assertSame('approved', DB::table('withdrawals')->where('id', $toPay)->value('status'));

        $this->actingAs($reviewer)->onPortal('manage')->post('/commands/withdrawals/'.$toApprove.'/review', ['decision' => 'rejected', 'reason' => 'Account suspended'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', DB::table('withdrawals')->where('id', $toApprove)->value('status'));
    }

    public function test_approval_waits_for_the_security_wait_after_a_password_change(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->withdraw($partner, '1000.00');
        $partner->forceFill(['password' => 'a-new-password-123'])->save();

        $this->expectException(ValidationException::class);
        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Looks fine');
    }

    public function test_a_partner_cancels_a_pending_request_and_gets_the_money_back(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->withdraw($partner, '1500.00');
        $this->assertSame('3500.00', (string) DB::table('wallets')->where('user_id', $partner->id)->value('available'));

        $this->actingAs($partner)->onPortal('dealer')->post('/commands/withdrawals/'.$id.'/cancel')->assertSessionHasNoErrors();

        $this->assertSame('cancelled', DB::table('withdrawals')->where('id', $id)->value('status'));
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first();
        $this->assertSame('5000.00', (string) $wallet->available);
        $this->assertSame('0.00', (string) $wallet->held);
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'cancelled', 'actor_id' => $partner->id]);
    }

    public function test_a_partner_cannot_cancel_an_approved_request_or_someone_elses(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $other = $this->partnerWithWallet('5000.00');
        $approved = $this->withdraw($partner, '1000.00');
        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $approved, 'approved', 'Checked');
        $othersRequest = $this->withdraw($other, '1000.00');

        $this->actingAs($partner)->onPortal('dealer')->post('/commands/withdrawals/'.$approved.'/cancel')
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_cancel_not_pending')]);
        $this->actingAs($partner)->onPortal('dealer')->post('/commands/withdrawals/'.$othersRequest.'/cancel')->assertNotFound();
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $othersRequest)->value('status'));
    }

    public function test_the_review_facts_show_what_finance_must_check_and_log_the_full_number(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $first = $this->withdraw($partner, '1000.00');
        $this->withdraw($partner, '500.00');
        $finance = User::factory()->staff('finance')->create();
        DB::table('users')->where('id', $partner->id)->update(['status' => 'suspended']);

        $this->actingAs($finance)->onPortal('manage')->getJson('/commands/withdrawals/'.$first.'/facts')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('data.partner.status', 'suspended')
                ->where('data.partner.nid_on_file', true)
                ->where('data.balance.available', '3500.00')
                ->where('data.other_open.count', 1)
                ->where('data.other_open.amount', '500.00')
                ->where('data.destination', '01700000000')
                ->where('data.channel_limit', '30000.00')
                ->where('data.over_channel_limit', false)
                ->where('data.blocker', __('payouts.err_partner_not_active'))
                ->etc());
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.destination_viewed', 'subject' => $first, 'actor_id' => $finance->id]);

        $this->actingAs($partner)->onPortal('dealer')->getJson('/commands/withdrawals/'.$first.'/facts')->assertForbidden();
    }

    public function test_an_approved_payout_names_its_payer_and_shows_as_overdue_after_the_due_days(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->withdraw($partner, '1000.00');
        $approver = User::factory()->staff('finance')->create();
        $payer = User::factory()->staff('finance')->create(['name' => 'Payer Karim']);

        // The approver cannot name themselves: a second person pays.
        $this->actingAs($approver)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked', 'payer_id' => $approver->id])
            ->assertSessionHasErrors('payer_id');
        $this->actingAs($approver)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked', 'payer_id' => $payer->id])
            ->assertSessionHasNoErrors();
        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame($payer->id, (int) $row->payer_id);
        $this->assertNotNull($row->approved_at);

        $this->travel(4)->days();
        $owner = User::factory()->staff('super_admin')->create();
        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?follow_up=overdue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.payout_totals.overdue_count', 1)
                ->where('data.payouts_table.rows.data.0.id', $id)
                ->where('data.payouts_table.rows.data.0.overdue', true)
                ->where('data.payouts_table.rows.data.0.payer_name', 'Payer Karim')
                ->where('data.payouts_table.rows.data.0.waiting_days', 4));
        $this->actingAs($owner)->onPortal('sadmin')->get('/overview')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.work_queue', fn ($queue) => (int) collect($queue->all())->firstWhere('key', 'payouts_overdue')['count'] === 1));

        // Another finance person takes it over.
        $other = User::factory()->staff('finance')->create();
        $this->actingAs($other)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/payer', ['payer_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, (int) DB::table('withdrawals')->where('id', $id)->value('payer_id'));
    }

    public function test_paid_with_receipt_later_then_the_receipt_is_attached(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->withdraw($partner, '1000.00');
        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Checked');
        $payer = User::factory()->staff('finance')->create();

        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01700000000', 'payment_reference' => 'BKX7Q2M9TA', 'receipt_later' => '1',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent from the phone',
        ])->assertSessionHasNoErrors();
        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame('paid', $row->status);
        $this->assertTrue((bool) $row->receipt_due);
        $this->assertNull($row->receipt_path);

        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/attach-receipt', ['receipt' => UploadedFile::fake()->image('blank.png', 400, 600)])
            ->assertSessionHasErrors(['receipt' => __('payouts.err_receipt_blank')]);
        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/attach-receipt', ['receipt' => ReceiptImage::make()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertFalse((bool) $row->receipt_due);
        $this->assertNotNull($row->receipt_path);
    }

    public function test_a_blank_or_tiny_receipt_and_a_badly_written_or_used_trxid_are_refused(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->withdraw($partner, '1000.00');
        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Checked');
        $payer = User::factory()->staff('finance')->create();
        $pay = fn (array $changes) => $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01700000000', 'payment_reference' => 'BKX7Q2M9TA',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent', 'receipt' => ReceiptImage::make(), ...$changes,
        ]);

        $pay(['receipt' => UploadedFile::fake()->image('white.png', 120, 60)])->assertSessionHasErrors(['receipt' => __('payouts.err_receipt_too_small')]);
        $pay(['receipt' => UploadedFile::fake()->image('white.png', 800, 1200)])->assertSessionHasErrors(['receipt' => __('payouts.err_receipt_blank')]);
        $pay(['payment_reference' => 'TRX-BKASH-SIM-0001'])->assertSessionHasErrors(['payment_reference' => __('payouts.err_reference_mobile')]);
        // A TrxID a partner already used for an order payment is not a payout's.
        DB::table('payment_claims')->insert(['order_id' => $this->orderFor($partner), 'channel' => 'bKash', 'reference' => 'BKX7Q2M9TA', 'amount' => '10.00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $pay([])->assertSessionHasErrors(['payment_reference' => __('portal.err_wd_payment_ref_used')]);
        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_money_goes_only_to_the_number_and_method_the_partner_asked_for(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->withdraw($partner, '1000.00');
        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Checked');
        $payer = User::factory()->staff('finance')->create();
        $pay = fn (array $changes) => $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01700000000', 'payment_reference' => 'BKX7Q2M9TA',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent', 'receipt' => ReceiptImage::make(), ...$changes,
        ]);

        $pay(['wallet_number' => '01711999000'])->assertSessionHasErrors(['wallet_number' => __('payouts.err_destination_differs', ['number' => '•••••••0000'])]);
        $pay(['method' => 'nagad'])->assertSessionHasErrors(['method' => __('payouts.err_method_differs', ['channel' => __('portal.pay_channel_bkash')])]);
        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));

        // The same number written with the country code is the partner's number.
        $pay(['wallet_number' => '+880 1700-000000'])->assertSessionHasNoErrors();
        $this->assertSame('paid', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    private function orderFor(User $partner): string
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert(['id' => $id, 'user_id' => $partner->id, 'number' => 'MN-T-'.Str::upper(Str::random(6)), 'status' => 'submitted',
            'total' => '10.00', 'paid' => '0.00', 'payload_hash' => hash('sha256', $id), 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], [
            'available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $dealer;
    }

    private function withdraw(User $dealer, string $amount): string
    {
        return app(ManualPayoutService::class)->request($dealer, [
            'amount' => $amount, 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}

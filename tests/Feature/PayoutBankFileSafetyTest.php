<?php

namespace Tests\Feature;

use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\PayoutBatchFiles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A withdrawal in a file sent to the bank cannot be paid twice: it is not listed again, it cannot
 * be rejected (money back to the wallet) while the bank may be paying it, and it leaves the file
 * only by being recorded as paid or by being marked as not sent by the bank.
 */
class PayoutBankFileSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
    }

    public function test_a_withdrawal_in_a_bank_file_cannot_be_rejected(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $finance = User::factory()->staff('finance')->create();
        $id = $this->approvedWithdrawal($partner, '1000.00');
        $this->exportFile($finance);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Changed my mind'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_reject_in_bank_file')]);

        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame('approved', $row->status);
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first();
        $this->assertSame('4000.00', (string) $wallet->available, 'Nothing went back to the wallet.');
        $this->assertSame('1000.00', (string) $wallet->held);
    }

    public function test_marking_not_sent_returns_it_to_the_approved_list_then_it_can_be_rejected_or_sent_again(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $finance = User::factory()->staff('finance')->create();
        $id = $this->approvedWithdrawal($partner, '1000.00');
        $batch = $this->exportFile($finance);
        $second = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/not-sent', ['note' => 'Bank says the account number was closed'])
            ->assertSessionHasNoErrors();
        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame($batch, $row->payout_batch_id, 'One person alone does not take it out of the bank file.');
        $this->assertSame($finance->id, $row->not_sent_requested_by);
        $this->assertSame('wait_second', app(PayoutBatchFiles::class)->notSentState($row, $finance));
        $this->assertSame('confirm', app(PayoutBatchFiles::class)->notSentState($row, $second));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/not-sent', ['note' => 'Confirming it myself'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_not_sent_same_person')]);

        $this->actingAs($second)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/not-sent', ['note' => 'Checked with the bank: not sent'])
            ->assertSessionHasNoErrors();

        $row = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertNull($row->not_sent_requested_by);
        $this->assertSame('approved', $row->status);
        $this->assertNull($row->exported_at);
        $this->assertNull($row->payout_batch_id);
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'not_sent']);
        $audit = DB::table('audit_events')->where('action', 'withdrawal.not_sent')->where('subject', $id)->first();
        $this->assertSame($batch, json_decode($audit->metadata, true)['batch_id']);
        $this->assertDatabaseHas('payout_batches', ['id' => $batch]);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/not-sent', ['note' => 'Pressed twice by mistake'])
            ->assertSessionHasErrors(['withdrawal' => __('payouts.err_not_in_bank_file')]);

        $next = app(PayoutBatchFiles::class)->export($finance, (string) Str::uuid(), now()->timezone('Asia/Dhaka')->format('Y-m'), null);
        $this->assertSame([$id], $next['ids'], 'Marked as not sent, it goes in the next file.');
        $owner = User::factory()->staff('super_admin')->create();
        $this->assertTrue(app(PayoutBatchFiles::class)->markNotSent($owner, $id, 'Bank refused again'), 'The owner finishes alone.');

        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Partner asked to stop'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertSame('5000.00', (string) DB::table('wallets')->where('user_id', $partner->id)->value('available'));
    }

    public function test_only_finance_marks_not_sent_and_a_note_is_needed(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $finance = User::factory()->staff('finance')->create();
        $id = $this->approvedWithdrawal($partner, '1000.00');
        $this->exportFile($finance);
        $reports = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);

        $this->actingAs($reports)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/not-sent', ['note' => 'Bank refused it'])->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/not-sent', ['note' => ''])->assertSessionHasErrors('note');
        $this->assertNotNull(DB::table('withdrawals')->where('id', $id)->value('exported_at'));
    }

    public function test_a_withdrawal_in_a_bank_file_is_recorded_as_paid_even_if_the_partner_was_suspended_since(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $approver = User::factory()->staff('finance')->create();
        $payer = User::factory()->staff('finance')->create();
        $id = $this->approvedWithdrawal($partner, '1000.00', $approver);
        $batch = $this->exportFile($approver);
        DB::table('users')->where('id', $partner->id)->update(['status' => 'suspended']);
        DB::table('withdrawals')->where('id', $id)->update(['on_hold_at' => now()]);

        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01700000000', 'payment_reference' => 'BK7Q2M9X4P', 'receipt_later' => '1',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent with the bank file',
        ])->assertSessionHasNoErrors();

        $this->assertSame('paid', DB::table('withdrawals')->where('id', $id)->value('status'));
        $audit = DB::table('audit_events')->where('action', 'withdrawal.paid')->where('subject', $id)->first();
        $this->assertSame($batch, json_decode($audit->metadata, true)['payout_batch_id']);
        $this->assertSame('0.00', (string) DB::table('wallets')->where('user_id', $partner->id)->value('held'));

        $file = app(PayoutBatchFiles::class)->downloadAgain($payer, $batch);
        $this->assertSame([], $file['ids'], 'A paid row is never in a file again.');
    }

    public function test_a_withdrawal_not_in_a_file_is_still_stopped_by_a_suspension(): void
    {
        $partner = $this->partnerWithWallet('5000.00');
        $id = $this->approvedWithdrawal($partner, '1000.00');
        DB::table('users')->where('id', $partner->id)->update(['status' => 'suspended']);

        $this->expectException(ValidationException::class);
        app(ManualPayoutService::class)->settle(User::factory()->staff('finance')->create(), $id, ['method' => 'cash', 'received_by' => 'Partner'], null, null, now()->subHour()->toDateTimeString(), 'Cash');
    }

    private function exportFile(User $finance): string
    {
        $batchId = (string) Str::uuid();
        $file = app(PayoutBatchFiles::class)->export($finance, $batchId, now()->timezone('Asia/Dhaka')->format('Y-m'), null);
        $this->assertNotSame([], $file['ids']);

        return $batchId;
    }

    private function approvedWithdrawal(User $partner, string $amount, ?User $approver = null): string
    {
        $id = app(ManualPayoutService::class)->request($partner, [
            'amount' => $amount, 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
        app(ManualPayoutService::class)->review($approver ?? User::factory()->staff('finance')->create(), $id, 'approved', 'Checked by phone');

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
}

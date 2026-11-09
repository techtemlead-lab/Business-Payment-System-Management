<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AmountInWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner decision: commissions are paid by hand. Staff record how each payout was paid,
 * a numbered receipt is printed, and the dealer or sub-dealer sees their own copy online.
 */
class PayoutReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
    }

    public function test_staff_must_record_how_the_payout_was_paid(): void
    {
        [$partner, $reviewer, $payer] = $this->people();
        $id = $this->approvedWithdrawal($partner, $reviewer);
        $base = ['paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Paid by hand'];

        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', $base)->assertSessionHasErrors('method');
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$base, 'method' => 'bank', 'payment_reference' => 'EFT-0001',
            'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')])->assertSessionHasErrors(['bank_name', 'account_number']);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$base, 'method' => 'bkash', 'payment_reference' => 'BK-0001',
            'receipt' => UploadedFile::fake()->image('bkash.png')])->assertSessionHasErrors('wallet_number');
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$base, 'method' => 'nagad', 'wallet_number' => '01711000000'])
            ->assertSessionHasErrors(['payment_reference', 'receipt']);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$base, 'method' => 'cash'])->assertSessionHasErrors('received_by');
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$base, 'method' => 'cheque'])->assertSessionHasErrors('method');

        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseCount('payout_receipts', 0);
    }

    public function test_marking_a_bank_payout_paid_issues_a_numbered_receipt_and_writes_the_audit_log(): void
    {
        [$partner, $reviewer, $payer] = $this->people();
        $id = $this->approvedWithdrawal($partner, $reviewer);

        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bank', 'bank_name' => 'Sonali Bank', 'account_number' => '0123456789012', 'payment_reference' => 'eft-77001',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent by bank transfer',
            'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();
        $this->assertMatchesRegularExpression('/^MN-PR-\d{6}$/', $receipt->number);
        $this->assertSame('bank', $receipt->method);
        $this->assertSame('EFT-77001', $receipt->reference);
        $this->assertSame('Sonali Bank', $receipt->bank_name);
        $this->assertSame('0123456789012', decrypt($receipt->account_number), 'The account number is stored encrypted.');
        $this->assertSame('500.00', (string) $receipt->amount);
        $this->assertSame('450.00', (string) $receipt->net_amount);
        $this->assertSame($payer->id, (int) $receipt->paid_by);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'paid', 'payment_reference' => 'EFT-77001', 'paid_by' => $payer->id]);

        $audit = DB::table('audit_events')->where('action', 'payout_receipt.issued')->sole();
        $this->assertSame($payer->id, (int) $audit->actor_id);
        $this->assertSame($receipt->number, $audit->subject);
        $this->assertSame('bank', json_decode((string) $audit->metadata, true)['method']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $partner->id]);

        // The next receipt gets the next number.
        $second = $this->approvedWithdrawal($partner, $reviewer);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$second.'/record-payment', [
            'method' => 'bkash', 'wallet_number' => '01711000000', 'payment_reference' => 'BK-77002',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent by bKash', 'receipt' => UploadedFile::fake()->image('bkash.png'),
        ])->assertSessionHasNoErrors();
        $next = DB::table('payout_receipts')->where('withdrawal_id', $second)->value('id');
        $this->assertSame((int) $receipt->id + 1, (int) $next);
    }

    public function test_a_cash_payout_needs_who_took_the_money_but_no_reference_or_proof(): void
    {
        [$partner, $reviewer, $payer] = $this->people();
        $id = $this->approvedWithdrawal($partner, $reviewer);

        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'cash', 'received_by' => 'Rahim Uddin (dealer himself)',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Paid in cash at the Rangpur office',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();
        $this->assertSame('cash', $receipt->method);
        $this->assertSame('Rahim Uddin (dealer himself)', $receipt->received_by);
        $this->assertNull($receipt->reference);
        $withdrawal = DB::table('withdrawals')->find($id);
        $this->assertSame('paid', $withdrawal->status);
        $this->assertSame($receipt->number, $withdrawal->payment_reference);
        $this->assertNull($withdrawal->receipt_path);
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
    }

    public function test_the_receipt_page_shows_who_was_paid_how_much_how_and_by_whom(): void
    {
        [$partner, $reviewer, $payer] = $this->people();
        $id = $this->approvedWithdrawal($partner, $reviewer);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'bank', 'bank_name' => 'Sonali Bank', 'account_number' => '0123456789012', 'payment_reference' => 'EFT-88001',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent by bank transfer',
            'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $receipt = DB::table('payout_receipts')->where('withdrawal_id', $id)->sole();

        $page = $this->actingAs($partner)->onPortal('dealer')->get('/payment-receipts/'.$receipt->id.'/print')->assertOk();
        $page->assertSee($receipt->number)
            ->assertSee(config('madina.company_name'))
            ->assertSee($partner->name)
            ->assertSee('DLR-7001')
            ->assertSee('500.00')
            ->assertSee('50.00')
            ->assertSee('450.00')
            ->assertSee(AmountInWords::taka('450.00'))
            ->assertSee(__('portal.payout_method_bank'))
            ->assertSee('EFT-88001')
            ->assertSee('Sonali Bank')
            ->assertSee('9012')
            ->assertDontSee('0123456789012')
            ->assertSee($payer->name)
            ->assertSee(__('portal.prc_partner_copy'));

        $this->actingAs($payer)->onPortal('manage')->get('/payment-receipts/'.$receipt->id.'/print')
            ->assertOk()->assertSee(__('portal.prc_office_copy'));
    }

    public function test_partners_see_and_print_only_their_own_receipts(): void
    {
        [$partner, $reviewer, $payer] = $this->people();
        $other = User::factory()->subDealer()->create(['status' => 'active', 'dealer_code' => 'SUB-7002']);
        $mine = $this->paidReceipt($partner, $reviewer, $payer, 'MINE-1');
        $theirs = $this->paidReceipt($other, $reviewer, $payer, 'THEIRS-1');

        $this->actingAs($partner)->onPortal('dealer')->get('/payment-receipts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'payment-receipts')
                ->has('data.payment_receipts.data', 1)
                ->where('data.payment_receipts.data.0.id', $mine)
                ->where('data.payment_receipts.data.0.reference', 'MINE-1')
                ->where('translations.portal.payment_receipts', 'Payment receipts'));
        $this->actingAs($partner)->onPortal('dealer')->get('/payment-receipts/'.$theirs.'/print')->assertNotFound();

        $this->actingAs($other)->onPortal('subdealer')->get('/payment-receipts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.payment_receipts.data', 1)->where('data.payment_receipts.data.0.id', $theirs));
        $this->actingAs($other)->onPortal('subdealer')->get('/payment-receipts/'.$theirs.'/print')->assertOk();
        $this->actingAs($other)->onPortal('subdealer')->get('/payment-receipts/'.$mine.'/print')->assertNotFound();

        // Office staff without the finance permission cannot open receipts.
        $content = User::factory()->staff('content')->create();
        $this->assertContains($this->actingAs($content)->onPortal('manage')->get('/payment-receipts/'.$mine.'/print')->getStatusCode(), [403, 404]);
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function people(): array
    {
        return [
            User::factory()->dealer()->create(['status' => 'active', 'name' => 'Rahim Uddin', 'dealer_code' => 'DLR-7001', 'phone' => '01711000000']),
            User::factory()->staff('finance')->create(['name' => 'Reviewer Karim']),
            User::factory()->staff('finance')->create(['name' => 'Payer Salma']),
        ];
    }

    private function approvedWithdrawal(User $partner, User $reviewer): string
    {
        $id = (string) Str::uuid();
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first();
        if ($wallet) {
            DB::table('wallets')->where('id', $wallet->id)->update(['held' => DB::raw('held + 500.00')]);
        } else {
            DB::table('wallets')->insert(['user_id' => $partner->id, 'available' => '0.00', 'held' => '500.00', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => '500.00', 'withheld_amount' => '50.00', 'net_amount' => '450.00',
            'status' => 'approved', 'channel' => 'Bank', 'destination' => encrypt('0123456789012'), 'idempotency_key' => (string) Str::uuid(),
            'reviewed_by' => $reviewer->id, 'created_at' => now()->subDay(), 'updated_at' => now()]);

        return $id;
    }

    private function paidReceipt(User $partner, User $reviewer, User $payer, string $reference): int
    {
        $id = $this->approvedWithdrawal($partner, $reviewer);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'nagad', 'wallet_number' => '01811000000', 'payment_reference' => $reference,
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent by Nagad', 'receipt' => UploadedFile::fake()->image('nagad.png'),
        ])->assertSessionHasNoErrors();

        return (int) DB::table('payout_receipts')->where('withdrawal_id', $id)->value('id');
    }
}

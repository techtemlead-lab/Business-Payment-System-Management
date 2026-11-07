<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Orders\OrderService;
use App\Domain\Support\UserManual;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner decision: every manual payout gets a receipt. Reserve payouts paid by the office
 * record the same method details as withdrawals and get the same MN-PR numbered receipt.
 */
class ReservePayoutReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
    }

    public function test_a_reserve_payout_needs_the_payment_method_details(): void
    {
        [$preparer, $id] = $this->approvedReservePayout();
        $base = ['paid_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Invoice payment confirmed'];
        $url = '/api/v1/operations/reserves/'.$id.'/pay';

        $this->actingAs($preparer)->postJson($url, $base)->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->postJson($url, [...$base, 'method' => 'bank', 'payment_reference' => 'EXP-BANK-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bank_name', 'account_number', 'payment_account_id', 'receipt']);
        $this->postJson($url, [...$base, 'method' => 'nagad'])->assertUnprocessable()->assertJsonValidationErrors(['wallet_number', 'payment_reference']);
        $this->postJson($url, [...$base, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('received_by');

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseCount('payout_receipts', 0);
    }

    public function test_a_bank_reserve_payout_issues_a_numbered_receipt_with_the_details(): void
    {
        [$preparer, $id] = $this->approvedReservePayout();
        $account = $this->account($preparer);

        $this->actingAs($preparer)->postJson('/api/v1/operations/reserves/'.$id.'/pay', [
            'method' => 'bank', 'bank_name' => 'Dutch-Bangla Bank', 'account_number' => '7011234567890', 'payment_reference' => 'exp-bank-77',
            'payment_account_id' => $account, 'paid_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Invoice payment confirmed',
            'receipt' => UploadedFile::fake()->create('expense.pdf', 5, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.receipt_number', fn (string $number): bool => (bool) preg_match('/^MN-PR-\d{6}$/', $number));

        $receipt = DB::table('payout_receipts')->where('reserve_disbursement_id', $id)->sole();
        $this->assertNull($receipt->withdrawal_id);
        $this->assertNull($receipt->user_id);
        $this->assertSame('Approved advertising supplier', $receipt->payee_name);
        $this->assertSame('bank', $receipt->method);
        $this->assertSame('EXP-BANK-77', $receipt->reference);
        $this->assertSame('7011234567890', decrypt($receipt->account_number));
        $this->assertSame('2.00', (string) $receipt->net_amount);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid', 'payment_reference' => 'EXP-BANK-77', 'paid_by' => $preparer->id]);
        $audit = DB::table('audit_events')->where('action', 'payout_receipt.issued')->sole();
        $this->assertSame($receipt->number, $audit->subject);
        $this->assertSame($id, json_decode((string) $audit->metadata, true)['reserve_disbursement_id']);
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));

        $this->actingAs($preparer)->onPortal('manage')->get('/payment-receipts/'.$receipt->id.'/print')
            ->assertOk()
            ->assertSee($receipt->number)
            ->assertSee('Approved advertising supplier')
            ->assertSee(__('portal.prc_outside_payee'))
            ->assertSee(__('portal.prc_reserve_amount'))
            ->assertSee('#'.$id.' · '.__('portal.prc_reserve_program_marketing'))
            ->assertSee('Dutch-Bangla Bank')
            ->assertSee('7890')
            ->assertDontSee('7011234567890')
            ->assertSee('EXP-BANK-77')
            ->assertSee(__('portal.prc_office_copy'));

        // A reserve payout to an outside payee is never shown to a partner.
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/payment-receipts/'.$receipt->id.'/print')->assertNotFound();
    }

    public function test_a_cash_reserve_payout_needs_only_who_took_the_money(): void
    {
        [$preparer, $id] = $this->approvedReservePayout();

        $this->actingAs($preparer)->postJson('/api/v1/operations/reserves/'.$id.'/pay', [
            'method' => 'cash', 'received_by' => 'Supplier manager Hasan', 'paid_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Paid in cash at the office',
        ])->assertOk();

        $receipt = DB::table('payout_receipts')->where('reserve_disbursement_id', $id)->sole();
        $this->assertSame('cash', $receipt->method);
        $this->assertSame('Supplier manager Hasan', $receipt->received_by);
        $reserve = DB::table('reserve_disbursements')->find($id);
        $this->assertSame('paid', $reserve->status);
        $this->assertSame($receipt->number, $reserve->payment_reference);
        $this->assertNull($reserve->receipt_path);
        $this->assertNull($reserve->payment_account_id);
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
    }

    public function test_the_user_manual_explains_the_payment_receipts_page(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $chapter = collect(app(UserManual::class)->chaptersFor($dealer, 'receipt'))->firstWhere('key', 'payment_receipts');

        $this->assertNotNull($chapter);
        $this->assertSame('Payment receipts: proof of every payout', $chapter['title']);
        $this->assertCount(6, $chapter['steps']);
    }

    /** @return array{0: User, 1: int} */
    private function approvedReservePayout(): array
    {
        $preparer = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $this->postedOrder(User::factory()->create());
        $id = $this->actingAs($preparer)->postJson('/api/v1/operations/reserves', ['program' => 'marketing', 'payee_name' => 'Approved advertising supplier', 'amount' => '2',
            'request_key' => (string) Str::uuid(), 'reason' => 'Approved marketing invoice'])->assertOk()->json('data.id');
        $this->actingAs($reviewer)->postJson('/api/v1/operations/reserves/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Marketing invoice reviewed'])->assertOk();

        return [$preparer, (int) $id];
    }

    private function account(User $actor): int
    {
        return app(PaymentAccounts::class)->save($actor, ['label' => 'Company bank', 'channel' => 'Bank', 'holder' => 'Madina',
            'account_number' => 'BANK-123456', 'collects_payments' => true, 'pays_out' => true, 'is_active' => true, 'reason' => 'Approved company account']);
    }

    private function postedOrder(User $buyer): void
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerMoneyBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
    }

    private function withdrawal(User $owner, User $reviewer): string
    {
        $id = (string) Str::uuid();
        DB::table('wallets')->insert(['user_id' => $owner->id, 'available' => '0.00', 'held' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $owner->id, 'amount' => '200.00', 'status' => 'approved', 'channel' => 'Bank', 'destination' => encrypt('TEST-ACCOUNT-1234'), 'idempotency_key' => (string) Str::uuid(), 'reviewed_by' => $reviewer->id, 'created_at' => now()->subDay(), 'updated_at' => now()]);

        return $id;
    }

    public function test_receipt_is_required_and_only_authorized_staff_can_record_payment(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        $this->actingAs($owner)->post('/commands/withdrawals/'.$id.'/record-payment', [])->assertForbidden();
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', ['method' => 'other', 'payment_reference' => 'PAY-001', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent manually'])->assertSessionHasErrors('receipt');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
    }

    public function test_manual_receipt_settlement_is_private_balanced_and_cannot_repeat(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $outsider = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        $data = ['method' => 'other', 'payment_reference' => 'PAY-001', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Manually sent and checked', 'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')];
        $this->actingAs($reviewer)->post('/commands/withdrawals/'.$id.'/record-payment', $data)->assertSessionHasErrors('withdrawal');
        $data['receipt'] = UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf');
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', $data)->assertRedirect();
        $w = DB::table('withdrawals')->find($id);
        $this->assertSame('paid', $w->status);
        Storage::disk('local')->assertExists($w->receipt_path);
        $this->assertDatabaseHas('wallets', ['user_id' => $owner->id, 'held' => '0.00']);
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
        $this->actingAs($owner)->get('/workspace/withdrawals/'.$id.'/receipt')->assertOk();
        $this->actingAs($outsider)->get('/workspace/withdrawals/'.$id.'/receipt')->assertNotFound();
        $this->get('/workspace/withdrawals/'.$id)->assertNotFound();
        $data['receipt'] = UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf');
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', $data)->assertSessionHasErrors('withdrawal');
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('payout-receipts'));
    }

    public function test_a_withdrawal_rejected_with_no_reason_tells_the_partner_none_was_written(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        DB::table('withdrawals')->where('id', $id)->update(['status' => 'requested', 'reviewed_by' => null]);
        $this->reasonFields(['money.withdrawal_review' => 'optional']);

        $this->actingAs($reviewer)->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'rejected'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'rejected', 'reason' => '']);
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'rejected', 'note' => '']);
        $notice = (string) DB::table('account_notices')->where('user_id', $owner->id)->orderByDesc('id')->value('body');
        $this->assertStringContainsString(__('reason_fields.not_written', [], $owner->preferredLocale()), $notice);
        $this->assertSame(__('reason_fields.not_written'), app(PartnerMoneyBook::class)->withdrawalRequests($owner)[0]['reason']);
    }

    public function test_a_blank_review_reason_or_payment_note_is_refused_once_the_owner_makes_it_compulsory(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        $payment = ['method' => 'other', 'payment_reference' => 'PAY-002', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s')];
        $this->reasonFields(['money.withdrawal_review' => 'required', 'money.withdrawal_settle' => 'required']);

        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$payment, 'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')])->assertSessionHasErrors('note');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);

        $this->reasonFields(['money.withdrawal_settle' => 'off']);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [...$payment, 'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'paid']);
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $id, 'event' => 'paid', 'note' => '']);
    }

    public function test_arbitrary_executable_file_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', ['method' => 'other', 'payment_reference' => 'PAY-X', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Test', 'receipt' => UploadedFile::fake()->create('proof.php', 20, 'application/x-httpd-php')])->assertSessionHasErrors('receipt');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
    }
}

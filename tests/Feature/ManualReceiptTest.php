<?php

namespace Tests\Feature;

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
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', ['payment_reference' => 'PAY-1', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent manually'])->assertSessionHasErrors('receipt');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
    }

    public function test_manual_receipt_settlement_is_private_balanced_and_cannot_repeat(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $outsider = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        $data = ['payment_reference' => 'PAY-1', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Manually sent and checked', 'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')];
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

    public function test_arbitrary_executable_file_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'dealer']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payer = User::factory()->create(['role' => 'finance']);
        $id = $this->withdrawal($owner, $reviewer);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', ['payment_reference' => 'PAY-X', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Test', 'receipt' => UploadedFile::fake()->create('proof.php', 20, 'application/x-httpd-php')])->assertSessionHasErrors('receipt');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
    }
}

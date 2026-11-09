<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Both payout screens must give the owner what the server asks for: the company account
 * the money left from, and the "I am doing this alone" confirmation when nobody else can pay.
 */
class PayoutRecordingScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.financial_activation' => true, 'madina.preview_mode' => false]);
        $this->owner = User::factory()->staff('super_admin')->create();
        DB::table('financial_verifications')->insert([
            'fingerprint' => 'test-opening', 'snapshot' => '{}', 'expected_totals' => '{}', 'source_reference' => 'TEST', 'evidence_path' => 'opening/test.bin',
            'evidence_hash' => str_repeat('a', 64), 'created_by' => $this->owner->id, 'verified_by' => $this->owner->id, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_owner_alone_sees_the_account_list_and_confirms_on_either_payout_screen(): void
    {
        $accountId = $this->payoutAccount();
        $id = $this->approvedWithdrawal();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.payment_accounts.0.id', $accountId)
                ->where('data.payouts_table.rows.data.0.payer_state', 'alone'));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/withdrawals/'.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('withdrawal.payer_state', 'alone'));

        $payment = [
            'method' => 'bkash', 'wallet_number' => '01711000000', 'payment_reference' => 'BKASH-QA-1', 'payment_account_id' => $accountId,
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Paid by bKash',
            'receipt' => UploadedFile::fake()->image('bkash.png'),
        ];
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/record-payment', $payment)
            ->assertSessionHasErrors('withdrawal');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/record-payment', [...$payment, 'self_approve' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'paid', 'payment_account_id' => $accountId, 'paid_by' => $this->owner->id]);
    }

    public function test_when_a_second_approver_exists_the_detail_page_says_they_must_pay(): void
    {
        User::factory()->staff('finance')->create();
        $id = $this->approvedWithdrawal();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/withdrawals/'.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('withdrawal.payer_state', 'second_person'));
    }

    private function payoutAccount(): int
    {
        return (int) DB::table('payment_accounts')->insertGetId([
            'label' => 'Payout bKash', 'channel' => 'bKash', 'holder' => 'Company', 'account_number' => encrypt('01700000000'),
            'collects_payments' => false, 'pays_out' => true, 'is_active' => true, 'version' => 1, 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function approvedWithdrawal(): string
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->insert(['user_id' => $partner->id, 'available' => '0.00', 'held' => '500.00', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => '500.00', 'withheld_amount' => '0.00', 'net_amount' => '500.00',
            'status' => 'approved', 'channel' => 'bKash', 'destination' => encrypt('01711000000'), 'idempotency_key' => (string) Str::uuid(),
            'reviewed_by' => $this->owner->id, 'created_at' => now()->subDay(), 'updated_at' => now()]);

        return $id;
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WithdrawalMoneySmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.financial_activation' => true]);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
    }

    public function test_review_approval_sends_localized_partner_sms(): void
    {
        $owner = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000091',
            'locale' => 'bn',
        ]);
        $reviewer = User::factory()->staff('finance')->create();
        $id = $this->requestedWithdrawal($owner);

        $this->actingAs($reviewer)->onPortal('manage')
            ->post('/commands/withdrawals/'.$id.'/review', [
                'decision' => 'approved',
                'reason' => 'Verified destination and available hold',
            ])
            ->assertRedirect();

        $sent = config('madina.sms.sent');
        $this->assertIsArray($sent);
        $this->assertNotEmpty($sent);
        $this->assertSame('withdrawal.approved', $sent[0]['event'] ?? null);
        $this->assertSame('01720000091', $sent[0]['phone'] ?? null);
        $this->assertStringContainsString('অনুমোদিত', $sent[0]['message'] ?? '');
        $this->assertStringContainsString('200.00', $sent[0]['message'] ?? '');
    }

    public function test_review_rejection_sends_partner_sms_and_releases_hold(): void
    {
        $owner = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000092',
            'locale' => 'en',
        ]);
        $reviewer = User::factory()->staff('finance')->create();
        $id = $this->requestedWithdrawal($owner);

        $this->actingAs($reviewer)->onPortal('manage')
            ->post('/commands/withdrawals/'.$id.'/review', [
                'decision' => 'rejected',
                'reason' => 'Destination could not be verified',
            ])
            ->assertRedirect();

        $sent = config('madina.sms.sent');
        $this->assertSame('withdrawal.rejected', $sent[0]['event'] ?? null);
        $this->assertStringContainsString('rejected', $sent[0]['message'] ?? '');
        $this->assertDatabaseHas('wallets', ['user_id' => $owner->id, 'held' => '0.00']);
    }

    public function test_settlement_sends_paid_partner_sms(): void
    {
        $owner = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000093',
            'locale' => 'bn',
        ]);
        $reviewer = User::factory()->staff('finance')->create();
        $payer = User::factory()->staff('finance')->create();
        $id = (string) Str::uuid();
        DB::table('wallets')->insert([
            'user_id' => $owner->id,
            'available' => '0.00',
            'held' => '200.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('withdrawals')->insert([
            'id' => $id,
            'user_id' => $owner->id,
            'amount' => '200.00',
            'status' => 'approved',
            'channel' => 'bKash',
            'destination' => encrypt('01720000093'),
            'idempotency_key' => (string) Str::uuid(),
            'reviewed_by' => $reviewer->id,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $this->actingAs($payer)->onPortal('manage')
            ->post('/commands/withdrawals/'.$id.'/record-payment', [
                'payment_reference' => 'PAY-SMS-1',
                'paid_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'note' => 'Manually sent and checked',
                'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();

        $sent = config('madina.sms.sent');
        $this->assertSame('withdrawal.paid', $sent[0]['event'] ?? null);
        $this->assertStringContainsString('পরিশোধ', $sent[0]['message'] ?? '');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'paid']);
    }

    private function requestedWithdrawal(User $owner): string
    {
        $id = (string) Str::uuid();
        DB::table('wallets')->insert([
            'user_id' => $owner->id,
            'available' => '0.00',
            'held' => '200.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('withdrawals')->insert([
            'id' => $id,
            'user_id' => $owner->id,
            'amount' => '200.00',
            'status' => 'requested',
            'channel' => 'Bank',
            'destination' => encrypt('TEST-ACCOUNT-1234'),
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

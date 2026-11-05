<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\CommissionRuleParameter;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyTdsAndKycWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        // 10% tax once a partner's withdrawals for the month reach ৳10,000.
        CommissionRuleParameter::where('rule_key', 'payout_tax_withholding')->update([
            'percentage' => '10.00', 'is_active' => true, 'parameters' => json_encode(['threshold' => '10000.00']),
        ]);
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
    }

    public function test_small_withdrawals_that_together_cross_the_threshold_are_taxed(): void
    {
        $dealer = $this->partnerWithWallet('50000.00');

        $first = $this->withdraw($dealer, '6000.00');
        $second = $this->withdraw($dealer, '6000.00');
        $third = $this->withdraw($dealer, '2000.00');

        $this->assertSame('0.00', $this->withheld($first));
        // Month total ৳12,000 crosses ৳10,000: tax on the whole month so far (catch-up).
        $this->assertSame('1200.00', $this->withheld($second));
        $this->assertSame('4800.00', DB::table('withdrawals')->where('id', $second)->value('net_amount'));
        // After crossing, every later withdrawal that month is taxed in full.
        $this->assertSame('200.00', $this->withheld($third));
    }

    public function test_a_new_month_starts_again_from_zero(): void
    {
        $dealer = $this->partnerWithWallet('50000.00');
        $this->withdraw($dealer, '9000.00');

        // 1 October 00:30 in Dhaka is still 30 September in UTC, but it is a new month here.
        $this->travelTo(now()->setTimezone('Asia/Dhaka')->setDate(2026, 10, 1)->setTime(0, 30)->utc());
        $next = $this->withdraw($dealer, '5000.00');

        $this->assertSame('0.00', $this->withheld($next));
    }

    public function test_rejected_withdrawals_do_not_count_towards_the_month(): void
    {
        $dealer = $this->partnerWithWallet('50000.00');
        $first = $this->withdraw($dealer, '8000.00');
        DB::table('withdrawals')->where('id', $first)->update(['status' => 'rejected']);

        $second = $this->withdraw($dealer, '5000.00');

        $this->assertSame('0.00', $this->withheld($second));
    }

    public function test_the_kyc_setting_is_off_by_default_and_changes_nothing(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');

        $this->assertFalse(app(FinanceSettings::class)->kycBeforeFirstWithdrawal()['enabled']);
        $this->assertNotEmpty($this->withdraw($dealer, '500.00'));
    }

    public function test_when_on_the_first_withdrawal_needs_approved_id_papers(): void
    {
        $this->turnKycOn();
        $dealer = $this->partnerWithWallet('5000.00');

        try {
            $this->withdraw($dealer, '500.00');
            $this->fail('The first withdrawal should wait for ID papers.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('portal.err_wd_kyc_first'), $exception->errors()['amount'][0]);
        }
        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertTrue(app(FinanceSettings::class)->needsKycBeforeWithdrawal($dealer));
    }

    public function test_when_on_approved_id_papers_allow_the_first_withdrawal(): void
    {
        $this->turnKycOn();
        $dealer = $this->partnerWithWallet('5000.00');
        $office = User::factory()->staff('finance')->create();
        DB::table('kyc_documents')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $dealer->id, 'kind' => 'nid_front', 'disk_path' => 'kyc-documents/x.jpg',
            'original_name' => 'nid.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 100, 'uploaded_by' => $office->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotEmpty($this->withdraw($dealer, '500.00'));
    }

    public function test_when_on_partners_paid_before_are_not_blocked(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        $paid = $this->withdraw($dealer, '500.00');
        DB::table('withdrawals')->where('id', $paid)->update(['status' => 'paid']);
        $this->turnKycOn();

        $this->assertNotEmpty($this->withdraw($dealer, '500.00'));
    }

    public function test_only_owners_can_change_the_kyc_setting_and_the_change_is_audited(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $status = $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Trying to change an owner setting',
        ])->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertFalse(app(FinanceSettings::class)->kycBeforeFirstWithdrawal()['enabled']);

        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $status = $this->actingAs($dealer)->onPortal('dealer')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Trying to change an owner setting',
        ])->getStatusCode();
        $this->assertContains($status, [403, 404]);

        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Check ID papers before paying anyone new',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(app(FinanceSettings::class)->kycBeforeFirstWithdrawal()['enabled']);
        $audit = DB::table('audit_events')->where('action', 'finance.kyc_before_first_withdrawal_changed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertFalse($metadata['before']['enabled']);
        $this->assertTrue($metadata['after']['enabled']);
        $this->assertSame('Check ID papers before paying anyone new', $metadata['reason']);
    }

    private function turnKycOn(): void
    {
        $owner = User::factory()->staff('md')->create();
        $settings = app(FinanceSettings::class);
        $settings->saveKycBeforeFirstWithdrawal($owner, true, $settings->kycBeforeFirstWithdrawal()['version'], 'Owner wants ID papers first');
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

    private function withheld(string $id): string
    {
        return (string) DB::table('withdrawals')->where('id', $id)->value('withheld_amount');
    }
}

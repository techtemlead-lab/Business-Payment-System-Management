<?php

namespace Tests\Feature;

use App\Domain\Payouts\WithdrawalMethods;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner, 2026-11-28: a dealer or sub-dealer keeps his accounts (bank, bKash, Nagad, Rocket) and a withdrawal goes only
 * to one of them. Cash at the office needs no account. Without a saved account the partner is asked to add one.
 */
class WithdrawSavedAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        config(['madina.withdrawal_methods_default' => ['Office', 'bKash', 'Nagad', 'Rocket']]);
    }

    public function test_without_a_saved_account_a_typed_number_is_refused_and_the_partner_is_asked_to_add_one(): void
    {
        $dealer = $this->partnerWithWallet();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'channel' => 'bKash', 'destination' => '01712345678', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['payout_account_id' => __('leftovers_partner.pa_add_first')]);
        $this->assertSame('আগে টাকা নেওয়ার অ্যাকাউন্ট যোগ করুন।', __('leftovers_partner.pa_add_first', [], 'bn'));
        $this->assertSame(0, DB::table('withdrawals')->count());
    }

    public function test_with_saved_accounts_one_must_still_be_picked_and_it_must_be_his_own(): void
    {
        $dealer = $this->partnerWithWallet();
        $this->savePayoutAccount($dealer, 'bKash');
        $stranger = $this->partnerWithWallet();
        $strangerAccount = $this->savePayoutAccount($stranger, 'Nagad', '01811111111');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'channel' => 'bKash', 'destination' => '01712345678', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['payout_account_id' => __('leftovers_partner.pa_pick_required')]);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'payout_account_id' => $strangerAccount, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['payout_account_id' => __('leftovers_partner.pa_not_found')]);
        $this->assertSame(0, DB::table('withdrawals')->count());
    }

    public function test_a_picked_saved_account_decides_the_channel_and_destination_whatever_else_is_sent(): void
    {
        $dealer = $this->partnerWithWallet();
        $account = $this->savePayoutAccount($dealer, 'Nagad', '01811111111');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'payout_account_id' => $account, 'channel' => 'bKash', 'destination' => '01999999999', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $withdrawal = DB::table('withdrawals')->where('user_id', $dealer->id)->sole();
        $this->assertSame('Nagad', $withdrawal->channel);
        $this->assertSame('01811111111', decrypt($withdrawal->destination));
    }

    public function test_a_saved_bank_account_is_taken_once_bank_is_switched_on(): void
    {
        $dealer = $this->partnerWithWallet();
        $account = $this->savePayoutAccount($dealer, 'Bank');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'payout_account_id' => $account, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['channel' => __('office_withdrawal.err_method_off')]);

        config(['madina.withdrawal_methods_default' => WithdrawalMethods::all()]);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'payout_account_id' => $account, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame('Bank', DB::table('withdrawals')->where('user_id', $dealer->id)->value('channel'));
    }

    public function test_cash_at_the_office_needs_no_account(): void
    {
        $dealer = $this->partnerWithWallet();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'channel' => 'Office', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $this->assertSame('Office', DB::table('withdrawals')->where('user_id', $dealer->id)->value('channel'));
    }

    public function test_the_api_keeps_its_documented_typed_request(): void
    {
        $dealer = $this->partnerWithWallet();

        $this->actingAs($dealer)->postJson('/api/v1/withdrawals', ['amount' => '500.00', 'channel' => 'bKash', 'destination' => '01712345678', 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated();
    }

    public function test_the_wallet_page_lists_every_saved_account_with_its_details_and_whether_its_way_is_open(): void
    {
        $dealer = $this->partnerWithWallet();
        $this->savePayoutAccount($dealer, 'bKash', '01711111111');
        $this->savePayoutAccount($dealer, 'Bank');

        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('withdraw.methods', ['Office', 'bKash', 'Nagad', 'Rocket'])
                ->has('withdraw.saved_accounts', 2)
                ->where('withdraw.saved_accounts.0.channel', 'bKash')
                ->where('withdraw.saved_accounts.0.is_default', true)
                ->where('withdraw.saved_accounts.1.channel', 'Bank')
                ->where('withdraw.saved_accounts.1.bank_name', 'Sonali Bank')
                ->where('withdraw.saved_accounts.1.branch', 'Pirganj'));
    }

    private function partnerWithWallet(): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => '5000.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);

        return $dealer;
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Payouts\PartnerPayoutAccounts;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** V3: saved payout accounts, picked in the withdrawal dialog. */
class PartnerPayoutAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.full_payment_when_ordering' => true]);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    private function partner(): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => '9000.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);

        return $dealer;
    }

    private function add(User $partner, array $account): void
    {
        $this->actingAs($partner)->onPortal('dealer')->post('/commands/payout-accounts', $account)->assertSessionHasNoErrors();
    }

    public function test_accounts_are_stored_encrypted_the_first_is_default_and_each_change_is_audited_without_the_number(): void
    {
        $dealer = $this->partner();
        $this->add($dealer, ['channel' => 'bKash', 'number' => '০১৭১২৩৪৫৬৭৮']);
        $this->add($dealer, ['channel' => 'Bank', 'number' => '1234 5678 90', 'bank_name' => 'Sonali Bank', 'branch' => 'Pirganj', 'account_name' => 'Rahim']);

        $rows = DB::table('partner_payout_accounts')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue((bool) $rows[0]->is_default);
        $this->assertFalse((bool) $rows[1]->is_default);
        $this->assertStringNotContainsString('01712345678', $rows[0]->details);
        $this->assertStringNotContainsString('Sonali', $rows[1]->details);
        $list = app(PartnerPayoutAccounts::class)->listFor($dealer);
        $this->assertSame('01712345678', $list[0]['number']);
        $this->assertSame('1234567890', $list[1]['number']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/payout-accounts/'.$rows[1]->id.'/default')->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('partner_payout_accounts')->where('is_default', true)->count());
        $this->assertTrue((bool) DB::table('partner_payout_accounts')->where('id', $rows[1]->id)->value('is_default'));

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/payout-accounts/'.$rows[1]->id.'/remove')->assertSessionHasNoErrors();
        $this->assertTrue((bool) DB::table('partner_payout_accounts')->where('id', $rows[0]->id)->value('is_default'), 'the next account becomes the default');

        $audit = DB::table('audit_events')->where('action', 'like', 'payout_account.%')->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['payout_account.added', 'payout_account.added', 'payout_account.default_set', 'payout_account.removed'], $audit);
        $this->assertStringNotContainsString('01712345678', (string) DB::table('audit_events')->where('action', 'like', 'payout_account.%')->pluck('metadata')->implode(' '));
    }

    public function test_bad_numbers_are_refused_and_one_partner_cannot_touch_anothers_account(): void
    {
        $dealer = $this->partner();
        $other = $this->partner();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/payout-accounts', ['channel' => 'bKash', 'number' => '12345'])->assertSessionHasErrors('number');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/payout-accounts', ['channel' => 'Bank', 'number' => '1234567890'])->assertSessionHasErrors('bank_name');
        $this->add($other, ['channel' => 'Nagad', 'number' => '01812345678']);
        $id = DB::table('partner_payout_accounts')->value('id');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/payout-accounts/'.$id.'/remove')->assertNotFound();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/payout-accounts/'.$id, ['channel' => 'Nagad', 'number' => '01999999999'])->assertNotFound();
        $this->assertSame(1, DB::table('partner_payout_accounts')->count());
    }

    public function test_a_withdrawal_picks_a_saved_account_and_office_still_sees_its_destination(): void
    {
        $dealer = $this->partner();
        $this->add($dealer, ['channel' => 'bKash', 'number' => '01712345678']);
        $id = (int) DB::table('partner_payout_accounts')->value('id');
        DB::table('users')->where('id', $dealer->id)->update(['credentials_changed_at' => now()->subDay()]);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'payout_account_id' => $id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $w = DB::table('withdrawals')->where('user_id', $dealer->id)->sole();
        $this->assertSame('bKash', $w->channel);
        $this->assertSame('01712345678', decrypt($w->destination));
    }

    public function test_with_saved_accounts_a_typed_destination_is_not_enough_and_a_stranger_account_id_fails(): void
    {
        $dealer = $this->partner();
        $this->add($dealer, ['channel' => 'bKash', 'number' => '01712345678']);
        DB::table('users')->where('id', $dealer->id)->update(['credentials_changed_at' => now()->subDay()]);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'channel' => 'bKash', 'destination' => '01999999999', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('payout_account_id');
        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'payout_account_id' => 99999, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('payout_account_id');
        $this->assertSame(0, DB::table('withdrawals')->count());
    }

    public function test_changing_an_account_starts_the_security_wait(): void
    {
        $dealer = $this->partner();
        $this->add($dealer, ['channel' => 'bKash', 'number' => '01712345678']);
        $id = (int) DB::table('partner_payout_accounts')->value('id');

        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'payout_account_id' => $id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertSame(0, DB::table('withdrawals')->count());
    }
}

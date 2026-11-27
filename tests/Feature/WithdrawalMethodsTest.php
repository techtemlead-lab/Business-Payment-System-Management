<?php

namespace Tests\Feature;

use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\PartnerPayoutAccounts;
use App\Domain\Payouts\WithdrawalMethods;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner, 2026-11-21: "Office withdrawal" (cash at the office) is the first way to take money out; the owner
 * switches each way on or off. Owner, 2026-11-27: bKash, Nagad and Rocket are on by default too; bank stays off.
 * Most tests below start from an install where the owner left only the office on.
 */
class WithdrawalMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        // An install where only the office way is on (the test settings switch every way on for older tests).
        config(['madina.withdrawal_methods_default' => [WithdrawalMethods::Office]]);
    }

    public function test_office_bkash_nagad_and_rocket_are_on_by_default_and_bank_is_off(): void
    {
        $saved = ['env' => $_ENV['MADINA_WITHDRAWAL_METHODS'] ?? null, 'server' => $_SERVER['MADINA_WITHDRAWAL_METHODS'] ?? null, 'put' => getenv('MADINA_WITHDRAWAL_METHODS')];
        unset($_ENV['MADINA_WITHDRAWAL_METHODS'], $_SERVER['MADINA_WITHDRAWAL_METHODS']);
        putenv('MADINA_WITHDRAWAL_METHODS');
        try {
            // The config file read with no MADINA_WITHDRAWAL_METHODS set: what a fresh install uses.
            $default = (require config_path('madina.php'))['withdrawal_methods_default'];
        } finally {
            foreach (['env' => '_ENV', 'server' => '_SERVER'] as $slot => $global) {
                if ($saved[$slot] !== null) {
                    $GLOBALS[$global]['MADINA_WITHDRAWAL_METHODS'] = $saved[$slot];
                }
            }
            if ($saved['put'] !== false) {
                putenv('MADINA_WITHDRAWAL_METHODS='.$saved['put']);
            }
        }
        $this->assertSame(['Office', 'bKash', 'Nagad', 'Rocket'], $default);

        config(['madina.withdrawal_methods_default' => $default]);
        $this->assertSame(['Office' => true, 'Bank' => false, 'bKash' => true, 'Nagad' => true, 'Rocket' => true], app(WithdrawalMethods::class)->settings()['enabled']);
        $dealer = $this->partnerWithWallet('5000.00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdraw.methods', ['Office', 'bKash', 'Nagad', 'Rocket']));
        foreach (['bKash' => '01711111111', 'Nagad' => '01811111111', 'Rocket' => '017111111112'] as $channel => $number) {
            $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'channel' => $channel, 'destination' => $number, 'idempotency_key' => (string) Str::uuid()])
                ->assertSessionHasNoErrors();
        }
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'channel' => 'Bank', 'destination' => '0123456789', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['channel' => __('office_withdrawal.err_method_off')]);
        // The partner's history names the way of each request.
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdrawal_history.rows.data', fn ($rows): bool => collect($rows)->pluck('channel')->sort()->values()->all() === ['Nagad', 'Rocket', 'bKash']));
    }

    public function test_an_install_with_only_the_office_on_offers_only_the_office(): void
    {
        $settings = app(WithdrawalMethods::class)->settings();

        $this->assertSame(['Office' => true, 'Bank' => false, 'bKash' => false, 'Nagad' => false, 'Rocket' => false], $settings['enabled']);
        $this->assertSame(['Office'], app(WithdrawalMethods::class)->enabled());
    }

    public function test_an_office_withdrawal_needs_no_account_and_holds_the_money(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'channel' => 'Office', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $withdrawal = DB::table('withdrawals')->where('user_id', $dealer->id)->sole();
        $this->assertSame('Office', $withdrawal->channel);
        $this->assertSame(WithdrawalMethods::OfficeDestination, decrypt($withdrawal->destination));
        $this->assertSame('4000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $this->assertSame('1000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('held'));
    }

    public function test_an_office_withdrawal_ignores_saved_accounts(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        app(PartnerPayoutAccounts::class)->save($dealer, ['channel' => 'bKash', 'number' => '01711111111']);
        DB::table('users')->where('id', $dealer->id)->update(['credentials_changed_at' => null]);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'channel' => 'Office', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $this->assertSame('Office', DB::table('withdrawals')->where('user_id', $dealer->id)->value('channel'));
    }

    public function test_a_switched_off_way_is_refused_and_moves_no_money(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['channel' => __('office_withdrawal.err_method_off')]);
        $this->actingAs($dealer)->postJson('/api/v1/withdrawals', ['amount' => '1000.00', 'channel' => 'Bank', 'destination' => '0123456789', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->assertSame(0, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
        $this->assertSame('5000.00', DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
    }

    public function test_a_saved_account_of_a_switched_off_way_is_kept_but_not_offered(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        $account = app(PartnerPayoutAccounts::class)->save($dealer, ['channel' => 'Nagad', 'number' => '01811111111']);
        DB::table('users')->where('id', $dealer->id)->update(['credentials_changed_at' => null]);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/my-money')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdraw.methods', ['Office'])->where('withdraw.saved_accounts', []));
        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500.00', 'payout_account_id' => $account, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['channel' => __('office_withdrawal.err_method_off')]);
        $this->assertSame(1, DB::table('partner_payout_accounts')->where('user_id', $dealer->id)->count());
    }

    public function test_the_owner_turns_a_way_on_and_then_partners_can_use_it(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $finance = User::factory()->staff('finance')->create();
        $dealer = $this->partnerWithWallet('5000.00');
        $switches = ['enabled' => ['Office' => true, 'Bank' => false, 'bKash' => true, 'Nagad' => false, 'Rocket' => false], 'version' => 0, 'reason' => 'bKash payouts by hand are ready'];

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/withdrawal-methods', $switches)->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-methods', $switches)->assertSessionHasNoErrors();

        $this->assertSame(['Office', 'bKash'], app(WithdrawalMethods::class)->enabled());
        $this->assertDatabaseHas('audit_events', ['action' => 'withdrawal.methods_changed', 'actor_id' => $owner->id]);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '1000.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame('bKash', DB::table('withdrawals')->where('user_id', $dealer->id)->value('channel'));
    }

    public function test_every_way_cannot_be_switched_off_and_a_stale_form_is_refused(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $none = ['enabled' => ['Office' => false, 'Bank' => false, 'bKash' => false, 'Nagad' => false, 'Rocket' => false], 'version' => 0, 'reason' => 'Testing all off'];

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-methods', $none)
            ->assertSessionHasErrors(['enabled' => __('office_withdrawal.err_one_method_on')]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/withdrawal-methods', [...$none, 'enabled' => ['Office' => true], 'version' => 5])
            ->assertSessionHasErrors('version');
        $this->assertSame(['Office'], app(WithdrawalMethods::class)->enabled());
    }

    public function test_a_request_made_before_its_way_was_switched_off_can_still_be_paid(): void
    {
        config(['madina.withdrawal_methods_default' => WithdrawalMethods::all()]);
        $dealer = $this->partnerWithWallet('5000.00');
        $id = app(ManualPayoutService::class)->request($dealer, ['amount' => '1000.00', 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid()]);
        config(['madina.withdrawal_methods_default' => [WithdrawalMethods::Office]]);

        app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Checked');

        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_the_finance_settings_page_shows_the_switches(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/finance-settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('withdrawalMethods.enabled.Office', true)->where('withdrawalMethods.enabled.bKash', false)->where('withdrawalMethods.can_edit', true));
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

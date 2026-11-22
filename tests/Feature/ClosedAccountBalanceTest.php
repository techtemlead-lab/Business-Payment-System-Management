<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\ClosedAccountBalance;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\TreasuryFunds;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CommissionAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner decision 2026-11-21: a closed account keeps its wallet money (no withdrawal, back if it is opened again);
 * only the Super Admin may move it to the company profit fund, with a reason, once.
 */
class ClosedAccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    public function test_the_owner_moves_a_closed_accounts_wallet_money_to_the_company_fund(): void
    {
        $partner = $this->partnerWithMoney('1250.50', closed: true);
        $profitBefore = app(CompanyFunds::class)->balances()['company_profit']['money_in'];
        $key = (string) Str::uuid();

        $this->move($partner, '1250.50', $key)->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('money_core.flash_closed_balance_moved', ['amount' => '1,250.50', 'code' => $partner->dealer_code]));

        $this->assertSame('0.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));
        $row = CommissionAllocation::query()->where('metadata->source', ClosedAccountBalance::Source)->sole();
        $this->assertNull($row->order_id);
        $this->assertNull($row->user_id);
        $this->assertSame('1250.50', $row->amount);
        $this->assertSame($partner->id, $row->metadata['intended_user_id']);
        $this->assertSame('Partner left the business', $row->metadata['reason']);
        $this->assertSame($profitBefore + 125050, app(CompanyFunds::class)->balances()['company_profit']['money_in']);

        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('e.event_key', 'closed_account_balance.'.$partner->id.'.'.$key)->orderBy('l.account')->get(['l.account', 'l.user_id', 'l.debit', 'l.credit']);
        $this->assertCount(2, $lines);
        $this->assertSame([ClosedAccountBalance::IncomeAccount, null, '0.00', '1250.50'], [$lines[0]->account, $lines[0]->user_id, $lines[0]->debit, $lines[0]->credit]);
        $this->assertSame(['wallet_liability', $partner->id, '1250.50', '0.00'], [$lines[1]->account, (int) $lines[1]->user_id, $lines[1]->debit, $lines[1]->credit]);

        $audit = DB::table('audit_events')->where('action', ClosedAccountBalance::AuditAction)->sole();
        $this->assertSame((string) $partner->id, $audit->subject);
        $this->assertSame($this->owner->id, (int) $audit->actor_id);
        $this->assertSame('1250.50', json_decode((string) $audit->metadata, true)['amount']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $partner->id, 'title' => __('money_core.notice_closed_balance_title')]);
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));

        // Treasury shows why the money is in company profit.
        $rows = collect(app(TreasuryFunds::class)->moneyIn(Request::create('/x', 'GET', ['period' => '']), 'company_profit')['rows']->items());
        $this->assertNotNull($rows->first(fn (array $line): bool => str_contains((string) $line['reason'], 'closed partner account')));
    }

    public function test_a_double_click_moves_the_money_once(): void
    {
        $partner = $this->partnerWithMoney('500.00', closed: true);
        $key = (string) Str::uuid();

        $this->move($partner, '500.00', $key)->assertSessionHasNoErrors();
        // The same request again (the browser resent it): nothing more happens.
        $this->move($partner, '500.00', $key)->assertSessionHasNoErrors();
        // A second click with a new key finds nothing left.
        $this->move($partner, '500.00', (string) Str::uuid())->assertSessionHasErrors(['balance' => __('money_core.closed_balance_err_nothing')]);

        $this->assertSame(1, CommissionAllocation::query()->where('metadata->source', ClosedAccountBalance::Source)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', ClosedAccountBalance::AuditAction)->count());
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'closed_account_balance.%')->count());
        $this->assertSame('0.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));
    }

    public function test_an_open_or_only_suspended_account_and_an_empty_one_are_refused(): void
    {
        $active = $this->partnerWithMoney('100.00', closed: false);
        $suspended = $this->partnerWithMoney('100.00', closed: false);
        $suspended->forceFill(['status' => 'suspended'])->save();
        $empty = $this->partnerWithMoney('0.00', closed: true);

        $this->move($active, '100.00')->assertSessionHasErrors(['balance' => __('money_core.closed_balance_err_not_closed')]);
        $this->move($suspended, '100.00')->assertSessionHasErrors(['balance' => __('money_core.closed_balance_err_not_closed')]);
        $this->move($empty, '0.00')->assertSessionHasErrors(['balance' => __('money_core.closed_balance_err_nothing')]);

        $this->assertSame('100.00', DB::table('wallets')->where('user_id', $active->id)->value('available'));
        $this->assertSame('100.00', DB::table('wallets')->where('user_id', $suspended->id)->value('available'));
        $this->assertSame(0, DB::table('audit_events')->where('action', ClosedAccountBalance::AuditAction)->count());
    }

    public function test_the_amount_shown_must_still_be_the_balance(): void
    {
        $partner = $this->partnerWithMoney('300.00', closed: true);

        $this->move($partner, '250.00')->assertSessionHasErrors(['balance' => __('money_core.closed_balance_err_changed', ['amount' => '300.00'])]);
        $this->move($partner, '300.00', null, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->move($partner, '300.00', null, ['confirm' => '0'])->assertSessionHasErrors('confirm');

        $this->assertSame('300.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));
    }

    public function test_only_the_super_admin_may_move_or_see_it(): void
    {
        $partner = $this->partnerWithMoney('100.00', closed: true);
        foreach ([User::factory()->staff('md')->create(), User::factory()->staff('chairman')->create(), User::factory()->staff('finance')->create()] as $other) {
            $this->actingAs($other)->onPortal($other->role === 'md' || $other->role === 'chairman' ? 'sadmin' : 'manage')
                ->post('/commands/accounts/'.$partner->id.'/closed-balance/to-company', $this->payload('100.00'))->assertForbidden();
            $this->actingAs($other)->onPortal($other->role === 'md' || $other->role === 'chairman' ? 'sadmin' : 'manage')
                ->getJson('/commands/accounts/'.$partner->id.'/closed-balance')->assertForbidden();
        }

        $this->actingAs($this->owner)->onPortal('sadmin')->getJson('/commands/accounts/'.$partner->id.'/closed-balance')
            ->assertOk()->assertExactJson(['closed' => true, 'amount' => '100.00', 'can_move' => true]);
        $this->assertSame('100.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));
    }

    public function test_closing_keeps_the_money_and_opening_again_gives_it_back_to_use(): void
    {
        $partner = $this->partnerWithMoney('700.00', closed: false);

        $this->actingAs($this->owner)->onPortal('sadmin')->delete('/commands/accounts/'.$partner->id, [
            'reason' => 'Partner left the business', 'confirmation' => $partner->dealer_code,
        ])->assertRedirect();
        $this->assertNotNull($partner->fresh()->closed_at);
        $this->assertSame('700.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));

        $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$partner->id, [
            'name' => $partner->name, 'status' => 'active', 'reason' => 'Partner came back',
        ])->assertRedirect();
        $this->assertNull($partner->fresh()->closed_at);
        $this->assertSame('700.00', DB::table('wallets')->where('user_id', $partner->id)->value('available'));
        $this->assertSame(0, CommissionAllocation::query()->where('metadata->source', ClosedAccountBalance::Source)->count());
    }

    public function test_the_closed_view_lists_closed_accounts_that_still_hold_money(): void
    {
        $holding = $this->partnerWithMoney('900.00', closed: true);
        $this->partnerWithMoney('0.00', closed: true);
        $this->partnerWithMoney('400.00', closed: false);

        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));
        $data = $this->actingAs($this->owner)->onPortal('sadmin')->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/accounts?status=closed')->assertOk()->json('props.data');

        $this->assertSame(1, $data['closed_with_money']['count']);
        $this->assertSame('900.00', $data['closed_with_money']['total']);
        $this->assertSame($holding->id, $data['closed_with_money']['rows'][0]['id']);

        $open = $this->actingAs($this->owner)->onPortal('sadmin')->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/accounts')->assertOk()->json('props.data');
        $this->assertArrayNotHasKey('closed_with_money', $open);
    }

    private function partnerWithMoney(string $amount, bool $closed): User
    {
        $partner = User::factory()->dealer()->create(['status' => $closed ? 'suspended' : 'active', 'closed_at' => $closed ? now()->subDays(10) : null]);
        DB::transaction(function () use ($partner, $amount): void {
            CommissionWallet::lockShared();
            DB::table('wallets')->insertOrIgnore(['user_id' => $partner->id, 'created_at' => now(), 'updated_at' => now()]);
            $cents = (int) round(((float) $amount) * 100);
            if ($cents > 0) {
                app(CommissionWallet::class)->journal('test.seed.'.$partner->id, 'Seed', [
                    ['account' => 'commission_expense', 'user_id' => null, 'debit' => $cents, 'credit' => 0],
                    ['account' => 'wallet_liability', 'user_id' => $partner->id, 'debit' => 0, 'credit' => $cents],
                ]);
                app(CommissionWallet::class)->credit($partner->id, $cents);
            }
        });

        return $partner;
    }

    /** @return array<string, mixed> */
    private function payload(string $amount, ?string $key = null): array
    {
        return ['reason' => 'Partner left the business', 'confirm' => '1', 'expected_amount' => $amount, 'request_key' => $key ?? (string) Str::uuid()];
    }

    /** @param array<string, mixed> $overrides */
    private function move(User $partner, string $amount, ?string $key = null, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/accounts/'.$partner->id.'/closed-balance/to-company', [...$this->payload($amount, $key), ...$overrides]);
    }
}

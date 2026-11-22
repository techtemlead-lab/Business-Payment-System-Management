<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\MoneyTaskFigures;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fund spends that wait too long, that the owner withdraws, that were paid and refused, and
 * whose money came back.
 */
class FundSpendCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $this->order = $this->postedOrder();
    }

    public function test_a_spend_with_a_payment_reference_is_refused_only_when_the_checker_says_it_was_never_paid(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $this->fundRow('company_profit_fund', '1000.00');
        $id = $this->spend($maker, ['payment_method' => 'bkash', 'reference' => 'TRX-998877'])->assertOk()->json('data.id');

        $this->actingAs($checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Amount does not match'])
            ->assertUnprocessable()->assertJsonValidationErrors('spend');
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'requested']);

        $this->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Amount does not match', 'not_paid' => true])->assertOk();
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'rejected']);
        $audit = json_decode((string) DB::table('audit_events')->where('action', 'fund.spend_rejected')->where('subject', (string) $id)->value('metadata'), true);
        $this->assertTrue($audit['confirmed_not_paid']);
    }

    public function test_money_that_came_back_is_a_reversing_entry_that_restores_an_allocation_fund_once(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $third = User::factory()->staff('finance')->create();
        $this->fundRow('company_profit_fund', '1000.00');
        $before = $this->balance('company_profit');
        $id = $this->spend($maker, ['amount' => '250.00'])->assertOk()->json('data.id');
        $this->actingAs($checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Bill checked'])->assertOk();
        $this->assertSame($before - 25000, $this->balance('company_profit'));

        $correction = ['reason' => 'Landlord paid the money back', 'returned_on' => '2026-11-08'];
        $this->actingAs($checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/reverse', $correction)->assertUnprocessable();
        $this->actingAs($third)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/reverse', $correction)->assertOk();
        $this->postJson('/api/v1/operations/company-funds/spends/'.$id.'/reverse', $correction)->assertUnprocessable();

        $this->assertSame($before, $this->balance('company_profit'));
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid']);
        $this->assertSame(1, DB::table('fund_spend_reversals')->where('disbursement_id', $id)->count());
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'fund.spend_reversed.'.$id)->count());
        foreach (['company_fund_expense', 'manual_payout_clearing'] as $account) {
            $net = DB::table('journal_lines')->join('journal_entries as e', 'e.id', '=', 'journal_lines.entry_id')
                ->whereIn('e.event_key', ['fund.spend.'.$id, 'fund.spend_reversed.'.$id])->where('account', $account)->selectRaw('SUM(debit - credit) AS net')->value('net');
            $this->assertEquals(0, $net, $account);
        }
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
        $checks = app(FinancialReadiness::class)->checks();
        $this->assertTrue($checks['fund_spend_journals']);
        $this->assertTrue($checks['fund_balances']);
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.spend_reversed', 'subject' => (string) $id]);
    }

    public function test_money_that_came_back_restores_the_company_share(): void
    {
        [$maker, $checker] = $this->financeStaff();
        $before = $this->balance('company_share');
        $this->assertGreaterThan(1000, $before);
        $id = $this->spend($maker, ['fund' => 'company_share', 'amount' => '10.00'])->assertOk()->json('data.id');
        $this->actingAs($checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Bill checked'])->assertOk();
        $this->assertSame($before - 1000, $this->balance('company_share'));

        $owner = User::factory()->staff('super_admin')->create();
        $this->actingAs($owner)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/reverse', ['reason' => 'Paid twice, the shop gave it back', 'returned_on' => '2026-11-08'])->assertOk();
        $this->assertSame($before, $this->balance('company_share'));
        $this->assertTrue(app(FinancialReadiness::class)->checks()['fund_spend_journals']);
    }

    public function test_the_owner_withdraws_a_spend_left_waiting_and_old_spends_show_on_money_tasks(): void
    {
        [$maker, $other] = $this->financeStaff();
        $owner = User::factory()->staff('super_admin')->create();
        $this->fundRow('company_profit_fund', '1000.00');
        $old = $this->spend($maker, ['amount' => '40.00'])->assertOk()->json('data.id');
        $this->assertSame(0, app(MoneyTaskFigures::class)->staleSpends()['count']);

        $this->travel(8)->days();
        $stale = app(MoneyTaskFigures::class)->staleSpends();
        $this->assertSame(1, $stale['count']);
        $this->assertSame('40.00', $stale['amount']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/work')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('approvals_table.rows.data.0.waiting_days', 8)->where('approvals_table.rows.data.0.can_cancel', true)
                ->where('tiles', fn ($tiles) => collect($tiles)->contains(fn ($tile) => $tile['key'] === 'spends_overdue' && $tile['count'] === 1)));

        $this->actingAs($other)->postJson('/api/v1/operations/company-funds/spends/'.$old.'/cancel', ['reason' => 'Waiting too long'])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/v1/operations/company-funds/spends/'.$old.'/cancel', ['reason' => 'Waiting too long'])->assertOk();
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $old, 'status' => 'cancelled']);
        $audit = json_decode((string) DB::table('audit_events')->where('action', 'fund.spend_cancelled')->where('subject', (string) $old)->value('metadata'), true);
        $this->assertTrue($audit['cancelled_by_owner']);
        $this->assertSame(0, app(MoneyTaskFigures::class)->staleSpends()['count']);
    }

    /** @return array{0: User, 1: User} */
    private function financeStaff(): array
    {
        return [User::factory()->staff('finance')->create(), User::factory()->staff('finance')->create()];
    }

    /** @param array<string, mixed> $overrides */
    private function spend(User $actor, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->post('/api/v1/operations/company-funds/spends', [
            'fund' => 'company_profit',
            'expense_head_id' => (int) DB::table('fund_expense_heads')->where('name_en', 'Office rent')->value('id'),
            'amount' => '100.00',
            'spent_on' => '2026-11-07',
            'payee' => 'Landlord',
            'payment_method' => 'cash',
            'note' => 'Monthly payment',
            'request_key' => (string) Str::uuid(),
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    private function balance(string $fund): int
    {
        return app(CompanyFunds::class)->balances()[$fund]['balance'];
    }

    private function fundRow(string $category, string $amount): int
    {
        return DB::table('commission_allocations')->insertGetId([
            'order_id' => $this->order->id, 'category' => $category, 'rate_percentage' => '0.00', 'cash_basis' => '1000.00',
            'amount' => $amount, 'original_amount' => $amount, 'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postedOrder(): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create(User::factory()->create(['role' => 'sub_dealer']), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '120.00']);
        app(CommissionPosting::class)->process($order);

        return $order;
    }
}

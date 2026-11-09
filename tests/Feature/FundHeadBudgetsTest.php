<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\AccountingStatements;
use App\Domain\Finance\FundBudgets;
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
 * A budget on an expense head warns when a spend goes over and never stops it. The approval
 * flow here is the real one, so the report's reading of fund spends is checked against it too.
 */
class FundHeadBudgetsTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $this->order = $this->postedOrder();
        $this->maker = User::factory()->staff('finance')->create();
        $this->checker = User::factory()->staff('finance')->create();
        $this->fundRow('company_profit_fund', '100000.00');
    }

    public function test_a_spend_over_budget_warns_but_is_still_saved(): void
    {
        $this->budget('Office rent', '2026-11', '150.00');

        $within = $this->spend(['amount' => '100.00'])->assertOk();
        $this->assertNull($within->json('warning'));

        $over = $this->spend(['amount' => '75.50'])->assertOk();

        $this->assertSame(__('funds.budget_over_warning', [
            'head' => 'Office rent', 'month' => '2026-11', 'budget' => '150.00', 'used' => '175.50', 'over' => '25.50',
        ]), $over->json('warning'));
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $over->json('data.id'), 'status' => 'requested']);
    }

    public function test_the_page_redirect_carries_the_warning_for_the_screen(): void
    {
        $this->budget('Office rent', '2026-11', '10.00');

        $this->actingAs($this->maker)->onPortal('manage')->post('/commands/operations/company-funds/spends', $this->payload(['amount' => '75.00']))
            ->assertRedirect()->assertSessionHas('warning')->assertSessionHas('success');
    }

    public function test_approving_a_spend_over_budget_warns_too_and_still_posts(): void
    {
        $this->budget('Office rent', '2026-11', '50.00');
        $id = (int) $this->spend(['amount' => '75.00'])->json('data.id');

        $review = $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the bill'])->assertOk();

        $this->assertNotNull($review->json('warning'));
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid']);
    }

    public function test_no_budget_and_another_month_give_no_warning(): void
    {
        $this->assertNull($this->spend(['amount' => '75.00'])->json('warning'));

        $this->budget('Office rent', '2026-10', '1.00');

        $this->assertNull($this->spend(['amount' => '75.00'])->json('warning'));
    }

    public function test_rejected_and_cancelled_spends_do_not_use_up_a_budget(): void
    {
        $this->budget('Office rent', '2026-11', '100.00');
        $id = (int) $this->spend(['amount' => '90.00'])->json('data.id');
        $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Wrong amount on bill'])->assertOk();

        $this->assertNull($this->spend(['amount' => '90.00'])->json('warning'));
    }

    public function test_a_budget_can_be_changed_and_cleared_and_is_logged(): void
    {
        $this->budget('Office rent', '2026-11', '150.00');
        $this->budget('Office rent', '2026-11', '250.00');

        $headId = $this->headId('Office rent');
        $this->assertSame(1, DB::table('fund_head_budgets')->where('expense_head_id', $headId)->count());
        $this->assertSame('250.00', (string) DB::table('fund_head_budgets')->where('expense_head_id', $headId)->value('amount'));

        $this->budget('Office rent', '2026-11', '');

        $this->assertSame(0, DB::table('fund_head_budgets')->where('expense_head_id', $headId)->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.budget_saved', 'subject' => $headId.':2026-11']);
    }

    public function test_the_budget_list_shows_used_left_and_over(): void
    {
        $this->budget('Office rent', '2026-11', '150.00');
        $this->spend(['amount' => '100.00']);
        $this->spend(['amount' => '75.00']);

        $row = app(FundBudgets::class)->forMonth('2026-11')[0];

        $this->assertSame('175.00', $row['used']);
        $this->assertSame('0.00', $row['left']);
        $this->assertTrue($row['over']);

        $this->actingAs($this->maker)->onPortal('manage')->get('/treasury/work?tab=budgets&period=custom&from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('budgets_table.rows.data.0.used', '175.00'));
        $this->actingAs($this->checker)->onPortal('manage')->get('/treasury/work')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('approvals_table.rows.data.0.over_budget', true));
    }

    public function test_the_owners_own_fund_is_not_counted_against_a_budget(): void
    {
        $this->budget('Office rent', '2026-11', '10.00');
        $id = DB::table('reserve_disbursements')->insertGetId([
            'program' => 'company_spend', 'fund_key' => 'benefits', 'expense_head_id' => $this->headId('Office rent'), 'payee_name' => 'Owner', 'amount' => '75.00',
            'spent_on' => '2026-11-07', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'reason' => 'Private', 'status' => 'requested', 'created_by' => $this->maker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(app(FundBudgets::class)->warningFor($id));
        $this->assertSame([], app(FundBudgets::class)->overBudgetKeys());
    }

    public function test_staff_outside_finance_cannot_set_a_budget(): void
    {
        $this->actingAs(User::factory()->staff('sr')->create())
            ->postJson('/api/v1/operations/company-funds/budgets', ['expense_head_id' => $this->headId('Office rent'), 'month' => '2026-11', 'amount' => '10.00'])
            ->assertForbidden();
    }

    public function test_the_real_approval_flow_shows_a_head_once_and_keeps_the_statements_tied(): void
    {
        $id = (int) $this->spend(['amount' => '125.00'])->json('data.id');
        $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the bill'])->assertOk();

        $statements = app(AccountingStatements::class)->build('2026-11-01', '2026-11-30');
        $expenses = collect($statements['profit_loss']['expenses'])->keyBy('account');

        $this->assertSame('125.00', $expenses['head_'.$this->headId('Office rent')]['amount']);
        $this->assertFalse($expenses->has('company_fund_expense'), 'The journal line and the head must not both appear.');
        $this->assertTrue($statements['checks']['ties_to_journal']);
        $this->assertTrue($statements['checks']['result_reconciled']);
        $this->assertSame('0.00', $statements['balance_sheet']['difference']);
    }

    private function budget(string $head, string $month, string $amount): void
    {
        $this->actingAs($this->maker)->postJson('/api/v1/operations/company-funds/budgets', ['expense_head_id' => $this->headId($head), 'month' => $month, 'amount' => $amount])->assertOk();
    }

    /** @param array<string, string> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'fund' => 'company_profit',
            'expense_head_id' => $this->headId('Office rent'),
            'amount' => '100.00',
            'spent_on' => '2026-11-07',
            'payee' => 'Landlord',
            'payment_method' => 'cash',
            'note' => 'Monthly payment',
            'request_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    /** @param array<string, string> $overrides */
    private function spend(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->maker)->post('/api/v1/operations/company-funds/spends', $this->payload($overrides), ['Accept' => 'application/json']);
    }

    private function headId(string $name): int
    {
        return (int) DB::table('fund_expense_heads')->where('name_en', $name)->value('id');
    }

    private function fundRow(string $category, string $amount): int
    {
        return DB::table('commission_allocations')->insertGetId([
            'order_id' => $this->order->id, 'category' => $category, 'rate_percentage' => '0.00', 'cash_basis' => '1000.00', 'amount' => $amount, 'original_amount' => $amount,
            'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
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

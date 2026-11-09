<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
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
use Tests\TestCase;

/**
 * A spend entered with a mistake is withdrawn or refused, then entered again correctly. The
 * bank reference belongs to the real payment, so the second entry must be able to use it.
 */
class FundSpendReferenceAfterRefusalTest extends TestCase
{
    use RefreshDatabase;

    private const Reference = 'BANK-778899';

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
        $this->fundRow($this->postedOrder(), '5000.00');
        $this->maker = User::factory()->staff('finance')->create();
        $this->checker = User::factory()->staff('super_admin')->create();
    }

    public function test_a_reference_is_free_again_after_the_spend_is_withdrawn(): void
    {
        $id = $this->spend(['amount' => '100.00'])->assertOk()->json('data.id');
        $this->actingAs($this->maker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/cancel', ['reason' => 'Typed the wrong amount'])->assertOk();

        $this->spend(['amount' => '150.00'])->assertOk();

        $this->assertSame(1, DB::table('reserve_disbursements')->where('payment_reference', self::Reference)->where('status', 'requested')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.spend_cancelled', 'subject' => (string) $id]);
        $this->assertStringContainsString(self::Reference, (string) DB::table('audit_events')->where('action', 'fund.spend_cancelled')->where('subject', (string) $id)->value('metadata'));
    }

    public function test_a_reference_is_free_again_after_the_spend_is_refused(): void
    {
        $id = $this->spend(['amount' => '100.00'])->assertOk()->json('data.id');
        $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'The amount does not match the bill'])->assertOk();

        $this->spend(['amount' => '150.00'])->assertOk();

        $this->assertStringContainsString(self::Reference, (string) DB::table('audit_events')->where('action', 'fund.spend_rejected')->where('subject', (string) $id)->value('metadata'));
    }

    public function test_a_reference_on_a_waiting_or_paid_spend_stays_taken(): void
    {
        $id = $this->spend(['amount' => '100.00'])->assertOk()->json('data.id');
        $this->spend(['amount' => '150.00'])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->actingAs($this->checker)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the bill'])->assertOk();

        $this->spend(['amount' => '150.00'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid', 'payment_reference' => self::Reference]);
    }

    /** @param array<string, mixed> $overrides */
    private function spend(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->maker)->post('/api/v1/operations/company-funds/spends', [
            'fund' => 'company_profit',
            'expense_head_id' => (int) DB::table('fund_expense_heads')->where('name_en', 'Office rent')->value('id'),
            'amount' => '100.00',
            'spent_on' => '2026-11-07',
            'payee' => 'Landlord',
            'payment_method' => 'bank',
            'reference' => self::Reference,
            'note' => 'Monthly payment',
            'request_key' => (string) Str::uuid(),
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    private function fundRow(Order $order, string $amount): void
    {
        DB::table('commission_allocations')->insert([
            'order_id' => $order->id,
            'category' => 'company_profit_fund',
            'rate_percentage' => '0.00',
            'cash_basis' => '1000.00',
            'amount' => $amount,
            'original_amount' => $amount,
            'status' => 'reserved',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
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

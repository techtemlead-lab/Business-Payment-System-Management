<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Owner's rule: dealers and sub-dealers buy goods only from the company. A
 * dealer never sells to a sub-dealer, and stock never moves between partners.
 */
class CompanyOnlySalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_dealer_order_is_always_sold_and_shipped_by_a_company_branch(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create(['sponsor_id' => $dealer->id]);
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);

        $orderId = $this->placeSubDealerOrder($subDealer, $product);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame($subDealer->id, (int) $order->user_id);
        $this->assertNull($order->fulfillment_branch_id);
        foreach (['seller_id', 'seller_user_id', 'dealer_id', 'supplier_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('orders', $column), "orders.$column must not exist: the company is the only seller.");
        }

        $this->approveAndDispatch($staff, $orderId);

        $order->refresh();
        $branch = CompanyBranch::query()->find($order->fulfillment_branch_id);
        $this->assertNotNull($branch, 'A dispatched order must name the company branch that sold it.');
        $this->assertDatabaseHas('shipments', ['order_id' => $orderId, 'branch_id' => $branch->id]);
        $this->assertSame(4, (int) BranchProductStock::query()
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('quantity'));
    }

    public function test_order_cannot_name_a_dealer_or_branch_as_the_seller(): void
    {
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create(['sponsor_id' => $dealer->id]);
        $product = Product::factory()->create(['stock' => 5]);
        $hub = CompanyBranch::query()->firstOrFail();

        foreach (['seller_id' => $dealer->id, 'dealer_id' => $dealer->id, 'sponsor_id' => $dealer->id, 'branch_id' => $hub->id] as $field => $value) {
            $this->actingAs($subDealer)->onPortal('subdealer')->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_dealer_cannot_dispatch_a_sub_dealer_order_or_move_company_stock(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create(['sponsor_id' => $dealer->id]);
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $hub = CompanyBranch::query()->firstOrFail();

        $orderId = $this->placeSubDealerOrder($subDealer, $product);
        $this->approve($staff, $orderId);
        $line = DB::table('order_lines')->where('order_id', $orderId)->value('id');

        $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/orders/'.$orderId.'/dispatch', [
            'courier' => 'Dealer van',
            'tracking' => 'D2S-1',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertForbidden();

        $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => 1,
            'reason' => 'sold to my sub-dealer',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('shipments', 0);
        $this->assertNull(Order::query()->findOrFail($orderId)->fulfillment_branch_id);
        $movementsBefore = DB::table('stock_movements')->count();

        foreach ([$dealer, $subDealer] as $partner) {
            try {
                app(BranchOperations::class)->adjustStock($partner, [
                    'product_id' => $product->id,
                    'quantity' => -1,
                    'reason' => 'partner transfer',
                    'reference' => (string) Str::uuid(),
                ]);
                $this->fail('A partner must never move company stock.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    private function placeSubDealerOrder(User $subDealer, Product $product): string
    {
        $orderId = (string) $this->actingAs($subDealer)->onPortal('subdealer')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $total = (string) Order::query()->findOrFail($orderId)->total;
        $this->actingAs($subDealer)->onPortal('subdealer')->postJson('/api/v1/orders/'.$orderId.'/payments', [
            'channel' => 'Bank',
            'reference' => 'COMPANY-ONLY-'.Str::upper(Str::random(6)),
            'amount' => $total,
            'proof' => UploadedFile::fake()->image('payment-proof.jpg'),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        return $orderId;
    }

    private function approve(User $staff, string $orderId): void
    {
        $claim = DB::table('payment_claims')->where('order_id', $orderId)->value('id');
        $this->actingAs($staff)->onPortal('manage')->postJson('/api/v1/claims/'.$claim.'/verify', [
            'approve' => true,
            'reason' => 'Matched bank statement',
        ])->assertOk();
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$orderId.'/decision', [
            'status' => 'approved',
            'reason' => 'Payment confirmed',
        ])->assertOk();
    }

    private function approveAndDispatch(User $staff, string $orderId): void
    {
        $this->approve($staff, $orderId);
        $line = DB::table('order_lines')->where('order_id', $orderId)->value('id');
        $this->actingAs($staff)->onPortal('manage')->postJson('/api/v1/orders/'.$orderId.'/dispatch', [
            'courier' => 'Company courier',
            'tracking' => 'CO-TRACK-1',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertOk();
    }
}

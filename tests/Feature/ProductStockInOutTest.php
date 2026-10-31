<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductStockInOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_in_increases_stock_with_return_reason_and_idempotent_reference(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $actor = User::factory()->staff('inventory')->create(['active_branch_id' => $hub->id]);
        $product = Product::factory()->create(['stock' => 4]);
        $reference = (string) Str::uuid();

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 6,
            'reason' => 'return',
            'reference' => $reference,
            'branch_id' => $hub->id,
        ])->assertOk()->assertJsonPath('data.stock', 10);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'branch_id' => $hub->id,
            'quantity' => 6,
            'kind' => 'product_in',
            'reason' => 'return',
            'reference' => $reference,
        ]);
        $this->assertSame(10, (int) BranchProductStock::query()
            ->where('branch_id', $hub->id)
            ->where('product_id', $product->id)
            ->value('quantity'));

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 6,
            'reason' => 'return',
            'reference' => $reference,
            'branch_id' => $hub->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_product_out_decreases_stock_and_rejects_underflow_or_negative_quantity(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $actor = User::factory()->staff('inventory')->create(['active_branch_id' => $hub->id]);
        $product = Product::factory()->create(['stock' => 5]);

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => 2,
            'reason' => 'damage',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ])->assertOk()->assertJsonPath('data.stock', 3);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity' => -2,
            'kind' => 'product_out',
            'reason' => 'damage',
        ]);

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => 0,
            'reason' => 'write_off',
            'reference' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => -1,
            'reason' => 'write_off',
            'reference' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => 9,
            'reason' => 'write_off',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_product_in_rejects_out_reason_codes_and_commands_route_records_flash(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $actor = User::factory()->staff('inventory')->create(['active_branch_id' => $hub->id]);
        $product = Product::factory()->create(['stock' => 1]);

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 2,
            'reason' => 'damage',
            'reference' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason_code');

        $this->from('/inventory')->post('/commands/stock', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 3,
            'reason' => 'supplier_receipt',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ])->assertRedirect('/inventory')->assertSessionHas('success', __('portal.flash_stock_in_recorded'));

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'kind' => 'product_in',
            'quantity' => 3,
            'reason' => 'supplier_receipt',
        ]);
        $this->assertSame(4, $product->fresh()->stock);
    }

    public function test_product_in_accepts_expires_on_only_when_column_exists(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $actor = User::factory()->staff('inventory')->create(['active_branch_id' => $hub->id]);
        $product = Product::factory()->create(['stock' => 0]);
        $expiresOn = now()->addMonths(6)->toDateString();

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 4,
            'reason' => 'opening',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'expires_on' => $expiresOn,
        ])->assertOk()->assertJsonPath('data.stock', 4);

        if (Schema::hasColumn('products', 'expires_on')) {
            $this->assertSame($expiresOn, optional($product->fresh()->expires_on)?->toDateString() ?? $product->fresh()->expires_on);
        } else {
            $this->assertSame(4, $product->fresh()->stock);
            $this->assertFalse(Schema::hasColumn('products', 'expires_on'));
        }
    }
}

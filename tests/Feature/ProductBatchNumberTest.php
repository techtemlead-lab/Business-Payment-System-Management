<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductBatchNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_catalog_staff_can_set_and_clear_the_batch_number_in_the_product_form(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['batch_number' => null]);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/products/'.$product->id, ['version' => (string) $product->fresh()->registry_version, 'batch_number' => 'B-2026-11', 'reason' => 'New batch arrived'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('B-2026-11', $product->fresh()->batch_number);

        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ProductEditor')->where('product.batch_number', 'B-2026-11'));

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/products/'.$product->id, ['version' => (string) $product->fresh()->registry_version, 'batch_number' => '', 'reason' => 'Batch not known'])->assertRedirect();
        $this->assertNull($product->fresh()->batch_number);

        $this->actingAs(User::factory()->create(['role' => 'inventory']))->onPortal('manage')
            ->patch('/commands/products/'.$product->id, ['version' => (string) $product->fresh()->registry_version, 'batch_number' => 'X', 'reason' => 'Not my job'])
            ->assertForbidden();
        $this->assertNull($product->fresh()->batch_number);
    }

    public function test_stock_received_with_a_batch_number_sets_the_current_batch(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $actor = User::factory()->staff('inventory')->create(['active_branch_id' => $hub->id]);
        $product = Product::factory()->create(['stock' => 5, 'batch_number' => 'OLD-1']);

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 10,
            'reason' => 'supplier_receipt',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'batch_number' => ' NEW-7 ',
        ])->assertOk();
        $this->assertSame('NEW-7', $product->fresh()->batch_number);

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 1,
            'reason' => 'supplier_receipt',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ])->assertOk();
        $this->assertSame('NEW-7', $product->fresh()->batch_number, 'Receiving without a batch keeps the current one.');

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => 1,
            'reason' => 'damage',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'batch_number' => 'IGNORED',
        ])->assertOk();
        $this->assertSame('NEW-7', $product->fresh()->batch_number, 'Stock going out never changes the batch.');
    }

    public function test_stock_list_shows_the_batch_number(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['active' => true, 'batch_number' => 'LIST-9', 'expires_on' => '2027-03-31']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products', fn ($products) => collect($products)->firstWhere('id', $product->id)['batch_number'] === 'LIST-9'));
    }

    public function test_invoice_shows_the_batch_and_expiry_that_were_current_when_the_order_was_placed(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'active' => true, 'stock' => 50,
            'batch_number' => 'SOLD-1', 'expires_on' => '2027-05-31']);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $product->forceFill(['batch_number' => 'LATER-2', 'expires_on' => '2028-01-31'])->save();

        $line = app(OrderInvoice::class)->payload($order->fresh(['user', 'lines']), 'dealer')['lines'][0];

        $this->assertSame('SOLD-1', $line['batch_number']);
        $this->assertSame('31/05/2027', $line['expires_on']);

        $html = $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id.'/invoice/print')->assertOk()->getContent();
        $this->assertStringContainsString(__('portal.inv_batch', ['batch' => 'SOLD-1']), $html);
    }
}

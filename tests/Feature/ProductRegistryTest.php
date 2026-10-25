<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function data(): array
    {
        return ['name' => 'Synthetic Product', 'sku' => 'TEST-REGISTRY', 'category' => 'Test Category', 'unit' => 'pack', 'description' => 'Synthetic product information', 'mrp' => '200.00', 'dealer_price' => '140.00', 'cost_price' => '57.13', 'active' => true, 'featured' => true, 'low_stock_at' => 12, 'reason' => 'Synthetic registry verification', 'video_source' => 'none'];
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('product.png', file_get_contents(public_path('madina-header-logo.png')));
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('product.webm', file_get_contents(base_path('docs/qa/fixtures/product-demo.webm')));
    }

    private function product(): Product
    {
        return Product::create(collect($this->data())->except(['reason'])->all());
    }

    public function test_creation_uploads_photo_and_stores_youtube_video_without_exposing_cost(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->post('/commands/products', [...$this->data(), 'image' => $this->photo(), 'video_source' => 'youtube', 'youtube_url' => 'https://youtu.be/M7lc1UVf-VE'])->assertRedirect();
        $p = Product::first();
        $this->assertSame('M7lc1UVf-VE', $p->video_youtube_id);
        Storage::disk('local')->assertExists($p->image_path);
        auth()->logout();
        $this->get('/products/'.$p->id)->assertOk()->assertSee('www.youtube-nocookie.com/embed/M7lc1UVf-VE', false)->assertDontSee('57.13');
        $this->get('/products/'.$p->id.'/image')->assertOk();
        $this->get('/')->assertSee($p->name)->assertDontSee('57.13');
        $this->actingAs(User::factory()->create(['role' => 'dealer']))->get('/workspace/catalog')->assertInertia(fn (Assert $page) => $page->missing('data.products.0.cost_price'));
    }

    public function test_media_permissions_and_direct_stock_changes_are_enforced(): void
    {
        $p = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'dealer']))->patch('/commands/products/'.$p->id, ['version' => '0', 'name' => 'Unauthorized', 'reason' => 'Wrong scope'])->assertForbidden();
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->patch('/commands/products/'.$p->id, ['version' => '0', 'dealer_price' => '100', 'reason' => 'Price override'])->assertForbidden();
        $this->patch('/commands/products/'.$p->id, ['version' => '0', 'cost_price' => '2', 'reason' => 'Cost override'])->assertForbidden();
        $this->patch('/commands/products/'.$p->id, ['version' => '0', 'name' => 'Edited Description', 'image' => $this->photo(), 'reason' => 'Approved photo update'])->assertRedirect();
        $this->assertSame('140.00', $p->fresh()->dealer_price);
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->patch('/commands/products/'.$p->id, ['version' => '1', 'low_stock_at' => 25, 'reason' => 'Revised stock threshold'])->assertRedirect();
        $this->assertSame(25, $p->fresh()->low_stock_at);
        $this->patch('/commands/products/'.$p->id, ['version' => '2', 'stock' => 999, 'reason' => 'Bypass stock history'])->assertForbidden();
        $this->assertSame(0, $p->fresh()->stock);
    }

    public function test_uploaded_video_is_playable_response_and_replaced_media_is_removed(): void
    {
        $p = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patch('/commands/products/'.$p->id, ['version' => '0', 'video_source' => 'upload', 'video' => $this->video(), 'reason' => 'Upload product demonstration'])->assertRedirect();
        $p->refresh();
        $old = $p->video_path;
        $this->assertSame('video/webm', $p->video_mime);
        auth()->logout();
        $this->get('/products/'.$p->id.'/video')->assertOk()->assertHeader('Content-Type', 'video/webm');
        $this->get('/products/'.$p->id)->assertSee('<video controls', false);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patch('/commands/products/'.$p->id, ['version' => '1', 'video_source' => 'youtube', 'youtube_url' => 'https://www.youtube.com/shorts/M7lc1UVf-VE', 'reason' => 'Use the approved channel video'])->assertRedirect();
        Storage::disk('local')->assertMissing($old);
        $this->assertNull($p->fresh()->video_path);
        $this->patch('/commands/products/'.$p->id, ['version' => '2', 'video_source' => 'none', 'reason' => 'Remove product demonstration'])->assertRedirect();
        $this->assertNull($p->fresh()->video_source);
    }

    public function test_untrusted_embeds_invalid_files_and_stale_edits_do_not_change_product(): void
    {
        $p = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        foreach (['javascript:alert(1)', 'https://youtube.com.evil.test/watch?v=M7lc1UVf-VE', '<iframe src="https://evil.test"></iframe>'] as $url) {
            $this->patch('/commands/products/'.$p->id, ['version' => '0', 'video_source' => 'youtube', 'youtube_url' => $url, 'reason' => 'Invalid video validation'])->assertSessionHasErrors('youtube_url');
        }
        $this->patch('/commands/products/'.$p->id, ['version' => '0', 'image' => UploadedFile::fake()->createWithContent('bad.png', '<?php echo 1;'), 'reason' => 'Invalid photo validation'])->assertSessionHasErrors('image');
        $this->patch('/commands/products/'.$p->id, ['version' => '0', 'video_source' => 'upload', 'video' => UploadedFile::fake()->create('large.webm', 102401, 'video/webm'), 'reason' => 'Oversized video validation'])->assertSessionHasErrors('video');
        $this->patch('/commands/products/'.$p->id, ['version' => '0', 'name' => 'New Name', 'reason' => 'First permitted correction'])->assertRedirect();
        $this->patch('/commands/products/'.$p->id, ['version' => '0', 'name' => 'Stale Name', 'image' => $this->photo(), 'reason' => 'Stale concurrent correction'])->assertSessionHasErrors('version');
        $this->assertSame('New Name', $p->fresh()->name);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_hidden_media_and_cost_are_only_available_to_authorized_staff(): void
    {
        $p = $this->product();
        $p->update(['active' => false]);
        $this->get('/products/'.$p->id)->assertNotFound();
        $this->get('/products/'.$p->id.'/image')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get('/company/products/'.$p->id)->assertInertia(fn (Assert $page) => $page->missing('product.cost_price'));
        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/company/products/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('product.cost_price', '57.13'));
    }

    public function test_price_edit_does_not_rewrite_previous_order_snapshot(): void
    {
        $p = $this->product();
        $owner = User::factory()->create(['role' => 'dealer']);
        $order = app(OrderService::class)->create($owner, ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patch('/commands/products/'.$p->id, ['version' => '0', 'dealer_price' => '150.00', 'reason' => 'Future purchase price update'])->assertRedirect();
        $this->assertSame('140.00', (string) DB::table('order_lines')->where('order_id', $order->id)->value('unit_price'));
        $this->assertSame('150.00', $p->fresh()->dealer_price);
    }

    public function test_complete_editor_payload_can_replace_video_and_save_repeatedly(): void
    {
        $p = $this->product();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patch('/commands/products/'.$p->id, [...$this->data(), 'version' => '0', 'video_source' => 'upload', 'video' => $this->video()])->assertRedirect();
        $this->patch('/commands/products/'.$p->id, [...$this->data(), 'version' => '1', 'video_source' => 'none', 'active' => false])->assertRedirect();
        $this->assertFalse($p->fresh()->active);
        $this->assertSame(2, $p->fresh()->registry_version);
    }

    public function test_product_deletion_deletes_unpurchased_and_archives_ordered_product(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create(['role' => 'dealer']);

        // 1. Unauthorized role cannot delete product
        $p1 = $this->product();
        $this->actingAs($dealer)->delete('/commands/products/'.$p1->id, ['reason' => 'Unauthorized'])->assertForbidden();

        // 2. Unordered product is completely deleted
        $this->actingAs($admin)->delete('/commands/products/'.$p1->id, ['reason' => 'Obsolete product'])
            ->assertRedirect('/catalog')
            ->assertSessionHas('success', __('portal.flash_product_deleted'));
        $this->assertNull(Product::find($p1->id));

        // 3. Product with order history is safely deactivated/archived to preserve financial records
        $p2 = $this->product();
        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $p2->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($admin)->delete('/commands/products/'.$p2->id, ['reason' => 'Discontinue with history'])
            ->assertRedirect('/catalog')
            ->assertSessionHas('success', __('portal.flash_product_archived'));

        $freshP2 = Product::find($p2->id);
        $this->assertNotNull($freshP2);
        $this->assertFalse($freshP2->active);
        $this->assertFalse($freshP2->featured);

        // Historical order lines remain completely untouched
        $this->assertSame($p2->id, DB::table('order_lines')->where('order_id', $order->id)->value('product_id'));
    }

    public function test_inventory_staff_can_pause_ordering_without_changing_stock_and_records_an_audit_trail(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create(['role' => 'dealer']);
        $product = $this->product();
        $product->update(['stock' => 20]);

        // 1. Unauthorized dealer cannot toggle stock
        $this->actingAs($dealer)->post('/commands/products/'.$product->id.'/toggle-stock')->assertForbidden();

        // Pause ordering while preserving the counted inventory.
        $this->actingAs($admin)
            ->from('/catalog')
            ->post('/commands/products/'.$product->id.'/toggle-stock')
            ->assertRedirect('/catalog')
            ->assertSessionHas('success', __('portal.flash_product_ordering_paused'));

        $product->refresh();
        $this->assertSame(20, $product->stock);
        $this->assertTrue($product->is_stock_paused);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'stock.quick_toggle',
            'subject' => (string) $product->id,
        ]);

        // Resume ordering without creating an artificial stock receipt.
        $this->actingAs($admin)
            ->from('/inventory')
            ->post('/commands/products/'.$product->id.'/toggle-stock')
            ->assertRedirect('/inventory')
            ->assertSessionHas('success', __('portal.flash_product_ordering_resumed'));

        $product->refresh();
        $this->assertSame(20, $product->stock);
        $this->assertFalse($product->is_stock_paused);

        $this->assertDatabaseCount('stock_movements', 0);
    }
}

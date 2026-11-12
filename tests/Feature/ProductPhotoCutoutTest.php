<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPhotoCutoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function productData(): array
    {
        return ['name' => 'Synthetic Product', 'sku' => 'TEST-CUTOUT', 'category' => 'Test Category', 'unit' => 'pack', 'description' => 'Synthetic product information', 'mrp' => '200.00', 'dealer_price' => '140.00', 'active' => true, 'featured' => true, 'low_stock_at' => 12, 'reason' => 'Synthetic photo verification', 'video_source' => 'none'];
    }

    private function photo(string $name = 'product.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(public_path('madina-header-logo.png')));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function productWithCutout(): Product
    {
        $this->actingAs($this->admin())->post('/commands/products', [...$this->productData(), 'image' => $this->photo('cutout.png'), 'image_original' => $this->photo('original.png')])->assertRedirect();

        return Product::query()->where('sku', 'TEST-CUTOUT')->firstOrFail();
    }

    public function test_a_photo_with_its_background_removed_keeps_the_original(): void
    {
        $product = $this->productWithCutout();

        $this->assertStringStartsWith('product-media/images/', $product->image_path);
        $this->assertStringStartsWith('product-media/originals/', $product->image_original_path);
        Storage::disk('local')->assertExists($product->image_path);
        Storage::disk('local')->assertExists($product->image_original_path);
    }

    public function test_the_original_photo_can_be_brought_back(): void
    {
        $product = $this->productWithCutout();
        [$cutout, $original] = [$product->image_path, $product->image_original_path];

        $this->patch('/commands/products/'.$product->id, ['version' => (string) $product->registry_version, 'restore_original_image' => '1', 'reason' => 'Prefer the original photo'])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame($original, $product->image_path);
        $this->assertNull($product->image_original_path);
        Storage::disk('local')->assertMissing($cutout);
        Storage::disk('local')->assertExists($original);
    }

    public function test_removing_the_photo_also_removes_the_kept_original(): void
    {
        $product = $this->productWithCutout();
        [$cutout, $original] = [$product->image_path, $product->image_original_path];

        $this->patch('/commands/products/'.$product->id, ['version' => (string) $product->registry_version, 'remove_image' => '1', 'reason' => 'Photo no longer wanted'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($product->refresh()->image_original_path);
        Storage::disk('local')->assertMissing($cutout);
        Storage::disk('local')->assertMissing($original);
    }

    public function test_uploading_a_new_photo_replaces_the_kept_original(): void
    {
        $product = $this->productWithCutout();
        $original = $product->image_original_path;

        $this->patch('/commands/products/'.$product->id, ['version' => (string) $product->registry_version, 'image' => $this->photo('new.png'), 'reason' => 'Newer product photo'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($product->refresh()->image_original_path);
        Storage::disk('local')->assertMissing($original);
    }

    public function test_an_original_cannot_be_sent_without_the_new_photo(): void
    {
        $this->actingAs($this->admin())->post('/commands/products', [...$this->productData(), 'image_original' => $this->photo('original.png')])->assertSessionHasErrors('image_original');

        $this->assertDatabaseMissing('products', ['sku' => 'TEST-CUTOUT']);
    }

    public function test_a_product_without_a_kept_original_cannot_restore_one(): void
    {
        $product = Product::create(collect($this->productData())->except(['reason'])->all());

        $this->actingAs($this->admin())->patch('/commands/products/'.$product->id, ['version' => '0', 'restore_original_image' => '1', 'reason' => 'Prefer the original photo'])->assertSessionHasErrors('restore_original_image');
    }

    public function test_deleting_a_product_removes_its_kept_original(): void
    {
        $product = $this->productWithCutout();
        $original = $product->image_original_path;

        $this->delete('/commands/products/'.$product->id, ['reason' => 'Created by mistake'])->assertRedirect();

        Storage::disk('local')->assertMissing($original);
    }

    public function test_only_staff_who_can_edit_the_catalog_can_fetch_the_model(): void
    {
        Storage::disk('local')->put('product-photo-model/cutout/config.json', '{}');
        $url = '/commands/product-photo-model/cutout/config.json';

        $this->actingAs(User::factory()->create(['role' => 'editor']))->get($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->get($url)->assertForbidden();
    }

    public function test_only_the_files_of_the_model_can_be_fetched(): void
    {
        Storage::disk('local')->put('product-photo-model/cutout/secret.txt', 'private');

        $this->actingAs($this->admin())->get('/commands/product-photo-model/cutout/secret.txt')->assertNotFound();
    }

    public function test_a_server_without_the_model_says_it_is_not_there(): void
    {
        $this->actingAs($this->admin())->get('/commands/product-photo-model/cutout/config.json')->assertNotFound();
    }

    public function test_the_office_portal_allows_the_photo_tool_and_the_public_site_does_not(): void
    {
        $this->onPortal('manage')->get('/login')->assertHeader('Content-Security-Policy');
        $office = $this->onPortal('manage')->get('/login')->headers->get('Content-Security-Policy');
        $public = $this->onPortal('public')->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'wasm-unsafe-eval'", $office);
        $this->assertStringNotContainsString("'wasm-unsafe-eval'", $public);
    }

    public function test_the_install_command_saves_files_that_match(): void
    {
        config(['madina.product_photo_model.files' => ['config.json' => hash('sha256', 'expected')]]);
        Http::fake(['huggingface.co/*' => Http::response('expected')]);

        $this->artisan('madina:install-product-photo-model')->assertSuccessful();

        Storage::disk('local')->assertExists('product-photo-model/cutout/config.json');
    }

    public function test_the_install_command_refuses_a_file_that_does_not_match(): void
    {
        config(['madina.product_photo_model.files' => ['config.json' => hash('sha256', 'expected')]]);
        Http::fake(['huggingface.co/*' => Http::response('something else')]);

        $this->artisan('madina:install-product-photo-model')->assertFailed();

        Storage::disk('local')->assertMissing('product-photo-model/cutout/config.json');
        Storage::disk('local')->assertMissing('product-photo-model/cutout/config.json.partial');
    }

    public function test_the_full_size_file_is_downloaded_only_when_asked_for(): void
    {
        config([
            'madina.product_photo_model.files' => ['config.json' => hash('sha256', 'expected')],
            'madina.product_photo_model.full_precision_files' => ['onnx/model.onnx' => hash('sha256', 'full size')],
        ]);
        Http::fake([
            'huggingface.co/*/config.json' => Http::response('expected'),
            'huggingface.co/*/onnx/model.onnx' => Http::response('full size'),
        ]);

        $this->artisan('madina:install-product-photo-model')->assertSuccessful();
        Storage::disk('local')->assertExists('product-photo-model/cutout/config.json');
        Storage::disk('local')->assertMissing('product-photo-model/cutout/onnx/model.onnx');

        $this->artisan('madina:install-product-photo-model', ['--full-precision' => true])->assertSuccessful();
        Storage::disk('local')->assertExists('product-photo-model/cutout/onnx/model.onnx');
    }

    public function test_the_full_size_file_that_does_not_match_is_refused(): void
    {
        config([
            'madina.product_photo_model.files' => ['config.json' => hash('sha256', 'expected')],
            'madina.product_photo_model.full_precision_files' => ['onnx/model.onnx' => hash('sha256', 'full size')],
        ]);
        Http::fake([
            'huggingface.co/*/config.json' => Http::response('expected'),
            'huggingface.co/*/onnx/model.onnx' => Http::response('something else'),
        ]);

        $this->artisan('madina:install-product-photo-model', ['--full-precision' => true])->assertFailed();

        Storage::disk('local')->assertMissing('product-photo-model/cutout/onnx/model.onnx');
    }

    public function test_the_browser_can_ask_whether_the_full_size_file_is_on_the_server(): void
    {
        $url = '/commands/product-photo-model/cutout/onnx/model.onnx';
        $this->actingAs($this->admin())->call('HEAD', $url)->assertNotFound();

        Storage::disk('local')->put('product-photo-model/cutout/onnx/model.onnx', 'full size');

        $this->actingAs($this->admin())->call('HEAD', $url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->call('HEAD', $url)->assertForbidden();
    }

    public function test_each_reason_the_tool_cannot_run_has_a_plain_message_that_offers_the_photo_as_it_is(): void
    {
        foreach (['ppc_unsupported', 'ppc_no_graphics', 'ppc_low_memory', 'ppc_tool_missing', 'ppc_no_connection', 'ppc_failed'] as $key) {
            $bangla = __('portal.'.$key, [], 'bn');
            $english = __('portal.'.$key, [], 'en');

            $this->assertNotSame('portal.'.$key, $bangla, $key.' has no Bangla words.');
            $this->assertNotSame('portal.'.$key, $english, $key.' has no English words.');
            $this->assertNotSame($english, $bangla, $key.' must be translated.');
            $this->assertStringContainsString('যেমন আছে তেমনই আপলোড', $bangla, $key);
            $this->assertStringContainsString('as it is', $english, $key);
        }
    }
}

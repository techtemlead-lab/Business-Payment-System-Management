<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPhotoChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function photo(string $file): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($file, file_get_contents(public_path($file)));
    }

    private function productWithPhoto(): Product
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->post('/commands/products', [
            'name' => 'Photo Product',
            'sku' => 'TEST-PHOTO',
            'category' => 'Test Category',
            'unit' => 'pack',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'active' => true,
            'reason' => 'Synthetic photo setup',
            'video_source' => 'none',
            'image' => $this->photo('madina-header-logo.png'),
        ])->assertRedirect();

        return Product::query()->where('sku', 'TEST-PHOTO')->firstOrFail();
    }

    private function servedPhoto(Product $product): string
    {
        return $this->get('/products/'.$product->id.'/image')->assertOk()->baseResponse->getFile()->getContent();
    }

    /** @return array<string, mixed> */
    private function photoOnlyChange(Product $product): array
    {
        return ['version' => (string) $product->registry_version, 'reason' => 'Product photo changed', 'image' => $this->photo('madina-header-logo.jpg')];
    }

    public function test_changing_only_the_photo_saves_and_the_new_photo_is_served_everywhere(): void
    {
        $product = $this->productWithPhoto();
        $oldPath = $product->image_path;
        $oldUrl = $product->media()['image_url'];
        $oldPhoto = $this->servedPhoto($product);
        Cache::put('public:featured_products:v2', ['a list with the old photo'], 120);

        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->patch('/commands/products/'.$product->id, $this->photoOnlyChange($product))
            ->assertRedirect('/products/'.$product->id)
            ->assertSessionHasNoErrors();

        $changed = $product->fresh();
        $this->assertNotSame($oldPath, $changed->image_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertNotSame($oldPhoto, $this->servedPhoto($changed));
        $this->assertSame(file_get_contents(public_path('madina-header-logo.jpg')), $this->servedPhoto($changed));
        $this->assertNotSame($oldUrl, $changed->media()['image_url']);
        $this->assertFalse(Cache::has('public:featured_products:v2'));
        $this->onPortal('public')->get('/products/'.$product->id)->assertOk()->assertSee($changed->media()['image_url'], false);
        $this->assertSame('Photo Product', $changed->name);
        $this->assertSame('140.00', $changed->dealer_price);
    }

    public function test_an_old_price_does_not_stop_a_photo_change(): void
    {
        $product = $this->productWithPhoto();
        $product->forceFill(['dealer_price' => '250.00'])->save();

        $this->patch('/commands/products/'.$product->id, $this->photoOnlyChange($product))
            ->assertRedirect('/products/'.$product->id)
            ->assertSessionHasNoErrors();

        $this->assertSame(file_get_contents(public_path('madina-header-logo.jpg')), $this->servedPhoto($product->fresh()));
        $this->assertSame('250.00', $product->fresh()->dealer_price);
    }

    public function test_someone_without_catalog_rights_cannot_change_the_photo(): void
    {
        $product = $this->productWithPhoto();
        $photoPath = $product->image_path;

        $this->actingAs(User::factory()->create(['role' => 'inventory']))
            ->patch('/commands/products/'.$product->id, $this->photoOnlyChange($product))
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'dealer']))
            ->patch('/commands/products/'.$product->id, $this->photoOnlyChange($product))
            ->assertForbidden();

        $this->assertSame($photoPath, $product->fresh()->image_path);
        $this->assertSame($product->registry_version, $product->fresh()->registry_version);
    }
}

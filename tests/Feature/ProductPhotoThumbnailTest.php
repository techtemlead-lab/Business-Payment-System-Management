<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductPhotoThumbnail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPhotoThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function productWithBigPhoto(bool $transparent = false, bool $active = true): Product
    {
        $canvas = imagecreatetruecolor(1600, 1200);
        if ($transparent) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 10, 120, 40, 127));
        } else {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 10, 120, 40));
        }
        ob_start();
        $transparent ? imagepng($canvas) : imagejpeg($canvas, null, 95);
        $bytes = (string) ob_get_clean();
        $path = 'product-images/big-'.($transparent ? 'cutout.png' : 'photo.jpg');
        Storage::disk('local')->put($path, $bytes);

        return Product::factory()->create(['image_path' => $path, 'active' => $active]);
    }

    public function test_a_list_asks_for_a_small_copy_that_the_browser_keeps(): void
    {
        $product = $this->productWithBigPhoto();

        $response = $this->get('/products/'.$product->id.'/image?v=1&w=480')->assertOk();

        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $copy = $response->baseResponse->getFile()->getPathname();
        $size = getimagesize($copy);
        $this->assertSame(480, $size[0]);
        $this->assertSame(360, $size[1]);
        $this->assertLessThan(filesize(Storage::disk('local')->path($product->image_path)), filesize($copy));
    }

    public function test_the_full_photo_is_still_sent_when_no_width_is_asked(): void
    {
        $product = $this->productWithBigPhoto();

        $response = $this->get('/products/'.$product->id.'/image?v=1')->assertOk();

        $this->assertSame(
            Storage::disk('local')->path($product->image_path),
            $response->baseResponse->getFile()->getPathname(),
        );
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_width_that_is_not_on_the_list_gets_the_full_photo_and_builds_nothing(): void
    {
        $product = $this->productWithBigPhoto();

        $response = $this->get('/products/'.$product->id.'/image?v=1&w=37')->assertOk();

        $this->assertSame(
            Storage::disk('local')->path($product->image_path),
            $response->baseResponse->getFile()->getPathname(),
        );
        $this->assertSame([], Storage::disk('local')->allFiles('product-thumbnails'));
    }

    public function test_a_cut_out_photo_keeps_its_see_through_background(): void
    {
        $product = $this->productWithBigPhoto(transparent: true);

        $copy = $this->get('/products/'.$product->id.'/image?v=1&w=480')->assertOk()->baseResponse->getFile()->getPathname();

        $this->assertSame(IMAGETYPE_PNG, getimagesize($copy)[2]);
        $corner = imagecolorat(imagecreatefrompng($copy), 0, 0);
        $this->assertGreaterThan(0, ($corner >> 24) & 0x7F, 'the corner must stay see-through');
    }

    public function test_a_new_photo_version_replaces_the_old_copy(): void
    {
        $product = $this->productWithBigPhoto();
        $this->get('/products/'.$product->id.'/image?v=1&w=480')->assertOk();
        $first = Storage::disk('local')->allFiles('product-thumbnails/'.$product->id);

        $product->forceFill(['registry_version' => $product->registry_version + 1])->save();
        $this->get('/products/'.$product->id.'/image?v=2&w=480')->assertOk();
        $second = Storage::disk('local')->allFiles('product-thumbnails/'.$product->id);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertNotSame($first, $second);
    }

    public function test_a_hidden_product_is_not_shown_to_a_visitor_and_is_never_kept_publicly(): void
    {
        $product = $this->productWithBigPhoto(active: false);

        $this->get('/products/'.$product->id.'/image?v=1&w=480')->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('product-thumbnails'));
    }

    public function test_the_card_address_asks_for_the_small_copy_only_for_a_product_photo(): void
    {
        $this->assertSame('/products/7/image?v=3&w=480', ProductPhotoThumbnail::address('/products/7/image?v=3'));
        $this->assertSame('/products/7/image?w=480', ProductPhotoThumbnail::address('/products/7/image'));
        $this->assertSame('/branding/banner.jpg', ProductPhotoThumbnail::address('/branding/banner.jpg'));
        $this->assertSame('', ProductPhotoThumbnail::address(''));
        $this->assertSame('/products/7/image?v=3', ProductPhotoThumbnail::address('/products/7/image?v=3', 999));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The browser makes a large product photo smaller before sending it. The server's own limits stay as they were. */
class ProductPhotoSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    /** A PNG that says it has this width and height and weighs this many kilobytes. */
    private function photo(int $width, int $height, int $kilobytes): UploadedFile
    {
        $header = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header));

        return UploadedFile::fake()->createWithContent('photo.png', str_pad($png, $kilobytes * 1024, "\0"));
    }

    private function addProductWith(UploadedFile $photo): TestResponse
    {
        return $this->actingAs(User::factory()->create(['role' => 'super_admin']))->post('/commands/products', [
            'name' => 'Size Limit Product',
            'sku' => 'TEST-SIZE',
            'category' => 'Test Category',
            'unit' => 'pack',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'active' => true,
            'reason' => 'Synthetic photo size check',
            'video_source' => 'none',
            'image' => $photo,
        ]);
    }

    public function test_a_photo_at_the_size_the_browser_shrinks_to_is_saved(): void
    {
        $this->addProductWith($this->photo(4096, 2731, 5120))->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'TEST-SIZE')->firstOrFail();
        Storage::disk('local')->assertExists((string) $product->image_path);
    }

    public function test_the_server_still_refuses_a_photo_that_is_too_wide_too_tall_or_too_heavy(): void
    {
        foreach ([[4097, 100, 10], [100, 4097, 10], [100, 100, 5121]] as [$width, $height, $kilobytes]) {
            $this->addProductWith($this->photo($width, $height, $kilobytes))->assertSessionHasErrors('image');
        }

        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_browser_shrinks_to_the_same_limits_the_server_checks(): void
    {
        $server = (string) file_get_contents(app_path('Http/Controllers/ProductRegistryController.php'));
        $browser = (string) file_get_contents(resource_path('js/shared/productPhotoFit.ts'));

        $this->assertSame(2, substr_count($server, 'max:5120|dimensions:max_width=4096,max_height=4096'), 'The photo and its original share one limit.');
        $this->assertStringContainsString('largestPhotoBytes = 5120 * 1024;', $browser);
        $this->assertStringContainsString('longestPhotoSide = 4096;', $browser);
    }

    public function test_a_photo_that_cannot_be_made_smaller_is_told_the_size_that_is_accepted(): void
    {
        foreach (['ppc_photo_making_smaller', 'ppc_photo_made_smaller', 'ppc_photo_too_large'] as $key) {
            $bangla = __('portal.'.$key, [], 'bn');
            $english = __('portal.'.$key, [], 'en');

            $this->assertNotSame('portal.'.$key, $bangla, $key.' has no Bangla words.');
            $this->assertNotSame('portal.'.$key, $english, $key.' has no English words.');
            $this->assertNotSame($english, $bangla, $key.' must be translated.');
        }

        foreach (['bn', 'en'] as $locale) {
            $line = __('portal.ppc_photo_too_large', ['megabytes' => 5, 'pixels' => 4096], $locale);

            $this->assertStringContainsString('5 MB', $line);
            $this->assertStringContainsString('4096 × 4096', $line);
        }
    }
}

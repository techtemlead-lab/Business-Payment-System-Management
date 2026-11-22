<?php

namespace Tests\Feature;

use App\Domain\Inventory\PdfPhotos;
use App\Domain\Inventory\ProductListRows;
use App\Models\Product;
use App\Support\StockImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pictures in the printed product list: the product's own photo whatever its format, the
 * original upload when the processed photo cannot be read, the default picture only as a last resort.
 */
class PdfPhotosTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $created = [];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = Storage::disk('local')->path('pdf-photo-test');
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_a_jpeg_photo_is_printed_as_itself_not_as_the_default_picture(): void
    {
        $this->assertPrintedAsRed($this->store('product-media/images/a.jpg', 'jpeg'));
    }

    public function test_a_png_photo_is_printed_as_itself_not_as_the_default_picture(): void
    {
        $this->assertPrintedAsRed($this->store('product-media/images/a.png', 'png'));
    }

    public function test_a_webp_photo_is_printed_as_itself_when_the_image_library_reads_webp(): void
    {
        if (! function_exists('imagecreatefromwebp') || ! (gd_info()['WebP Support'] ?? false)) {
            $this->markTestSkipped('This PHP image library (GD) was built without WebP support; the production image now installs it.');
        }

        $this->assertPrintedAsRed($this->store('product-media/images/a.webp', 'webp'));
    }

    public function test_an_unreadable_file_falls_back_to_the_default_picture_and_the_products_are_listed_in_the_log(): void
    {
        Storage::disk('local')->put('product-media/images/broken.jpg', 'this is not a picture');
        Log::spy();

        $rows = $this->thumbnails([['product_id' => 41, 'category' => 'Syrup', 'image' => Storage::disk('local')->path('product-media/images/broken.jpg')]]);

        $this->assertNotNull($rows[0]['image'], 'The default picture is printed instead.');
        $this->assertFalse($this->isRed($rows[0]['image']), 'This is the default picture, not a red photo.');
        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('notice')->withArgs(fn (string $message, array $context): bool => ($context['product_ids'] ?? null) === [41])->once();
    }

    public function test_the_original_upload_is_used_when_the_processed_photo_cannot_be_read(): void
    {
        Storage::disk('local')->put('product-media/images/processed.png', 'broken processed copy');
        $this->store('product-media/originals/original.jpg', 'jpeg');
        $broken = Storage::disk('local')->path('product-media/images/processed.png');

        $rows = $this->thumbnails([['product_id' => 7, 'category' => null, 'image' => $broken, 'image_fallbacks' => PdfPhotos::fallbacksFor('product-media/originals/original.jpg', $broken)]]);

        $this->assertRed($rows[0]['image']);
        $this->assertSame([Storage::disk('local')->path('product-media/originals/original.jpg')], PdfPhotos::fallbacksFor('product-media/originals/original.jpg', $broken));
    }

    public function test_the_list_rows_carry_the_original_upload_and_use_it_when_the_processed_file_is_gone(): void
    {
        $this->store('product-media/originals/o.jpg', 'jpeg');
        $this->store('product-media/images/p.jpg', 'jpeg');
        Product::factory()->create(['name' => 'Both Files', 'active' => true, 'image_path' => 'product-media/images/p.jpg', 'image_original_path' => 'product-media/originals/o.jpg']);
        Product::factory()->create(['name' => 'Only Original', 'active' => true, 'image_path' => 'product-media/images/gone.jpg', 'image_original_path' => 'product-media/originals/o.jpg']);

        $rows = array_column(app(ProductListRows::class)->build()['rows'], null, 'name');

        $this->assertSame(Storage::disk('local')->path('product-media/images/p.jpg'), $rows['Both Files']['image']);
        $this->assertSame([Storage::disk('local')->path('product-media/originals/o.jpg')], $rows['Both Files']['image_fallbacks']);
        $this->assertSame(Storage::disk('local')->path('product-media/originals/o.jpg'), $rows['Only Original']['image']);
        $this->assertSame([], $rows['Only Original']['image_fallbacks']);
    }

    public function test_a_product_without_a_photo_prints_the_default_picture_of_its_category(): void
    {
        $rows = $this->thumbnails([['product_id' => 3, 'category' => 'Syrup', 'image' => StockImage::pdfFileForCategory('Syrup')]]);

        $this->assertNotNull($rows[0]['image']);
        $this->assertFalse($this->isRed($rows[0]['image']), 'This is the default picture, not a red photo.');
    }

    /**
     * A solid red 300 x 200 picture saved in the given format; returns the stored path.
     */
    private function store(string $path, string $format): string
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 0, 0));
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 90),
            default => imagejpeg($image, null, 95),
        };
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function assertPrintedAsRed(string $storedPath): void
    {
        $rows = $this->thumbnails([['product_id' => 1, 'category' => 'Syrup', 'image' => Storage::disk('local')->path($storedPath)]]);

        $this->assertRed($rows[0]['image']);
        $this->assertSame(130, $rows[0]['image_width']);
        $this->assertSame(87, $rows[0]['image_height']);
    }

    private function assertRed(string $file): void
    {
        $this->assertTrue($this->isRed($file), 'The own red photo of the product is printed.');
    }

    private function isRed(string $file): bool
    {
        [$red, $green, $blue] = $this->centre($file);

        return $red > 200 && $green < 60 && $blue < 60;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function thumbnails(array $rows): array
    {
        $created = [];
        $result = PdfPhotos::withThumbnails($rows, $this->dir, $created);
        $this->created = [...$this->created, ...$created];

        return $result;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function centre(string $file): array
    {
        $image = imagecreatefromjpeg($file);
        $rgb = imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2));

        return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
    }
}

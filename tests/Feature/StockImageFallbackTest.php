<?php

namespace Tests\Feature;

use App\Models\HealthArticle;
use App\Models\Product;
use App\Support\SiteCustomization;
use App\Support\StockImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StockImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_product_without_a_photo_gets_the_category_picture_and_the_caption(): void
    {
        $product = Product::factory()->create(['category' => 'Fruit Syrup', 'image_path' => null, 'active' => true]);

        $this->get('/products/'.$product->id)->assertOk()
            ->assertSee('/branding/stock/honey.webp', false)
            ->assertSee(__('stock_images.caption'))
            ->assertDontSee(__('site.photo_coming_soon'));

        $this->get('/products')->assertOk()
            ->assertSee('/branding/stock/honey.webp', false)
            ->assertSee(__('stock_images.caption'))
            ->assertDontSee('luxury-art-bottle', false);
    }

    public function test_product_with_a_real_photo_shows_it_with_no_caption(): void
    {
        $product = Product::factory()->create(['category' => 'Fruit Syrup', 'image_path' => 'products/real.jpg', 'active' => true]);

        foreach (['/products/'.$product->id, '/products'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('/products/'.$product->id.'/image', false)
                ->assertDontSee('/branding/stock/', false)
                ->assertDontSee(__('stock_images.caption'));
        }
    }

    public function test_the_caption_follows_the_site_language(): void
    {
        app()->setLocale('bn');
        $this->assertSame('প্রতীকী ছবি', StockImage::forCategory('Oil')['caption']);

        app()->setLocale('en');
        $this->assertSame('Representative image', StockImage::forCategory('Oil')['caption']);
    }

    public function test_alt_text_names_the_product_and_says_it_is_representative(): void
    {
        app()->setLocale('en');

        $alt = StockImage::forCategory('Oil', 'Taskin Pain Oil')['alt'];

        $this->assertStringContainsString('Taskin Pain Oil', $alt);
        $this->assertStringContainsString('representative image', $alt);
    }

    #[TestWith(['Fruit Syrup', 'honey'])]
    #[TestWith(['Superfood', 'dates'])]
    #[TestWith(['Oil', 'black-seed'])]
    #[TestWith(['Ayurvedic', 'herbs'])]
    #[TestWith(['Herbal', 'herbs'])]
    #[TestWith(['Cosmecics', 'aloe'])]
    #[TestWith(['Botanical', 'leaves'])]
    #[TestWith(['Wellness', 'leaves'])]
    #[TestWith(['ভেষজ', 'herbs'])]
    #[TestWith(['Physio', 'brand'])]
    #[TestWith(['Something new', 'brand'])]
    #[TestWith(['', 'brand'])]
    public function test_each_category_maps_to_the_expected_picture(string $category, string $image): void
    {
        $this->assertSame('/branding/stock/'.$image.'.webp', StockImage::forCategory($category)['url']);
    }

    public function test_every_picture_exists_at_a_sensible_size_with_alt_text_in_both_languages(): void
    {
        foreach (StockImage::allImageNames() as $image) {
            $path = public_path('branding/stock/'.$image.'.webp');

            $this->assertFileExists($path);
            $this->assertLessThanOrEqual(150 * 1024, filesize($path), $image.' is too heavy');

            [$width, $height] = getimagesize($path);
            $this->assertSame([StockImage::Width, StockImage::Height], [$width, $height], $image);

            $key = 'stock_images.subject_'.str_replace('-', '_', $image);
            foreach (['en', 'bn'] as $locale) {
                $this->assertNotSame($key, trans($key, [], $locale), $image.' has no '.$locale.' alt text');
            }
        }
    }

    public function test_a_video_thumbnail_set_in_the_cms_is_left_alone(): void
    {
        SiteCustomization::save([
            'video_gallery_enabled' => '1',
            'video_gallery_items' => [
                ['title' => 'Owner video', 'subtitle' => '', 'video_url' => '/videos/owner.mp4', 'video_embed_url' => '', 'thumbnail' => '/branding/madina-top-skincare.jpg'],
            ],
        ]);

        $this->get('/')->assertOk()
            ->assertSee('poster="/branding/madina-top-skincare.jpg"', false)
            ->assertDontSee('poster="/branding/stock/', false);
    }

    public function test_an_article_image_set_in_the_cms_wins_and_a_blank_one_gets_the_default(): void
    {
        HealthArticle::factory()->create(['title' => 'Owner picture', 'image_url' => '/storage/articles/owner.jpg']);
        HealthArticle::factory()->create(['title' => 'No picture', 'image_url' => null]);

        $this->get('/articles')->assertOk()
            ->assertSee('/storage/articles/owner.jpg', false)
            ->assertSee('/branding/stock/herbs.webp', false);
    }
}

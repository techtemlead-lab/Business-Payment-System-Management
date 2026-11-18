<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * With the production switch on, the app still checks who may see a private file, then hands the
 * download to nginx (X-Accel-Redirect) so a slow phone does not hold a PHP worker.
 */
class NginxProtectedFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['madina.nginx_protected_files' => true]);
    }

    public function test_a_product_photo_is_handed_to_nginx_with_its_cache_rule_and_no_body(): void
    {
        Storage::disk('local')->put('product-images/tonic photo.jpg', 'jpeg-bytes');
        $product = Product::factory()->create(['image_path' => 'product-images/tonic photo.jpg', 'active' => true]);

        $response = $this->get('/products/'.$product->id.'/image')->assertOk();

        $response->assertHeader('X-Accel-Redirect', '/_protected/product-images/tonic%20photo.jpg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('', $response->getContent());
    }

    public function test_a_hidden_product_photo_is_still_refused_before_nginx_is_asked(): void
    {
        Storage::disk('local')->put('product-images/old.jpg', 'jpeg-bytes');
        $product = Product::factory()->create(['image_path' => 'product-images/old.jpg', 'active' => false]);

        $this->get('/products/'.$product->id.'/image')->assertNotFound()->assertHeaderMissing('X-Accel-Redirect');
    }

    public function test_an_uploaded_video_keeps_its_own_type(): void
    {
        Storage::disk('local')->put('product-videos/demo.mp4', 'mp4-bytes');
        $product = Product::factory()->create(['active' => true, 'video_source' => 'upload', 'video_path' => 'product-videos/demo.mp4', 'video_mime' => 'video/mp4']);

        $this->get('/products/'.$product->id.'/video')->assertOk()
            ->assertHeader('X-Accel-Redirect', '/_protected/product-videos/demo.mp4')
            ->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_the_photo_model_goes_through_nginx_only_for_catalog_staff(): void
    {
        Storage::disk('local')->put('product-photo-model/cutout/config.json', '{}');
        $url = '/commands/product-photo-model/cutout/config.json';

        $this->actingAs(User::factory()->create(['role' => 'editor']))->get($url)->assertOk()
            ->assertHeader('X-Accel-Redirect', '/_protected/product-photo-model/cutout/config.json');
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->get($url)->assertForbidden()->assertHeaderMissing('X-Accel-Redirect');
    }

    public function test_with_the_switch_off_php_sends_the_file_itself(): void
    {
        config(['madina.nginx_protected_files' => false]);
        Storage::disk('local')->put('product-images/tonic.jpg', 'jpeg-bytes');
        $product = Product::factory()->create(['image_path' => 'product-images/tonic.jpg', 'active' => true]);

        $response = $this->get('/products/'.$product->id.'/image')->assertOk()->assertHeaderMissing('X-Accel-Redirect');

        $this->assertSame('jpeg-bytes', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }
}

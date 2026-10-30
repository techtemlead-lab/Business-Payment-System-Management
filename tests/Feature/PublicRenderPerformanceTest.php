<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicRenderPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_information_pages_render_without_dealer_directory_work(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/about')->assertOk();
        $aboutQueries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringNotContainsString('directory_visible', $aboutQueries);

        DB::flushQueryLog();
        $this->get('/contact')->assertOk();
        $contactQueries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringNotContainsString('directory_visible', $contactQueries);
    }

    public function test_site_customization_is_cached_across_reads(): void
    {
        Cache::flush();
        SiteCustomization::forgetCache();

        $first = SiteCustomization::all();
        $this->assertIsArray($first);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = SiteCustomization::all();
        $this->assertSame($first, $second);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_public_layout_defers_font_awesome_and_skips_bn_font_for_english(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString("fontawesome-local.css\" media=\"print\" onload=\"this.media='all'\"", $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringContainsString('/madina-header-logo.png?v=9', $html);
        $this->assertStringContainsString('rel="preload" as="image"', $html);
    }

    public function test_home_and_products_recover_from_poisoned_category_cache(): void
    {
        Product::factory()->create(['active' => true, 'category' => 'Herbal Oils', 'name' => 'Neem Oil']);

        // Simulate the production failure: a Support Collection was written to the
        // database cache and later unserialized into an unusable value.
        Cache::put('public:product_categories:v2', collect(['Herbal Oils']), 300);
        Cache::put('public:featured_products:v2', collect([]), 120);

        $this->get('/')->assertOk()->assertSee('Neem Oil', false);
        $this->get('/products')->assertOk()->assertSee('Herbal Oils');

        $this->assertIsArray(Cache::get('public:product_categories:v2'));
        $this->assertIsArray(Cache::get('public:featured_products:v2'));
        $this->assertContains('Herbal Oils', Cache::get('public:product_categories:v2'));
    }

    public function test_product_category_cache_is_reused_as_plain_array(): void
    {
        Product::factory()->create(['active' => true, 'category' => 'Tonics', 'name' => 'Ashwagandha']);

        Cache::flush();
        $this->get('/products')->assertOk()->assertSee('Tonics');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/products')->assertOk()->assertSee('Tonics');

        $categoryQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'category')
        );
        $this->assertCount(0, $categoryQueries);
        $this->assertSame(['Tonics'], Cache::get('public:product_categories:v2'));
    }
}

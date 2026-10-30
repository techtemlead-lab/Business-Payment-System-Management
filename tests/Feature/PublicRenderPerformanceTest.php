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

    public function test_public_layout_loads_local_font_awesome_and_preloads_header_logo(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('/reference/fontawesome-local.css', $html);
        $this->assertStringNotContainsString('fonts.bunny.net/css?family=noto-sans-bengali', $html);
        $this->assertStringContainsString('/madina-header-logo.png?v=9', $html);
        $this->assertStringContainsString('rel="preload" as="image"', $html);
    }

    public function test_public_layout_includes_progressive_first_paint_boot_shell(): void
    {
        $home = $this->get('/')->assertOk()->getContent();
        $login = $this->get('/login')->assertOk()->getContent();

        foreach ([$home, $login] as $html) {
            $this->assertStringContainsString('id="refPageProgress"', $html);
            $this->assertStringContainsString('id="refPageBoot"', $html);
            $this->assertStringContainsString('ref-booting', $html);
            $this->assertStringContainsString(__('site.loading_experience'), $html);
        }

        $this->assertStringContainsString('ref-deferred-paint', $home);
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

    public function test_homepage_skips_unused_product_pagination_and_reuses_public_caches(): void
    {
        Product::factory()->create(['active' => true, 'category' => 'Oils', 'name' => 'Mustard Oil', 'featured' => true]);

        Cache::flush();
        SiteCustomization::forgetCache();

        $this->get('/')->assertOk()->assertSee('Mustard Oil', false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql): string => strtolower($sql));
        $this->assertFalse(
            $queries->contains(fn (string $sql): bool => str_contains($sql, 'from "products"') && str_contains($sql, 'count(*)')),
            'Homepage should not run a products COUNT pagination query.',
        );
        $this->assertFalse(
            $queries->contains(fn (string $sql): bool => str_contains($sql, 'from "site_feedback"')),
            'Warm homepage should reuse cached testimonials.',
        );
        $this->assertFalse(
            $queries->contains(fn (string $sql): bool => str_contains($sql, 'homepage_video')),
            'Warm homepage should reuse cached homepage video settings.',
        );
    }
}

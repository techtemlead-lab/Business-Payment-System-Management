<?php

namespace Tests\Feature;

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
        $this->assertStringContainsString('/madina-header-logo.png?v=7', $html);
        $this->assertStringContainsString('rel="preload" as="image"', $html);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared fonts partial must be compiled by Blade, not printed as text: two directives written back to back
 * (`@endunless@include(...)`) are not recognised, and the directive showed on top of every page.
 */
class FontsPartialRenderedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_no_page_layout_prints_a_blade_directive_as_text(): void
    {
        foreach (['dealer', 'manage', 'sadmin', 'public'] as $portal) {
            $html = $this->onPortal($portal)->get('/login')->getContent();
            $this->assertStringNotContainsString('@include(', $html, "A Blade directive is printed as text on the {$portal} page.");
            $this->assertStringNotContainsString('@endunless', $html, "A Blade directive is printed as text on the {$portal} page.");
        }
        $home = $this->onPortal('public')->get('/')->getContent();
        $this->assertStringNotContainsString('@include(', $home);
        $this->assertStringContainsString('fonts.bunny.net', $home);
    }

    public function test_every_blade_directive_has_a_boundary_before_it(): void
    {
        // A directive glued to a word before it (for example `@endunless@include`) is not compiled.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }
            if (preg_match('/@end(?:unless|if|foreach|section|php)@(?:include|vite|yield)/', (string) file_get_contents((string) $file))) {
                $offenders[] = substr((string) $file, strlen(resource_path('views')) + 1);
            }
        }
        $this->assertSame([], $offenders, 'Directives written back to back are not compiled by Blade.');
    }
}

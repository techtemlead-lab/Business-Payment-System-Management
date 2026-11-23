<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards for the phone-width public website styles: the stylesheets exist, are loaded by the public
 * and sign-in pages, keep their changes inside the phone media query, and the markup hooks they need exist.
 */
class MobilePublicStylesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_phone_stylesheet_is_imported_and_scoped_to_phones(): void
    {
        $css = file_get_contents(resource_path('css/mobile-public.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString('@import \'./mobile-public.css\';', (string) file_get_contents(resource_path('css/reference-home.css')));

        // Outside the phone query only the rule hiding the phone-only controls may exist.
        $outside = preg_replace('/@media \(max-width: 767px\)\s*\{.*\}\s*$/s', '', $css);
        $outside = preg_replace('/\/\*.*?\*\//s', '', (string) $outside);
        $outside = preg_replace('/@media \(min-width: 768px\)\s*\{.*?\}\s*\}/s', '', (string) $outside);
        $this->assertSame('', trim((string) $outside), 'mobile-public.css must keep its rules inside the phone media query');
    }

    public function test_sign_in_phone_stylesheet_is_loaded_by_the_auth_styles(): void
    {
        $this->assertFileExists(resource_path('css/mobile-public-auth.css'));
        $this->assertStringContainsString("@import './mobile-public-auth.css';", (string) file_get_contents(resource_path('css/auth-motion.css')));
        $this->assertStringContainsString('@media (max-width: 767px)', (string) file_get_contents(resource_path('css/mobile-public-auth.css')));
    }

    public function test_markup_hooks_used_by_the_phone_styles_exist(): void
    {
        $views = resource_path('views/reference/');
        $this->assertStringContainsString('data-filter-sheet-open', (string) file_get_contents($views.'catalog.blade.php'));
        $this->assertStringContainsString('data-mobile-fold', (string) file_get_contents($views.'information.blade.php'));
        $this->assertStringContainsString('ref-apply-sticky', (string) file_get_contents($views.'information.blade.php'));
        $this->assertStringContainsString('ref-product-sticky', (string) file_get_contents($views.'product.blade.php'));
        $this->assertStringContainsString('data-company-pass', (string) file_get_contents(resource_path('views/company/auth.blade.php')));
    }

    public function test_new_phone_labels_exist_in_both_languages(): void
    {
        foreach (['mobile_nav_about_short', 'contact_action_call', 'contact_action_whatsapp', 'contact_action_email', 'partnership_apply_now', 'catalog_filter_open', 'catalog_filter_show'] as $key) {
            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame('site.'.$key, __('site.'.$key, [], $locale), "site.$key missing in $locale");
            }
        }
    }

    public function test_the_bottom_bar_marks_the_page_you_are_on(): void
    {
        $home = $this->onPortal('public')->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="ref-mobile-home"[^>]*aria-current="page"/', (string) $home);

        $about = $this->onPortal('public')->get('/about')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="ref-mobile-nav-item" aria-current="page"/', (string) $about);
        $this->assertDoesNotMatchRegularExpression('/class="ref-mobile-home"[^>]*aria-current="page"/', (string) $about);
    }
}

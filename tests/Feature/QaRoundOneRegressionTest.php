<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Guards the fixes from the full-page check. These are layout and browser details that a
 * feature test cannot draw, so the screens were checked in a real browser; these tests stop
 * the same mistakes from coming back.
 */
class QaRoundOneRegressionTest extends TestCase
{
    public function test_the_link_underline_effect_stays_off_the_button_tiles(): void
    {
        $css = (string) file_get_contents(resource_path('css/reference-motion.css'));
        $underlineSelectors = collect(explode("\n", $css))
            ->filter(fn (string $line): bool => str_contains($line, 'background-image:linear-gradient(currentColor,currentColor)') || str_contains($line, 'background-size:100% 1px'))
            ->map(fn (string $line): string => strstr($line, '{', true) ?: $line);

        $this->assertCount(2, $underlineSelectors, 'The underline effect should still be set up for ordinary text links.');

        foreach ($underlineSelectors as $selector) {
            // Those rules set background-image and background-size, which wiped out the
            // green background of the main button on the information pages.
            $this->assertStringNotContainsString('.ref-information-links', $selector);
            $this->assertStringContainsString('.ref-footer-links a', $selector);
        }

        $tiles = (string) file_get_contents(resource_path('css/reference-pages.css'));
        $this->assertMatchesRegularExpression('/\.ref-information-links>a\.ref-primary-button\{\s*background:linear-gradient/', $tiles);
    }

    public function test_toolbar_buttons_wrap_instead_of_running_off_a_phone_screen(): void
    {
        $css = (string) file_get_contents(resource_path('css/dashboard.css'));

        $this->assertMatchesRegularExpression('/\.order-filters \.order-filter-actions\{[^}]*flex-wrap:wrap/', $css);
    }

    public function test_office_tables_wrap_to_fit_a_laptop_and_keep_status_and_actions_on_screen(): void
    {
        $css = (string) file_get_contents(resource_path('css/screens-office.css'));

        // Headings that stayed on one line pushed Status and Manage off the right edge at 1366px.
        $this->assertMatchesRegularExpression('/\.table-block :is\(th,th\.is-num\)\{white-space:normal\}/', $css);
        $this->assertMatchesRegularExpression('/\.inline-actions\{flex-wrap:wrap/', $css);

        // A table that still cannot fit pins Status and the buttons, and says it scrolls.
        $this->assertMatchesRegularExpression('/\.is-overflowing :is\(th,td\):is\(\.table-pin-status,\.table-pin-action\)\{position:sticky/', $css);

        $table = (string) file_get_contents(resource_path('js/components/UI.tsx'));
        $this->assertStringContainsString('table-pin-action', $table);
        $this->assertStringContainsString("translate('portal', 'table_scroll_hint')", $table);

        foreach (['en', 'bn'] as $locale) {
            $this->assertArrayHasKey('table_scroll_hint', require lang_path($locale.'/portal.php'));
        }
    }

    public function test_the_phone_top_bar_keeps_room_for_a_readable_page_title(): void
    {
        $css = (string) file_get_contents(resource_path('css/screens-phone-topbar.css'));

        $this->assertStringContainsString("@import './screens-phone-topbar.css';", (string) file_get_contents(resource_path('css/dashboard.css')));
        $this->assertMatchesRegularExpression('/@media\(max-width:767px\)/', $css);
        $this->assertMatchesRegularExpression('/\.portal-website-link\{display:none\}/', $css);
        $this->assertMatchesRegularExpression('/-webkit-line-clamp:2/', $css);
        $this->assertMatchesRegularExpression('/white-space:normal/', $css);

        // The website link leaves the phone top bar only because it stays in the profile menu.
        $shell = (string) file_get_contents(resource_path('js/components/Shell.tsx'));
        $this->assertMatchesRegularExpression('/role="menuitem"[^\n]*>\s*\n?\s*<Globe size=\{16\} \/>\s*\n?\s*\{translate\(\'portal\', \'portal_visit_website\'\)\}/', $shell);
    }

    public function test_request_keys_use_the_shared_helper_that_works_without_https(): void
    {
        $helper = (string) file_get_contents(resource_path('js/shared/randomUuid.ts'));

        $this->assertStringContainsString('crypto.getRandomValues', $helper);

        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getRelativePathname() === str_replace('/', DIRECTORY_SEPARATOR, 'shared/randomUuid.ts')) {
                continue;
            }

            if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $this->assertStringNotContainsString('crypto.randomUUID', $file->getContents(), $file->getRelativePathname().' should call randomUuid() so it works over plain http.');
        }
    }
}

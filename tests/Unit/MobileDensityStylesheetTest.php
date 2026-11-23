<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The phone density sheet (tighter spacing on phones) must reach phones only, and both the panels and the public
 * site must load it.
 */
class MobileDensityStylesheetTest extends TestCase
{
    private function css(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/'.$file);
    }

    public function test_every_rule_sits_inside_the_phone_media_query(): void
    {
        $css = trim((string) preg_replace('#/\*.*?\*/#s', '', $this->css('mobile-density.css')));

        $this->assertStringStartsWith('@media (max-width: 767px) {', $css);

        $depth = 0;
        $closedAt = null;
        foreach (str_split($css) as $position => $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $closedAt = $position;
                    break;
                }
            }
        }

        $this->assertNotNull($closedAt, 'The media query is never closed.');
        $this->assertSame(strlen($css) - 1, $closedAt, 'There is a rule after the phone media query.');
    }

    public function test_panels_and_public_pages_import_the_density_sheet_after_the_other_phone_sheets(): void
    {
        $dashboard = $this->css('dashboard.css');
        $this->assertStringContainsString("@import './mobile-density.css';", $dashboard);
        $this->assertGreaterThan(
            strpos($dashboard, "@import './mobile-office.css';"),
            strpos($dashboard, "@import './mobile-density.css';"),
        );

        $public = $this->css('reference-home.css');
        $this->assertStringContainsString("@import './mobile-density.css';", $public);
        $this->assertGreaterThan(
            strpos($public, "@import './mobile-public.css';"),
            strpos($public, "@import './mobile-density.css';"),
        );
    }

    public function test_tap_targets_and_form_text_are_not_shrunk(): void
    {
        $css = $this->css('mobile-density.css');

        $this->assertDoesNotMatchRegularExpression('/min-height:\s*(?:[0-9]|[1-3][0-9]|4[0-3])px\s*[;!]/', $css.';');
        $this->assertDoesNotMatchRegularExpression('/\binput\b[^{]*\{[^}]*font-size/', $css);
    }

    public function test_the_phone_type_scale_is_one_set_of_variables_and_keeps_readable_floors(): void
    {
        $css = $this->css('mobile-density.css');

        foreach (['--mt-body', '--mt-small', '--mt-label', '--mt-card-title', '--mt-number'] as $name) {
            $this->assertStringContainsString($name.':', $css);
        }
        $this->assertMatchesRegularExpression('/--mt-body:\s*14px/', $css, 'Body text on a phone is at least 14 px.');
        $this->assertMatchesRegularExpression('/--mt-label:\s*1[2-9]px/', $css);
        $this->assertStringContainsString(':is(.dealer-shell, .subdealer-shell) { zoom: 1; }', $css, 'The phone default zoom of the partner screens is 1.');
    }

    public function test_one_font_stack_serves_the_public_site_and_all_portals(): void
    {
        $fonts = $this->css('fonts.css');
        foreach (['--font-body-en', '--font-body-bn', '--font-heading', '--font-fallback', '--font-stack'] as $name) {
            $this->assertStringContainsString($name.':', $fonts);
        }
        $this->assertStringContainsString("'Madina Digits', var(--font-body-en)", $fonts, 'Bangla digits keep their equal-width face first.');
        $this->assertStringContainsString("'Nirmala UI'", $fonts);

        foreach (['dashboard.css', 'reference-home.css'] as $file) {
            $css = $this->css($file);
            $this->assertStringContainsString("@import './fonts.css';", $css, $file);
            $this->assertStringContainsString('--ds-font:var(--font-stack);', $css, $file);
        }

        $partial = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/fonts.blade.php');
        $this->assertStringContainsString('family=inter:400,500,600,700|noto-sans-bengali:400,500,600,700&display=swap', $partial);
        $this->assertStringContainsString('rel="preload" as="font"', $partial);
        foreach (['app.blade.php', 'company/auth.blade.php', 'reference/layout.blade.php', 'errors/layout.blade.php'] as $view) {
            $this->assertStringContainsString("@include('partials.fonts')", (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/'.$view), $view);
        }
    }

    public function test_computer_and_tablet_keep_bangla_menus_tables_and_the_top_bar_on_one_line(): void
    {
        $fonts = $this->css('fonts.css');

        $this->assertStringContainsString('@media (min-width: 768px) {', $fonts);
        $this->assertStringContainsString('text-overflow: ellipsis;', $fonts);
        $this->assertStringContainsString('--sidebar-width: 256px;', $fonts);
        $this->assertMatchesRegularExpression('/\.breadcrumb \{\s*flex: 1 1 220px;\s*min-width: 220px;/', $fonts);

        $office = $this->css('screens-office-shell.css');
        $this->assertStringContainsString('--ds-fs-base:14.5px', $office, 'Staff and owner text fits the wider Bangla font.');
    }
}

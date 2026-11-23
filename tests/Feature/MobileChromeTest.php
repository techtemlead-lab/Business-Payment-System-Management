<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The phone app chrome (top bar, menu drawer, bottom bar) lives in one phone-only stylesheet,
 * so the desktop layout cannot change with it.
 */
class MobileChromeTest extends TestCase
{
    private function chromeCss(): string
    {
        return File::get(resource_path('css/mobile-chrome.css'));
    }

    public function test_the_chrome_stylesheet_is_imported_once_by_the_dashboard_styles(): void
    {
        $dashboard = File::get(resource_path('css/dashboard.css'));

        $this->assertSame(1, substr_count($dashboard, "@import './mobile-chrome.css';"));
    }

    public function test_every_chrome_rule_is_phone_only_except_the_hidden_defaults_of_the_new_elements(): void
    {
        $css = $this->chromeCss();
        $phoneStart = strpos($css, '@media (max-width: 767px) {');

        $this->assertNotFalse($phoneStart);
        $outside = substr($css, 0, $phoneStart);
        $this->assertStringContainsString('.app-shell .workspace-phone-title', $outside);
        $this->assertStringContainsString('display: none;', $outside);
        $this->assertSame(1, preg_match_all('/\{/', preg_replace('/\/\*.*?\*\//s', '', $outside)), 'Outside the phone media query there is one rule only: the hidden defaults.');
    }

    public function test_the_bars_are_56_physical_pixels_and_the_tab_labels_are_12px(): void
    {
        $css = $this->chromeCss();

        $this->assertStringContainsString('--chrome-top-h: calc(56 * var(--px));', $css);
        $this->assertStringContainsString('--chrome-bottom-h: calc(56 * var(--px) + env(safe-area-inset-bottom, 0px) / var(--m-zoom));', $css);
        $this->assertMatchesRegularExpression('/portal-bottom-nav > :is\(a,button\) \{[^}]*font-size: 12px;/s', $css);
    }

    public function test_the_short_bottom_bar_names_exist_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            $lines = require lang_path($locale.'/portal.php');

            foreach (['nav_bottom_network', 'nav_bottom_more', 'nav_bottom_dashboard', 'nav_bottom_products', 'nav_bottom_orders', 'nav_bottom_wallet', 'bottom_payouts_hub', 'bottom_office_messages', 'bottom_office_partners'] as $key) {
                $this->assertArrayHasKey($key, $lines, "{$locale} portal lang is missing {$key}");
            }
        }
    }

    public function test_the_shell_keeps_the_new_phone_elements_the_stylesheet_styles(): void
    {
        $shell = File::get(resource_path('js/components/Shell.tsx'));

        foreach (['workspace-phone-title', 'sidebar-user', 'sidebar-branch', 'profile-menu-phone-tools'] as $class) {
            $this->assertStringContainsString($class, $shell);
            $this->assertStringContainsString($class, $this->chromeCss());
        }
    }

    public function test_the_phone_bars_take_their_colours_from_the_logo_variables_defined_once(): void
    {
        $tokens = File::get(resource_path('css/mobile-brand-bars.css'));

        foreach (['--bar-bg', '--bar-bg-deep', '--bar-fg', '--bar-fg-muted', '--bar-active', '--bar-accent', '--bar-focus'] as $variable) {
            $this->assertStringContainsString($variable.':', $tokens, "{$variable} is not defined");
        }
        $this->assertStringContainsString('html[data-theme="dark"]', $tokens, 'The dark theme needs its own darker green.');

        $this->assertSame(1, substr_count(File::get(resource_path('css/dashboard.css')), "@import './mobile-brand-bars-portal.css';"));
        $this->assertSame(1, substr_count(File::get(resource_path('css/reference-home.css')), "@import './mobile-brand-bars-public.css';"));

        $portal = File::get(resource_path('css/mobile-brand-bars-portal.css'));
        $public = File::get(resource_path('css/mobile-brand-bars-public.css'));
        foreach ([$portal, $public] as $css) {
            $this->assertSame(1, substr_count($css, '@media (max-width: 767px) {'), 'The bar colours apply on phones only.');
            $this->assertSame('', trim((string) preg_replace('/\/\*.*?\*\//s', '', substr($css, 0, strpos($css, '@media')))), 'No rule outside the phone media query.');
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\s*;/i', preg_replace('/rgba?\([^)]*\)/', '', $css) ?? '', 'Bar colours come from the variables, not literals.');
        }
        $this->assertStringContainsString('.portal-bottom-nav', $portal);
        $this->assertStringContainsString('.workspace-header', $portal);
        $this->assertStringContainsString('.ref-mobile-nav', $public);
        $this->assertStringContainsString('.ref-header', $public);
    }

    public function test_phones_show_the_company_logo_instead_of_the_menu_button_and_the_menu_opens_from_the_bottom_bar(): void
    {
        $shell = File::get(resource_path('js/components/Shell.tsx'));
        $css = $this->chromeCss();

        $this->assertStringContainsString('className="workspace-header-logo"', $shell);
        $this->assertStringContainsString("siteBrand?.logo || '/reference/logo.png'", $shell);
        $this->assertMatchesRegularExpression('/workspace-header \.workspace-menu-button \{\s*display: none;/', $css, 'The menu button is hidden on phones.');
        $this->assertMatchesRegularExpression('/\.workspace-header \.workspace-header-logo \{\s*display: inline-flex;/', $css);
        $this->assertSame(2, substr_count($shell, 'data-menu-trigger'), 'Both bottom bars have a Menu item that opens the drawer.');
        $this->assertStringContainsString('data-menu-trigger', File::get(resource_path('js/components/PortalHeader.tsx')));
        $this->assertStringContainsString('focusMenuTrigger', $shell, 'Focus returns to the visible menu trigger when the drawer closes.');
    }

    public function test_the_partner_bottom_bar_is_menu_dashboard_products_orders_wallet_with_pictures_only(): void
    {
        $nav = File::get(resource_path('js/components/PortalHeader.tsx'));

        $order = [];
        foreach (['data-menu-trigger', "'nav_bottom_dashboard'", "'nav_bottom_products'", "'nav_bottom_orders'", "'nav_bottom_wallet'"] as $needle) {
            $position = strpos($nav, $needle);
            $this->assertNotFalse($position, "{$needle} is missing from the partner bottom bar");
            $order[] = $position;
        }
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'The items are in the agreed order.');
        $this->assertStringContainsString("'/catalog'", $nav);
        $this->assertStringNotContainsString('nav_bottom_network', $nav, 'Team left the bar; it stays in the drawer.');

        $this->assertStringContainsString('portal-bottom-nav--icons', $nav);
        $this->assertStringContainsString('aria-label={label} title={label}', $nav, 'Every picture keeps its name for screen readers and long-press.');
        $this->assertStringContainsString('aria-label={menuLabel}', $nav);
        $this->assertSame(2, substr_count($nav, 'size={26} strokeWidth={2}'), 'The shared link line and the Menu button use 26px outlined icons.');
        $this->assertDoesNotMatchRegularExpression('/<Icon[^>]*\/>\s*\{label\}/', $nav, 'No visible text under the icons.');

        $css = $this->chromeCss();
        $this->assertStringContainsString('.portal-bottom-nav--icons', $css.File::get(resource_path('css/mobile-brand-bars-portal.css')));
        $this->assertStringContainsString('width: calc(26 * var(--px));', $css);
    }

    public function test_the_phone_drawer_is_one_scroll_area_with_nothing_pinned(): void
    {
        $css = $this->chromeCss();
        $start = strpos($css, '/* ---- Menu drawer');
        $end = strpos($css, '/* ---- Bottom bar');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $drawer = substr($css, $start, $end - $start);

        $this->assertStringNotContainsString('sticky', $drawer);
        $this->assertStringNotContainsString('position: fixed', $drawer);
        $this->assertStringNotContainsString('minmax(0, 1fr) auto;', $drawer, 'No pinned footer row under a scrolling list.');
        $this->assertStringNotContainsString('linear-gradient', $drawer, 'No fade that pins an edge of the list.');
        $this->assertMatchesRegularExpression('/\.sidebar \{[^}]*overflow-y: auto;/s', $drawer, 'The whole drawer scrolls.');
        $this->assertMatchesRegularExpression('/\.sidebar-nav-container \{[^}]*overflow: visible;/s', $drawer);
        $this->assertMatchesRegularExpression('/\.sidebar-footer \{[^}]*position: static;/s', $drawer);
        $this->assertStringContainsString('.help-card > a[href^="tel:"]', $drawer, 'The office phone stays; the other help text and links are hidden.');
    }
}

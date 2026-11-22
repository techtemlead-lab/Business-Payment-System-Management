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

            foreach (['nav_bottom_network', 'nav_bottom_more', 'bottom_payouts_hub', 'bottom_office_messages', 'bottom_office_partners'] as $key) {
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
}

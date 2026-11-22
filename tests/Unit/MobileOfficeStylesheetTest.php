<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The phone layout of the office pages lives in resources/css/mobile-office.css. These checks keep it phone-only
 * (nothing but "stay hidden" rules outside the media query) and keep the class names and words that the React
 * pages use in step with the stylesheet.
 */
class MobileOfficeStylesheetTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function css(): string
    {
        return (string) file_get_contents($this->root().'/resources/css/mobile-office.css');
    }

    public function test_only_hiding_rules_sit_outside_the_phone_media_query(): void
    {
        $css = $this->css();
        $this->assertNotSame('', $css);
        $position = strpos($css, '@media (max-width:767px)');
        $this->assertNotFalse($position, 'The phone media query is missing.');

        $before = preg_replace('#/\*.*?\*/#s', '', substr($css, 0, $position));
        preg_match_all('/\{([^}]*)\}/', (string) $before, $blocks);
        $this->assertNotEmpty($blocks[1]);
        foreach ($blocks[1] as $declarations) {
            $this->assertMatchesRegularExpression('/^\s*display:\s*(none|contents)\s*;?\s*$/', $declarations, 'Outside the media query only "display:none" or "display:contents" is allowed.');
        }
        $this->assertSame(1, substr_count($css, '@media (max-width:767px)'), 'Keep one top-level phone query.');
    }

    public function test_the_phone_only_class_names_exist_in_the_pages_and_in_the_stylesheet(): void
    {
        $uses = [
            'od-now-bar' => 'resources/js/components/OrderNextStep.tsx',
            'od-flow' => 'resources/js/pages/OrderDetail.tsx',
            'tps-summary' => 'resources/js/components/TransactionProgressStepper.tsx',
            'cp-mode-sticky' => 'resources/js/components/commissionpage/ConditionsSection.tsx',
            'msec-savebar' => 'resources/js/pages/ProductEditor.tsx',
            'tsy-warn-link' => 'resources/js/pages/TreasuryProfit.tsx',
            'msec-closed' => 'resources/js/shared/useMobileSections.ts',
        ];
        foreach ($uses as $class => $file) {
            $this->assertStringContainsString($class, (string) file_get_contents($this->root().'/'.$file), "$file no longer uses $class.");
            $this->assertStringContainsString('.'.$class, $this->css(), "mobile-office.css has no rule for $class.");
        }
    }

    public function test_the_sticky_bars_sit_above_the_bottom_tab_bar(): void
    {
        $this->assertStringContainsString('bottom:calc(var(--chrome-bottom-h, 56px) + env(safe-area-inset-bottom, 0px))', $this->css());
        $this->assertSame(2, substr_count($this->css(), 'var(--chrome-bottom-h, 56px) + env(safe-area-inset-bottom, 0px)'));
    }

    public function test_the_next_step_words_exist_in_both_languages(): void
    {
        $keys = [];
        foreach (['verify', 'approve', 'courier', 'handover', 'delivered', 'pay', 'received'] as $step) {
            $keys[] = "od_now_$step";
            $keys[] = "od_btn_$step";
        }
        $keys = array_merge($keys, ['od_now_title', 'tps_summary_step', 'tps_show', 'tps_hide']);

        $bangla = require $this->root().'/lang/bn/orders_stock.php';
        $english = require $this->root().'/lang/en/orders_stock.php';
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $bangla);
            $this->assertArrayHasKey($key, $english);
            $this->assertMatchesRegularExpression('/[\x{0980}-\x{09FF}]/u', (string) $bangla[$key], "$key must be Bangla.");
        }
        $profitBangla = require $this->root().'/lang/bn/profit_loss.php';
        $profitEnglish = require $this->root().'/lang/en/profit_loss.php';
        $this->assertArrayHasKey('warn_short', $profitBangla);
        $this->assertArrayHasKey('warn_short', $profitEnglish);
    }
}

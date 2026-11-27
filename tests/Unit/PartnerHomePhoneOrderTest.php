<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Client, 2026-11-25: on a phone the dealer and sub-dealer home opens on the person's card (photo, name, position,
 * rank, mobile, code, area), then the three money figures, then the menu tiles, and only then the owner's notice.
 */
class PartnerHomePhoneOrderTest extends TestCase
{
    public function test_the_person_card_comes_first_on_a_phone(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/organise-partner.css');

        $order = fn (string $selector): int => $this->orderOf($css, $selector);

        $this->assertSame(1, $order('.partner-home.ph .pd-profile'));
        $this->assertLessThan($order('.partner-home.ph .pd-primary'), $order('.partner-home.ph .pd-totals'));
        $this->assertLessThan($order('.partner-home.ph > .ph-menu-tiles'), $order('.partner-home.ph .pd-primary'));
        $this->assertLessThan($order('.partner-home.ph > .portal-notice'), $order('.partner-home.ph > .ph-menu-tiles'));
        $this->assertLessThan($order('.partner-home.ph .pd-waiting'), $order('.partner-home.ph > .portal-notice'));
    }

    public function test_the_menu_tiles_are_for_a_phone_only(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/organise-partner.css');

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\) \{[^@]*\.partner-home\.ph > \.ph-menu-tiles \{ display: none; \}/s',
            $css,
        );
    }

    /** Client's drawing, 2026-11-27: on a computer the person and the money share the middle column, the progress is on the right. */
    public function test_a_computer_shows_two_columns_with_the_person_in_the_middle(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/organise-partner.css');

        $this->assertStringContainsString('@container (min-width: 880px) {'."\n".'  .pd-wrap .pd-grid { grid-template-columns: minmax(0, 1fr) minmax(260px, 320px); }', $css);
    }

    private function orderOf(string $css, string $selector): int
    {
        $pattern = '/'.preg_quote($selector, '/').' \{ order: (\d+); \}/';
        $this->assertSame(1, preg_match($pattern, $css, $match), "No order rule for {$selector}");

        return (int) $match[1];
    }
}

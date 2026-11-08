<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The public site's text sizes live in one block at the end of the stylesheet. The sizes
 * themselves were measured in a real browser on every page; these tests stop the block
 * from being weakened or the old forced small sizes from coming back.
 */
class PublicReadingSizesTest extends TestCase
{
    private function stylesheet(): string
    {
        return File::get(resource_path('css/reference-home.css'));
    }

    public function test_english_and_bangla_reading_sizes_stay_at_or_above_the_agreed_floors(): void
    {
        $css = $this->stylesheet();

        $this->assertStringContainsString('body.ref-home{--ref-fs-lead:17px;--ref-fs-body:16px;--ref-fs-link:15px;--ref-fs-small:13px;--ref-fs-tiny:12px}', $css);
        $this->assertStringContainsString('body.ref-home.locale-bn{--ref-fs-lead:18px;--ref-fs-body:17px;--ref-fs-link:16px;--ref-fs-small:14px;--ref-fs-tiny:13px}', $css);
    }

    public function test_the_footer_phone_number_stays_on_one_line(): void
    {
        $css = $this->stylesheet();

        $this->assertStringContainsString('body.ref-home .ref-support-box b{font-size:var(--ref-fs-lead);line-height:1.3;white-space:nowrap}', $css);
        $this->assertStringNotContainsString('.ref-support-box b{font-size:clamp(11px', $css);
    }

    public function test_footer_text_is_not_forced_below_the_reading_sizes(): void
    {
        $css = $this->stylesheet();

        foreach (['.ref-footer-links', '.ref-footer-bottom', '.ref-footer-contact-info'] as $selector) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.preg_quote($selector, '/').'\s*\{[^}]*font-size:\s*(?:[0-9]|1[0-2])(?:\.[0-9]+)?px\s*!important/',
                $css,
                "{$selector} is forced to a small size again.",
            );
        }
    }
}

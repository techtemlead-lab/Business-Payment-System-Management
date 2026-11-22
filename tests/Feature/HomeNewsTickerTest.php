<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client report 2026-11-22: on a phone the moving news line on the home page stopped part-way through
 * the text and started again. The line must carry the whole text, twice, so it can loop without a jump.
 */
class HomeNewsTickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::reset();
    }

    public function test_the_news_line_holds_the_whole_text_twice_and_hides_the_copy_from_screen_readers(): void
    {
        $text = 'Herbal products now available. Dealership is given to registered doctors only.';
        SiteCustomization::save(['announcement_text' => $text]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="ref-announcement-item">'.$text.'</span>', $html);
        $this->assertStringContainsString('<span class="ref-announcement-item" aria-hidden="true">'.$text.'</span>', $html);
        $this->assertSame(2, substr_count($html, 'ref-announcement-item'));
        $this->assertStringContainsString('data-ref-ticker', $html);
    }

    public function test_a_longer_text_gets_a_longer_first_guess_so_the_speed_stays_the_same(): void
    {
        SiteCustomization::save(['announcement_text' => str_repeat('Natural health care. ', 4)]);
        $short = $this->firstGuessSeconds($this->get('/')->assertOk()->getContent());

        SiteCustomization::save(['announcement_text' => str_repeat('Natural health care. ', 40)]);
        $long = $this->firstGuessSeconds($this->get('/')->assertOk()->getContent());

        $this->assertGreaterThanOrEqual(8, $short);
        $this->assertGreaterThan($short, $long);
    }

    private function firstGuessSeconds(string $html): int
    {
        $this->assertSame(1, preg_match('/--ref-ticker-seconds:\s*(\d+)s/', $html, $found));

        return (int) $found[1];
    }
}

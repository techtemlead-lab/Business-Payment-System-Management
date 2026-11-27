<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client voice note, 2026-11-27: the moving news line on the home page must follow the visitor's language.
 */
class PublicNewsLineLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::reset();
    }

    public function test_the_news_line_reads_in_bangla_for_bangla_visitors_even_when_english_text_was_saved(): void
    {
        SiteCustomization::save(['announcement_text' => 'Herbal products are now available. Dealerships are given to registered doctors only.']);

        $html = $this->withCookie('locale', 'bn')->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="ref-announcement-item">'.e(__('site.announcement_text', [], 'bn')).'</span>', $html);
        $this->assertStringNotContainsString('Herbal products are now available', $html);
    }

    public function test_the_news_line_shows_the_owners_bangla_box_to_bangla_visitors_and_the_english_box_to_english_visitors(): void
    {
        SiteCustomization::save([
            'announcement_text' => 'Herbal products are now available.',
            'announcement_text_bn' => 'ভেষজ পণ্য এখন পাওয়া যাচ্ছে।',
        ]);

        $this->withCookie('locale', 'bn')->get('/')->assertOk()->assertSee('ভেষজ পণ্য এখন পাওয়া যাচ্ছে।');
        $this->withCookie('locale', 'en')->get('/')->assertOk()
            ->assertSee('<span class="ref-announcement-item">Herbal products are now available.</span>', false)
            ->assertDontSee('ভেষজ পণ্য এখন পাওয়া যাচ্ছে।');
    }

    public function test_the_news_line_never_shows_bangla_only_text_to_english_visitors(): void
    {
        SiteCustomization::save(['announcement_text' => 'ভেষজ পণ্য এখন পাওয়া যাচ্ছে।']);

        $this->withCookie('locale', 'en')->get('/')->assertOk()
            ->assertSee('<span class="ref-announcement-item">'.e(__('site.announcement_text', [], 'en')).'</span>', false)
            ->assertDontSee('ভেষজ পণ্য এখন পাওয়া যাচ্ছে।');
    }
}

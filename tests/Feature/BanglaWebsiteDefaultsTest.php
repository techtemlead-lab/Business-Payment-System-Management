<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Database\Seeders\TrainingItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bangla visitors see Bangla for the website's factory text (banner picture
 * descriptions, footer address, video section), while text the owner typed
 * in the website editor is always shown as written.
 */
class BanglaWebsiteDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bangla_home_page_shows_bangla_factory_text(): void
    {
        $html = $this->withSession(['locale' => 'bn'])->onPortal('public')->get('/')->assertOk()->getContent();

        foreach (['hero_slide1_alt', 'side_banner_1_slide2_alt', 'side_banner_2_slide3_alt', 'footer_location'] as $key) {
            $this->assertStringContainsString(e(__('site.'.$key, [], 'bn')), $html, $key);
            $this->assertStringNotContainsString(e(__('site.'.$key, [], 'en')), $html, $key);
        }
    }

    public function test_english_home_page_keeps_english_factory_text(): void
    {
        $html = $this->withSession(['locale' => 'en'])->onPortal('public')->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(e(__('site.hero_slide1_alt', [], 'en')), $html);
        $this->assertStringContainsString(e(__('site.footer_location', [], 'en')), $html);
    }

    public function test_owner_edits_are_shown_as_written_in_bangla(): void
    {
        $slides = SiteCustomization::defaults()['slides'];
        $slides[0]['alt'] = 'Eid offer on herbal tea';
        SiteCustomization::save(['slides' => $slides, 'footer_location' => 'Station Road, Rangpur']);

        $html = $this->withSession(['locale' => 'bn'])->onPortal('public')->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Eid offer on herbal tea', $html);
        $this->assertStringContainsString('Station Road, Rangpur', $html);
    }

    public function test_factory_defaults_match_the_english_language_file(): void
    {
        $defaults = SiteCustomization::defaults();

        $this->assertSame(__('site.hero_slide2_alt', [], 'en'), $defaults['slides'][1]['alt']);
        $this->assertSame(__('site.side_banner_2_slide1_alt', [], 'en'), $defaults['side_banner_2_slides'][0]['alt']);
        $this->assertSame(__('site.footer_address', [], 'en'), $defaults['footer_address']);
    }

    public function test_starter_lessons_lead_with_bangla(): void
    {
        $this->seed(TrainingItemSeeder::class);

        $this->assertDatabaseCount('training_items', 3);
        $this->assertDatabaseHas('training_items', [
            'title_en' => 'How to place an order and send the payment screenshot',
            'summary' => 'চার ধাপে অর্ডার, পেমেন্ট ও প্রমাণ পাঠান। / Order, pay and send proof in four easy steps.',
        ]);
    }
}

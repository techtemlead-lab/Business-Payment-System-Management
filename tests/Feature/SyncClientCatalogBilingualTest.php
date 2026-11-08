<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncClientCatalogBilingualTest extends TestCase
{
    use RefreshDatabase;

    private const OldEnglishFooter = 'Licensed distributor of natural herbal and nutrition products. Trade License 2026-00563, Pirganj Municipality, Rangpur. Marketed nutrition line: Holistic Nutracare.';

    private const OldEnglishAbout = 'Madina Natural Health Care is a licensed single-owner distributor (Trade License 2026-00563, Pirganj Municipality) headquartered on Thana Road, Pirganj 5470, Rangpur. We supply herbal and Holistic Nutracare nutrition products through an authorized dealer and sub-dealer network serving communities across Bangladesh.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function sync(): void
    {
        $this->artisan('madina:sync-client-catalog')->assertSuccessful();
        SiteCustomization::forgetCache();
    }

    public function test_after_a_sync_each_language_reads_its_own_footer_and_about_text(): void
    {
        $this->sync();

        $this->withCookie('locale', 'bn')->get('/about')->assertOk()
            ->assertSee(__('site.about_hero_lead', [], 'bn'))
            ->assertSee(__('site.footer_about_desc', [], 'bn'))
            ->assertDontSee(self::OldEnglishFooter)
            ->assertDontSee(self::OldEnglishAbout);

        $this->withCookie('locale', 'en')->get('/about')->assertOk()
            ->assertSee(__('site.about_hero_lead', [], 'en'))
            ->assertSee(__('site.footer_about_desc', [], 'en'));
    }

    public function test_both_languages_say_the_same_facts_in_the_footer(): void
    {
        $this->assertStringContainsString('2026-00563', __('site.footer_about_desc', [], 'en'));
        $this->assertStringContainsString('২০২৬-০০৫৬৩', __('site.footer_about_desc', [], 'bn'));
    }

    public function test_text_saved_by_an_earlier_sync_stops_showing_english_to_bangla_visitors(): void
    {
        SiteCustomization::save(['footer_about_desc' => self::OldEnglishFooter, 'about_hero_lead' => self::OldEnglishAbout]);
        SiteCustomization::forgetCache();

        $this->withCookie('locale', 'bn')->get('/about')->assertOk()
            ->assertSee(__('site.about_hero_lead', [], 'bn'))
            ->assertSee(__('site.footer_about_desc', [], 'bn'))
            ->assertDontSee(self::OldEnglishFooter);
    }

    public function test_a_sync_never_overwrites_what_the_owner_wrote_in_the_website_editor(): void
    {
        $edits = [
            'footer_about_desc' => 'Owner written footer text',
            'about_hero_lead' => 'Owner written about text',
            'contact_phone' => '+8801999000111',
            'contact_email' => 'owner@example.test',
            'office_address' => 'Owner written office address',
            'contact_company_desc' => 'Owner written contact intro',
        ];
        SiteCustomization::save($edits);

        $this->sync();

        $saved = SiteCustomization::all();
        foreach ($edits as $key => $value) {
            $this->assertSame($value, $saved[$key], $key);
        }
        $this->withCookie('locale', 'bn')->get('/about')->assertOk()->assertSee('Owner written about text');
    }

    public function test_an_empty_field_is_filled_in(): void
    {
        SiteCustomization::save(['contact_phone' => '']);

        $this->sync();

        $this->assertSame('+8801730532966', SiteCustomization::all()['contact_phone']);
    }
}

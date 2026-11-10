<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A fact the website shows in several places is stored once, so one edit reaches every place.
 */
class SiteSharedFactsTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_09_020000_merge_repeated_website_facts.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    /** @param array<string, string> $settings */
    private function saveAsEditor(array $settings): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post('/commands/customization', ['customization' => $settings])->assertSessionHasNoErrors();
        auth()->logout();
    }

    /** @param array<string, mixed> $body */
    private function storeOldStyleRow(array $body): void
    {
        DB::table('content_pages')->updateOrInsert(
            ['slug' => 'frontend_customization'],
            ['title' => 'Frontend Customization', 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'published' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        SiteCustomization::forgetCache();
    }

    private function runMergeMigration(): void
    {
        (require database_path('migrations/'.self::Migration))->up();
    }

    public function test_one_phone_edit_reaches_the_footer_and_the_contact_page(): void
    {
        $this->saveAsEditor(['contact_phone' => '01900-111111']);

        $this->get('/')->assertOk()->assertSee('01900-111111')->assertSee('tel:01900111111', false)->assertDontSee('+8801730532966');
        $this->get('/contact')->assertOk()->assertSee('01900-111111')->assertDontSee('+8801730532966');
    }

    public function test_one_email_edit_reaches_the_footer_and_the_contact_page(): void
    {
        $this->saveAsEditor(['contact_email' => 'office@example.test']);

        $this->get('/')->assertOk()->assertSee('mailto:office@example.test', false);
        $this->get('/contact')->assertOk()->assertSee('mailto:office@example.test', false);
    }

    public function test_the_address_and_the_licence_reach_the_sentences_that_mention_them(): void
    {
        $this->saveAsEditor(['office_address' => 'Station Road, Rangpur', 'trust_trade_licence' => 'TL-777']);

        $this->get('/about')->assertOk()->assertSee('Station Road, Rangpur')->assertSee('TL-777')->assertDontSee('Thana Road')->assertDontSee('2026-00563');
        $this->get('/contact')->assertOk()->assertSee('Station Road, Rangpur')->assertDontSee('Thana Road');
        $this->get('/privacy')->assertOk()->assertSee('Station Road, Rangpur')->assertDontSee('Thana Road');
        $this->get('/')->assertOk()->assertSee('TL-777')->assertDontSee('2026-00563');
    }

    public function test_the_built_in_text_shows_the_real_company_details_and_no_curly_words(): void
    {
        foreach (['/', '/about', '/contact', '/privacy', '/terms'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('{company}', false)->assertDontSee('{address}', false)->assertDontSee('{phones}', false)->assertDontSee('{licence}', false);
        }
        $this->get('/about')->assertSee('2026-00563')->assertSee('Thana Road, Pirganj 5470, Rangpur');
        $this->get('/privacy')->assertSee('+8801730532966 / 01730532967');
    }

    public function test_bangla_visitors_get_bangla_digits_inside_sentences(): void
    {
        $this->withCookie('locale', 'bn')->withSession(['locale' => 'bn'])->get('/about')->assertOk()->assertSee('২০২৬-০০৫৬৩')->assertSee('থানা রোড');
    }

    public function test_the_company_name_is_one_box(): void
    {
        $this->saveAsEditor(['contact_company_title' => 'Madina Herbal House']);

        $this->get('/')->assertOk()
            ->assertSee('<h3>Madina Herbal House</h3>', false)
            ->assertSee('ref-bar-text">Madina Herbal House', false);
        $this->get('/contact')->assertOk()->assertSee('Madina Herbal House');
    }

    public function test_one_whatsapp_edit_reaches_the_social_row_and_the_chat_button(): void
    {
        $this->saveAsEditor(['social_whatsapp' => '01888888888']);

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['ref-social is-whatsapp', 'https://wa.me/8801888888888'], false)
            ->assertSeeInOrder(['ref-floating-tools', 'https://wa.me/8801888888888'], false)
            ->assertDontSee('https://wa.me/8801730532966');
    }

    public function test_the_second_phone_shows_in_both_places_and_can_be_removed(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('ref-footer-contact-link is-hotline', false)
            ->assertSeeInOrder(['ref-support-contacts', '01730532967'], false);
        $this->get('/contact')->assertSee('01730532967');

        $this->saveAsEditor(['footer_phone' => '']);

        $this->get('/')->assertOk()->assertDontSee('01730532967');
        $this->get('/contact')->assertOk()->assertDontSee('01730532967');
    }

    public function test_the_number_that_was_never_the_companys_gives_way_when_it_was_saved_earlier(): void
    {
        $this->storeOldStyleRow(['footer_phone' => '01723731877']);

        foreach (['/', '/contact', '/privacy'] as $path) {
            $this->get($path)->assertOk()->assertSee('01730532967')->assertDontSee('01723731877');
        }
    }

    public function test_every_social_network_appears_side_by_side_in_the_footer_and_on_the_contact_page(): void
    {
        $settings = [];
        foreach (array_keys(SiteCustomization::SocialNetworks) as $network) {
            $settings['social_'.$network] = 'https://example.test/'.$network;
        }
        $this->saveAsEditor($settings);

        foreach (['/', '/contact'] as $path) {
            $response = $this->get($path)->assertOk();
            foreach (array_keys(SiteCustomization::SocialNetworks) as $network) {
                $response->assertSee('ref-social is-'.$network, false)->assertSee('https://example.test/'.$network, false);
            }
        }
    }

    public function test_an_empty_social_box_shows_no_button(): void
    {
        $this->saveAsEditor(['social_facebook' => '', 'social_whatsapp' => 'https://wa.me/8801730532966']);

        $this->get('/')->assertOk()->assertDontSee('ref-social is-facebook', false)->assertSee('ref-social is-whatsapp', false);
    }

    public function test_the_migration_moves_what_the_owner_typed_to_the_one_place(): void
    {
        $this->storeOldStyleRow([
            'footer_support_title' => '01555-000000',
            'contact_phone' => '+8801730532966',
            'footer_email' => 'footer@example.test',
            'contact_email' => '',
            'whatsapp_link' => 'https://wa.me/8801666666666',
            'social_whatsapp' => 'https://wa.me/8801730532966',
            'footer_address' => 'New Road, Rangpur',
            'office_address' => 'Thana Road, Pirganj 5470, Rangpur, Bangladesh',
            'top_bar_text' => 'MADINA NATURAL HEALTH CARE',
            'footer_about_title' => 'Madina Group',
            'contact_company_title' => 'Madina Natural Health Care',
            'legal_meta_location' => 'Rangpur City',
            'footer_location' => '',
            'footer_support_link' => 'tel:0155',
            'about_mission_title' => 'Owner written title',
        ]);

        $this->runMergeMigration();

        $raw = SiteCustomization::raw();
        $this->assertSame('01555-000000', $raw['contact_phone']);
        $this->assertSame('footer@example.test', $raw['contact_email']);
        $this->assertSame('https://wa.me/8801666666666', $raw['social_whatsapp']);
        $this->assertSame('New Road, Rangpur', $raw['office_address']);
        $this->assertSame('Madina Group', $raw['contact_company_title']);
        $this->assertSame('Rangpur City', $raw['footer_location']);
        $this->assertSame('Owner written title', $raw['about_mission_title']);
        foreach (array_keys(SiteCustomization::RepeatedFacts) as $repeat) {
            $this->assertArrayNotHasKey($repeat, $raw);
        }
        $this->assertArrayNotHasKey('footer_support_link', $raw);
    }

    public function test_the_migration_keeps_an_edit_made_on_the_fact_itself(): void
    {
        $this->storeOldStyleRow(['contact_phone' => '01444-000000', 'footer_support_title' => '01555-000000']);

        $this->runMergeMigration();

        $this->assertSame('01444-000000', SiteCustomization::raw()['contact_phone']);
    }

    public function test_the_migration_fills_the_second_phone_when_the_box_only_repeated_the_first(): void
    {
        $this->storeOldStyleRow(['contact_phone' => '+8801730532966', 'footer_phone' => '01730-532966']);
        $this->runMergeMigration();
        $this->assertSame('01730532967', SiteCustomization::raw()['footer_phone']);

        $this->storeOldStyleRow(['contact_phone' => '+8801730532966', 'footer_phone' => '01812345678']);
        $this->runMergeMigration();
        $this->assertSame('01812345678', SiteCustomization::raw()['footer_phone']);
    }

    public function test_the_migration_never_brings_back_the_other_companys_details(): void
    {
        $this->storeOldStyleRow(['footer_email' => 'holisticnutracare@gmail.com', 'contact_email' => '']);

        $this->runMergeMigration();

        $this->assertSame('', SiteCustomization::raw()['contact_email'] ?? '');
    }

    public function test_the_migration_is_safe_on_a_site_with_no_saved_settings_and_when_run_twice(): void
    {
        $this->runMergeMigration();
        $this->assertSame([], SiteCustomization::raw());

        $this->storeOldStyleRow(['footer_email' => 'footer@example.test']);
        $this->runMergeMigration();
        $once = SiteCustomization::raw();
        $this->runMergeMigration();

        $this->assertSame($once, SiteCustomization::raw());
    }
}

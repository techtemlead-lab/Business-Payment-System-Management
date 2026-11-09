<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ContactHoursAndEmailTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_09_123749_keep_office_hours_showing_on_the_contact_page.php';

    private const DefaultEmail = 'madinanaturalhealthcare@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function inLanguage(string $locale, string $path): TestResponse
    {
        return $this->onPortal('public')->withSession(['locale' => $locale])->get($path);
    }

    /** @param  array<string, mixed>  $settings */
    private function storeSettings(array $settings): void
    {
        DB::table('content_pages')->updateOrInsert(['slug' => 'frontend_customization'], [
            'title' => 'Frontend Customization',
            'body' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SiteCustomization::forgetCache();
    }

    public function test_office_hours_show_in_both_languages_on_a_new_site(): void
    {
        $this->inLanguage('en', '/contact')->assertOk()->assertSee(__('site.contact_phone_hours', [], 'en'));
        $this->inLanguage('bn', '/contact')->assertOk()->assertSee(__('site.contact_phone_hours', [], 'bn'));
    }

    public function test_a_blank_hours_box_hides_them_in_both_languages(): void
    {
        SiteCustomization::save(['contact_phone_hours' => '']);

        foreach (['en', 'bn'] as $locale) {
            $this->inLanguage($locale, '/contact')->assertOk()
                ->assertDontSee(__('site.contact_phone_hours', [], 'en'))
                ->assertDontSee(__('site.contact_phone_hours', [], 'bn'))
                ->assertDontSee('ref-contact-hours');
        }
    }

    public function test_typed_hours_show_as_typed_in_both_languages(): void
    {
        SiteCustomization::save(['contact_phone_hours' => 'Every day 10 to 5']);

        foreach (['en', 'bn'] as $locale) {
            $this->inLanguage($locale, '/contact')->assertOk()->assertSee('Every day 10 to 5');
        }
    }

    public function test_the_update_keeps_hours_showing_where_the_box_was_never_filled(): void
    {
        $this->storeSettings(['contact_phone_hours' => '', 'contact_title' => 'Owner heading']);

        (require database_path('migrations/'.self::Migration))->up();

        $saved = SiteCustomization::raw();
        $this->assertSame(__('site.contact_phone_hours', [], 'en'), $saved['contact_phone_hours']);
        $this->assertSame('Owner heading', $saved['contact_title']);
        $this->inLanguage('bn', '/contact')->assertOk()->assertSee(__('site.contact_phone_hours', [], 'bn'));

        SiteCustomization::save(['contact_phone_hours' => '']);
        $this->inLanguage('bn', '/contact')->assertOk()->assertDontSee(__('site.contact_phone_hours', [], 'bn'));
    }

    public function test_the_update_never_replaces_hours_the_owner_typed(): void
    {
        $this->storeSettings(['contact_phone_hours' => 'Owner hours']);

        (require database_path('migrations/'.self::Migration))->up();

        $this->assertSame('Owner hours', SiteCustomization::raw()['contact_phone_hours']);
    }

    public function test_the_default_email_shows_when_nothing_was_ever_saved(): void
    {
        $this->inLanguage('en', '/contact')->assertOk()->assertSee('mailto:'.self::DefaultEmail, false);
        $this->inLanguage('bn', '/')->assertOk()->assertSee(self::DefaultEmail);
    }

    public function test_the_default_email_reaches_a_site_whose_box_is_blank(): void
    {
        $this->storeSettings(['contact_email' => '', 'contact_title' => 'Owner heading']);

        $this->inLanguage('en', '/contact')->assertOk()->assertSee('mailto:'.self::DefaultEmail, false)->assertSee('Owner heading');
        $this->inLanguage('bn', '/')->assertOk()->assertSee(self::DefaultEmail);
    }

    public function test_an_email_the_owner_typed_is_never_replaced(): void
    {
        $this->storeSettings(['contact_email' => 'office@example.test']);

        $this->inLanguage('en', '/contact')->assertOk()
            ->assertSee('office@example.test')
            ->assertDontSee(self::DefaultEmail);
        $this->assertSame('office@example.test', SiteCustomization::raw()['contact_email']);
    }
}

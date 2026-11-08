<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The location in the last line of the public site and the error page. It comes
 * from the website editor; until the owner edits it, the head office is shown.
 */
class SiteFooterLocationTest extends TestCase
{
    use RefreshDatabase;

    private const NewEnglish = 'Pirganj, Rangpur, Bangladesh';

    private const NewBangla = 'পীরগঞ্জ, রংপুর, বাংলাদেশ';

    private const OldEnglish = 'Dhaka, Bangladesh';

    private const OldBangla = 'ঢাকা, বাংলাদেশ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function assertLocationShown(string $shown, string $locale, string ...$notShown): void
    {
        $home = $this->withSession(['locale' => $locale])->onPortal('public')->get('/')->assertOk()->getContent();
        app()->setLocale($locale);
        $errorPage = (string) $this->view('errors.404');

        foreach ([$home, $errorPage] as $html) {
            $this->assertStringContainsString($shown, $html);
            foreach ($notShown as $text) {
                $this->assertStringNotContainsString($text, $html);
            }
        }
    }

    public function test_footer_and_error_page_show_the_head_office_in_both_languages(): void
    {
        $this->assertLocationShown(self::NewEnglish, 'en', self::OldEnglish, self::OldBangla);
        $this->assertLocationShown(self::NewBangla, 'bn', self::OldEnglish, self::OldBangla);
    }

    public function test_factory_text_matches_the_language_file(): void
    {
        $this->assertSame(self::NewEnglish, SiteCustomization::defaults()['footer_location']);
        $this->assertSame(self::NewEnglish, __('site.footer_location', [], 'en'));
        $this->assertSame(self::NewBangla, __('site.footer_location', [], 'bn'));
    }

    public function test_owner_text_from_the_website_editor_wins_in_both_languages(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->post('/commands/customization', ['customization' => ['footer_location' => 'Station Road, Rangpur']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertSame('Station Road, Rangpur', SiteCustomization::raw()['footer_location']);
        $this->assertLocationShown('Station Road, Rangpur', 'en', self::NewEnglish, self::NewBangla);
        $this->assertLocationShown('Station Road, Rangpur', 'bn', self::NewEnglish, self::NewBangla);
    }

    public function test_saved_old_default_is_treated_as_not_customised_and_other_saved_text_stays(): void
    {
        foreach ([self::OldEnglish, self::OldBangla] as $old) {
            DB::table('content_pages')->updateOrInsert(['slug' => 'frontend_customization'], [
                'title' => 'Frontend Customization',
                'body' => json_encode(['footer_location' => $old, 'top_bar_text' => 'OWNER TOP BAR'], JSON_UNESCAPED_UNICODE),
                'published' => true,
                'updated_at' => now(),
            ]);
            SiteCustomization::forgetCache();

            $this->assertArrayNotHasKey('footer_location', SiteCustomization::raw());
            $this->assertSame('OWNER TOP BAR', SiteCustomization::raw()['top_bar_text']);
            $this->assertLocationShown(self::NewEnglish, 'en', self::OldEnglish, self::OldBangla);
            $this->assertLocationShown(self::NewBangla, 'bn', self::OldEnglish, self::OldBangla);
        }
    }
}

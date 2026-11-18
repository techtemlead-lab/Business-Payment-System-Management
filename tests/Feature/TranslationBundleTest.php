<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TranslationBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Portal pages no longer carry about 1 MB of translation words in every first load (PQ-03). The words
 * come from one versioned file that the browser keeps for a year.
 */
class TranslationBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_file_holds_every_group_and_is_kept_for_a_year(): void
    {
        $response = $this->onPortal('dealer')->get(TranslationBundle::url('bn'))->assertOk();

        $response->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $words = $response->json();
        $this->assertSame(array_keys(TranslationBundle::Groups), array_keys($words));
        $this->assertSame(__('portal.log_out', [], 'bn'), $words['portal']['log_out']);
        $this->assertSame(__('partner_ux.text_size', [], 'bn'), $words['partner_ux']['text_size']);
    }

    public function test_every_host_serves_the_file_and_the_other_language_has_its_own(): void
    {
        foreach (['public', 'dealer', 'subdealer', 'manage', 'sadmin'] as $portal) {
            $this->onPortal($portal)->get(TranslationBundle::url('en'))->assertOk()->assertJsonPath('portal.log_out', __('portal.log_out', [], 'en'));
        }
        $this->assertNotSame(TranslationBundle::version('en'), TranslationBundle::version('bn'));
    }

    public function test_an_old_version_link_gets_the_current_words_but_is_not_kept(): void
    {
        $response = $this->onPortal('dealer')->get('/i18n/bn/0123456789abcdef.json')->assertOk();

        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->onPortal('dealer')->get('/i18n/xx/0123456789abcdef.json')->assertNotFound();
    }

    public function test_a_second_visit_with_the_same_version_is_answered_without_a_body(): void
    {
        $first = $this->onPortal('dealer')->get(TranslationBundle::url('bn'))->assertOk();

        $this->onPortal('dealer')->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])
            ->get(TranslationBundle::url('bn'))->assertStatus(304);
    }

    public function test_a_portal_page_names_the_file_instead_of_carrying_the_words(): void
    {
        config(['madina.inline_translations' => false]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('translationsUrl', TranslationBundle::url(app()->getLocale()))
                ->missing('translations.portal')
                ->loadDeferredProps('manual', fn (Assert $reload) => $reload->has('userManualChapters')));
    }

    public function test_with_the_inline_switch_on_the_words_stay_in_the_page(): void
    {
        config(['madina.inline_translations' => true]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('translations.portal')->has('userManualChapters'));
    }
}

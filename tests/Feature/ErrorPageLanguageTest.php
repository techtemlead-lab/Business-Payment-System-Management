<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An old or mistyped address matches no route, so the language middleware never
 * runs; the "page not found" screen must still follow the visitor's language.
 */
class ErrorPageLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_address_uses_the_saved_bangla_language(): void
    {
        $this->withCookie('locale', 'bn')
            ->onPortal('sadmin')
            ->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('এই পাতাটি খুঁজে পাওয়া যায়নি')
            ->assertDontSee('We could not find that page');
    }

    public function test_unknown_address_follows_a_bangla_browser_when_nothing_is_saved(): void
    {
        $this->withHeader('Accept-Language', 'bn-BD,bn;q=0.9')
            ->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('এই পাতাটি খুঁজে পাওয়া যায়নি');
    }

    public function test_unknown_address_stays_english_by_default(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->onPortal('dealer')
            ->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('We could not find that page');
    }

    public function test_a_tampered_language_cookie_is_ignored(): void
    {
        $this->withUnencryptedCookie('locale', 'not-a-language')
            ->withHeader('Accept-Language', 'en-US')
            ->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('We could not find that page');
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-in pages open in Bangla for someone who never chose a language, and a choice made on one
 * host (public site, dealer, sub-dealer, office) follows the person to the others (AX-02).
 */
class GuestLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_first_visit_to_the_dealer_sign_in_opens_in_bangla_even_when_the_browser_asks_for_english(): void
    {
        config(['madina.guest_locale' => 'bn']);

        $this->onPortal('dealer')->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get('/login')
            ->assertOk()->assertSee('<html lang="bn"', false);
    }

    public function test_the_browser_decides_when_the_guest_language_is_switched_off(): void
    {
        config(['madina.guest_locale' => '']);

        $this->onPortal('dealer')->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get('/login')
            ->assertOk()->assertSee('<html lang="en"', false);
    }

    public function test_a_language_in_the_link_wins_and_is_remembered_for_every_host(): void
    {
        config(['madina.guest_locale' => 'bn']);

        $response = $this->onPortal('subdealer')->get('/login?lang=en')->assertOk()->assertSee('<html lang="en"', false);

        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'locale');
        $this->assertNotNull($cookie);
        $this->assertSame('.example.test', $cookie->getDomain());
    }

    public function test_an_unknown_language_in_the_link_is_ignored(): void
    {
        config(['madina.guest_locale' => 'bn']);

        $this->onPortal('dealer')->get('/login?lang=xx')->assertOk()->assertSee('<html lang="bn"', false);
    }
}

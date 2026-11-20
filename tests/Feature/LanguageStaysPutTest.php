<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portal language must never change without a click (client report, 2026-11-20).
 * Bangla is the default; a person's own choice (the switcher) wins on every host and stays.
 */
class LanguageStaysPutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.guest_locale' => 'bn']);
    }

    private function signInOnManage(User $user): void
    {
        $this->onPortal('manage')
            ->post('/login', ['email' => $user->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp');
        $this->onPortal('manage')->post('/login/otp', ['code' => $this->lastLoginCode()])->assertRedirect('/');
    }

    public function test_signing_in_without_choosing_keeps_the_bangla_the_sign_in_page_showed(): void
    {
        // Accounts made before 8 Nov 2026 carry the old column default "en" although nobody chose it.
        $finance = User::factory()->staff('finance')->create([
            'locale' => 'en',
            'phone' => '01711000078',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('manage')->get('/login')->assertOk()->assertSee('<html lang="bn"', false);

        $this->signInOnManage($finance);

        $this->onPortal('manage')->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'bn'));
        $this->assertSame('bn', $finance->fresh()->locale);
    }

    public function test_a_language_chosen_on_another_host_beats_the_saved_language_of_a_signed_in_person(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'en', 'phone' => '01711000079']);

        $this->actingAs($finance)->withCookie('locale', 'bn')->onPortal('manage')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'bn'));
    }

    public function test_the_shared_cookie_beats_an_older_choice_kept_in_this_hosts_session(): void
    {
        $this->withSession(['locale' => 'en'])->withCookie('locale', 'bn')
            ->onPortal('dealer')->get('/login')
            ->assertOk()->assertSee('<html lang="bn"', false);
    }

    public function test_a_timed_background_refresh_keeps_the_same_language(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn', 'phone' => '01711000080']);

        $this->actingAs($finance)->onPortal('manage')->get('/')
            ->assertInertia(fn ($page) => $page->where('locale', 'bn'));
        $this->actingAs($finance)->onPortal('manage')
            ->withHeaders(['X-Background-Refresh' => '1', 'Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'bn'));
        $this->assertSame('bn', $finance->fresh()->locale);
    }

    public function test_choosing_a_language_also_clears_the_old_host_only_cookie(): void
    {
        // Before 18 Nov the cookie had no domain; a second, older "locale" cookie would win over the new one.
        $response = $this->onPortal('dealer')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $cookies = collect($response->headers->getCookies())->filter(fn ($c): bool => $c->getName() === 'locale');
        $hostOnly = $cookies->first(fn ($c): bool => $c->getDomain() === null);
        $shared = $cookies->first(fn ($c): bool => $c->getDomain() === '.example.test');

        $this->assertNotNull($shared);
        $this->assertNotNull($hostOnly);
        $this->assertTrue($hostOnly->isCleared());
    }
}

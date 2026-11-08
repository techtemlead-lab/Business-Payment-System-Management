<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "please wait a little" page is drawn before the language is chosen for the request,
 * so a Bangla partner who tried too many times used to read it in English.
 */
class PartnerRateLimitPageLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_too_many_tries_page_follows_the_partners_saved_language(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'bn']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($dealer)->onPortal('dealer')->post('/security/password', ['current_password' => 'wrong-password-here']);
        }
        // A real request starts in the app's default language; tests keep the last request's.
        app()->setLocale('en');

        $response = $this->actingAs($dealer)->onPortal('dealer')->post('/security/password', ['current_password' => 'wrong-password-here']);

        $response->assertStatus(429);
        $response->assertSee(__('site.error_429_heading', [], 'bn'), false);
    }
}

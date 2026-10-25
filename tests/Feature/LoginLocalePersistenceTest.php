<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLocalePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_applies_session_bangla_locale_to_staff_account(): void
    {
        $finance = User::factory()->staff('finance')->create([
            'locale' => 'en',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('manage')
            ->post('/locale', ['locale' => 'bn'])
            ->assertRedirect();

        $this->onPortal('manage')
            ->post('/login', [
                'email' => $finance->email,
                'password' => 'SyntheticPassword123',
            ])
            ->assertRedirect('/');

        $this->assertSame('bn', $finance->fresh()->locale);

        $this->onPortal('manage')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'bn'));
    }
}

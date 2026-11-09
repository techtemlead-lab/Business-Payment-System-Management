<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The owner's rule: any 8 characters are accepted as a password, and nothing else is checked.
 */
class PasswordChoiceRulesTest extends TestCase
{
    use RefreshDatabase;

    private const OldPassword = 'Old-Password-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_partner_can_change_to_eight_digits(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'password' => self::OldPassword]);

        $this->actingAs($partner)->onPortal('dealer')->from('/security')->post('/security/password', [
            'current_password' => self::OldPassword,
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('12345678', (string) $partner->fresh()->password));
    }

    public function test_a_staff_member_can_change_to_eight_small_letters(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'status' => 'active', 'password' => self::OldPassword]);

        $this->actingAs($staff)->onPortal('manage')->from('/security')->post('/security/password', [
            'current_password' => self::OldPassword,
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('abcdefgh', (string) $staff->fresh()->password));
    }

    public function test_seven_characters_are_still_refused(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'password' => self::OldPassword]);

        $this->actingAs($partner)->onPortal('dealer')->from('/security')->post('/security/password', [
            'current_password' => self::OldPassword,
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OldPassword, (string) $partner->fresh()->password));
    }

    public function test_the_emailed_password_link_accepts_eight_digits(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'password' => self::OldPassword]);
        $token = Password::createToken($partner);

        $this->onPortal('dealer')->post('/reset-password', [
            'token' => $token,
            'email' => $partner->email,
            'password' => '87654321',
            'password_confirmation' => '87654321',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('87654321', (string) $partner->fresh()->password));
    }

    public function test_the_old_cookie_of_the_same_browser_stops_working_after_a_password_change(): void
    {
        config(['session.driver' => 'database']);
        $partner = User::factory()->dealer()->create(['status' => 'active', 'password' => self::OldPassword]);
        $this->actingAs($partner)->onPortal('dealer')->get('/security')->assertOk();
        $oldSessionId = (string) DB::table('sessions')->where('user_id', $partner->id)->value('id');
        $this->assertNotSame('', $oldSessionId);

        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->actingAs($partner)->onPortal('dealer')->from('/security')->post('/security/password', [
                'current_password' => self::OldPassword,
                'password' => 'New-Password-2026',
                'password_confirmation' => 'New-Password-2026',
            ])->assertRedirect('/security');

        $this->assertDatabaseMissing('sessions', ['id' => $oldSessionId]);
        $this->assertDatabaseHas('sessions', ['user_id' => $partner->id]);
    }
}

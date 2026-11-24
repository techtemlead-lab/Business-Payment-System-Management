<?php

namespace Tests\Feature;

use App\Domain\Auth\SignInGuard;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * SA-01, JA-04, JA-11 and the reset-link part of SA-10 (readiness test of 2026-11-18).
 */
class SignInHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const Password = 'Synthetic4826';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.preview_mode', false);
        Config::set('madina.login_otp.enabled', false);
        Config::set('madina.login_captcha.enabled', false);
    }

    private function dealer(array $attributes = []): User
    {
        return User::factory()->dealer()->create([
            'dealer_code' => 'MNHC1001',
            'phone' => null,
            'password' => self::Password,
            ...$attributes,
        ]);
    }

    public function test_every_spelling_of_one_partner_code_shares_one_lockout(): void
    {
        $this->dealer();

        foreach (['MNHC1001', 'mnhc1001', 'mnhc-1001', 'MNHC 1001', 'mnhc 10 01'] as $spelling) {
            $this->onPortal('dealer')
                ->post('/login', ['email' => $spelling, 'password' => 'Wrong'.$spelling])
                ->assertSessionHasErrors(['email' => __('portal.flash_bad_credentials')]);
        }

        // The right password in yet another spelling is still refused: the account itself is locked.
        $this->onPortal('dealer')
            ->post('/login', ['email' => 'M NHC1001', 'password' => self::Password])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('seconds', strtolower((string) session('errors')->first('email')));
    }

    public function test_the_correct_password_still_signs_in_after_a_few_wrong_tries(): void
    {
        $dealer = $this->dealer();
        foreach (['MNHC1001', 'mnhc 1001'] as $spelling) {
            $this->onPortal('dealer')->post('/login', ['email' => $spelling, 'password' => 'Nope1234']);
        }

        $this->onPortal('dealer')
            ->post('/login', ['email' => 'mnhc1001', 'password' => self::Password])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    public function test_an_address_with_many_failures_must_answer_the_picture_and_other_addresses_do_not(): void
    {
        $dealer = $this->dealer();
        for ($i = 0; $i < SignInGuard::AddressFailuresBeforeCaptcha; $i++) {
            $this->onPortal('dealer')
                ->post('/login', ['email' => 'nobody'.$i.'@example.test', 'password' => 'Guess'.$i]);
        }

        $this->onPortal('dealer')->get('/login')->assertOk()->assertSee('name="captcha"', false);
        $this->onPortal('dealer')
            ->post('/login', ['email' => 'mnhc1001', 'password' => self::Password])
            ->assertSessionHasErrors('captcha');
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->onPortal('dealer')
            ->get('/login')->assertOk()->assertDontSee('name="captcha"', false);
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->onPortal('dealer')
            ->post('/login', ['email' => 'mnhc1001', 'password' => self::Password])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    public function test_a_suspended_partner_with_the_right_password_is_told_to_call_the_office(): void
    {
        $this->dealer(['status' => 'suspended']);

        $this->onPortal('dealer')
            ->post('/login', ['email' => 'MNHC1001', 'password' => self::Password])
            ->assertSessionHasErrors('email');
        $message = (string) session('errors')->first('email');
        $this->assertStringContainsString('suspended', $message);
        $this->assertNotSame(__('portal.flash_bad_credentials'), $message);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'login.failed']);
    }

    public function test_a_wrong_password_on_a_suspended_account_does_not_reveal_the_suspension(): void
    {
        $this->dealer(['status' => 'suspended']);

        $this->onPortal('dealer')
            ->post('/login', ['email' => 'MNHC1001', 'password' => 'NotTheRightOne'])
            ->assertSessionHasErrors(['email' => __('portal.flash_bad_credentials')]);
    }

    public function test_a_phone_shared_with_a_suspended_account_still_signs_in_the_active_one(): void
    {
        $active = $this->dealer(['dealer_code' => 'MNHC1002', 'phone' => '01712340001']);
        $this->dealer(['dealer_code' => 'MNHC1003', 'phone' => '01712340001', 'status' => 'suspended']);

        $this->onPortal('dealer')
            ->post('/login', ['email' => '01712340001', 'password' => self::Password])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($active);
    }

    public function test_common_pins_and_the_partners_own_phone_and_code_are_refused_as_a_new_password(): void
    {
        $dealer = $this->dealer(['phone' => '01712345678']);

        $guard = app(SignInGuard::class);
        foreach (['11111111', '87654321', 'password', '8801712345678', '1712345678', 'MNHC1001', 'mnhc-1001'] as $tooEasy) {
            $this->assertTrue($guard->isTooEasy($tooEasy, $dealer), $tooEasy);
        }
        $this->assertFalse($guard->isTooEasy('40719352', $dealer));

        // The security page allows only a few tries a minute, so three cases go through it.
        foreach (['12345678', '01712345678', 'mnhc1001'] as $tooEasy) {
            $this->actingAs($dealer)->onPortal('dealer')->from('/security')->post('/security/password', [
                'current_password' => self::Password,
                'password' => $tooEasy,
                'password_confirmation' => $tooEasy,
            ])->assertSessionHasErrors(['password' => __('security.password_too_easy')]);
        }
        $this->assertTrue(Hash::check(self::Password, (string) $dealer->fresh()->password));

        // Digits only stay allowed (owner rule).
        $this->actingAs($dealer)->onPortal('dealer')->from('/security')->post('/security/password', [
            'current_password' => self::Password,
            'password' => '40719352',
            'password_confirmation' => '40719352',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('40719352', (string) $dealer->fresh()->password));
    }

    public function test_a_too_easy_password_through_the_emailed_link_is_refused_and_the_link_still_works(): void
    {
        $dealer = $this->dealer();
        $token = Password::createToken($dealer);

        $this->onPortal('dealer')->post('/reset-password', [
            'token' => $token, 'email' => $dealer->email, 'password' => '12345678', 'password_confirmation' => '12345678',
        ])->assertSessionHasErrors(['password' => __('security.password_too_easy')]);

        $this->onPortal('dealer')->post('/reset-password', [
            'token' => $token, 'email' => $dealer->email, 'password' => '40719352', 'password_confirmation' => '40719352',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('40719352', (string) $dealer->fresh()->password));
    }

    public function test_password_reset_for_a_shared_email_changes_nobody(): void
    {
        $older = User::factory()->dealer()->create([
            'email' => 'shared@example.test',
            'password' => self::Password,
        ]);
        $newer = User::factory()->dealer()->create([
            'email' => 'Shared@example.test',
            'password' => self::Password,
        ]);
        $token = Password::createToken($older);

        $this->onPortal('dealer')->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'shared@example.test'])
            ->assertSessionHas('success');
        $this->onPortal('dealer')->post('/reset-password', [
            'token' => $token,
            'email' => 'shared@example.test',
            'password' => '40719352',
            'password_confirmation' => '40719352',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::Password, (string) $older->fresh()->password));
        $this->assertTrue(Hash::check(self::Password, (string) $newer->fresh()->password));
        $this->assertSame(Password::INVALID_USER, Password::sendResetLink(['email' => 'shared@example.test']));
    }

    public function test_password_reset_matches_the_one_account_when_the_email_case_differs(): void
    {
        $dealer = User::factory()->dealer()->create([
            'email' => 'Partner@example.test',
            'password' => self::Password,
        ]);
        $token = Password::createToken($dealer);

        $this->onPortal('dealer')->post('/reset-password', [
            'token' => $token,
            'email' => 'partner@example.test',
            'password' => '40719352',
            'password_confirmation' => '40719352',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('40719352', (string) $dealer->fresh()->password));
    }

    public function test_a_password_change_cancels_reset_links_still_waiting(): void
    {
        $dealer = $this->dealer();
        $token = Password::createToken($dealer);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $dealer->email]);

        $this->actingAs($dealer)->onPortal('dealer')->from('/security')->post('/security/password', [
            'current_password' => self::Password,
            'password' => '40719352',
            'password_confirmation' => '40719352',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $dealer->email]);

        auth()->logout();
        $this->onPortal('dealer')->post('/reset-password', [
            'token' => $token, 'email' => $dealer->email, 'password' => '58302716', 'password_confirmation' => '58302716',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('40719352', (string) $dealer->fresh()->password));
    }

    public function test_forgot_password_is_limited_per_account_not_per_shared_connection(): void
    {
        foreach ([1, 2, 3] as $try) {
            $this->onPortal('dealer')->from('/forgot-password')
                ->post('/forgot-password', ['email' => 'first@example.test'])
                ->assertSessionHasNoErrors();
        }

        $this->onPortal('dealer')->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'first@example.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors(['email' => __('security.reset_wait_minutes', ['minutes' => 1])]);

        // Someone else on the same office connection is not held up.
        $this->onPortal('dealer')->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'second@example.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_a_partner_always_lands_on_the_home_page_after_signing_in(): void
    {
        $dealer = $this->dealer();
        // PP-10: a page asked for before signing in is not where a partner lands.
        $this->withSession(['url.intended' => 'http://'.PortalHost::host('dealer').'/orders'])
            ->onPortal('dealer')
            ->post('/login', ['email' => 'MNHC1001', 'password' => self::Password])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }
}

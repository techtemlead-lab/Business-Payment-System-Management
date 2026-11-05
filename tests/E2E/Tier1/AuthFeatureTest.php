<?php

namespace Tests\E2E\Tier1;

use App\Domain\Auth\TotpAuthenticator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Tests\E2E\E2ETestCase;

/**
 * Tier 1 — Auth Feature Tests (F01: Partner Auth & OTP, F02: Staff Auth & 2FA).
 *
 * Verifies partner and staff authentication, dealer code lookups, rate limiting,
 * OTP dispatch, TOTP two-factor enrollment and verification, and portal access restrictions.
 */
class AuthFeatureTest extends E2ETestCase
{
    /**
     * F01: Partner logs in successfully with email and password on dealer portal.
     */
    public function test_partner_logs_in_with_email_and_password(): void
    {
        $dealer = $this->createDealer([
            'email' => 'dealer.auth@example.com',
            'password' => 'SecretPass123',
        ]);

        $response = $this->onPortal('dealer')->post('/login', [
            'email' => 'dealer.auth@example.com',
            'password' => 'SecretPass123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    /**
     * F01: Partner logs in successfully with case-insensitive dealer code.
     */
    public function test_partner_logs_in_with_dealer_code_case_insensitively(): void
    {
        $dealer = $this->createDealer([
            'dealer_code' => 'MNHC-D999',
            'password' => 'SecretPass123',
        ]);

        $response = $this->onPortal('dealer')->post('/login', [
            'email' => 'mnhc-d999',
            'password' => 'SecretPass123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    /**
     * F01: Partner login is rejected with invalid password.
     */
    public function test_partner_login_fails_with_invalid_password(): void
    {
        $this->createDealer([
            'email' => 'dealer.fail@example.com',
            'password' => 'CorrectPassword',
        ]);

        $response = $this->onPortal('dealer')->post('/login', [
            'email' => 'dealer.fail@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * F01: Partner login with OTP enabled dispatches SMS OTP and completes verification.
     */
    public function test_partner_login_with_otp_flow(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.login_otp.enabled', true);

        $dealer = $this->createDealer([
            'email' => 'dealer.otp@example.com',
            'password' => 'SecretPass123',
            'phone' => '01712345678',
        ]);

        $response = $this->onPortal('dealer')->post('/login', [
            'email' => 'dealer.otp@example.com',
            'password' => 'SecretPass123',
        ]);

        $response->assertRedirect('/login/otp');
        $this->assertGuest();

        $sentSms = config('madina.sms.sent', []);
        $this->assertNotEmpty($sentSms, 'Expected SMS OTP to be dispatched');

        preg_match('/\b\d{6}\b/', $sentSms[0]['message'], $matches);
        $this->assertNotEmpty($matches, 'Expected 6-digit OTP in message');
        $otp = $matches[0];

        $verifyResponse = $this->onPortal('dealer')->post('/login/otp', [
            'code' => $otp,
        ]);

        $verifyResponse->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    /**
     * F01: Repeated failed logins trigger rate limiter throttling.
     */
    public function test_partner_login_rate_limiting_throttles_failed_attempts(): void
    {
        $this->createDealer([
            'email' => 'throttle@example.com',
            'dealer_code' => 'THROTTLE-01',
            'password' => 'SecretPass123',
        ]);

        $key = 'THROTTLE-01|127.0.0.1';
        RateLimiter::clear($key);

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 60);
        }

        $response = $this->onPortal('dealer')->post('/login', [
            'email' => 'THROTTLE-01',
            'password' => 'WrongPassword',
        ]);

        $response->assertSessionHasErrors('email');
        $errorMessage = session('errors')->first('email');
        $this->assertMatchesRegularExpression('/\d+/', (string) $errorMessage);
    }

    /**
     * F01: Suspended partner account is prevented from authenticating.
     */
    public function test_suspended_partner_cannot_authenticate(): void
    {
        $this->createDealer([
            'email' => 'suspended@example.com',
            'password' => 'SecretPass123',
            'status' => 'suspended',
        ]);

        $response = $this->onPortal('dealer')->post('/login', [
            'email' => 'suspended@example.com',
            'password' => 'SecretPass123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * F02: Staff member authenticates on manage portal.
     */
    public function test_staff_member_logs_in_on_manage_portal(): void
    {
        $staff = $this->createStaff('finance', [
            'email' => 'finance.staff@example.com',
            'password' => 'StaffPass123',
        ]);

        $response = $this->onPortal('manage')->post('/login', [
            'email' => 'finance.staff@example.com',
            'password' => 'StaffPass123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
    }

    /**
     * F02: Staff with TOTP 2FA enrolled is prompted for TOTP challenge and succeeds.
     */
    public function test_staff_with_totp_completes_2fa_challenge(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.totp.enabled', true);

        $totp = app(TotpAuthenticator::class);
        $secret = $totp->generateSecret();

        $staff = $this->createStaff('finance', [
            'email' => 'finance.totp@example.com',
            'password' => 'StaffPass123',
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->onPortal('manage')->post('/login', [
            'email' => 'finance.totp@example.com',
            'password' => 'StaffPass123',
        ]);

        $response->assertRedirect('/login/2fa');
        $this->assertGuest();

        $code = $this->currentTotpCode($secret);
        $challengeResponse = $this->onPortal('manage')->post('/login/2fa', ['code' => $code]);

        $challengeResponse->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
    }

    /**
     * F02: Staff submitting invalid TOTP code is rejected.
     */
    public function test_staff_submitting_invalid_totp_code_fails(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.totp.enabled', true);

        $totp = app(TotpAuthenticator::class);
        $secret = $totp->generateSecret();

        $this->createStaff('finance', [
            'email' => 'invalid.totp@example.com',
            'password' => 'StaffPass123',
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $this->onPortal('manage')->post('/login', [
            'email' => 'invalid.totp@example.com',
            'password' => 'StaffPass123',
        ])->assertRedirect('/login/2fa');

        $response = $this->onPortal('manage')->post('/login/2fa', ['code' => '000000']);
        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /**
     * F02: Super admin authenticates successfully on sadmin portal.
     */
    public function test_super_admin_logs_in_on_sadmin_portal(): void
    {
        $admin = $this->createStaff('super_admin', [
            'email' => 'superadmin@example.com',
            'password' => 'AdminPass123',
        ]);

        $response = $this->onPortal('sadmin')->post('/login', [
            'email' => 'superadmin@example.com',
            'password' => 'AdminPass123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * F02: Partner account is restricted from accessing staff manage portal.
     */
    public function test_partner_cannot_authenticate_as_staff(): void
    {
        $dealer = $this->createDealer([
            'email' => 'dealer.trystaff@example.com',
            'password' => 'DealerPass123',
        ]);

        $response = $this->onPortal('manage')->post('/login', [
            'email' => 'dealer.trystaff@example.com',
            'password' => 'DealerPass123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}

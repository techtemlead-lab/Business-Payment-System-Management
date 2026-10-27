<?php

namespace Tests\Feature;

use App\Domain\Auth\TotpAuthenticator;
use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_mode_forces_partner_login_otp_off_even_when_owner_enabled(): void
    {
        Config::set('madina.preview_mode', true);
        Config::set('madina.login_otp.enabled', true);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $owner = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'password' => 'OwnerPassword12345',
        ]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/partner-login-otp', [
            'enabled' => '1',
            'version' => 0,
            'current_password' => 'OwnerPassword12345',
        ])->assertRedirect();

        $settings = app(SecuritySettings::class)->partnerLoginOtp();
        $this->assertTrue($settings['stored_enabled']);
        $this->assertTrue($settings['preview_forced_off']);
        $this->assertFalse($settings['enabled']);

        $this->onPortal('sadmin')->post('/logout');
        $this->assertGuest();

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01711112222',
            'password' => 'SyntheticPassword123',
        ]);
        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_owner_can_disable_partner_login_otp_outside_preview(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.login_otp.enabled', true);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $owner = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'password' => 'OwnerPassword12345',
        ]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/partner-login-otp', [
            'enabled' => '0',
            'version' => 0,
            'current_password' => 'OwnerPassword12345',
        ])->assertRedirect();

        $this->assertFalse(app(SecuritySettings::class)->partnerLoginOtpEnabled());

        $this->onPortal('sadmin')->post('/logout');
        $this->assertGuest();

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01722223333',
            'password' => 'SyntheticPassword123',
        ]);
        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_first_staff_login_does_not_require_totp_until_enrolled(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.totp.enabled', true);

        $staff = User::factory()->create([
            'role' => 'finance',
            'status' => 'active',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('manage')
            ->post('/login', ['email' => $staff->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
    }

    public function test_enrolled_staff_must_pass_totp_outside_preview(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.totp.enabled', true);
        $totp = app(TotpAuthenticator::class);
        $secret = $totp->generateSecret();

        $staff = User::factory()->create([
            'role' => 'finance',
            'status' => 'active',
            'password' => 'SyntheticPassword123',
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $this->onPortal('manage')
            ->post('/login', ['email' => $staff->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/2fa');
        $this->assertGuest();

        $code = $this->currentTotpCode($secret);
        $this->onPortal('manage')->post('/login/2fa', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
    }

    public function test_preview_mode_skips_totp_challenge_even_when_enrolled(): void
    {
        Config::set('madina.preview_mode', true);
        Config::set('madina.totp.enabled', true);
        $totp = app(TotpAuthenticator::class);
        $secret = $totp->generateSecret();

        $owner = User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'password' => 'SyntheticPassword123',
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $this->onPortal('sadmin')
            ->post('/login', ['email' => $owner->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_security_headers_include_csp_referrer_and_nosniff(): void
    {
        $this->withoutVite();
        $response = $this->get('/');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $response->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_audit_events_are_append_only(): void
    {
        Audit::record(null, 'security.test', 'fixture', ['ok' => true]);
        $id = (int) DB::table('audit_events')->where('action', 'security.test')->value('id');
        $this->assertGreaterThan(0, $id);

        $this->expectException(\Throwable::class);
        DB::table('audit_events')->where('id', $id)->update(['action' => 'tampered']);
    }

    public function test_admin_ip_allowlist_blocks_manage_portal(): void
    {
        Config::set('madina.admin_ip_allowlist', '203.0.113.10');
        $this->onPortal('manage')->get('/login')->assertForbidden();

        Config::set('madina.admin_ip_allowlist', '127.0.0.1');
        $this->onPortal('manage')->get('/login')->assertOk();
    }

    private function currentTotpCode(string $secret): string
    {
        $authenticator = app(TotpAuthenticator::class);
        $reflection = new \ReflectionClass($authenticator);
        $decode = $reflection->getMethod('base32Decode');
        $decode->setAccessible(true);
        $hotp = $reflection->getMethod('hotp');
        $hotp->setAccessible(true);
        $binary = $decode->invoke($authenticator, $secret);

        return $hotp->invoke($authenticator, $binary, intdiv(time(), 30));
    }
}

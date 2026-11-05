<?php

namespace Tests\Feature;

use App\Domain\Auth\LoginCaptcha;
use App\Domain\Auth\LoginOtpChallenge;
use App\Domain\Auth\TotpAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoginTwoStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.preview_mode', false);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        Config::set('madina.login_otp.enabled', true);
    }

    private function dealer(array $attributes = []): User
    {
        return User::factory()->dealer()->create([
            'phone' => '01712345678',
            'password' => 'SyntheticPassword123',
            ...$attributes,
        ]);
    }

    private function seedCaptcha(string $text = 'ABCDE', int $secondsLeft = 120): void
    {
        $this->app['session']->put(LoginCaptcha::SessionKey, ['text' => $text, 'expires_at' => now()->addSeconds($secondsLeft)->getTimestamp()]);
    }

    public function test_every_portal_shows_the_captcha_and_serves_a_fresh_picture(): void
    {
        foreach (['sadmin', 'manage', 'dealer', 'subdealer'] as $portal) {
            $this->onPortal($portal)->get('/login')->assertOk()
                ->assertSee('name="captcha"', false)
                ->assertSee('/login/captcha', false)
                ->assertSee(__('auth_ui.captcha_refresh'));

            $first = $this->onPortal($portal)->get('/login/captcha');
            $first->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
            $this->assertStringStartsWith("\x89PNG", (string) $first->getContent());
            $stored = session(LoginCaptcha::SessionKey);
            $this->assertMatchesRegularExpression('/^['.LoginCaptcha::Alphabet.']{'.LoginCaptcha::Length.'}$/', $stored['text']);
        }
    }

    public function test_wrong_captcha_is_rejected_before_the_password_is_checked_and_is_single_use(): void
    {
        $dealer = $this->dealer();
        $this->seedCaptcha('ABCDE');

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123', 'captcha' => 'ABCDX'])
            ->assertSessionHasErrors(['captcha' => __('auth_ui.captcha_wrong')]);
        $this->assertGuest();
        $this->assertSame([], config('madina.sms.sent'));
        $this->assertNull(session(LoginCaptcha::SessionKey));
        $this->assertDatabaseHas('audit_events', ['action' => 'login.failed', 'subject' => 'unknown']);

        // The same (now correct) answer cannot be replayed: the picture is gone.
        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123', 'captcha' => 'ABCDE'])
            ->assertSessionHasErrors(['captcha' => __('auth_ui.captcha_expired')]);
        $this->assertGuest();
    }

    public function test_a_failed_sign_in_returns_to_the_form_not_to_the_captcha_picture(): void
    {
        foreach (['sadmin', 'manage', 'dealer', 'subdealer'] as $portal) {
            $this->onPortal($portal)->get('/login')->assertOk();
            $this->onPortal($portal)->get('/login/captcha')->assertOk();
            $this->onPortal($portal)
                ->post('/login', ['email' => 'nobody@example.test', 'password' => 'WrongPassword123', 'captcha' => 'WRONG'])
                ->assertRedirect('/login');
        }
    }

    public function test_captcha_answer_ignores_case_and_spaces_but_expires(): void
    {
        $dealer = $this->dealer();
        $this->seedCaptcha('ABCDE', -1);
        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123', 'captcha' => 'ABCDE'])
            ->assertSessionHasErrors(['captcha' => __('auth_ui.captcha_expired')]);

        $this->seedCaptcha('ABCDE');
        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123', 'captcha' => ' ab cde '])
            ->assertRedirect('/login/otp');
    }

    public function test_wrong_captchas_count_towards_the_sign_in_lockout(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $dealer = $this->dealer();
        for ($i = 0; $i < 5; $i++) {
            $this->seedCaptcha('ABCDE');
            $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123', 'captcha' => 'WRONG']);
        }

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'login.failed', 'subject' => 'unknown']);
        $this->assertTrue(\DB::table('audit_events')->where('action', 'login.failed')->where('metadata', 'like', '%"reason":"locked"%')->exists());
    }

    public function test_sub_dealer_code_signs_in_with_sms_code_and_success_is_audited(): void
    {
        $subDealer = User::factory()->subDealer()->create([
            'dealer_code' => 'MNHC-100001-000001',
            'phone' => '01812345678',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('subdealer')
            ->post('/login', ['email' => 'mnhc-100001-000001', 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp');
        $this->assertGuest();
        $this->onPortal('subdealer')->get('/login/otp')->assertOk()
            ->assertSee(__('auth_ui.otp_sent_to', ['digits' => '678']))
            ->assertDontSee(__('auth_ui.otp_dev_code', ['code' => '']));

        $this->onPortal('subdealer')->post('/login/otp', ['code' => $this->lastLoginCode()])->assertRedirect('/');
        $this->assertAuthenticatedAs($subDealer);
        $login = \DB::table('audit_events')->where('action', 'login')->where('actor_id', $subDealer->id)->first();
        $this->assertNotNull($login);
        $this->assertSame('sms', json_decode($login->metadata, true)['second_step']);
    }

    public function test_the_sms_code_is_never_written_to_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged): void {
            $logged[] = $entry->message.' '.json_encode($entry->context);
        });
        $dealer = $this->dealer();
        $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
        $this->travel(LoginOtpChallenge::ResendCooldownSeconds + 1)->seconds();
        $this->onPortal('dealer')->post('/login/otp/resend')->assertSessionHasNoErrors();
        $code = $this->lastLoginCode();
        $this->assertNotNull($code);

        $this->assertNotEmpty($logged, 'The SMS log line itself should still be written.');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($code, $line);
        }
    }

    public function test_resend_waits_for_the_cooldown_and_replaces_the_old_code(): void
    {
        $dealer = $this->dealer();
        $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
        $firstCode = $this->lastLoginCode();

        $this->onPortal('dealer')->post('/login/otp/resend')
            ->assertRedirect('/login/otp')
            ->assertSessionHasErrors('code');
        $this->assertCount(1, config('madina.sms.sent'));

        $this->travel(LoginOtpChallenge::ResendCooldownSeconds + 1)->seconds();
        $this->onPortal('dealer')->post('/login/otp/resend')->assertRedirect('/login/otp')->assertSessionHasNoErrors();
        $this->assertCount(2, config('madina.sms.sent'));
        $secondCode = $this->lastLoginCode();
        $this->assertDatabaseHas('audit_events', ['action' => 'login.otp_resent', 'subject' => (string) $dealer->id]);

        if ($firstCode !== $secondCode) {
            $this->onPortal('dealer')->post('/login/otp', ['code' => $firstCode])->assertSessionHasErrors('code');
        }
        $this->onPortal('dealer')->post('/login/otp', ['code' => $secondCode])->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    public function test_codes_sent_per_hour_are_capped(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $dealer = $this->dealer();
        $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123']);
        for ($i = 1; $i < LoginOtpChallenge::SendsPerHour; $i++) {
            $this->travel(LoginOtpChallenge::ResendCooldownSeconds + 1)->seconds();
            $this->onPortal('dealer')->post('/login/otp/resend')->assertSessionHasNoErrors();
        }
        $this->assertCount(LoginOtpChallenge::SendsPerHour, config('madina.sms.sent'));

        $this->travel(LoginOtpChallenge::ResendCooldownSeconds + 1)->seconds();
        $this->onPortal('dealer')->post('/login/otp/resend')->assertSessionHasErrors('code');
        $this->assertCount(LoginOtpChallenge::SendsPerHour, config('madina.sms.sent'));
    }

    public function test_wrong_codes_lock_the_account_across_sign_ins(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $dealer = $this->dealer();
        $wrong = 0;
        while ($wrong < LoginOtpChallenge::WrongCodesPerHour) {
            $this->travel(LoginOtpChallenge::ResendCooldownSeconds + 1)->seconds();
            $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
            for ($i = 0; $i < 4 && $wrong < LoginOtpChallenge::WrongCodesPerHour; $i++, $wrong++) {
                $this->onPortal('dealer')->post('/login/otp', ['code' => '000000'])->assertSessionHasErrors('code');
            }
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'login.otp_locked', 'subject' => (string) $dealer->id]);

        // Even with a fresh, correct code the account stays locked for the hour.
        $this->travel(LoginOtpChallenge::ResendCooldownSeconds + 1)->seconds();
        $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
        $this->onPortal('dealer')->post('/login/otp', ['code' => $this->lastLoginCode()])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_waiting_sign_in_expires(): void
    {
        $dealer = $this->dealer();
        $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
        $code = $this->lastLoginCode();

        $this->travel(16)->minutes();
        $this->onPortal('dealer')->get('/login/otp')->assertRedirect('/login');
        $this->onPortal('dealer')->post('/login/otp', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_staff_without_a_phone_are_stopped_and_super_admin_gets_through_audited(): void
    {
        $staff = User::factory()->staff('finance')->create(['phone' => null, 'password' => 'SyntheticPassword123']);
        $this->onPortal('manage')
            ->post('/login', ['email' => $staff->email, 'password' => 'SyntheticPassword123'])
            ->assertSessionHasErrors(['email' => __('auth_ui.otp_no_phone')]);
        $this->assertGuest();
        $this->assertTrue(\DB::table('audit_events')->where('action', 'login.failed')->where('subject', (string) $staff->id)->where('metadata', 'like', '%"reason":"no_phone"%')->exists());

        $owner = User::factory()->staff('super_admin')->create(['phone' => null, 'password' => 'SyntheticPassword123']);
        $this->onPortal('sadmin')->post('/login', ['email' => $owner->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('audit_events', ['action' => 'login.second_factor_bypassed', 'subject' => (string) $owner->id]);
    }

    public function test_staff_with_an_authenticator_app_pass_sms_then_the_app_code(): void
    {
        Config::set('madina.totp.enabled', true);
        $totp = app(TotpAuthenticator::class);
        $secret = $totp->generateSecret();
        $staff = User::factory()->staff('finance')->create([
            'phone' => '01912345678',
            'password' => 'SyntheticPassword123',
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $this->onPortal('manage')->post('/login', ['email' => $staff->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
        $this->onPortal('manage')->post('/login/otp', ['code' => $this->lastLoginCode()])->assertRedirect('/login/2fa');
        $this->assertGuest();

        $reflection = new \ReflectionClass($totp);
        $binary = $reflection->getMethod('base32Decode')->invoke($totp, $secret);
        $code = $reflection->getMethod('hotp')->invoke($totp, $binary, intdiv(time(), 30));
        $this->onPortal('manage')->post('/login/2fa', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
        $login = \DB::table('audit_events')->where('action', 'login')->where('actor_id', $staff->id)->first();
        $this->assertSame('sms+totp', json_decode($login->metadata, true)['second_step']);
    }

    public function test_the_api_works_with_the_session_from_the_two_step_sign_in(): void
    {
        $dealer = $this->dealer();
        $this->signInThroughForm('dealer', $dealer->email, 'SyntheticPassword123')->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
        $this->onPortal('dealer')->getJson('/api/v1/catalog')->assertOk();
    }

    public function test_code_is_shown_on_screen_only_on_a_developer_machine_without_sms(): void
    {
        Config::set('madina.sms.driver', 'log');
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        $dealer = $this->dealer();
        $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])->assertRedirect('/login/otp');
        $code = (string) session('login_otp_dev_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->onPortal('dealer')->get('/login/otp')->assertOk()->assertSee($code);

        $this->app['env'] = 'production';
        $this->assertFalse(app(LoginOtpChallenge::class)->showsCodeOnScreen());
        $this->onPortal('dealer')->get('/login/otp')->assertOk()->assertDontSee($code);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Auth\TotpAuthenticator;
use App\Http\Middleware\RequireAuthenticatorSetup;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Owners (Super Admin, MD, Chairman) and finance staff must use an authenticator app
 * (owner decision Q14). Without one, the sign-in sends them to the setup page first.
 */
class LoginTotpRequiredTest extends TestCase
{
    use RefreshDatabase;

    private const Password = 'SyntheticPassword123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.preview_mode', false);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        Config::set('madina.login_otp.enabled', true);
        Config::set('madina.totp.enabled', true);
        Config::set('madina.totp.required', true);
    }

    public function test_owner_without_an_authenticator_app_is_sent_to_setup_after_the_sms_code(): void
    {
        $owner = $this->staffAccount('super_admin', '01912345671');

        $this->onPortal('sadmin')->post('/login', ['email' => $owner->email, 'password' => self::Password])->assertRedirect('/login/otp');
        $this->assertGuest();
        $this->onPortal('sadmin')->post('/login/otp', ['code' => $this->lastLoginCode()])
            ->assertRedirect(RequireAuthenticatorSetup::SetupPath)
            ->assertSessionHas('success', __('portal.flash_totp_setup_required'));
        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('audit_events', ['action' => 'security.totp_setup_required', 'actor_id' => $owner->id]);

        // Every other page waits until the app is set up; the setup page itself stays open.
        $this->onPortal('sadmin')->get('/')->assertRedirect(RequireAuthenticatorSetup::SetupPath);
        $this->onPortal('sadmin')->get('/orders')->assertRedirect(RequireAuthenticatorSetup::SetupPath);
        $this->onPortal('sadmin')->getJson('/api/v1/orders')->assertForbidden();
        $this->onPortal('sadmin')->get('/security')->assertOk()
            ->assertInertia(fn ($page) => $page->where('securityControls.totp.required', true)->where('securityControls.totp.confirmed', false));

        // Setting the app up through the existing forms opens the portal again.
        $this->onPortal('sadmin')->post('/security/totp/begin', ['current_password' => self::Password])->assertRedirect();
        $secret = (string) session('totp_setup_secret');
        $this->onPortal('sadmin')->post('/security/totp/confirm', ['current_password' => self::Password, 'code' => $this->currentCode($secret)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertTrue($owner->fresh()->hasTotpEnabled());
        $this->onPortal('sadmin')->get('/')->assertOk();
        $this->assertFalse(session()->has(RequireAuthenticatorSetup::SessionKey));
    }

    public function test_finance_staff_without_an_authenticator_app_are_sent_to_setup(): void
    {
        $finance = $this->staffAccount('finance', '01912345672');

        $this->signInThroughForm('manage', $finance->email, self::Password)->assertRedirect(RequireAuthenticatorSetup::SetupPath);
        $this->assertAuthenticatedAs($finance);
        $this->onPortal('manage')->get('/')->assertRedirect(RequireAuthenticatorSetup::SetupPath);
    }

    public function test_money_and_import_operations_also_wait_for_the_authenticator_app(): void
    {
        $finance = $this->staffAccount('finance', '01912345675');
        $this->signInThroughForm('manage', $finance->email, self::Password)->assertRedirect(RequireAuthenticatorSetup::SetupPath);

        $this->onPortal('manage')->post('/commands/operations/payment-accounts', ['label' => 'Main bKash'])
            ->assertRedirect(RequireAuthenticatorSetup::SetupPath);
        $this->onPortal('manage')->get('/commands/operations/views/payroll')
            ->assertRedirect(RequireAuthenticatorSetup::SetupPath);
        $this->onPortal('manage')->postJson('/api/v1/operations/payroll/prepare', ['month' => now()->format('Y-m')])
            ->assertForbidden();
        $this->onPortal('manage')->getJson('/api/v1/operations/imports')->assertForbidden();
        $this->onPortal('manage')->get('/commands/operations/imports')->assertRedirect(RequireAuthenticatorSetup::SetupPath);
        $this->assertDatabaseCount('payment_accounts', 0);
    }

    public function test_md_and_chairman_must_set_up_the_app_too(): void
    {
        foreach (['md' => '01912345673', 'chairman' => '01912345674'] as $role => $phone) {
            $owner = $this->staffAccount($role, $phone);
            $this->signInThroughForm('sadmin', $owner->email, self::Password)->assertRedirect(RequireAuthenticatorSetup::SetupPath);
            $this->onPortal('sadmin')->post('/logout');
            Config::set('madina.sms.sent', []);
        }
    }

    public function test_owner_with_an_authenticator_app_is_asked_for_the_app_code(): void
    {
        $secret = app(TotpAuthenticator::class)->generateSecret();
        $owner = $this->staffAccount('super_admin', '01912345675', ['totp_secret' => $secret, 'totp_confirmed_at' => now()]);

        $this->signInThroughForm('sadmin', $owner->email, self::Password)->assertRedirect('/login/2fa');
        $this->assertGuest();

        $this->onPortal('sadmin')->post('/login/2fa', ['code' => $this->currentCode($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
        $this->onPortal('sadmin')->get('/')->assertOk();
    }

    public function test_dealers_are_unaffected(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => '01712345676', 'password' => self::Password]);

        $this->signInThroughForm('dealer', $dealer->email, self::Password)->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
        $this->onPortal('dealer')->get('/')->assertOk();
        $this->assertFalse(session()->has(RequireAuthenticatorSetup::SessionKey));
    }

    public function test_other_office_staff_are_unaffected(): void
    {
        $support = $this->staffAccount('support', '01912345677');

        $this->signInThroughForm('manage', $support->email, self::Password)->assertRedirect('/');
        $this->assertAuthenticatedAs($support);
    }

    public function test_preview_mode_keeps_the_requirement_off(): void
    {
        Config::set('madina.preview_mode', true);
        $owner = $this->staffAccount('super_admin', '01912345678');

        $this->signInThroughForm('sadmin', $owner->email, self::Password)->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
        $this->onPortal('sadmin')->get('/')->assertOk();
    }

    public function test_the_requirement_can_be_switched_off_on_the_server(): void
    {
        Config::set('madina.totp.required', false);
        $owner = $this->staffAccount('super_admin', '01912345679');

        $this->signInThroughForm('sadmin', $owner->email, self::Password)->assertRedirect('/');
        $this->assertContains('staff_totp_not_required', app(DeploymentReadiness::class)->inspect()['warnings']);
    }

    public function test_readiness_lists_owners_and_finance_staff_without_the_app(): void
    {
        $owner = $this->staffAccount('super_admin', '01912345680');
        $this->assertContains('owners_or_finance_without_totp', app(DeploymentReadiness::class)->inspect()['warnings']);

        $owner->forceFill(['totp_secret' => app(TotpAuthenticator::class)->generateSecret(), 'totp_confirmed_at' => now()])->save();
        $this->staffAccount('support', '01912345681');
        $warnings = app(DeploymentReadiness::class)->inspect()['warnings'];
        $this->assertNotContains('owners_or_finance_without_totp', $warnings);
        $this->assertNotContains('staff_totp_not_required', $warnings);
    }

    public function test_the_production_default_requires_the_app(): void
    {
        $config = file_get_contents(config_path('madina.php'));

        $this->assertStringContainsString("'required' => env('MADINA_TOTP_REQUIRED', true)", $config);
        $this->assertStringContainsString('MADINA_TOTP_REQUIRED=true', file_get_contents(base_path('.env.production.example')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staffAccount(string $role, string $phone, array $attributes = []): User
    {
        return User::factory()->staff($role)->create(['phone' => $phone, 'password' => self::Password, ...$attributes]);
    }

    /** The code an authenticator app would show right now for this secret. */
    private function currentCode(string $secret): string
    {
        $totp = app(TotpAuthenticator::class);
        $reflection = new \ReflectionClass($totp);
        $binary = $reflection->getMethod('base32Decode')->invoke($totp, $secret);

        return (string) $reflection->getMethod('hotp')->invoke($totp, $binary, intdiv(time(), 30));
    }
}

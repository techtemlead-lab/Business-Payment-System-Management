<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PartnerLoginOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        Config::set('madina.login_otp.enabled', true);
    }

    public function test_partner_with_mobile_must_complete_sms_otp_before_session(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01711112222',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp')
            ->assertSessionHas('success');
        $this->assertGuest();

        $sent = config('madina.sms.sent');
        $this->assertSame('login.otp', $sent[0]['event'] ?? null);
        preg_match('/(\d{6})/', (string) $sent[0]['message'], $matches);

        $this->onPortal('dealer')->get('/login/otp')->assertOk()->assertSee('One-time code', false);
        $this->onPortal('dealer')->post('/login/otp', ['code' => $matches[1]])->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    public function test_partner_without_mobile_skips_otp(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => null,
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_staff_login_never_requires_otp(): void
    {
        $staff = User::factory()->create([
            'role' => 'finance',
            'status' => 'active',
            'phone' => '01733334444',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('manage')
            ->post('/login', ['email' => $staff->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($staff);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_wrong_otp_is_rejected(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01755556666',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp');

        $this->onPortal('dealer')
            ->post('/login/otp', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_bangla_locale_partner_receives_bangla_otp_sms(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01755556666',
            'locale' => 'bn',
            'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp');

        $sent = config('madina.sms.sent');
        $this->assertNotEmpty($sent);
        $message = (string) ($sent[array_key_last($sent)]['message'] ?? '');
        $this->assertStringContainsString('মদিনা লগইন কোড:', $message);
        $this->assertMatchesRegularExpression('/\d{6}/', $message);
    }
}

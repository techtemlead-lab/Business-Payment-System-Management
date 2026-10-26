<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalePortalRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_switch_stays_on_dealer_portal_host_and_path(): void
    {
        $this->onPortal('dealer')
            ->from('/login')
            ->post('/locale', ['locale' => 'bn'])
            ->assertRedirect(PortalHost::url('dealer', '/login'));
    }

    public function test_locale_switch_rebuilds_path_on_active_portal_when_previous_host_differs(): void
    {
        $this->onPortal('dealer')
            ->withHeader('Referer', 'http://example.test/forgot-password')
            ->post('/locale', ['locale' => 'bn'])
            ->assertRedirect(PortalHost::url('dealer', '/forgot-password'));
    }

    public function test_account_invite_flash_follows_admin_bangla_locale(): void
    {
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000105',
        ]);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/invite')
            ->assertRedirect()
            ->assertSessionHas('success', 'অ্যাকাউন্ট যোগ্য হলে ব্যক্তিগত পাসওয়ার্ড-সেটিং লিঙ্ক ইমেইল করা হয়েছে।');

        $sent = config('madina.sms.sent');
        $this->assertSame('account.invitation_sent', $sent[0]['event'] ?? null);
        $this->assertSame(
            __('sms.account_invitation_sent', [], 'bn'),
            $sent[0]['message'] ?? null,
        );
    }

    public function test_partner_otp_flash_follows_bangla_locale(): void
    {
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => [], 'madina.login_otp.enabled' => true]);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01711112222',
            'password' => 'SyntheticPassword123',
            'locale' => 'bn',
        ]);

        $this->onPortal('dealer')
            ->post('/locale', ['locale' => 'bn'])
            ->assertRedirect();

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp')
            ->assertSessionHas('success', 'আপনার নিবন্ধিত মোবাইলে পাঠানো ওয়ান-টাইম কোডটি লিখুন।');
    }
}

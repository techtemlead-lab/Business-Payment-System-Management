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
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/invite')
            ->assertRedirect()
            ->assertSessionHas('success', 'অ্যাকাউন্ট যোগ্য হলে ব্যক্তিগত পাসওয়ার্ড-সেটিং লিঙ্ক ইমেইল করা হয়েছে।');
    }
}

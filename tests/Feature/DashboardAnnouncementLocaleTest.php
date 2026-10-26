<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnnouncementLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_dealer_announcement_follows_active_locale(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        app()->setLocale('en');
        $english = DashboardManagement::noticeForUser($dealer);
        $this->assertSame(__('portal.dealer_announcement_default', [], 'en'), $english['text']);

        app()->setLocale('bn');
        $bangla = DashboardManagement::noticeForUser($dealer);
        $this->assertSame(__('portal.dealer_announcement_default', [], 'bn'), $bangla['text']);
        $this->assertNotSame($english['text'], $bangla['text']);
    }

    public function test_custom_dealer_announcement_is_not_rewritten_by_locale(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DashboardManagement::save([
            'dealer_announcement' => 'Special promotion week for all dealers!',
            'dealer_announcement_type' => 'warning',
        ]);

        app()->setLocale('bn');
        $notice = DashboardManagement::noticeForUser($dealer);
        $this->assertSame('Special promotion week for all dealers!', $notice['text']);
        $this->assertSame('warning', $notice['type']);
    }
}

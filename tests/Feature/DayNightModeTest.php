<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DayNightModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_include_theme_boot_and_day_night_toggle(): void
    {
        $this->withoutVite();

        $html = $this->onPortal('public')->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('madina-color-scheme', $html);
        $this->assertStringContainsString('madina-theme-domain', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringContainsString(__('site.night_mode'), $html);
        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString("? stored : 'light'", $html);
        $this->assertStringNotContainsString('prefers-color-scheme', $html);
        $this->assertStringContainsString('ref-theme-glyph', $html);
        $this->assertStringContainsString('ref-chat-icon', $html);
        $this->assertStringNotContainsString('fa-solid fa-sun', $html);
        $this->assertStringNotContainsString('fa-brands fa-whatsapp', $html);
    }

    public function test_portal_documents_include_theme_boot_script(): void
    {
        $this->withoutVite();
        $dealer = User::factory()->create(['role' => 'dealer']);

        $html = $this->actingAs($dealer)
            ->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portals/dealer/Dashboard'))
            ->getContent();

        $this->assertStringContainsString('madina-color-scheme', $html);
        $this->assertStringContainsString('color-scheme', $html);
    }

    public function test_company_auth_includes_theme_boot_and_toggle(): void
    {
        $this->withoutVite();

        $html = $this->onPortal('sadmin')->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('madina-color-scheme', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);
    }
}

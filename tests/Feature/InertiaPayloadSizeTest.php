<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InertiaPayloadSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_app_page_visits_send_bangla_as_plain_characters(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $version = (new HandleInertiaRequests)->version(Request::create('/'));

        $response = $this->actingAs($dealer)
            ->withSession(['locale' => 'bn'])
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get(PortalHost::url('dealer', '/orders'));

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('\u09', $body, 'Bangla letters must not be sent as \u escapes.');
        $this->assertStringContainsString('অর্ডার', $body);
        $this->assertSame('portals/dealer/Dashboard', $response->json('component'));
    }

    public function test_first_page_load_embeds_bangla_as_plain_characters_and_stays_script_safe(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'রহিম </script> স্টোর']);

        $response = $this->actingAs($dealer)
            ->withSession(['locale' => 'bn'])
            ->get(PortalHost::url('dealer', '/orders'));

        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertStringContainsString('<script data-page="app" type="application/json">', $html);
        $this->assertStringNotContainsString('\u09', $html);
        $this->assertStringContainsString('রহিম '.'\u003C/script\u003E'.' স্টোর', $html);
    }
}

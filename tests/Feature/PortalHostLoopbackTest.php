<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PortalHostLoopbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_maps_loopback_ip_to_public_when_domain_is_localhost(): void
    {
        Config::set('madina.app_domain', 'localhost');

        $request = Request::create('http://127.0.0.1:8088/', 'GET', server: [
            'HTTP_HOST' => '127.0.0.1:8088',
            'SERVER_NAME' => '127.0.0.1',
        ]);

        $this->assertSame('public', PortalHost::current($request));
    }

    public function test_csp_allows_vite_dev_server_while_hot(): void
    {
        $this->withoutVite();
        $hot = public_path('hot');
        file_put_contents($hot, 'http://127.0.0.1:5173');

        try {
            $csp = (string) $this->onPortal('public')->get('/')
                ->assertOk()
                ->headers->get('Content-Security-Policy');

            $this->assertStringContainsString('http://127.0.0.1:5173', $csp);
            $this->assertStringContainsString('ws://127.0.0.1:5173', $csp);
            $this->assertMatchesRegularExpression('/script-src[^;]*127\.0\.0\.1:5173/', $csp);
            $this->assertMatchesRegularExpression('/style-src[^;]*127\.0\.0\.1:5173/', $csp);
            $this->assertMatchesRegularExpression('/connect-src[^;]*127\.0\.0\.1:5173/', $csp);
        } finally {
            @unlink($hot);
        }
    }

    public function test_built_vite_assets_use_root_relative_urls(): void
    {
        $html = $this->onPortal('public')->get('/')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#(?:href|src)="/build/assets/[^"]+"#', $html);
        $this->assertStringNotContainsString('http://127.0.0.1:5173', $html);
        $this->assertDoesNotMatchRegularExpression('#(?:href|src)="https?://[^"]+/build/assets/#', $html);
    }
}

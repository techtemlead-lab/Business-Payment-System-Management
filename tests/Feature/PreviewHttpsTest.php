<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewHttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_https_login_redirect_when_backend_connection_is_http(): void
    {
        config(['app.force_https' => true]);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->get('http://preview.example/dashboard')->assertRedirect('https://preview.example/login');
    }

    public function test_local_development_keeps_http_when_https_is_not_enabled(): void
    {
        $this->get('http://preview.example/dashboard')->assertRedirect('http://preview.example/login');
    }
}

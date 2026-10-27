<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ForceHttpsSchemeTest extends TestCase
{
    public function test_https_app_url_forces_https_scheme_for_generated_urls(): void
    {
        config(['app.url' => 'https://madina.example.test']);
        URL::forceRootUrl('http://madina.example.test');
        URL::forceScheme('http');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('https', parse_url((string) url('/login'), PHP_URL_SCHEME));
        $this->assertSame('https://madina.example.test/login', url('/login'));
    }

    public function test_http_app_url_does_not_force_https_scheme(): void
    {
        config(['app.url' => 'http://madina.example.test']);
        URL::forceRootUrl('http://madina.example.test');
        URL::forceScheme('http');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('http', parse_url((string) url('/login'), PHP_URL_SCHEME));
        $this->assertSame('http://madina.example.test/login', url('/login'));
    }
}

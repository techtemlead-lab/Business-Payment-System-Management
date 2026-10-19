<?php

namespace Tests;

use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $portalHostOnce = null;

    public function createApplication(): Application
    {
        // Docker process variables have priority in dotenv. Set the test process
        // explicitly before bootstrap; never allow RefreshDatabase on the demo DB.
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'madina_testing', 'APP_ENV' => 'testing', 'APP_URL' => 'http://example.test', 'APP_DOMAIN' => 'example.test', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        $app = parent::createApplication();
        if ($app->make('config')->get('database.connections.pgsql.database') !== 'madina_testing') {
            throw new \RuntimeException('Tests must use the isolated madina_testing database.');
        }

        return $app;
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $root = rtrim((string) config('app.url'), '/');
        $once = $this->portalHostOnce;
        $this->portalHostOnce = null;
        if (is_string($once) && $once !== '') {
            $server['HTTP_HOST'] = $once;
            $server['SERVER_NAME'] = $once;
        }
        $preset = $server['HTTP_HOST'] ?? ($this->serverVariables['HTTP_HOST'] ?? null);
        if (is_string($preset) && $preset !== '') {
            $root = $this->originForHost($preset);
        } elseif (is_string($uri) && ! str_starts_with($uri, 'http')) {
            $actor = auth()->user();
            $target = PortalHost::testRequest($uri, $actor instanceof User ? $actor : null);
            if ($target !== null) {
                $root = $this->originForHost($target['host']);
                $server['HTTP_HOST'] = $target['host'];
                $server['SERVER_NAME'] = $target['host'];
                $uri = $target['uri'];
            }
        }
        url()->forceRootUrl($root);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function onPortal(string $portal): static
    {
        $this->portalHostOnce = PortalHost::host($portal);

        return $this;
    }

    private function originForHost(string $host): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}

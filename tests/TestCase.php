<?php

namespace Tests;

use App\Domain\Finance\FinancialReadiness;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private ?string $portalHostOnce = null;

    /**
     * Arm financial posting the same way production does: activation plus a
     * dual-control verified opening-balance record.
     */
    protected function enableFinancialPosting(bool $preview = false): void
    {
        config([
            'madina.financial_activation' => true,
            'madina.preview_mode' => $preview,
        ]);
        if ($preview || DB::table('financial_verifications')->whereNotNull('verified_at')->exists()) {
            return;
        }
        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        $fingerprint = 'test-verified-opening';
        $snapshot = ['source' => 'test'];
        try {
            $live = app(FinancialReadiness::class)->inspect();
            $fingerprint = (string) $live['fingerprint'];
            $snapshot = $live;
        } catch (\Throwable) {
            // Fall back to a synthetic fingerprint when readiness inspect is unavailable.
        }
        DB::table('financial_verifications')->insert([
            'fingerprint' => $fingerprint,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'expected_totals' => json_encode($snapshot['totals'] ?? [], JSON_THROW_ON_ERROR),
            'source_reference' => 'TEST-OPENING',
            'evidence_path' => 'opening-balance-evidence/test.bin',
            'evidence_hash' => str_repeat('b', 64),
            'created_by' => $preparer->id,
            'verified_by' => $reviewer->id,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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

    /**
     * Test-only geography fixture (not used by production seeders).
     * Production place codes start empty; admins create them in the panel.
     */
    protected function seedClassicPlaceCodes(): void
    {
        $management = PlaceCode::query()->create([
            'level' => PlaceCode::LevelManagement,
            'code' => 'MNHC-100',
            'name' => 'Management',
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $divisions = [
            1 => ['code' => 'MNHC-101', 'name' => 'Dhaka'],
            2 => ['code' => 'MNHC-102', 'name' => 'Rangpur'],
            3 => ['code' => 'MNHC-103', 'name' => 'Chattogram'],
            4 => ['code' => 'MNHC-104', 'name' => 'Rajshahi'],
            5 => ['code' => 'MNHC-105', 'name' => 'Khulna'],
            6 => ['code' => 'MNHC-106', 'name' => 'Barishal'],
            7 => ['code' => 'MNHC-107', 'name' => 'Sylhet'],
            8 => ['code' => 'MNHC-108', 'name' => 'Mymensingh'],
        ];

        foreach ($divisions as $sortOrder => $meta) {
            PlaceCode::query()->create([
                'level' => PlaceCode::LevelDivision,
                'code' => $meta['code'],
                'name' => $meta['name'],
                'parent_id' => $management->id,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
        }
    }

    private function originForHost(string $host): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}

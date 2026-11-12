<?php

namespace Tests;

use App\Domain\Auth\LoginCaptcha;
use App\Domain\Finance\FinancialReadiness;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\PortalHost;
use App\Support\ReasonFields;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

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
        // MADINA_TEST_DATABASE lets two checkouts run tests at the same time without dropping
        // each other's tables; it must still be a madina_testing* database.
        $database = (string) (getenv('MADINA_TEST_DATABASE') ?: 'madina_testing');
        if (! preg_match('/^madina_testing(_[a-z0-9]+)*$/D', $database)) {
            throw new \RuntimeException('MADINA_TEST_DATABASE must be madina_testing or start with madina_testing_.');
        }
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'APP_ENV' => 'testing', 'APP_URL' => 'http://example.test', 'APP_DOMAIN' => 'example.test', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        $app = parent::createApplication();
        if ($app->make('config')->get('database.connections.pgsql.database') !== $database) {
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
        $parameters = $this->solveLoginCaptcha($method, $uri, $parameters);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Sign-in posts that do not mention the captcha get a solved one, so tests about other
     * sign-in rules stay short. Captcha tests pass their own "captcha" value and are left alone.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function solveLoginCaptcha(string $method, string $uri, array $parameters): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (strtoupper($method) !== 'POST' || ! is_string($path) || ! preg_match('#/login$#', $path) || array_key_exists('captcha', $parameters)) {
            return $parameters;
        }
        $this->app['session']->put(LoginCaptcha::SessionKey, ['text' => 'TEST7', 'expires_at' => now()->addMinutes(3)->getTimestamp()]);

        return [...$parameters, 'captcha' => 'TEST7'];
    }

    /** The newest SMS sign-in code sent through the array SMS driver. */
    protected function lastLoginCode(): ?string
    {
        $sent = array_values(array_filter((array) config('madina.sms.sent', []), fn (array $sms): bool => ($sms['event'] ?? null) === 'login.otp'));
        $last = end($sent);

        return is_array($last) && preg_match('/(\d{6})/', (string) $last['message'], $matches) ? $matches[1] : null;
    }

    /** Sign in through the real form: password and captcha, then the SMS code. */
    protected function signInThroughForm(string $portal, string $username, string $password): TestResponse
    {
        $response = $this->onPortal($portal)->post('/login', ['email' => $username, 'password' => $password]);
        if ($response->headers->get('Location') !== PortalHost::url($portal, '/login/otp')) {
            return $response;
        }

        return $this->onPortal($portal)->post('/login/otp', ['code' => (string) $this->lastLoginCode()]);
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

    /**
     * Set the owners' choice for reason and note boxes, e.g. ['order.refund' => 'required'].
     * Written straight to the settings row, so a test needs no extra owner account for it.
     *
     * @param  array<string, string>  $modes
     */
    protected function reasonFields(array $modes): void
    {
        $row = DB::table('business_settings')->where('key', ReasonFields::SettingsKey)->first();
        $saved = $row === null ? [] : (json_decode((string) $row->value, true)['modes'] ?? []);
        DB::table('business_settings')->updateOrInsert(['key' => ReasonFields::SettingsKey], [
            'value' => json_encode(['modes' => (object) [...$saved, ...$modes]], JSON_THROW_ON_ERROR),
            'version' => (int) ($row->version ?? 0) + 1,
            'updated_by' => $row->updated_by ?? (User::query()->orderBy('id')->value('id') ?? User::factory()->staff('md')->create(['status' => 'suspended'])->id),
            'created_at' => $row->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }

    private function originForHost(string $host): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}

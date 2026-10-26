<?php

namespace App\Domain\Identity;

use App\Domain\Integrations\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Birth Registration (BDRIS) lookup. Disabled until an administrator enables it
 * and supplies endpoint credentials. Callers must fall back to manual KYC entry.
 */
final class BdrisLookup
{
    public function __construct(private IntegrationSettings $integrations) {}

    /**
     * @return array{
     *   success: bool,
     *   source: string,
     *   message: string,
     *   enabled: bool,
     *   details: array{
     *     name:?string,
     *     name_bn:?string,
     *     father_name:?string,
     *     mother_name:?string,
     *     occupation:?string,
     *     date_of_birth:?string
     *   }|null
     * }
     */
    public function fetch(string $birthCertificateNumber, string $dateOfBirth): array
    {
        $number = trim($birthCertificateNumber);
        $dob = trim($dateOfBirth);
        if ($number === '' || $dob === '') {
            return [
                'success' => false,
                'source' => 'validation',
                'message' => __('portal.acct_bdris_need_fields'),
                'enabled' => $this->enabled(),
                'details' => null,
            ];
        }

        $config = $this->config();
        if (! $config['enabled']) {
            return [
                'success' => false,
                'source' => 'disabled',
                'message' => __('portal.acct_bdris_disabled'),
                'enabled' => false,
                'details' => null,
            ];
        }

        $baseUrl = rtrim((string) $config['base_url'], '/');
        if ($baseUrl === '') {
            return [
                'success' => false,
                'source' => 'config',
                'message' => __('portal.acct_bdris_not_configured'),
                'enabled' => true,
                'details' => null,
            ];
        }

        try {
            $response = Http::timeout((int) ($config['timeout'] ?? 12))
                ->acceptJson()
                ->withToken((string) ($config['api_key'] ?? ''))
                ->get($baseUrl.'/birth-registrations/lookup', [
                    'birth_certificate_number' => $number,
                    'date_of_birth' => $dob,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('bdris.lookup_failed', ['message' => $exception->getMessage()]);

            return [
                'success' => false,
                'source' => 'network',
                'message' => __('portal.acct_bdris_unreachable'),
                'enabled' => true,
                'details' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'source' => 'bdris',
                'message' => __('portal.acct_bdris_no_match'),
                'enabled' => true,
                'details' => null,
            ];
        }

        $payload = $response->json('data') ?? $response->json();
        if (! is_array($payload)) {
            return [
                'success' => false,
                'source' => 'bdris',
                'message' => __('portal.acct_bdris_empty'),
                'enabled' => true,
                'details' => null,
            ];
        }

        return [
            'success' => true,
            'source' => 'bdris',
            'message' => __('portal.acct_bdris_loaded'),
            'enabled' => true,
            'details' => [
                'name' => $this->stringOrNull($payload['name'] ?? $payload['name_en'] ?? null),
                'name_bn' => $this->stringOrNull($payload['name_bn'] ?? $payload['name_bangla'] ?? null),
                'father_name' => $this->stringOrNull($payload['father_name'] ?? null),
                'mother_name' => $this->stringOrNull($payload['mother_name'] ?? null),
                'occupation' => $this->stringOrNull($payload['occupation'] ?? null),
                'date_of_birth' => $this->stringOrNull($payload['date_of_birth'] ?? $dob),
            ],
        ];
    }

    public function enabled(): bool
    {
        return (bool) ($this->config()['enabled'] ?? false);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $db = $this->integrations->provider('bdris', 'bdris');
        if ($db['enabled'] || filled($db['base_url'] ?? null) || filled($db['api_key'] ?? null)) {
            return $db;
        }

        // Legacy env fallback remains disabled unless explicitly enabled in .env.
        return [
            'enabled' => (bool) config('madina.bdris.enabled'),
            'base_url' => (string) config('madina.bdris.base_url', ''),
            'api_key' => (string) config('madina.bdris.api_key', ''),
            'timeout' => (int) config('madina.bdris.timeout', 12),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

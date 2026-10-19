<?php

namespace App\Domain\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Birth Registration (BDRIS) lookup. When the remote service is unavailable or
 * unconfigured, callers must fall back to manual KYC entry.
 */
final class BdrisLookup
{
    /**
     * @return array{
     *   success: bool,
     *   source: string,
     *   message: string,
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
                'message' => 'Enter the birth certificate number and date of birth before searching.',
                'details' => null,
            ];
        }

        if (! config('madina.bdris.enabled')) {
            return [
                'success' => false,
                'source' => 'disabled',
                'message' => 'BDRIS lookup is not connected. Enter the personal details manually.',
                'details' => null,
            ];
        }

        $baseUrl = rtrim((string) config('madina.bdris.base_url'), '/');
        if ($baseUrl === '') {
            return [
                'success' => false,
                'source' => 'config',
                'message' => 'BDRIS endpoint is not configured. Enter the personal details manually.',
                'details' => null,
            ];
        }

        try {
            $response = Http::timeout((int) config('madina.bdris.timeout', 12))
                ->acceptJson()
                ->withToken((string) config('madina.bdris.api_key', ''))
                ->get($baseUrl.'/birth-registrations/lookup', [
                    'birth_certificate_number' => $number,
                    'date_of_birth' => $dob,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('bdris.lookup_failed', ['message' => $exception->getMessage()]);

            return [
                'success' => false,
                'source' => 'network',
                'message' => 'Could not reach the BDRIS server. Enter the personal details manually.',
                'details' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'source' => 'bdris',
                'message' => 'BDRIS did not return a match. Enter the personal details manually.',
                'details' => null,
            ];
        }

        $payload = $response->json('data') ?? $response->json();
        if (! is_array($payload)) {
            return [
                'success' => false,
                'source' => 'bdris',
                'message' => 'BDRIS response was empty. Enter the personal details manually.',
                'details' => null,
            ];
        }

        return [
            'success' => true,
            'source' => 'bdris',
            'message' => 'Details loaded from BDRIS. Review and edit if needed.',
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

    private function stringOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

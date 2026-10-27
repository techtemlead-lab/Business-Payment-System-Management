<?php

namespace App\Domain\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SmsGatewayClient
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * @return array{sent: bool, provider: ?string, message: string}
     */
    public function send(string $phone, string $message): array
    {
        foreach (array_keys(IntegrationCatalog::groups()['sms']['providers']) as $provider) {
            $config = $this->settings->provider('sms', $provider);
            if (! ($config['enabled'] ?? false)) {
                continue;
            }

            try {
                $ok = match ($provider) {
                    'ssl_wireless' => $this->sendSslWireless($config, $phone, $message),
                    'bulk_sms_bd' => $this->sendBulkSmsBd($config, $phone, $message),
                    default => $this->sendCustom($config, $phone, $message),
                };
                if ($ok) {
                    return ['sent' => true, 'provider' => $provider, 'message' => __('portal.si_sms_accepted', ['provider' => $provider])];
                }
            } catch (\Throwable $exception) {
                Log::warning('sms.gateway_failed', ['provider' => $provider, 'error' => $exception->getMessage()]);
            }
        }

        return ['sent' => false, 'provider' => null, 'message' => __('portal.si_sms_none_accepted')];
    }

    public function anyEnabled(): bool
    {
        foreach (array_keys(IntegrationCatalog::groups()['sms']['providers']) as $provider) {
            if ($this->settings->isEnabled('sms', $provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, provider: ?string, balance: ?string, message: string}
     */
    public function balance(?string $provider = null): array
    {
        $providers = $provider
            ? [$provider]
            : array_keys(IntegrationCatalog::groups()['sms']['providers']);

        foreach ($providers as $key) {
            $config = $this->settings->provider('sms', $key);
            if (! ($config['enabled'] ?? false)) {
                continue;
            }
            try {
                $balance = match ($key) {
                    'ssl_wireless' => $this->balanceSslWireless($config),
                    'bulk_sms_bd' => $this->balanceBulkSmsBd($config),
                    default => $this->balanceCustom($config),
                };
                if ($balance !== null) {
                    return [
                        'ok' => true,
                        'provider' => $key,
                        'balance' => $balance,
                        'message' => __('portal.si_sms_balance_value', ['balance' => $balance]),
                    ];
                }
            } catch (\Throwable $exception) {
                Log::warning('sms.balance_failed', ['provider' => $key, 'error' => $exception->getMessage()]);
            }
        }

        return [
            'ok' => false,
            'provider' => $provider,
            'balance' => null,
            'message' => __('portal.si_sms_balance_unavailable'),
        ];
    }

    /** @param  array<string, mixed>  $config */
    private function balanceSslWireless(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->acceptJson()->asJson()->post($url, [
            'api_token' => $config['api_key'],
            'sid' => $config['sender_id'] ?: $config['api_secret'],
        ]);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        $value = $json['balance'] ?? $json['data']['balance'] ?? $json['Balance'] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** @param  array<string, mixed>  $config */
    private function balanceBulkSmsBd(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? 'http://bulksmsbd.net/api/getBalanceApi'));
        $response = Http::connectTimeout(3)->timeout(12)->get($url, [
            'api_key' => $config['api_key'],
        ]);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        if (is_array($json)) {
            $value = $json['balance'] ?? $json['data']['balance'] ?? $json['Balance'] ?? null;
            if ($value !== null) {
                return (string) $value;
            }
        }
        $body = trim($response->body());

        return $body !== '' ? $body : null;
    }

    /** @param  array<string, mixed>  $config */
    private function balanceCustom(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->acceptJson()->withToken((string) $config['api_key'])->get($url);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        $value = is_array($json) ? ($json['balance'] ?? $json['data']['balance'] ?? $json['remaining'] ?? null) : null;

        return $value === null ? null : (string) $value;
    }

    /** @param  array<string, mixed>  $config */
    private function sendSslWireless(array $config, string $phone, string $message): bool
    {
        $response = Http::timeout(15)->acceptJson()->asJson()->post(rtrim((string) $config['base_url'], '/'), [
            'api_token' => $config['api_key'],
            'sid' => $config['sender_id'] ?: $config['api_secret'],
            'msisdn' => $phone,
            'sms' => $message,
            'csms_id' => uniqid('madina_', true),
        ]);

        return $response->successful();
    }

    /** @param  array<string, mixed>  $config */
    private function sendBulkSmsBd(array $config, string $phone, string $message): bool
    {
        $response = Http::timeout(15)->get(rtrim((string) $config['base_url'], '/'), [
            'api_key' => $config['api_key'],
            'type' => 'text',
            'number' => $phone,
            'senderid' => $config['sender_id'],
            'message' => $message,
        ]);

        return $response->successful();
    }

    /** @param  array<string, mixed>  $config */
    private function sendCustom(array $config, string $phone, string $message): bool
    {
        $base = rtrim((string) $config['base_url'], '/');
        if ($base === '') {
            return false;
        }
        $response = Http::timeout(15)->acceptJson()->withToken((string) $config['api_key'])->post($base, [
            'to' => $phone,
            'message' => $message,
            'sender_id' => $config['sender_id'] ?? null,
        ]);

        return $response->successful();
    }
}

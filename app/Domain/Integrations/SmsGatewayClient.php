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

<?php

namespace App\Domain\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PaymentGatewayClient
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * Optional remote lookup for a claimed TrxID. Manual verification remains authoritative
     * unless an enabled provider returns a clear match.
     *
     * @return array{checked: bool, matched: bool, provider: ?string, message: string, amount: ?string, raw: ?array}
     */
    public function lookup(string $channel, string $reference, string $expectedAmount): array
    {
        $provider = match (strtolower($channel)) {
            'bkash' => 'bkash',
            'nagad' => 'nagad',
            'sslcommerz', 'card', 'ssl' => 'sslcommerz',
            'rocket' => 'rocket',
            default => null,
        };
        if ($provider === null || ! $this->settings->isEnabled('payments', $provider)) {
            return ['checked' => false, 'matched' => false, 'provider' => $provider, 'message' => 'No enabled payment gateway for this channel.', 'amount' => null, 'raw' => null];
        }
        $config = $this->settings->provider('payments', $provider);

        try {
            return match ($provider) {
                'bkash' => $this->bkashLookup($config, $reference, $expectedAmount),
                'nagad' => $this->nagadLookup($config, $reference, $expectedAmount),
                'sslcommerz' => $this->sslLookup($config, $reference, $expectedAmount),
                'rocket' => $this->rocketLookup($config, $reference, $expectedAmount),
                default => ['checked' => false, 'matched' => false, 'provider' => $provider, 'message' => 'Unsupported provider.', 'amount' => null, 'raw' => null],
            };
        } catch (\Throwable $exception) {
            Log::warning('payment.gateway_lookup_failed', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return ['checked' => true, 'matched' => false, 'provider' => $provider, 'message' => 'Gateway lookup failed: '.$exception->getMessage(), 'amount' => null, 'raw' => null];
        }
    }

    /** @param  array<string, mixed>  $config */
    private function bkashLookup(array $config, string $reference, string $expectedAmount): array
    {
        $base = rtrim((string) $config['base_url'], '/');
        $token = Http::timeout(15)->withBasicAuth((string) $config['username'], (string) $config['password'])
            ->withHeaders(['Content-Type' => 'application/json', 'username' => (string) $config['username'], 'password' => (string) $config['password']])
            ->post($base.'/tokenized/checkout/token/grant', [
                'app_key' => $config['app_key'],
                'app_secret' => $config['app_secret'],
            ])->json('id_token');
        if (! is_string($token) || $token === '') {
            return ['checked' => true, 'matched' => false, 'provider' => 'bkash', 'message' => 'bKash token grant failed.', 'amount' => null, 'raw' => null];
        }
        $response = Http::timeout(15)->withHeaders([
            'Authorization' => $token,
            'X-APP-Key' => (string) $config['app_key'],
            'Content-Type' => 'application/json',
        ])->post($base.'/tokenized/checkout/general/searchTransaction', ['trxID' => $reference]);
        $payload = $response->json();
        $amount = is_array($payload) ? (string) ($payload['amount'] ?? $payload['transactionStatus'] ?? '') : '';
        $matched = $response->successful() && isset($payload['transactionStatus']) && strtoupper((string) $payload['transactionStatus']) === 'COMPLETED';

        return ['checked' => true, 'matched' => $matched, 'provider' => 'bkash', 'message' => $matched ? 'bKash transaction found.' : 'bKash did not confirm this TrxID.', 'amount' => $amount !== '' ? $amount : $expectedAmount, 'raw' => is_array($payload) ? $payload : null];
    }

    /** @param  array<string, mixed>  $config */
    private function nagadLookup(array $config, string $reference, string $expectedAmount): array
    {
        $response = Http::timeout(15)->withHeaders([
            'X-KM-Api-Key' => (string) $config['app_key'],
            'X-KM-IP-V4' => request()->ip() ?? '127.0.0.1',
        ])->get(rtrim((string) $config['base_url'], '/').'/api/dfs/payment/status/'.$reference);
        $payload = $response->json();
        $matched = $response->successful() && in_array(strtoupper((string) ($payload['status'] ?? '')), ['SUCCESS', 'COMPLETE', 'COMPLETED'], true);

        return ['checked' => true, 'matched' => $matched, 'provider' => 'nagad', 'message' => $matched ? 'Nagad payment found.' : 'Nagad did not confirm this reference.', 'amount' => (string) ($payload['amount'] ?? $expectedAmount), 'raw' => is_array($payload) ? $payload : null];
    }

    /** @param  array<string, mixed>  $config */
    private function sslLookup(array $config, string $reference, string $expectedAmount): array
    {
        $response = Http::timeout(15)->asForm()->post(rtrim((string) $config['base_url'], '/').'/validator/api/merchantTransIDvalidationAPI.php', [
            'tran_id' => $reference,
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_password'],
            'format' => 'json',
        ]);
        $payload = $response->json();
        $status = strtoupper((string) ($payload['status'] ?? $payload['APIConnect'] ?? ''));
        $matched = $response->successful() && in_array($status, ['VALID', 'VALIDATED', 'SUCCESS'], true);

        return ['checked' => true, 'matched' => $matched, 'provider' => 'sslcommerz', 'message' => $matched ? 'SSLCommerz transaction found.' : 'SSLCommerz did not confirm this reference.', 'amount' => (string) ($payload['amount'] ?? $expectedAmount), 'raw' => is_array($payload) ? $payload : null];
    }

    /** @param  array<string, mixed>  $config */
    private function rocketLookup(array $config, string $reference, string $expectedAmount): array
    {
        $base = rtrim((string) $config['base_url'], '/');
        if ($base === '') {
            return ['checked' => true, 'matched' => false, 'provider' => 'rocket', 'message' => 'Rocket API base URL is empty.', 'amount' => null, 'raw' => null];
        }
        $response = Http::timeout(15)->withToken((string) $config['api_key'])->get($base.'/transactions/'.$reference, [
            'secret' => $config['api_secret'],
        ]);
        $payload = $response->json();
        $matched = $response->successful() && in_array(strtoupper((string) ($payload['status'] ?? '')), ['SUCCESS', 'PAID', 'COMPLETED'], true);

        return ['checked' => true, 'matched' => $matched, 'provider' => 'rocket', 'message' => $matched ? 'Rocket transaction found.' : 'Rocket did not confirm this reference.', 'amount' => (string) ($payload['amount'] ?? $expectedAmount), 'raw' => is_array($payload) ? $payload : null];
    }
}

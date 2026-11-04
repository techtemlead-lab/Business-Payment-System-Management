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
            return ['checked' => false, 'matched' => false, 'provider' => $provider, 'message' => __('portal.si_pay_none_enabled'), 'amount' => null, 'raw' => null];
        }
        $config = $this->settings->provider('payments', $provider);

        try {
            $result = match ($provider) {
                'bkash' => $this->bkashLookup($config, $reference, $expectedAmount),
                'nagad' => $this->nagadLookup($config, $reference, $expectedAmount),
                'sslcommerz' => $this->sslLookup($config, $reference, $expectedAmount),
                'rocket' => $this->rocketLookup($config, $reference, $expectedAmount),
                default => ['checked' => false, 'matched' => false, 'provider' => $provider, 'message' => __('portal.si_pay_unsupported'), 'amount' => null, 'raw' => null],
            };

            return $this->requireMatchingAmount($result, $expectedAmount);
        } catch (\Throwable $exception) {
            Log::warning('payment.gateway_lookup_failed', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return ['checked' => true, 'matched' => false, 'provider' => $provider, 'message' => __('portal.si_pay_lookup_failed', ['error' => $exception->getMessage()]), 'amount' => null, 'raw' => null];
        }
    }

    /**
     * A completed transaction only counts when the provider-reported amount
     * equals the claimed amount; otherwise one small real payment could be
     * reused to "prove" a much larger claim.
     *
     * @param  array{checked: bool, matched: bool, provider: ?string, message: string, amount: ?string, raw: ?array}  $result
     * @return array{checked: bool, matched: bool, provider: ?string, message: string, amount: ?string, raw: ?array}
     */
    private function requireMatchingAmount(array $result, string $expectedAmount): array
    {
        if (! $result['matched']) {
            return $result;
        }

        $reported = $result['amount'];
        if ($reported === null || ! is_numeric($reported)) {
            return [...$result, 'matched' => false, 'amount' => null, 'message' => __('portal.si_pay_amount_unknown', ['provider' => (string) $result['provider']])];
        }

        $reportedCents = (int) round(((float) $reported) * 100);
        $expectedCents = (int) round(((float) $expectedAmount) * 100);
        if ($reportedCents !== $expectedCents) {
            return [...$result, 'matched' => false, 'message' => __('portal.si_pay_amount_mismatch', [
                'provider' => (string) $result['provider'],
                'reported' => number_format($reportedCents / 100, 2),
                'claimed' => number_format($expectedCents / 100, 2),
            ])];
        }

        return $result;
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
            return ['checked' => true, 'matched' => false, 'provider' => 'bkash', 'message' => __('portal.si_pay_token_failed', ['provider' => 'bKash']), 'amount' => null, 'raw' => null];
        }
        $response = Http::timeout(15)->withHeaders([
            'Authorization' => $token,
            'X-APP-Key' => (string) $config['app_key'],
            'Content-Type' => 'application/json',
        ])->post($base.'/tokenized/checkout/general/searchTransaction', ['trxID' => $reference]);
        $payload = $response->json();
        $amount = is_array($payload) ? (string) ($payload['amount'] ?? '') : '';
        $matched = $response->successful() && isset($payload['transactionStatus']) && strtoupper((string) $payload['transactionStatus']) === 'COMPLETED';

        return ['checked' => true, 'matched' => $matched, 'provider' => 'bkash', 'message' => $matched ? __('portal.si_pay_found', ['provider' => 'bKash']) : __('portal.si_pay_unconfirmed', ['provider' => 'bKash']), 'amount' => $amount !== '' ? $amount : null, 'raw' => is_array($payload) ? $payload : null];
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

        return ['checked' => true, 'matched' => $matched, 'provider' => 'nagad', 'message' => $matched ? __('portal.si_pay_found', ['provider' => 'Nagad']) : __('portal.si_pay_unconfirmed', ['provider' => 'Nagad']), 'amount' => isset($payload['amount']) ? (string) $payload['amount'] : null, 'raw' => is_array($payload) ? $payload : null];
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

        return ['checked' => true, 'matched' => $matched, 'provider' => 'sslcommerz', 'message' => $matched ? __('portal.si_pay_found', ['provider' => 'SSLCommerz']) : __('portal.si_pay_unconfirmed', ['provider' => 'SSLCommerz']), 'amount' => isset($payload['amount']) ? (string) $payload['amount'] : null, 'raw' => is_array($payload) ? $payload : null];
    }

    /** @param  array<string, mixed>  $config */
    private function rocketLookup(array $config, string $reference, string $expectedAmount): array
    {
        $base = rtrim((string) $config['base_url'], '/');
        if ($base === '') {
            return ['checked' => true, 'matched' => false, 'provider' => 'rocket', 'message' => __('portal.si_pay_empty_url', ['provider' => 'Rocket']), 'amount' => null, 'raw' => null];
        }
        $response = Http::timeout(15)->withToken((string) $config['api_key'])->get($base.'/transactions/'.$reference, [
            'secret' => $config['api_secret'],
        ]);
        $payload = $response->json();
        $matched = $response->successful() && in_array(strtoupper((string) ($payload['status'] ?? '')), ['SUCCESS', 'PAID', 'COMPLETED'], true);

        return ['checked' => true, 'matched' => $matched, 'provider' => 'rocket', 'message' => $matched ? __('portal.si_pay_found', ['provider' => 'Rocket']) : __('portal.si_pay_unconfirmed', ['provider' => 'Rocket']), 'amount' => isset($payload['amount']) ? (string) $payload['amount'] : null, 'raw' => is_array($payload) ? $payload : null];
    }
}

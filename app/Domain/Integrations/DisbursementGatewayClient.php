<?php

namespace App\Domain\Integrations;

use App\Support\Audit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DisbursementGatewayClient
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * Disburse funds directly to a partner's destination wallet or bank account.
     *
     * @return array{success: bool, reference: ?string, provider: string, message: string, fee: float, raw: ?array}
     */
    public function disburse(string $channel, string $destination, float $amount, string $withdrawalId): array
    {
        $provider = match (strtolower($channel)) {
            'bkash' => 'bkash',
            'nagad' => 'nagad',
            'rocket' => 'rocket',
            'bank', 'beftn' => 'bank_beftn',
            default => 'manual',
        };

        $isLive = ! config('madina.preview_mode', false) && $this->settings->isEnabled('payouts', $provider);

        // If in preview or test mode, perform a safe, deterministic sandbox execution
        if (! $isLive) {
            $simulatedRef = 'DISB-'.strtoupper($provider).'-'.strtoupper(substr(md5($withdrawalId.$destination), 0, 10));

            Audit::record(
                null,
                'payout.disbursement_simulated',
                'withdrawal:'.$withdrawalId,
                [
                    'provider' => $provider,
                    'amount' => $amount,
                    'destination_masked' => substr($destination, 0, 3).'****'.substr($destination, -3),
                    'reference' => $simulatedRef,
                ]
            );

            return [
                'success' => true,
                'reference' => $simulatedRef,
                'provider' => $provider,
                'message' => 'Simulated automated disbursement generated successfully.',
                'fee' => 0.00,
                'raw' => ['mode' => 'simulated', 'withdrawal_id' => $withdrawalId],
            ];
        }

        $config = $this->settings->provider('payouts', $provider);

        try {
            return match ($provider) {
                'bkash' => $this->bkashDisburse($config, $destination, $amount, $withdrawalId),
                'nagad' => $this->nagadDisburse($config, $destination, $amount, $withdrawalId),
                default => [
                    'success' => false,
                    'reference' => null,
                    'provider' => $provider,
                    'message' => 'Automated disbursement provider not supported.',
                    'fee' => 0.00,
                    'raw' => null,
                ],
            };
        } catch (\Throwable $e) {
            Log::error('payout.disbursement_failed', [
                'provider' => $provider,
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'reference' => null,
                'provider' => $provider,
                'message' => 'Disbursement failed: '.$e->getMessage(),
                'fee' => 0.00,
                'raw' => null,
            ];
        }
    }

    /** @param array<string, mixed> $config */
    private function bkashDisburse(array $config, string $destination, float $amount, string $withdrawalId): array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://b2b.pay.bka.sh/v1'), '/');

        // B2B intra-account transfer call
        $response = Http::timeout(20)->withHeaders([
            'Authorization' => 'Bearer '.($config['token'] ?? ''),
            'X-KM-IP-V4' => request()->ip() ?? '127.0.0.1',
        ])->post($baseUrl.'/b2b/transfer', [
            'merchant_invoice_number' => $withdrawalId,
            'receiver_msisdn' => $destination,
            'amount' => $amount,
            'currency' => 'BDT',
        ]);

        if ($response->successful() && $response->json('transaction_status') === 'Completed') {
            return [
                'success' => true,
                'reference' => $response->json('trx_id') ?: (string) Str::uuid(),
                'provider' => 'bkash',
                'message' => 'bKash B2B payout completed.',
                'fee' => (float) $response->json('fee', 0.00),
                'raw' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'reference' => null,
            'provider' => 'bkash',
            'message' => $response->json('status_message') ?: 'bKash disbursement rejected.',
            'fee' => 0.00,
            'raw' => $response->json(),
        ];
    }

    /** @param array<string, mixed> $config */
    private function nagadDisburse(array $config, string $destination, float $amount, string $withdrawalId): array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.mynagad.com/api/dfs'), '/');

        $response = Http::timeout(20)->post($baseUrl.'/payout/disburse', [
            'order_id' => $withdrawalId,
            'msisdn' => $destination,
            'amount' => $amount,
        ]);

        if ($response->successful() && $response->json('status') === 'SUCCESS') {
            return [
                'success' => true,
                'reference' => $response->json('issuer_payment_ref_no') ?: (string) Str::uuid(),
                'provider' => 'nagad',
                'message' => 'Nagad disbursement completed.',
                'fee' => 0.00,
                'raw' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'reference' => null,
            'provider' => 'nagad',
            'message' => $response->json('message') ?: 'Nagad disbursement rejected.',
            'fee' => 0.00,
            'raw' => $response->json(),
        ];
    }
}

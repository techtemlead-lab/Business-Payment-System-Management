<?php

namespace App\Domain\Integrations;

use App\Support\RedactedError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CourierGatewayClient
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * @param  array{recipient_name?:?string, recipient_phone?:?string, recipient_address?:string, amount?:string|int|float, invoice?:string, note?:string, pickup_address?:?string}  $shipment
     * @return array{success: bool, tracking: ?string, consignment_id: ?string, provider: ?string, message: string, raw: ?array, outcome_unknown?: bool}
     */
    public function create(string $provider, array $shipment): array
    {
        if (! $this->settings->isEnabled('couriers', $provider)) {
            return ['success' => false, 'tracking' => null, 'consignment_id' => null, 'provider' => $provider, 'message' => __('portal.si_courier_disabled'), 'raw' => null];
        }
        $config = $this->settings->provider('couriers', $provider);

        try {
            return match ($provider) {
                'pathao' => $this->pathaoCreate($config, $shipment),
                'steadfast' => $this->steadfastCreate($config, $shipment),
                'redx' => $this->redxCreate($config, $shipment),
                'paperfly' => $this->paperflyCreate($config, $shipment),
                'ecourier' => $this->ecourierCreate($config, $shipment),
                default => ['success' => false, 'tracking' => null, 'consignment_id' => null, 'provider' => $provider, 'message' => __('portal.si_courier_unknown'), 'raw' => null],
            };
        } catch (\Throwable $exception) {
            Log::warning('courier.create_failed', ['provider' => $provider, 'error' => RedactedError::message($exception)]);

            // No clear answer came back, so the courier may still have taken the parcel.
            return ['success' => false, 'tracking' => null, 'consignment_id' => null, 'provider' => $provider, 'message' => __('portal.si_courier_request_failed', ['error' => RedactedError::message($exception)]), 'raw' => null, 'outcome_unknown' => true];
        }
    }

    /**
     * @return array{success: bool, status: ?string, message: string, raw: ?array}
     */
    public function track(string $provider, string $tracking): array
    {
        if (! $this->settings->isEnabled('couriers', $provider)) {
            return ['success' => false, 'status' => null, 'message' => __('portal.si_courier_disabled'), 'raw' => null];
        }
        $config = $this->settings->provider('couriers', $provider);
        $base = rtrim((string) $config['base_url'], '/');

        try {
            $response = match ($provider) {
                'steadfast' => Http::timeout(15)->withHeaders([
                    'Api-Key' => (string) $config['api_key'],
                    'Secret-Key' => (string) $config['api_secret'],
                ])->get($base.'/status_by_trackingcode/'.$tracking),
                'pathao' => Http::timeout(15)->withToken((string) $config['api_key'])->get($base.'/aladdin/api/v1/orders/'.$tracking.'/info'),
                'redx' => Http::timeout(15)->withToken((string) $config['api_key'])->get($base.'/v1.0.0-beta/parcel/track/'.$tracking),
                default => Http::timeout(15)->withToken((string) $config['api_key'])->get($base.'/track/'.$tracking),
            };
            $payload = $response->json();

            return [
                'success' => $response->successful(),
                'status' => is_array($payload) ? $this->trackedStatus($payload) : null,
                'message' => $response->successful() ? __('portal.si_courier_tracking_ok') : __('portal.si_courier_tracking_failed'),
                'raw' => is_array($payload) ? $payload : null,
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'status' => null, 'message' => __('portal.si_courier_tracking_error'), 'raw' => null];
        }
    }

    /**
     * The parcel status in the courier's own words. Steadfast answers with "delivery_status"
     * (its "status" is the HTTP code), Pathao with "data.order_status", RedX with a list of
     * tracking messages (the newest last).
     *
     * @param  array<string, mixed>  $payload
     */
    private function trackedStatus(array $payload): string
    {
        $tracking = $payload['tracking'] ?? null;
        $latest = is_array($tracking) && array_is_list($tracking) && $tracking !== [] ? end($tracking) : null;
        $candidates = [
            $payload['delivery_status'] ?? null,
            $payload['data']['order_status'] ?? null,
            $payload['data']['order_status_slug'] ?? null,
            is_array($latest) ? ($latest['message_en'] ?? $latest['status'] ?? null) : null,
            $payload['data']['status'] ?? null,
            $payload['order_status'] ?? null,
            $payload['status'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && ! is_numeric($candidate)) {
                return trim($candidate);
            }
        }

        return '';
    }

    /** @param  array<string, mixed>  $config @param  array<string, mixed>  $shipment */
    private function pathaoCreate(array $config, array $shipment): array
    {
        $response = Http::timeout(20)->acceptJson()->withToken((string) $config['api_key'])->post(rtrim((string) $config['base_url'], '/').'/aladdin/api/v1/orders', [
            'store_id' => $config['store_id'] ?: null,
            'merchant_order_id' => $shipment['invoice'] ?? null,
            'recipient_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'recipient_phone' => $shipment['recipient_phone'] ?? '',
            'recipient_address' => $shipment['recipient_address'] ?? '',
            'delivery_type' => 48,
            'item_type' => 2,
            'item_quantity' => 1,
            'item_weight' => 0.5,
            'amount_to_collect' => (float) ($shipment['amount'] ?? 0),
            'item_description' => $shipment['note'] ?? __('portal.si_courier_default_item'),
        ]);
        $payload = $response->json();

        return $this->result('pathao', $response->successful(), $payload,
            $payload['data']['consignment_id'] ?? $payload['consignment_id'] ?? null,
            // Pathao can answer with only a consignment id, which is also what its tracking uses.
            $payload['data']['tracking_id'] ?? $payload['tracking_id'] ?? $payload['data']['consignment_id'] ?? $payload['consignment_id'] ?? null);
    }

    /** @param  array<string, mixed>  $config @param  array<string, mixed>  $shipment */
    private function steadfastCreate(array $config, array $shipment): array
    {
        $response = Http::timeout(20)->acceptJson()->withHeaders([
            'Api-Key' => (string) $config['api_key'],
            'Secret-Key' => (string) $config['api_secret'],
            'Content-Type' => 'application/json',
        ])->post(rtrim((string) $config['base_url'], '/').'/create_order', [
            'invoice' => $shipment['invoice'] ?? uniqid('INV-', true),
            'recipient_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'recipient_phone' => $shipment['recipient_phone'] ?? '',
            'recipient_address' => $shipment['recipient_address'] ?? '',
            'cod_amount' => (float) ($shipment['amount'] ?? 0),
            'note' => $shipment['note'] ?? null,
        ]);
        $payload = $response->json();

        return $this->result('steadfast', $response->successful(), $payload,
            $payload['consignment']['consignment_id'] ?? $payload['consignment_id'] ?? null,
            $payload['consignment']['tracking_code'] ?? $payload['tracking_code'] ?? null);
    }

    /** @param  array<string, mixed>  $config @param  array<string, mixed>  $shipment */
    private function redxCreate(array $config, array $shipment): array
    {
        $response = Http::timeout(20)->acceptJson()->withToken((string) $config['api_key'])->post(rtrim((string) $config['base_url'], '/').'/v1.0.0-beta/parcel', [
            'customer_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'customer_phone' => $shipment['recipient_phone'] ?? '',
            'delivery_area' => $shipment['recipient_address'] ?? '',
            'customer_address' => $shipment['recipient_address'] ?? '',
            'merchant_invoice_id' => $shipment['invoice'] ?? null,
            'cash_collection_amount' => (float) ($shipment['amount'] ?? 0),
            'parcel_weight' => 500,
        ]);
        $payload = $response->json();

        return $this->result('redx', $response->successful(), $payload,
            $payload['tracking_id'] ?? $payload['data']['tracking_id'] ?? null,
            $payload['tracking_id'] ?? $payload['data']['tracking_id'] ?? null);
    }

    /** @param  array<string, mixed>  $config @param  array<string, mixed>  $shipment */
    private function paperflyCreate(array $config, array $shipment): array
    {
        $response = Http::timeout(20)->acceptJson()->withBasicAuth((string) $config['username'], (string) $config['password'])
            ->withHeaders(['paperflykey' => (string) $config['api_key']])
            ->post(rtrim((string) $config['base_url'], '/').'/OrderPlacement', [
                'merOrderRef' => $shipment['invoice'] ?? uniqid('ORD-', true),
                'pickMerchantName' => 'Madina Natural Health Care',
                'pickMerchantAddress' => filled($shipment['pickup_address'] ?? null) ? $shipment['pickup_address'] : __('portal.si_courier_pick_address'),
                'productSizeWeight' => 'standard',
                'productBrief' => $shipment['note'] ?? __('portal.si_courier_default_item'),
                'packagePrice' => (float) ($shipment['amount'] ?? 0),
                'custname' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
                'custAddress' => $shipment['recipient_address'] ?? '',
                'custPhone' => $shipment['recipient_phone'] ?? '',
            ]);
        $payload = $response->json();

        return $this->result('paperfly', $response->successful(), $payload,
            $payload['success']['tracking_number'] ?? $payload['tracking_number'] ?? null,
            $payload['success']['tracking_number'] ?? $payload['tracking_number'] ?? null);
    }

    /** @param  array<string, mixed>  $config @param  array<string, mixed>  $shipment */
    private function ecourierCreate(array $config, array $shipment): array
    {
        $response = Http::timeout(20)->acceptJson()->withHeaders([
            'API-KEY' => (string) $config['api_key'],
            'API-SECRET' => (string) $config['api_secret'],
            'USER-ID' => (string) ($config['user_id'] ?? ''),
        ])->post(rtrim((string) $config['base_url'], '/').'/order-place', [
            'recipient_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'recipient_mobile' => $shipment['recipient_phone'] ?? '',
            'recipient_city' => 'Dhaka',
            'recipient_area' => $shipment['recipient_address'] ?? '',
            'recipient_address' => $shipment['recipient_address'] ?? '',
            'package_code' => '#2101',
            'product_price' => (float) ($shipment['amount'] ?? 0),
            'payment_method' => 'COD',
            'ep_id' => $shipment['invoice'] ?? null,
            'product_id' => $shipment['note'] ?? __('portal.si_courier_default_item'),
        ]);
        $payload = $response->json();

        return $this->result('ecourier', $response->successful(), $payload,
            $payload['ID'] ?? $payload['id'] ?? null,
            $payload['ID'] ?? $payload['tracking'] ?? null);
    }

    /** @param  array<string, mixed>|null  $payload */
    private function result(string $provider, bool $ok, ?array $payload, mixed $consignment, mixed $tracking): array
    {
        return [
            'success' => $ok && filled($tracking),
            'tracking' => filled($tracking) ? (string) $tracking : null,
            'consignment_id' => filled($consignment) ? (string) $consignment : null,
            'provider' => $provider,
            'message' => $ok ? __('portal.si_courier_created') : __('portal.si_courier_rejected'),
            'raw' => $payload,
        ];
    }
}

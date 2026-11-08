<?php

namespace App\Domain\Integrations;

use App\Support\RedactedError;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CourierGatewayClient
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * @param  array{recipient_name?:?string, recipient_phone?:?string, recipient_address?:string, amount?:string|int|float, invoice?:string, note?:string, pickup_address?:?string, courier_area?:array<string, ?string>|null}  $shipment
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
     * @return array{success: bool, status: ?string, message: string, raw: ?array, step?: ?string}
     */
    public function track(string $provider, string $tracking): array
    {
        if (! $this->settings->isEnabled('couriers', $provider)) {
            return ['success' => false, 'status' => null, 'message' => __('portal.si_courier_disabled'), 'raw' => null];
        }
        $config = $this->settings->provider('couriers', $provider);
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');

        try {
            $response = match ($provider) {
                'steadfast' => Http::timeout(15)->withHeaders([
                    'Api-Key' => (string) $config['api_key'],
                    'Secret-Key' => (string) $config['api_secret'],
                ])->get($base.'/status_by_trackingcode/'.$tracking),
                'pathao' => Http::timeout(15)->withToken($this->pathaoToken($config))->get($base.'/aladdin/api/v1/orders/'.$tracking.'/info'),
                'redx' => Http::timeout(15)->withHeaders($this->redxAuth($config))->get($base.'/v1.0.0-beta/parcel/track/'.$tracking),
                'ecourier' => Http::timeout(15)->acceptJson()->withHeaders($this->ecourierAuth($config))->post($base.'/track', ['ecr' => $tracking]),
                'paperfly' => Http::timeout(15)->acceptJson()->withBasicAuth((string) $config['username'], (string) $config['password'])
                    ->withHeaders(['paperflykey' => (string) $config['api_key']])->post($base.'/API-Order-Tracking', ['ReferenceNumber' => $tracking]),
                default => throw new \InvalidArgumentException('This courier has no tracking connection.'),
            };
            $payload = $response->json();
            $map = CourierStatusMap::parse((string) ($config['status_map'] ?? ''));
            $status = is_array($payload) && filled($config['status_field'] ?? null) ? CourierStatusMap::statusFrom($payload, (string) $config['status_field'], $map) : null;
            $status ??= is_array($payload) ? $this->trackedStatus($payload) : null;

            return [
                'success' => $response->successful(),
                'status' => $status,
                'step' => CourierStatusMap::stepFor($map, $status),
                'message' => $response->successful() ? __('portal.si_courier_tracking_ok') : __('portal.si_courier_tracking_failed'),
                'raw' => is_array($payload) ? $payload : null,
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'status' => null, 'message' => __('portal.si_courier_tracking_error'), 'raw' => null];
        }
    }

    /**
     * The courier's own list of cities, zones or areas, for the owner to pick from.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws \RuntimeException When the courier refuses or answers with something unreadable.
     */
    public function areas(string $provider, string $level, ?string $parentId = null): array
    {
        $config = $this->settings->provider('couriers', $provider);
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $response = match ($provider.'.'.$level) {
            'pathao.city' => $this->pathaoLookup($config, $base.'/aladdin/api/v1/city-list'),
            'pathao.zone' => $this->pathaoLookup($config, $base.'/aladdin/api/v1/cities/'.rawurlencode((string) $parentId).'/zone-list'),
            'pathao.area' => $this->pathaoLookup($config, $base.'/aladdin/api/v1/zones/'.rawurlencode((string) $parentId).'/area-list'),
            'redx.area' => Http::timeout(30)->acceptJson()->withHeaders($this->redxAuth($config))->get($base.'/v1.0.0-beta/areas'),
            default => throw new \InvalidArgumentException('This courier has no such list.'),
        };
        $rows = $response->json($provider === 'redx' ? 'areas' : 'data.data');
        if (! $response->successful() || ! is_array($rows)) {
            throw new \RuntimeException(ucfirst($provider).' answered with status '.$response->status());
        }
        $idKey = $provider === 'redx' ? 'id' : $level.'_id';
        $nameKey = $provider === 'redx' ? 'name' : $level.'_name';
        $list = [];
        foreach ($rows as $row) {
            if (is_array($row) && filled($row[$idKey] ?? null) && filled($row[$nameKey] ?? null)) {
                $list[] = ['id' => (string) $row[$idKey], 'name' => (string) $row[$nameKey]];
            }
        }

        return $list;
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
            // eCourier lists its steps newest first, each as [status, comment, time].
            $payload['query_data'][0]['status'][0][0] ?? null,
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
        $response = Http::timeout(20)->acceptJson()->withToken($this->pathaoToken($config))->post(rtrim((string) $config['base_url'], '/').'/aladdin/api/v1/orders', [
            'store_id' => $config['store_id'] ?: null,
            'merchant_order_id' => $shipment['invoice'] ?? null,
            'recipient_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'recipient_phone' => $shipment['recipient_phone'] ?? '',
            'recipient_address' => $shipment['recipient_address'] ?? '',
            ...array_filter([
                'recipient_city' => $shipment['courier_area']['city_id'] ?? null,
                'recipient_zone' => $shipment['courier_area']['zone_id'] ?? null,
                'recipient_area' => $shipment['courier_area']['area_id'] ?? null,
            ], 'filled'),
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
        $response = Http::timeout(20)->acceptJson()->withHeaders($this->redxAuth($config))->post(rtrim((string) $config['base_url'], '/').'/v1.0.0-beta/parcel', [
            'customer_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'customer_phone' => $shipment['recipient_phone'] ?? '',
            'delivery_area' => $shipment['courier_area']['area_name'] ?? $shipment['recipient_address'] ?? '',
            ...array_filter(['delivery_area_id' => $shipment['courier_area']['area_id'] ?? null], 'filled'),
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
            ->post(rtrim((string) $config['base_url'], '/').'/merchant/api/service/new_order_v2.php', [
                'merchantOrderReference' => $shipment['invoice'] ?? uniqid('ORD-', true),
                'storeName' => 'Madina Natural Health Care',
                'productBrief' => $shipment['note'] ?? __('portal.si_courier_default_item'),
                'packagePrice' => (float) ($shipment['amount'] ?? 0),
                'max_weight' => '1',
                'customerName' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
                'customerAddress' => $shipment['recipient_address'] ?? '',
                'customerPhone' => $shipment['recipient_phone'] ?? '',
            ]);
        $payload = $response->json();

        return $this->result('paperfly', $response->successful(), $payload,
            $payload['success']['tracking_number'] ?? $payload['tracking_number'] ?? null,
            $payload['success']['tracking_number'] ?? $payload['tracking_number'] ?? null);
    }

    /** @param  array<string, mixed>  $config @param  array<string, mixed>  $shipment */
    private function ecourierCreate(array $config, array $shipment): array
    {
        if (blank($config['package_code'] ?? null)) {
            return ['success' => false, 'tracking' => null, 'consignment_id' => null, 'provider' => 'ecourier', 'message' => __('courier_areas.needs_package_code'), 'raw' => null];
        }
        $response = Http::timeout(20)->acceptJson()->withHeaders($this->ecourierAuth($config))->post(rtrim((string) $config['base_url'], '/').'/order-place', [
            'recipient_name' => $shipment['recipient_name'] ?? __('portal.si_courier_default_recipient'),
            'recipient_mobile' => $shipment['recipient_phone'] ?? '',
            'recipient_city' => Str::limit((string) ($shipment['recipient_city'] ?? ''), 40, ''),
            'recipient_thana' => Str::limit((string) ($shipment['recipient_thana'] ?? ''), 40, ''),
            'recipient_area' => Str::limit((string) ($shipment['recipient_area'] ?? ''), 40, ''),
            'recipient_address' => $shipment['recipient_address'] ?? '',
            'package_code' => (string) ($config['package_code'] ?? ''),
            'product_price' => (int) round((float) ($shipment['amount'] ?? 0)),
            'payment_method' => 'COD',
            'recipient_zip' => '',
            // eCourier allows 20 characters here; the end of the invoice is the part unique to this parcel.
            'product_id' => substr((string) ($shipment['invoice'] ?? ''), -20),
            'comments' => $shipment['note'] ?? null,
        ]);
        $payload = $response->json();

        return $this->result('ecourier', $response->successful(), $payload,
            $payload['ID'] ?? $payload['id'] ?? null,
            $payload['ID'] ?? $payload['tracking'] ?? null);
    }

    /** @param  array<string, mixed>  $config */
    private function pathaoLookup(array $config, string $url): Response
    {
        return Http::timeout(15)->acceptJson()->withToken($this->pathaoToken($config))->get($url);
    }

    /**
     * RedX takes its token in its own header, not the usual Authorization one.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function redxAuth(array $config): array
    {
        return ['API-ACCESS-TOKEN' => 'Bearer '.(string) preg_replace('/^Bearer\s+/i', '', trim((string) $config['api_key']))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function ecourierAuth(array $config): array
    {
        return ['API-KEY' => (string) $config['api_key'], 'API-SECRET' => (string) $config['api_secret'], 'USER-ID' => (string) ($config['user_id'] ?? '')];
    }

    /**
     * Pathao signs in with a client id, client secret, username and password and hands back a
     * token that lasts for days, which is kept until shortly before it expires. Without a
     * username and password, the API key is used as a token the owner pasted in.
     *
     * @param  array<string, mixed>  $config
     */
    private function pathaoToken(array $config): string
    {
        if (blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
            return (string) $config['api_key'];
        }
        $base = rtrim((string) $config['base_url'], '/');
        $cacheKey = 'courier:pathao-token:'.hash('sha256', implode('|', [$base, $config['api_key'], $config['username'], $config['password']]));
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return decrypt($cached);
        }
        $response = Http::timeout(15)->acceptJson()->post($base.'/aladdin/api/v1/issue-token', [
            'client_id' => (string) $config['api_key'],
            'client_secret' => (string) $config['api_secret'],
            'username' => (string) $config['username'],
            'password' => (string) $config['password'],
            'grant_type' => 'password',
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new \RuntimeException('Pathao sign-in failed with status '.$response->status());
        }
        Cache::put($cacheKey, encrypt($token), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 300)));

        return $token;
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

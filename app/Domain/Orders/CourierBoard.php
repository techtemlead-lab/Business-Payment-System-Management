<?php

namespace App\Domain\Orders;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The staff deliveries board: every parcel booked with a courier, filtered by step and
 * courier, with the bookings the courier refused listed on their own.
 */
final class CourierBoard
{
    public const Filters = [...ShipmentTracking::Statuses, 'booking'];

    public function __construct(private readonly CourierOptions $couriers) {}

    /**
     * @param  Builder  $orderIds  the order ids this reader may see
     * @return array{filters: array{status: ?string, courier: ?string}, couriers: list<array{key: string, label: string}>, counts: array<string, int>, parcels: mixed, failed: list<object>}
     */
    public function for(Request $request, Builder $orderIds): array
    {
        $status = in_array($request->query('ship_status'), self::Filters, true) ? (string) $request->query('ship_status') : null;
        $courier = in_array($request->query('ship_courier'), CourierOptions::knownProviders(), true) ? (string) $request->query('ship_courier') : null;
        $base = fn () => DB::table('shipments')
            ->whereNotNull('shipments.courier_provider')
            ->whereIn('shipments.order_id', clone $orderIds);

        $parcels = $base()
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->when($courier, fn ($query) => $query->where('shipments.courier_provider', $courier))
            ->when($status === 'booking', fn ($query) => $query->whereIn('shipments.courier_booking_status', ['pending', 'booking']))
            ->when($status !== null && $status !== 'booking', fn ($query) => $query->where('shipments.courier_status', $status))
            ->orderByDesc('shipments.updated_at')
            ->select([
                'shipments.id', 'shipments.order_id', 'orders.number', 'users.name as partner', 'users.dealer_code', 'shipments.courier',
                'shipments.courier_provider', 'shipments.tracking', 'shipments.courier_status', 'shipments.courier_booking_status',
                'shipments.courier_status_at', 'shipments.updated_at',
            ])
            ->paginate(15, pageName: 'parcels_page')
            ->withQueryString()
            ->through(function (object $row): object {
                $row->tracking_url = CourierOptions::trackingUrl($row->courier_provider, $row->tracking);

                return $row;
            });

        $failed = $base()
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('shipments.courier_booking_status', 'failed')
            ->orderByDesc('shipments.updated_at')
            ->limit(50)
            ->get(['shipments.id', 'shipments.order_id', 'orders.number', 'users.name as partner', 'shipments.courier', 'shipments.courier_booking_error', 'shipments.updated_at'])
            ->all();

        $counts = $base()
            ->selectRaw("CASE WHEN courier_booking_status IN ('pending', 'booking') THEN 'booking' WHEN courier_booking_status = 'failed' THEN 'failed' ELSE COALESCE(courier_status, 'waiting_pickup') END AS step, COUNT(*) AS aggregate")
            ->groupBy('step')
            ->pluck('aggregate', 'step')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'filters' => ['status' => $status, 'courier' => $courier],
            'couriers' => $this->couriers->enabled(),
            'counts' => $counts,
            'parcels' => $parcels,
            'failed' => $failed,
        ];
    }
}

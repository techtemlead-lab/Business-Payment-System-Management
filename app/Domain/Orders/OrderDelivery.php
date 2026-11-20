<?php

namespace App\Domain\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * How an order's goods reached the partner, as the printed invoice says it: taken from the office,
 * sent by courier (courier name and tracking number), or not decided yet.
 */
final class OrderDelivery
{
    /**
     * @return array{kind: 'office'|'courier'|'none', couriers: list<array{name: string, tracking: string}>}
     */
    public function for(Order $order): array
    {
        $shipments = DB::table('shipments')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['courier', 'tracking', 'courier_provider', 'courier_consignment_id']);

        $couriers = [];
        $pickups = 0;
        foreach ($shipments as $shipment) {
            if (OfficePickup::matches((string) $shipment->courier)) {
                $pickups++;

                continue;
            }
            $name = filled($shipment->courier_provider) ? CourierOptions::label((string) $shipment->courier_provider) : trim((string) $shipment->courier);
            $tracking = trim((string) $shipment->tracking) !== '' ? trim((string) $shipment->tracking) : trim((string) $shipment->courier_consignment_id);
            if ($name !== '') {
                $couriers[] = ['name' => $name, 'tracking' => $tracking];
            }
        }

        return [
            'kind' => $couriers !== [] ? 'courier' : ($pickups > 0 ? 'office' : 'none'),
            'couriers' => $couriers,
        ];
    }
}

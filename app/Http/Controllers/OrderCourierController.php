<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\CourierAreaMapping;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Orders\BackOrders;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\OfficePickup;
use App\Domain\Orders\OrderLines;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShippingLabels;
use App\Http\Requests\Backend\ShipWithCourierRequest;
use App\Models\Order;
use App\Models\User;
use App\Support\ReasonFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page staff land on once an order is approved: they confirm the courier (the dealer's pick is
 * filled in, and they may choose another) and press Next. For a courier with a booking system the
 * order is then sent to it by itself and its tracking number appears on the order.
 */
class OrderCourierController extends Controller
{
    /** The orders that still have items to send. */
    private const OpenStatuses = ['approved', 'partially_dispatched'];

    public function show(Request $request, Order $order): Response|RedirectResponse
    {
        $staff = $request->user();
        abort_unless($staff->allows('inventory') && (int) $staff->id !== (int) $order->user_id, 403);
        abort_unless(app(BranchOperations::class)->mayOpenOrder($staff, $order), 404);
        $unsent = $order->lines()->whereRaw(OrderLines::unsentSql())->exists();
        // Collected at the office: there is no courier to choose; the hand-over is on the order page.
        if (! in_array($order->status, self::OpenStatuses, true) || ! $unsent || OfficePickup::isPickupOrder($order)) {
            return redirect('/orders/'.$order->id);
        }

        $partner = User::query()->find($order->user_id);
        $place = ShippingLabels::deliveryPlace($partner);
        $mapping = app(CourierAreaMapping::class);
        $couriers = array_map(fn (array $option): array => [
            'key' => $option['key'],
            'label' => $option['label'],
            'manual' => (bool) ($option['manual'] ?? false),
            // A booking-system courier without all its API details cannot take the parcel.
            'api_ready' => (bool) ($option['manual'] ?? false) ? null : (bool) ($option['api_ready'] ?? false),
            'area_missing' => CourierAreaMapping::needsMapping($option['key']) && $mapping->forPlaceCode($option['key'], $place['place_code']) === null,
        ], app(CourierOptions::class)->enabled());

        return Inertia::render('OrderCourier', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'total' => $order->total,
                'courier_choice' => $order->courier_choice,
                'fulfillment_branch_id' => $order->fulfillment_branch_id,
                'lines' => $order->lines()->whereRaw(OrderLines::unsentSql())->orderBy('id')->get(['id', 'product_id', 'name', 'quantity', 'dispatched', 'closed']),
            ],
            'receiver' => [
                'name' => $partner?->name,
                'phone' => $partner?->phone,
                'address' => ShippingLabels::deliveryAddress($partner),
            ],
            'couriers' => $couriers,
            'dealerChoice' => $order->courier_choice,
            'stockByBranch' => app(BackOrders::class)->stockByBranch($order),
        ]);
    }

    public function store(ShipWithCourierRequest $request, Order $order, OrderService $orders): RedirectResponse
    {
        $data = $request->validated();
        $orders->shipWithCourier($request->user(), $order, [
            'courier_provider' => $data['courier_provider'],
            'tracking' => $data['tracking'] ?? null,
            'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'idempotency_key' => $data['idempotency_key'],
        ], ReasonFields::text($data['note'] ?? null));

        $manual = CourierOptions::isManual($data['courier_provider']);

        return redirect('/orders/'.$order->id)->with('success', __($manual ? 'courier.flash_sent_manual' : 'courier.flash_sent_to_courier', ['courier' => CourierOptions::label($data['courier_provider'])]));
    }
}

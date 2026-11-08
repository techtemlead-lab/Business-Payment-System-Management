<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Http\Requests\Backend\ChangeCourierRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff actions on courier parcels: change the courier, try a refused booking again,
 * mark a parcel picked up, or ask the courier for news now.
 */
class CourierShipmentController extends Controller
{
    public function change(ChangeCourierRequest $request, Order $order, OrderService $orders): RedirectResponse
    {
        $data = $request->validated();
        $orders->changeCourier($request->user(), $order, $data['courier'], $data['note'] ?? null);

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier.flash_changed'));
    }

    public function retry(Request $request, Order $order, int $shipment, OrderService $orders): RedirectResponse
    {
        $orders->retryCourierBooking($request->user(), $order, $shipment);

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier.flash_retried'));
    }

    public function pickedUp(Request $request, Order $order, int $shipment, ShipmentTracking $tracking): RedirectResponse
    {
        $tracking->markPickedUp($request->user(), $order, $shipment);

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier.flash_picked_up'));
    }
}

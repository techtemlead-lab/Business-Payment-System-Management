<?php

namespace App\Http\Controllers;

use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Http\Requests\Backend\ChangeCourierRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff actions on courier parcels: change the courier, try a refused booking again,
 * mark a parcel picked up, ask the courier for news now, or take a returned parcel back.
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

    public function receivedBack(Request $request, Order $order, int $shipment, OrderService $orders): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $orders->receiveReturnedParcel($request->user(), $order, $shipment, $data['note'] ?? null);

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier.flash_received_back'));
    }

    public function manualUpdate(Request $request, Order $order, int $shipment, ShipmentTracking $tracking): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_slice(ShipmentTracking::Statuses, 1))],
            'tracking' => ['nullable', 'string', 'max:100'],
        ]);
        $tracking->updateByHand($request->user(), $order, $shipment, $data['status'] ?? null, $data['tracking'] ?? null);

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier_more.flash_manual_updated'));
    }

    public function mixedOutcomeDecision(Request $request, Order $order, MixedOutcomeDecisions $decisions): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(MixedOutcomeDecisions::Choices)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $decisions->decide($request->user(), $order, $data['decision'], trim($data['reason']));

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier_more.flash_decision_saved'));
    }
}

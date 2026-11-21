<?php

namespace App\Http\Controllers;

use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OfficePickup;
use App\Domain\Orders\OrderDeliveryMethod;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShipmentTracking;
use App\Http\Requests\Backend\ChangeCourierRequest;
use App\Models\Order;
use App\Support\Money;
use App\Support\ReasonFields;
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
        // A change on an approved order that is not sent yet only saves the new pick; Next on the courier page sends it.
        $orders->changeCourier($request->user(), $order, $data['courier'], $data['note'] ?? null, bookNow: false);

        return back(fallback: '/orders/'.$order->id)->with('success', __('courier.flash_changed'));
    }

    /** Switch an order between courier and office pickup before anything is sent; the partner is told. */
    public function deliveryMethod(Request $request, Order $order, OrderDeliveryMethod $methods): RedirectResponse
    {
        $data = $request->validate([
            'delivery_method' => ['required', 'string', Rule::in(OfficePickup::Methods)],
            'note' => ReasonFields::rule('order.courier_change', 500),
        ]);
        $methods->change($request->user(), $order, $data['delivery_method'], $data['note'] ?? null);

        return back(fallback: '/orders/'.$order->id)->with('success', __('order_delivery.flash_changed'));
    }

    /** The partner took the goods at the office: out of stock and delivered in one step. */
    public function officeHandover(Request $request, Order $order, OrderDeliveryMethod $methods): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'integer'],
            'note' => ReasonFields::rule('order.delivery_confirm', 500),
        ]);
        $methods->handOver($request->user(), $order, [
            'idempotency_key' => $data['idempotency_key'],
            'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'note' => $data['note'] ?? null,
        ]);

        return redirect('/orders/'.$order->id)->with('success', __('order_delivery.flash_handed'));
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
        $data = $request->validate(['note' => ReasonFields::rule('order.received_back', 500)]);
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
            'reason' => ReasonFields::rule('order.commission_decision', 1000, 5),
        ]);
        $money = $decisions->decide($request->user(), $order, $data['decision'], ReasonFields::text($data['reason'] ?? null));
        $message = match (true) {
            $money['fund_amount'] === null => __('courier_more.flash_decision_saved'),
            $money['recovered'] + $money['still_owed'] > 0 => __('courier_more.flash_no_commission_taken_back', [
                'fund' => Money::grouped(Money::decimal($money['fund_amount'])),
                'taken' => Money::grouped(Money::decimal($money['recovered'])),
                'owed' => Money::grouped(Money::decimal($money['still_owed'])),
            ]),
            default => __('courier_more.flash_no_commission_saved', ['fund' => Money::grouped(Money::decimal($money['fund_amount']))]),
        };

        return back(fallback: '/orders/'.$order->id)->with('success', $message);
    }
}

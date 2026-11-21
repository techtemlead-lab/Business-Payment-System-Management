<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OwedItems;
use App\Models\Order;
use App\Support\ReasonFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Owed goods: record a short line, send owed goods with the next order, or refund them. */
class OwedItemsController extends Controller
{
    public function record(Request $request, Order $order, OwedItems $owed): RedirectResponse
    {
        $data = $request->validate(['line_id' => ['required', 'integer'], 'note' => ['required', 'string', 'min:3', 'max:500']]);
        $owed->record($request->user(), $order, (int) $data['line_id'], (string) $data['note']);

        return back(fallback: '/orders/'.$order->id)->with('success', __('owed_items.flash_recorded'));
    }

    public function shipWith(Request $request, Order $order, OwedItems $owed): RedirectResponse
    {
        $added = $owed->shipWith($request->user(), $order);

        return back(fallback: '/orders/'.$order->id)->with('success', __('owed_items.flash_added', ['count' => $added]));
    }

    public function cancel(Request $request, int $owedItem, OwedItems $owed): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $owed->cancelWithRefund($request->user(), $owedItem, ReasonFields::text($data['reason']) ?? '');

        return back()->with('success', __('owed_items.flash_refund_started'));
    }
}

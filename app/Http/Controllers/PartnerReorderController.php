<?php

namespace App\Http\Controllers;

use App\Domain\Orders\RepeatOrderOptions;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Order this again" on the My orders page: one of the partner's own past orders as cart lines at today's
 * prices. Nothing is saved; the cart lives in the browser and the order still goes through the normal checkout.
 */
final class PartnerReorderController
{
    public function __invoke(Request $request, Order $order, RepeatOrderOptions $options): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true)
            && $order->user_id === $user->id
            && ! $order->is_opening_balance, 404);

        return response()->json($options->forOrder($user, $order));
    }
}

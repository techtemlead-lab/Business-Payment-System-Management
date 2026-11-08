<?php

namespace App\Http\Controllers;

use App\Domain\Orders\ShippingLabels;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The courier label for one order. Each time the page opens counts as one print.
 */
class ShippingLabelController extends Controller
{
    public function print(Request $request, Order $order, ShippingLabels $labels): Response
    {
        return response()
            ->view('invoices.shipping-label', $labels->printFor($request->user(), $order))
            ->header('Cache-Control', 'no-store, private');
    }
}

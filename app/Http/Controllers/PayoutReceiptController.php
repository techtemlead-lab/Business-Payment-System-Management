<?php

namespace App\Http\Controllers;

use App\Domain\Payouts\PayoutReceipts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable commission payout receipt. Partners open only their own; owners and
 * finance staff open any.
 */
class PayoutReceiptController extends Controller
{
    public function print(Request $request, int $receipt, PayoutReceipts $receipts): Response
    {
        return $receipts->printHtml($request->user(), $receipt);
    }
}

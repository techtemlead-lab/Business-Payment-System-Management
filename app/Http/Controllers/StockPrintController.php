<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockPrintPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PDF of the live product list, offered on the Stock page to staff with the inventory permission.
 */
class StockPrintController extends Controller
{
    public function __invoke(Request $request, StockPrintPdf $pdf): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('inventory'), 403);

        return $pdf->download($user);
    }
}

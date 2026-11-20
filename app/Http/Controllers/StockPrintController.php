<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockMovementList;
use App\Domain\Inventory\StockPrintPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PDF of the stock, offered on the Stock page to staff with the inventory permission. It follows the
 * branch and the filters chosen on the page, which travel in the address of the download button.
 */
class StockPrintController extends Controller
{
    public function __invoke(Request $request, StockPrintPdf $pdf, BranchOperations $branches): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('inventory'), 403);

        $scope = StockMovementList::scope($user, $request, $branches->branchChoices($user)['preferred']);

        return $pdf->download($user, $request, $scope);
    }
}

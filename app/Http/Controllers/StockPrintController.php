<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockMovementList;
use App\Domain\Inventory\StockPrintPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PDF of the current stock list for the branch on the Stock page (or every branch on "All
 * branches"), for staff with the inventory permission.
 */
class StockPrintController extends Controller
{
    public function __invoke(Request $request, StockPrintPdf $pdf): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('inventory'), 403);
        $scope = StockMovementList::scope($user, $request, app(BranchOperations::class)->preferredBranch($user));

        return $pdf->download($user, $scope);
    }
}

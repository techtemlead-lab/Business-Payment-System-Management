<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\ProductListPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PDF of the live product list (only products on sale on the website), offered on the Products page to staff who manage the catalogue or the stock.
 */
class ProductListPrintController extends Controller
{
    public function __invoke(Request $request, ProductListPdf $pdf): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('inventory') || $user->allows('catalog'), 403);

        // "?pictures=0" is the compact copy without pictures; anything else is the copy with pictures.
        return $pdf->download($user, $request->query('pictures') !== '0');
    }
}

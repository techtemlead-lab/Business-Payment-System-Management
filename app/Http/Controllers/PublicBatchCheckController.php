<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockLotReports;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Anyone holding a pack can check its batch number: product, expiry and maker only.
 */
class PublicBatchCheckController extends Controller
{
    public function show(Request $request, StockLotReports $reports): View
    {
        $data = $request->validate(['batch' => ['nullable', 'string', 'max:60']]);
        $batch = trim((string) ($data['batch'] ?? ''));

        return view('reference.batch-check', [
            'batch' => $batch,
            'results' => $batch !== '' ? $reports->publicCheck($batch) : [],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockLotHolds;
use App\Domain\Inventory\StockLotReports;
use App\Domain\Inventory\StockTransfers;
use App\Models\StockLot;
use App\Models\StockTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockLotController extends Controller
{
    public function saveNearExpiryDays(Request $request, StockLotReports $reports): RedirectResponse
    {
        abort_unless($request->user()->allows('inventory'), 403);
        $data = $request->validate(['days' => ['required', 'integer', 'in:'.implode(',', StockLotReports::NearExpiryChoices)]]);
        $reports->saveNearExpiryDays($request->user(), (int) $data['days']);

        return back()->with('success', __('stock_lots.flash_days_saved'));
    }

    public function hold(Request $request, StockLot $stockLot, StockLotHolds $holds): RedirectResponse
    {
        abort_unless($request->user()->allows('inventory'), 403);
        $holds->hold($request->user(), $stockLot, $this->holdReason($request));

        return back()->with('success', __('stock_lots.flash_lot_held'));
    }

    public function release(Request $request, StockLot $stockLot, StockLotHolds $holds): RedirectResponse
    {
        abort_unless($request->user()->allows('inventory'), 403);
        $holds->release($request->user(), $stockLot, $this->holdReason($request));

        return back()->with('success', __('stock_lots.flash_lot_released'));
    }

    public function writeOff(Request $request, StockLot $stockLot, StockLotHolds $holds): RedirectResponse
    {
        abort_unless($request->user()->allows('inventory'), 403);
        $holds->writeOff($request->user(), $stockLot, $this->holdReason($request));

        return back()->with('success', __('stock_lots.flash_lot_written_off'));
    }

    public function requestTransfer(Request $request, StockTransfers $transfers): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'source_branch_id' => ['required', 'integer', 'exists:company_branches,id'],
            'destination_branch_id' => ['required', 'integer', 'exists:company_branches,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $transfers->request($request->user(), $data);

        return back()->with('success', __('stock_lots.flash_transfer_requested'));
    }

    public function sendTransfer(Request $request, StockTransfer $stockTransfer, StockTransfers $transfers): RedirectResponse
    {
        $transfers->send($request->user(), $stockTransfer);

        return back()->with('success', __('stock_lots.flash_transfer_sent'));
    }

    public function receiveTransfer(Request $request, StockTransfer $stockTransfer, StockTransfers $transfers): RedirectResponse
    {
        $transfers->receive($request->user(), $stockTransfer);

        return back()->with('success', __('stock_lots.flash_transfer_received'));
    }

    public function cancelTransfer(Request $request, StockTransfer $stockTransfer, StockTransfers $transfers): RedirectResponse
    {
        $transfers->cancel($request->user(), $stockTransfer);

        return back()->with('success', __('stock_lots.flash_transfer_cancelled'));
    }

    private function holdReason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }
}

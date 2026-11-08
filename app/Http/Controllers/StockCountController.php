<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockCountService;
use App\Models\StockCountSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockCountController extends Controller
{
    public function start(Request $request, StockCountService $counts): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:company_branches,id']]);
        $session = $counts->start($request->user(), (int) $data['branch_id']);

        return back()->with('success', __('purchasing.flash_count_started', ['id' => (string) $session->id]));
    }

    public function saveCounts(Request $request, StockCountSession $stockCountSession, StockCountService $counts): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:5000'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.counted_quantity' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);
        $counts->saveCounts($request->user(), $stockCountSession, $data['lines']);

        return back()->with('success', __('purchasing.flash_counts_saved'));
    }

    public function submit(Request $request, StockCountSession $stockCountSession, StockCountService $counts): RedirectResponse
    {
        $counts->submit($request->user(), $stockCountSession);

        return back()->with('success', __('purchasing.flash_count_submitted'));
    }

    public function approve(Request $request, StockCountSession $stockCountSession, StockCountService $counts): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'self_approve' => ['sometimes', 'boolean']]);
        $counts->approve($request->user(), $stockCountSession, $data['note'] ?? null, $request->boolean('self_approve'));

        return back()->with('success', __('purchasing.flash_count_approved'));
    }

    public function reject(Request $request, StockCountSession $stockCountSession, StockCountService $counts): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000'], 'self_approve' => ['sometimes', 'boolean']]);
        $counts->reject($request->user(), $stockCountSession, $data['note'], $request->boolean('self_approve'));

        return back()->with('success', __('purchasing.flash_count_rejected'));
    }

    public function cancel(Request $request, StockCountSession $stockCountSession, StockCountService $counts): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $counts->cancel($request->user(), $stockCountSession, $data['reason'] ?? null);

        return back()->with('success', __('purchasing.flash_count_cancelled'));
    }
}

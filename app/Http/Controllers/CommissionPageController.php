<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionPageModel;
use App\Domain\Finance\PolicyDraftService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The commission page's two extra actions. Rates themselves are saved by CommandController::updateCommissionRules.
 */
final class CommissionPageController extends Controller
{
    /** Kinds a new commission can be filed under; the page shows a plain word for each. */
    public const NewHeadKinds = ['cashback', 'referral', 'd2d', 'area', 'salary', 'rank', 'incentive', 'marketing', 'rewards', 'other'];

    /** How one order would split between the commissions. Reads only; nothing is posted. */
    public function split(Request $request, CommissionPageModel $page): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate([
            'buyer_type' => ['required', Rule::in(['dealer', 'sub_dealer'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,2'],
            'mrp' => ['nullable', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,2'],
        ]);

        return response()->json($page->splitFor(
            $data['buyer_type'],
            Money::cents(number_format((float) $data['amount'], 2, '.', '')),
            isset($data['mrp']) ? Money::cents(number_format((float) $data['mrp'], 2, '.', '')) : null,
        ));
    }

    /**
     * A new commission the engine cannot pay yet. Every commission is a fixed line in the posting code, so
     * this files a written draft per buyer type for the office to review; no live rate changes.
     */
    public function newHead(Request $request, PolicyDraftService $drafts): RedirectResponse
    {
        abort_unless($request->user()->canAct('finance', 'create'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(self::NewHeadKinds)],
            'dealer_pct' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'sub_dealer_pct' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);
        $rates = ['dealer' => (float) $data['dealer_pct'], 'sub_dealer' => (float) $data['sub_dealer_pct']];
        if (max($rates) <= 0) {
            throw ValidationException::withMessages(['dealer_pct' => __('commission_page.err_new_head_rate')]);
        }
        $kind = (string) __('commission_page.kind_'.$data['kind']);
        DB::transaction(function () use ($request, $drafts, $data, $rates, $kind): void {
            foreach ($rates as $type => $rate) {
                if ($rate <= 0) {
                    continue;
                }
                $drafts->create($request->user(), [
                    'name' => __('commission_page.new_head_draft_name', ['name' => $data['name'], 'kind' => $kind]),
                    'account_type' => $type,
                    'components' => [['name' => $data['name'], 'rate' => number_format($rate, 2, '.', '')]],
                ]);
            }
        });

        return back()->with('success', __('commission_page.flash_new_head_saved'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountDeletion;
use App\Domain\Finance\ClosedAccountBalance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Super Admin's action on a closed partner account: move the money still in it to the company fund.
 */
class ClosedAccountBalanceController extends Controller
{
    /** How much would move, shown before the owner confirms. */
    public function show(Request $r, User $user, ClosedAccountBalance $balances): JsonResponse
    {
        $this->permit($r, $user);

        return response()->json($balances->preview($user));
    }

    public function store(Request $r, User $user, ClosedAccountBalance $balances): RedirectResponse
    {
        $this->permit($r, $user);
        $data = $r->validate([
            'reason' => 'required|string|min:5|max:1000',
            'confirm' => 'accepted',
            'expected_amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'request_key' => 'required|uuid',
        ]);

        $result = $balances->moveToCompany($r->user(), $user, trim($data['reason']), $data['request_key'], $data['expected_amount']);

        return back()->with('success', __('money_core.flash_closed_balance_moved', [
            'amount' => Money::grouped($result['amount']),
            'code' => (string) $user->dealer_code,
        ]));
    }

    private function permit(Request $r, User $account): void
    {
        abort_unless(ClosedAccountBalance::canUse($r->user()) && PartnerAccountDeletion::isPartner($account), 403);
    }
}

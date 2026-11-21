<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountDeletion;
use App\Domain\Finance\ClawbackWriteOff;
use App\Domain\Finance\StaleHoldings;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owner's actions on the "Old money" list of the readiness page: how many days count as old, and writing off
 * what a closed or suspended partner still owes from a refund.
 */
class StaleHoldingsController extends Controller
{
    public function saveDays(Request $r, StaleHoldings $holdings): RedirectResponse
    {
        abort_unless(StaleHoldings::canEdit($r->user()), 403);
        $data = $r->validate([
            'days' => 'required|integer|min:1|max:'.StaleHoldings::MaximumDays,
            'version' => 'required|integer|min:0',
        ]);
        $holdings->save($r->user(), (int) $data['days'], (int) $data['version']);

        return back()->with('success', __('money_core.stale_days_saved'));
    }

    public function writeOff(Request $r, User $user, ClawbackWriteOff $writeOffs): RedirectResponse
    {
        abort_unless(ClawbackWriteOff::canUse($r->user()) && PartnerAccountDeletion::isPartner($user), 403);
        $data = $r->validate([
            'reason' => 'required|string|min:5|max:1000',
            'confirm' => 'accepted',
            'expected_amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'request_key' => 'required|uuid',
        ]);
        $result = $writeOffs->writeOff($r->user(), $user, trim($data['reason']), $data['request_key'], $data['expected_amount']);

        return back()->with('success', __('money_core.flash_clawback_written_off', ['amount' => Money::grouped($result['amount']), 'code' => (string) $user->dealer_code]));
    }
}

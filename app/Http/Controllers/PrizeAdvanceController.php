<?php

namespace App\Http\Controllers;

use App\Domain\Finance\PrizeAdvances;
use App\Models\PrizeAdvance;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Super Admin's actions on bike and car prizes paid in advance: give one, mark what is left of one recovered, or
 * write it off. {@see PrizeAdvances} checks the person again and moves the money.
 */
class PrizeAdvanceController extends Controller
{
    public function store(Request $request, PrizeAdvances $advances): RedirectResponse
    {
        abort_unless(PrizeAdvances::canGive($request->user()), 403);
        $data = $request->validate([
            'partner_id' => 'required|integer',
            'kind' => 'required|in:bike,car',
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => 'required|string|min:5|max:1000',
            // The owner types the day in Bangladesh time, which is ahead of the server's UTC clock.
            'given_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Dhaka')->toDateString(),
            'confirm' => 'accepted',
            'request_key' => 'required|uuid',
        ]);
        $advance = $advances->give($request->user(), [
            'partner_id' => (int) $data['partner_id'],
            'kind' => $data['kind'],
            'amount' => $data['amount'],
            'reason' => trim($data['reason']),
            'given_on' => $data['given_on'],
            'request_key' => $data['request_key'],
        ]);

        return back()->with('success', __('prize_advances.flash_given', ['amount' => Money::grouped((string) $advance->amount)]));
    }

    public function recover(Request $request, int $advance, PrizeAdvances $advances): RedirectResponse
    {
        abort_unless(PrizeAdvances::canGive($request->user()), 403);
        $data = $request->validate([
            'reference' => 'required|string|min:3|max:100',
            'note' => 'required|string|min:5|max:1000',
            'confirm' => 'accepted',
            'request_key' => 'required|uuid',
        ]);
        $closed = $advances->close($request->user(), $advance, PrizeAdvance::StatusRecovered, trim($data['note']), $data['request_key'], trim($data['reference']));

        return back()->with('success', __('prize_advances.flash_recovered', ['amount' => Money::grouped((string) $closed->closed_amount)]));
    }

    public function writeOff(Request $request, int $advance, PrizeAdvances $advances): RedirectResponse
    {
        abort_unless(PrizeAdvances::canGive($request->user()), 403);
        $data = $request->validate([
            'note' => 'required|string|min:5|max:1000',
            'confirm' => 'accepted',
            'request_key' => 'required|uuid',
        ]);
        $closed = $advances->close($request->user(), $advance, PrizeAdvance::StatusWrittenOff, trim($data['note']), $data['request_key']);

        return back()->with('success', __('prize_advances.flash_written_off', ['amount' => Money::grouped((string) $closed->closed_amount)]));
    }
}

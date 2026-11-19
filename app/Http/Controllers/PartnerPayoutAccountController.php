<?php

namespace App\Http\Controllers;

use App\Domain\Payouts\PartnerPayoutAccounts;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** V3: a dealer or sub-dealer adds, edits, removes and defaults his own payout accounts. */
class PartnerPayoutAccountController extends Controller
{
    public function __construct(private readonly PartnerPayoutAccounts $accounts) {}

    public function store(Request $request): RedirectResponse
    {
        $this->accounts->save($this->partner($request), $this->input($request));

        return back()->with('success', __('leftovers_partner.pa_saved_flash'));
    }

    public function update(Request $request, int $account): RedirectResponse
    {
        $this->accounts->save($this->partner($request), $this->input($request), $account);

        return back()->with('success', __('leftovers_partner.pa_saved_flash'));
    }

    public function destroy(Request $request, int $account): RedirectResponse
    {
        $this->accounts->remove($this->partner($request), $account);

        return back()->with('success', __('leftovers_partner.pa_removed_flash'));
    }

    public function makeDefault(Request $request, int $account): RedirectResponse
    {
        $this->accounts->makeDefault($this->partner($request), $account);

        return back()->with('success', __('leftovers_partner.pa_default_flash'));
    }

    private function partner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active' && in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'channel' => 'required|string|max:10',
            'number' => 'required|string|max:40',
            'bank_name' => 'nullable|string|max:100',
            'branch' => 'nullable|string|max:100',
            'account_name' => 'nullable|string|max:100',
        ]);
    }
}

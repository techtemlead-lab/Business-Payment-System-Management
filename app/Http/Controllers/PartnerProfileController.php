<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerMonthlyTargets;
use App\Domain\Accounts\PartnerProfileLock;
use App\Domain\Accounts\PartnerProfileSheet;
use App\Domain\Commissions\CommissionQualification;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One dealer's whole file for the office: every stored fact, the password (owners only, through the
 * audited reveal), the monthly targets and a link to the dealer's own tree. Reading is for staff
 * with the Accounts right; only owners change a dealer's monthly target.
 */
class PartnerProfileController extends Controller
{
    public function show(Request $request, User $partner, PartnerProfileSheet $sheet, PartnerMonthlyTargets $targets): Response
    {
        $this->authorizeStaff($request, $partner);
        $month = $this->month($request);

        return Inertia::render('PartnerProfile', [
            'section' => 'accounts',
            'sheet' => $sheet->for($partner, $request->user()),
            'month' => $month,
            'targets' => $targets->for($partner, $month),
            'canSetTargets' => $request->user()->owner(),
            'canManagePassword' => $request->user()->owner() || $request->user()->allows('accounts'),
        ]);
    }

    public function setTarget(Request $request, User $partner, PartnerMonthlyTargets $targets): RedirectResponse
    {
        $this->authorizeOwner($request, $partner);
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D'],
            'kind' => ['required', Rule::in(PartnerMonthlyTargets::Kinds)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $targets->set($request->user(), $partner, $data['month'], $data['kind'], (string) $data['amount'], $data['note'] ?? null);

        return back()->with('status', __('partner_profile.flash_target_saved'));
    }

    public function clearTarget(Request $request, User $partner, PartnerMonthlyTargets $targets): RedirectResponse
    {
        $this->authorizeOwner($request, $partner);
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D'],
            'kind' => ['required', Rule::in(PartnerMonthlyTargets::Kinds)],
        ]);
        $targets->clear($request->user(), $partner, $data['month'], $data['kind']);

        return back()->with('status', __('partner_profile.flash_target_cleared'));
    }

    public function setLock(Request $request, User $partner, PartnerProfileLock $lock): RedirectResponse
    {
        $this->authorizeStaff($request, $partner);
        $data = $request->validate(['locked' => ['required', 'boolean']]);
        $lock->set($request->user(), $partner, (bool) $data['locked']);

        return back()->with('status', __($data['locked'] ? 'partner_profile.flash_locked' : 'partner_profile.flash_unlocked'));
    }

    private function authorizeStaff(Request $request, User $partner): void
    {
        $viewer = $request->user();
        abort_unless($viewer->staff() || $viewer->usingStaffWorkspace(), 403);
        abort_unless($viewer->owner() || $viewer->allows('accounts'), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);
    }

    private function authorizeOwner(Request $request, User $partner): void
    {
        abort_unless($request->user()->owner(), 403);
        abort_unless($partner->role === 'dealer', 404);
    }

    private function month(Request $request): string
    {
        $month = $request->query('month');

        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) ? $month : CommissionQualification::currentMonth();
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerProfileLock;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The partner profile lock: the office locks or opens a profile, and a partner whose profile is
 * open may fix his own shop name and village.
 */
class PartnerProfileController extends Controller
{
    /** Office: lock a partner's profile, or open it so the partner can fix his own details. */
    public function lock(Request $request, User $user, PartnerProfileLock $lock): RedirectResponse
    {
        abort_unless($request->user()->allows('accounts'), 403);
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 422, __('partner_profile.err_partners_only'));
        $data = $request->validate([
            'locked' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);
        $lock->set($request->user(), $user, (bool) $data['locked'], (string) ($data['reason'] ?? ''));

        return back()->with('success', __($data['locked'] ? 'partner_profile.flash_locked' : 'partner_profile.flash_unlocked', ['name' => $user->name]));
    }

    /** Partner: change his own shop name and village, only while the office has left his profile open. */
    public function details(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && PartnerProfileLock::partnerMayEdit($user), 403, __('partner_profile.err_locked'));
        $data = $request->validate([
            'shop_name' => 'nullable|string|max:150',
            'village' => 'nullable|string|max:150',
        ]);
        $profile = is_array($user->profile) ? $user->profile : [];
        $before = ['shop_name' => $profile['shop_name'] ?? null, 'village' => $profile['village'] ?? null];
        $profile['shop_name'] = trim((string) ($data['shop_name'] ?? '')) ?: null;
        $profile['village'] = trim((string) ($data['village'] ?? '')) ?: null;
        $user->forceFill(['profile' => $profile])->save();
        Audit::record($user->id, 'profile.details_updated', (string) $user->id, [
            'before' => $before,
            'after' => ['shop_name' => $profile['shop_name'], 'village' => $profile['village']],
        ]);

        return back()->with('success', __('partner_profile.flash_details_saved'));
    }
}

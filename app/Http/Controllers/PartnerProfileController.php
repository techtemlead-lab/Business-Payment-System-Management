<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\PartnerProfileLock;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    /**
     * Partner: ask the office to change a locked detail (phone, union, address). The office gets the old and the new
     * values side by side and makes the change itself, so a profile can stay locked and still be corrected.
     */
    public function changeRequest(Request $request, AccountPhoneRules $phones): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        $data = $request->validate([
            'phone' => 'nullable|string|max:30',
            'union' => 'nullable|string|max:150',
            'village' => 'nullable|string|max:150',
            'note' => 'required|string|max:1000',
        ]);
        $profile = PartnerProfileLock::profileOf($user);
        $old = ['phone' => $user->phone, 'union' => $profile['union'] ?? null, 'village' => $profile['village'] ?? null];
        $new = [];
        foreach (['phone', 'union', 'village'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '' || $value === (string) ($old[$field] ?? '')) {
                continue;
            }
            $new[$field] = $field === 'phone' ? $phones->normalize($value) : $value;
        }
        if ($new === []) {
            throw ValidationException::withMessages(['note' => __('joining.err_change_nothing')]);
        }
        Audit::record($user->id, 'profile.change_requested', (string) $user->id, ['old' => $old, 'new' => $new, 'note' => $data['note']]);
        $lines = collect($new)->map(fn (string $value, string $field): string => __('joining.change_field_'.$field).': '.((string) ($old[$field] ?? '—') ?: '—').' → '.$value)->implode('; ');
        Audit::notifyAdminsAndStaff(
            ['accounts'],
            fn (string $locale): string => __('joining.change_notice_title', ['name' => $user->name], $locale),
            fn (string $locale): string => __('joining.change_notice_body', ['code' => (string) $user->dealer_code, 'lines' => $lines, 'note' => (string) $data['note']], $locale),
        );

        return back()->with('success', __('joining.change_sent'));
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
        $profile = PartnerProfileLock::profileOf($user);
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

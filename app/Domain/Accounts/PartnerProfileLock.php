<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * The office's lock on a partner's profile. A locked profile can be changed by the office only;
 * an unlocked one lets the partner fix his own photo, shop name and village. New partners start
 * locked, so nothing changes for anyone until the office opens a profile on purpose.
 */
final class PartnerProfileLock
{
    public const Key = 'profile_locked';

    public static function isLocked(User $user): bool
    {
        $profile = is_array($user->profile) ? $user->profile : [];

        return (bool) ($profile[self::Key] ?? true);
    }

    /** Whether the signed-in partner may change his own details right now. */
    public static function partnerMayEdit(User $user): bool
    {
        return in_array($user->role, ['dealer', 'sub_dealer'], true) && ! self::isLocked($user);
    }

    public function set(User $actor, User $partner, bool $locked, string $reason = ''): void
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user_id' => __('partner_profile.err_partners_only')]);
        }
        $was = self::isLocked($partner);
        $profile = is_array($partner->profile) ? $partner->profile : [];
        $profile[self::Key] = $locked;
        $partner->forceFill(['profile' => $profile])->save();

        Audit::record($actor->id, 'accounts.profile_lock_changed', (string) $partner->id, [
            'was_locked' => $was,
            'locked' => $locked,
            'reason' => trim($reason),
        ]);
        $locale = $partner->preferredLocale();
        Audit::notice(
            $partner->id,
            __('partner_profile.notice_lock_title', [], $locale),
            __($locked ? 'partner_profile.notice_locked_body' : 'partner_profile.notice_unlocked_body', [], $locale),
        );
    }
}

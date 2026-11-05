<?php

namespace App\Domain\Accounts;

use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\Audit;

/**
 * Tells the owners when a staff member who is not an owner resets someone's password
 * (owner decision, 2026-11-05). The notice lands in each active owner's in-app
 * notifications. It never contains the new password. Owners can switch it off on
 * the Security page.
 */
final class PasswordResetOwnerNotice
{
    public function __construct(private SecuritySettings $security) {}

    /** @return int How many owners were told. */
    public function notify(User $actor, User $target): int
    {
        if ($actor->owner() || ! $this->security->passwordResetNoticeEnabled()) {
            return 0;
        }

        $owners = User::query()
            ->whereIn('role', ['super_admin', 'md', 'chairman'])
            ->where('status', 'active')
            ->whereKeyNot($actor->id)
            ->get(['id', 'locale']);

        foreach ($owners as $owner) {
            $locale = $owner->preferredLocale();
            Audit::notice(
                $owner->id,
                __('portal.notice_password_reset_by_staff_title', [], $locale),
                __('portal.notice_password_reset_by_staff_body', [
                    'staff' => $actor->name,
                    'account' => $target->name,
                    'code' => $target->dealer_code ?: $target->phone ?: '#'.$target->id,
                    'time' => now()->timezone('Asia/Dhaka')->format('d M Y, h:i A'),
                ], $locale),
            );
        }

        return $owners->count();
    }
}

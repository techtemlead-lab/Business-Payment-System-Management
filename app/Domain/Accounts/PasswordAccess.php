<?php

namespace App\Domain\Accounts;

use App\Models\User;

/**
 * Who may reset or see another account's password.
 *
 * - Reset (owner decision, 2026-11-05): owners and authorised staff. Dealer / sub-dealer
 *   passwords need an owner or the Accounts permission; staff passwords need an owner or the
 *   staff-admin permission; MD and Chairman passwords only the Super Admin. Nobody resets the
 *   Super Admin from here, and nobody resets their own password from here (use Security).
 * - Read back the current password (owner decision, 2026-11-17): only the owners (Super Admin,
 *   MD, Chairman), and only for dealer and sub-dealer accounts. Staff, MD and Chairman passwords
 *   are never kept readable, so nobody can sign in as another office member and pass a
 *   second-person check in their name.
 * - When an owner resets an account they may type the new password and see it once, because the
 *   person learns of the change at once (their old password stops working).
 */
final class PasswordAccess
{
    public function canReset(User $actor, User $target): bool
    {
        if ($actor->status !== 'active' || $actor->is($target) || $target->role === 'super_admin') {
            return false;
        }
        if (in_array($target->role, ['md', 'chairman'], true)) {
            return $actor->role === 'super_admin';
        }
        if ($target->staff()) {
            return $actor->owner() || $actor->allows('staff_admin');
        }
        if (in_array($target->role, ['dealer', 'sub_dealer'], true)) {
            return $actor->owner() || $actor->allows('accounts');
        }

        return false;
    }

    public function canView(User $actor, User $target): bool
    {
        return $actor->owner()
            && in_array($target->role, User::RevealablePasswordRoles, true)
            && $this->canReset($actor, $target);
    }

    /** May choose the new password on a reset and see it once. */
    public function mayChooseOnReset(User $actor, User $target): bool
    {
        return $actor->owner() && $this->canReset($actor, $target);
    }
}

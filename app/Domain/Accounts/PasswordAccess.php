<?php

namespace App\Domain\Accounts;

use App\Models\User;

/**
 * Who may reset or see another account's password (owner decision, 2026-11-05).
 *
 * - Reset: owners and authorised staff. Dealer / sub-dealer passwords need an owner
 *   or the Accounts permission; staff passwords need an owner or the staff-admin permission;
 *   MD and Chairman passwords only the Super Admin. Nobody resets the Super Admin
 *   from here, and nobody resets their own password from here (use Security).
 * - See the real password: owners only (Super Admin, MD, Chairman), and only for
 *   accounts they are allowed to reset.
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
        return $actor->owner() && $this->canReset($actor, $target);
    }
}

<?php

namespace App\Domain\Security;

use App\Models\User;

/**
 * The one rule behind "the owner is never blocked" (client change request CR-10, 2026-11-18).
 *
 * The company has one owner (Super Admin) and nobody ranks above them, so no step may wait for
 * a "higher authority", a second person, an owner confirmation, a compulsory reason box or a
 * profile lock when the owner does it. Staff keep every check, and so do the MD and Chairman
 * (they are owner-level readers, not the owner). Guards ask this class instead of checking the
 * role themselves, so the rule lives in one place.
 */
final class OwnerExemption
{
    public static function applies(?User $user): bool
    {
        return $user !== null && $user->status === 'active' && $user->role === 'super_admin';
    }
}

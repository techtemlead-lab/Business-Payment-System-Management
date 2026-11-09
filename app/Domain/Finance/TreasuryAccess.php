<?php

namespace App\Domain\Finance;

use App\Models\User;

/**
 * Who may open which Treasury page. A dealer with a linked office role keeps the same desk, so
 * only these checks decide who gets in, on every portal.
 */
final class TreasuryAccess
{
    public static function mayOpenFunds(User $user): bool
    {
        return FundSpending::canManage($user);
    }

    public static function mayOpenOrders(User $user): bool
    {
        return $user->status === 'active' && ($user->owner() || $user->allows('finance') || $user->allows('reports'));
    }

    public static function mayOpenPartners(User $user): bool
    {
        return $user->status === 'active' && ($user->owner() || $user->allows('finance'));
    }

    public static function mayOpenEvents(User $user): bool
    {
        return $user->status === 'active' && ($user->owner() || $user->allows('finance'));
    }

    /** The owners' fund exists only for an owner on the owner panel. */
    public static function showsOwnerFunds(User $user, string $portal): bool
    {
        return $user->owner() && $portal === 'sadmin';
    }
}

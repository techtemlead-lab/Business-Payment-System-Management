<?php

namespace App\Domain\Finance;

use App\Domain\Staff\LeadershipAccess;
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
        return $user->status === 'active' && (LeadershipAccess::granted($user, 'treasury') || $user->allows('finance') || $user->allows('reports'));
    }

    public static function mayOpenPartners(User $user): bool
    {
        return $user->status === 'active' && (LeadershipAccess::granted($user, 'treasury') || $user->allows('finance'));
    }

    /** One partner's statement: also open to account managers, as the old statement page was. */
    public static function mayOpenPartner(User $user): bool
    {
        return self::mayOpenPartners($user) || ($user->status === 'active' && $user->allows('accounts'));
    }

    public static function mayOpenEvents(User $user): bool
    {
        return $user->status === 'active' && (LeadershipAccess::granted($user, 'treasury') || $user->allows('finance'));
    }

    /** The profit and loss shows the owners' share, so it is for an owner on the owner panel only. */
    public static function mayOpenProfit(User $user, string $portal): bool
    {
        return $user->status === 'active' && self::showsOwnerFunds($user, $portal);
    }

    /** The owners' fund exists only for an owner on the owner panel. */
    public static function showsOwnerFunds(User $user, string $portal): bool
    {
        return LeadershipAccess::granted($user, 'treasury') && $portal === 'sadmin';
    }
}

<?php

namespace App\Domain\Finance;

use App\Models\User;

/**
 * The withdrawal size at or above which a request is a large payout: it is marked in the activity
 * log and, since CR-16, only an owner may approve it ({@see OwnerApprovalLimits}). Smaller requests
 * are approved by finance staff and paid by a second staff member. The owner sets it; every change
 * is audit-logged.
 */
final class WithdrawalApprovalLimit
{
    public const DefaultAmount = OwnerApprovalLimits::Defaults[OwnerApprovalLimits::Withdrawal];

    public function __construct(private readonly OwnerApprovalLimits $limits) {}

    /** @return array{amount: string, version: int} */
    public function current(): array
    {
        return $this->limits->current(OwnerApprovalLimits::Withdrawal);
    }

    public function save(User $actor, string $amount, int $version, string $reason): void
    {
        $this->limits->save($actor, OwnerApprovalLimits::Withdrawal, $amount, $version, $reason);
    }
}

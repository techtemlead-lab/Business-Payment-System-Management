<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The withdrawal size at or above which a request is marked as a large payout in the activity
 * log. The mark does not stop a payment or ask for an extra approver: withdrawals follow the
 * normal approval steps, including the owner finishing alone when nobody else can
 * ({@see SelfApproval}). The owner sets it; every change is audit-logged.
 */
final class WithdrawalApprovalLimit
{
    public const DefaultAmount = '100000.00';

    private const Key = 'withdrawal_approval_limit';

    /** @return array{amount: string, version: int} */
    public function current(): array
    {
        $row = DB::table('business_settings')->where('key', self::Key)->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['amount' => (string) ($stored['amount'] ?? self::DefaultAmount), 'version' => $row->version ?? 0];
    }

    public function save(User $actor, string $amount, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $amount, $version, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->owner(), 403);
            $before = $this->current();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            $cents = Money::cents($amount);
            if ($cents < 1) {
                throw ValidationException::withMessages(['amount' => __('accounting.err_limit_positive')]);
            }
            $after = ['amount' => Money::decimal($cents)];
            $stored = DB::table('business_settings')->where('key', self::Key)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::Key], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'finance.withdrawal_approval_limit_changed', self::Key, [
                'before' => ['amount' => $before['amount']], 'after' => $after, 'reason' => $reason,
            ]);
        }, 3);
    }
}

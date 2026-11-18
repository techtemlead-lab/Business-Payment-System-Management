<?php

namespace App\Domain\Payouts;

use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Owner rule (Q28, decision D4): a partner who is not active is not paid. Suspending a partner puts
 * every open withdrawal of theirs (asked for or approved, not yet paid) on hold: the money stays
 * reserved, nobody can approve or pay it, and finance can still reject it to give the money back.
 * Reactivating the partner lifts the hold. Call both inside the transaction that changes the
 * partner's status, after the partner's row is locked.
 */
final class WithdrawalHolds
{
    /** Statuses of a withdrawal that is still waiting for money to leave. */
    public const OpenStatuses = ['requested', 'approved'];

    /**
     * Puts the partner's open withdrawals on hold. Returns how many were put on hold.
     */
    public function holdForPartner(User $actor, int $partnerId, string $note): int
    {
        $open = DB::table('withdrawals')->where('user_id', $partnerId)->whereIn('status', self::OpenStatuses)
            ->whereNull('on_hold_at')->lockForUpdate()->get(['id', 'amount', 'status']);
        foreach ($open as $withdrawal) {
            DB::table('withdrawals')->where('id', $withdrawal->id)->update(['on_hold_at' => now(), 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $withdrawal->id, 'actor_id' => $actor->id, 'event' => 'on_hold', 'note' => $note, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.on_hold', $withdrawal->id, ['user_id' => $partnerId, 'amount' => $withdrawal->amount, 'status' => $withdrawal->status]);
        }

        return $open->count();
    }

    /**
     * Lifts the hold from the partner's open withdrawals. Returns how many were released.
     */
    public function releaseForPartner(User $actor, int $partnerId, string $note): int
    {
        $held = DB::table('withdrawals')->where('user_id', $partnerId)->whereIn('status', self::OpenStatuses)
            ->whereNotNull('on_hold_at')->lockForUpdate()->get(['id', 'amount']);
        foreach ($held as $withdrawal) {
            DB::table('withdrawals')->where('id', $withdrawal->id)->update(['on_hold_at' => null, 'updated_at' => now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id' => $withdrawal->id, 'actor_id' => $actor->id, 'event' => 'hold_released', 'note' => $note, 'created_at' => now()]);
            Audit::record($actor->id, 'withdrawal.hold_released', $withdrawal->id, ['user_id' => $partnerId, 'amount' => $withdrawal->amount]);
        }

        return $held->count();
    }

    /**
     * The partner's open withdrawals, for the suspend dialog: how many and how much.
     *
     * @return array{count: int, amount: string}
     */
    public function openSummary(int $partnerId): array
    {
        $row = DB::table('withdrawals')->where('user_id', $partnerId)->whereIn('status', self::OpenStatuses)
            ->selectRaw('COUNT(*) AS open_count, COALESCE(SUM(amount), 0) AS open_amount')->first();

        return ['count' => (int) ($row->open_count ?? 0), 'amount' => Money::decimal(Money::cents((string) ($row->open_amount ?? '0')))];
    }
}

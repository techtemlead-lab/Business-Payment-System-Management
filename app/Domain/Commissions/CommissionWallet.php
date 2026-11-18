<?php

namespace App\Domain\Commissions;

use App\Models\CommissionAllocation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommissionWallet
{
    /** Advisory key of the money lock (one global key, taken exclusive or shared). */
    private const MoneyLockKey = 81416001;

    /** Advisory key a backup takes exclusive; every money change holds it shared. */
    private const BackupLockKey = 81416004;

    /** First half of the two-part advisory key that locks one partner's money. */
    private const PartnerLockSpace = 81416010;

    /**
     * The whole-company money lock, exclusive: nothing else that touches money runs until this
     * transaction ends. For changes to the rules themselves (policy, settings, opening balances,
     * the referral tree, fund spending against a fund's balance). Everyday money moves use
     * lockShared() plus lockPartners() instead, so they do not queue behind each other (PQ-01).
     */
    public static function lock(): void
    {
        self::requireTransaction();
        DB::select('SELECT pg_advisory_xact_lock_shared(?)', [self::BackupLockKey]);
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::MoneyLockKey]);
    }

    /**
     * The money lock, shared: any number of everyday money moves run side by side, and all of them
     * wait while someone holds lock(). Rows they both change are kept apart by row locks and by
     * lockPartners(). Safe to call when this transaction already holds lock().
     */
    public static function lockShared(): void
    {
        self::requireTransaction();
        DB::select('SELECT pg_advisory_xact_lock_shared(?)', [self::BackupLockKey]);
        DB::select('SELECT pg_advisory_xact_lock_shared(?)', [self::MoneyLockKey]);
    }

    /**
     * lockShared() plus one exclusive lock per partner, always taken in id order so two moves for
     * the same partners queue instead of deadlocking. Use for every change to a partner's wallet,
     * debts, withdrawals or rank counts.
     */
    public static function lockPartners(int ...$userIds): void
    {
        self::lockShared();
        $userIds = array_values(array_unique(array_filter($userIds, fn (int $id): bool => $id > 0)));
        sort($userIds);
        foreach ($userIds as $userId) {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::PartnerLockSpace, $userId]);
        }
    }

    private static function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Financial mutations require a transaction.');
        }
    }

    public static function requireActive(): void
    {
        if (config('madina.preview_mode') || ! config('madina.financial_activation')) {
            throw ValidationException::withMessages(['policy' => __('portal.err_wallet_posting_disabled')]);
        }
        if (! DB::table('financial_verifications')->whereNotNull('verified_at')->exists()) {
            throw ValidationException::withMessages(['policy' => __('portal.err_wallet_posting_unverified')]);
        }
    }

    public static function hasDebt(int $userId): bool
    {
        if (DB::table('commission_reversals')->where('user_id', $userId)->where('unrecovered', '>', 0)->exists()) {
            return true;
        }
        foreach (CommissionAllocation::where('user_id', $userId)->whereNotNull('metadata->unrecovered_deficit')->get() as $allocation) {
            if (Money::cents((string) ($allocation->metadata['unrecovered_deficit'] ?? '0.00')) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Credit an already journalled earning; recover outstanding clawbacks before making it spendable. */
    public function credit(int $userId, int $amount, bool $held = false): void
    {
        self::lockPartners($userId);
        DB::table('wallets')->insertOrIgnore(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
        $recovery = 0;
        if (! $held) {
            $reversalDebts = DB::table('commission_reversals')->where('user_id', $userId)->where('unrecovered', '>', 0)->orderBy('id')->lockForUpdate()->get();
            foreach ($reversalDebts as $debtRow) {
                $debt = Money::cents($debtRow->unrecovered);
                $deduct = min($debt, $amount - $recovery);
                if ($deduct > 0) {
                    DB::table('commission_reversals')->where('id', $debtRow->id)->update(['unrecovered' => Money::decimal($debt - $deduct),
                        'recovered' => Money::decimal(Money::cents($debtRow->recovered) + $deduct), 'updated_at' => now()]);
                    $allocation = CommissionAllocation::findOrFail($debtRow->allocation_id);
                    if ($allocation->user_id === $userId) {
                        $allocation->update(['metadata' => [...$allocation->metadata, 'unrecovered_deficit' => Money::decimal(max(0,
                            Money::cents((string) ($allocation->metadata['unrecovered_deficit'] ?? '0.00')) - $deduct))]]);
                    }
                    $recovery += $deduct;
                }
            }
            $debts = CommissionAllocation::where('user_id', $userId)->whereNotNull('metadata->unrecovered_deficit')->orderBy('id')->lockForUpdate()->get();
            foreach ($debts as $allocation) {
                if (DB::table('commission_reversals')->where('allocation_id', $allocation->id)->exists()) {
                    continue;
                }
                $debt = Money::cents((string) ($allocation->metadata['unrecovered_deficit'] ?? '0.00'));
                $deduct = min($debt, $amount - $recovery);
                if ($deduct > 0) {
                    $allocation->update(['metadata' => [...$allocation->metadata, 'unrecovered_deficit' => Money::decimal($debt - $deduct)]]);
                    $recovery += $deduct;
                }
                if ($recovery === $amount) {
                    break;
                }
            }
        }
        $changes = ['updated_at' => now()];
        if ($held) {
            $changes['held'] = Money::decimal(Money::cents($wallet->held) + $amount);
            $changes['commission_held'] = Money::decimal(Money::cents($wallet->commission_held) + $amount);
        } else {
            $changes['available'] = Money::decimal(Money::cents($wallet->available) + $amount - $recovery);
        }
        DB::table('wallets')->where('id', $wallet->id)->update($changes);
        if ($recovery > 0) {
            $locale = User::find($userId)?->preferredLocale() ?? app()->getLocale();
            $this->journal('commissions.debt_recovery.'.Str::uuid(), __('portal.jr_clawback_offset', [], $locale), [
                ['account' => 'wallet_liability', 'user_id' => $userId, 'debit' => $recovery, 'credit' => 0],
                ['account' => 'commission_clawback_receivable', 'user_id' => $userId, 'debit' => 0, 'credit' => $recovery],
            ]);
        }
    }

    /** @return array{recovered: int, unrecovered: int} */
    public function debit(int $userId, int $amount, bool $held = false): array
    {
        self::lockPartners($userId);
        $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
        $balance = $wallet ? Money::cents($held ? $wallet->commission_held : $wallet->available) : 0;
        $recovered = min($balance, $amount);
        if ($wallet && $recovered > 0) {
            $changes = [$held ? 'held' : 'available' => Money::decimal(Money::cents($held ? $wallet->held : $wallet->available) - $recovered), 'updated_at' => now()];
            if ($held) {
                $changes['commission_held'] = Money::decimal($balance - $recovered);
            }
            DB::table('wallets')->where('id', $wallet->id)->update($changes);
        }

        return ['recovered' => $recovered, 'unrecovered' => $amount - $recovered];
    }

    /** @param list<array{account: string, user_id: ?int, debit: int, credit: int}> $lines */
    public function journal(string $eventKey, string $description, array $lines): void
    {
        self::lockShared();
        if (array_sum(array_column($lines, 'debit')) !== array_sum(array_column($lines, 'credit'))) {
            throw new \LogicException('Unbalanced financial journal.');
        }
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $eventKey, 'description' => $description, 'actor_id' => Auth::id(), 'created_at' => now()]);
        foreach ($lines as $line) {
            if ($line['debit'] > 0 || $line['credit'] > 0) {
                DB::table('journal_lines')->insert([...$line, 'entry_id' => $id, 'debit' => Money::decimal($line['debit']), 'credit' => Money::decimal($line['credit'])]);
            }
        }
    }
}

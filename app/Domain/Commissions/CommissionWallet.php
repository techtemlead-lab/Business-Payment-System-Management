<?php

namespace App\Domain\Commissions;

use App\Models\CommissionAllocation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommissionWallet
{
    /** Serialize financial mutations across order, settlement and payout transactions. */
    public static function lock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Financial mutations require a transaction.');
        }
        DB::select('SELECT pg_advisory_xact_lock_shared(?)', [81416004]);
        DB::select('SELECT pg_advisory_xact_lock(?)', [81416001]);
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
        self::lock();
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
            $this->journal('commissions.debt_recovery.'.Str::uuid(), __('portal.jr_clawback_offset'), [
                ['account' => 'wallet_liability', 'user_id' => $userId, 'debit' => $recovery, 'credit' => 0],
                ['account' => 'commission_clawback_receivable', 'user_id' => $userId, 'debit' => 0, 'credit' => $recovery],
            ]);
        }
    }

    /** @return array{recovered: int, unrecovered: int} */
    public function debit(int $userId, int $amount, bool $held = false): array
    {
        self::lock();
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
        self::lock();
        if (array_sum(array_column($lines, 'debit')) !== array_sum(array_column($lines, 'credit'))) {
            throw new \LogicException('Unbalanced financial journal.');
        }
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $eventKey, 'description' => $description, 'created_at' => now()]);
        foreach ($lines as $line) {
            if ($line['debit'] > 0 || $line['credit'] > 0) {
                DB::table('journal_lines')->insert([...$line, 'entry_id' => $id, 'debit' => Money::decimal($line['debit']), 'credit' => Money::decimal($line['credit'])]);
            }
        }
    }
}

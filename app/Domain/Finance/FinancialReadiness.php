<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinancialReadiness
{
    /** @return array<string, mixed> */
    public function inspect(): array
    {
        return DB::transaction(function (): array {
            CommissionWallet::lock();
            $checks = [
                'balanced_journals' => ! DB::table('journal_lines')->select('entry_id')->groupBy('entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->exists(),
                'wallet_liabilities' => ! DB::table('wallets as w')->whereRaw("w.available + w.held <> COALESCE((SELECT SUM(credit-debit) FROM journal_lines j WHERE j.user_id=w.user_id AND j.account IN ('wallet_liability','commission_held_liability')),0)")->exists(),
                'maturing_holds' => ! DB::table('wallets as w')->whereRaw("w.commission_held <> COALESCE((SELECT SUM(amount) FROM commission_allocations a WHERE a.user_id=w.user_id AND a.status='maturing'),0)")->exists(),
                'withdrawal_reservations' => ! DB::table('wallets as w')->whereRaw("w.held-w.commission_held <> COALESCE((SELECT SUM(amount) FROM withdrawals x WHERE x.user_id=w.user_id AND x.status IN ('requested','approved')),0)")->exists(),
                'order_receipts' => ! DB::table('orders as o')->whereRaw('o.paid <> COALESCE((SELECT SUM(amount) FROM receipts r WHERE r.order_id=o.id),0)')->exists(),
                'funded_batches' => ! DB::table('commission_batches')->whereRaw('cash_basis <> company_share + total_allocated + unallocated OR refunded_amount > cash_basis')->exists(),
                'allocation_totals' => ! DB::table('commission_batches as b')->whereRaw('b.total_allocated <> COALESCE((SELECT SUM(COALESCE(a.original_amount,a.amount)) FROM commission_allocations a WHERE a.order_id=b.order_id),0)')->exists(),
                'nonnegative_balances' => ! DB::table('wallets')->whereRaw('available < 0 OR held < 0 OR commission_held < 0 OR commission_held > held')->exists(),
                'nonnegative_stock' => ! DB::table('products')->where('stock', '<', 0)->exists(),
            ];
            $totals = [];
            foreach (['wallet_available' => ['wallets', 'available'], 'wallet_held' => ['wallets', 'held'],
                'order_paid' => ['orders', 'paid'], 'withdrawal_paid' => ['withdrawals', 'amount']] as $key => [$table, $column]) {
                $query = DB::table($table);
                if ($key === 'withdrawal_paid') {
                    $query->where('status', 'paid');
                }
                $totals[$key] = Money::decimal(Money::cents((string) $query->sum($column)));
            }
            $counts = [];
            foreach (['orders', 'receipts', 'journal_entries', 'commission_batches', 'commission_refunds', 'withdrawals', 'payroll_entries', 'reserve_disbursements'] as $table) {
                $counts[$table] = DB::table($table)->count();
            }
            $policy = app(CommissionPolicy::class)->snapshot();
            $digest = hash_init('sha256');
            foreach (DB::table('wallets')->orderBy('user_id')->cursor(['user_id', 'available', 'held', 'commission_held']) as $wallet) {
                hash_update($digest, json_encode($wallet, JSON_THROW_ON_ERROR));
            }
            foreach (DB::table('orders')->orderBy('id')->cursor(['id', 'total', 'paid', 'status']) as $order) {
                hash_update($digest, json_encode($order, JSON_THROW_ON_ERROR));
            }
            $snapshot = ['checks' => $checks, 'totals' => $totals, 'counts' => $counts, 'balance_digest' => hash_final($digest), 'policy' => $policy['rules']];

            return [...$snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'internally_reconciled' => ! in_array(false, $checks, true), 'posting_enabled' => (bool) config('madina.financial_activation'),
                'opening_verified' => DB::table('financial_verifications')->whereNotNull('verified_at')->exists()];
        }, 3);
    }

    /** @param array<string, string> $totals */
    public function submit(User $actor, array $totals, string $source, UploadedFile $evidence): int
    {
        abort_unless($actor->allows('finance'), 403);
        $path = $evidence->storeAs('opening-balance-evidence', Str::uuid().'.'.$evidence->guessExtension(), 'local');
        try {
            return DB::transaction(function () use ($actor, $totals, $source, $evidence, $path): int {
                CommissionWallet::lock();
                abort_unless($actor->fresh()->allows('finance'), 403);
                $snapshot = $this->inspect();
                if (! $snapshot['internally_reconciled']) {
                    throw ValidationException::withMessages(['balances' => 'Resolve internal reconciliation failures before submitting opening balances.']);
                }
                $expected = array_map(fn ($value): string => Money::decimal(Money::cents($value)), $totals);
                if ($expected != $snapshot['totals']) {
                    throw ValidationException::withMessages(['balances' => 'The independently supplied opening totals do not match the ledger.']);
                }
                $id = DB::table('financial_verifications')->insertGetId(['fingerprint' => $snapshot['fingerprint'], 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'expected_totals' => json_encode($expected, JSON_THROW_ON_ERROR), 'source_reference' => $source, 'evidence_path' => $path,
                    'evidence_hash' => hash_file('sha256', $evidence->getRealPath()), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                Audit::record($actor->id, 'opening_balances.submitted', (string) $id, ['source_reference' => $source]);

                return $id;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function verify(User $actor, int $id): void
    {
        abort_unless($actor->allows('finance'), 403);
        DB::transaction(function () use ($actor, $id): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $row = DB::table('financial_verifications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->verified_at || $row->created_by === $actor->id || $row->fingerprint !== $this->inspect()['fingerprint']) {
                throw ValidationException::withMessages(['balances' => 'A separate Finance reviewer must verify an unchanged opening snapshot.']);
            }
            DB::table('financial_verifications')->where('id', $id)->update(['verified_by' => $actor->id, 'verified_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'opening_balances.verified', (string) $id);
        }, 3);
    }
}

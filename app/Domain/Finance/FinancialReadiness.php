<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\ReasonFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinancialReadiness
{
    /**
     * Do the money records agree with each other right now? Each check is a single read of
     * saved rows, so a payment halfway through cannot make one fail.
     *
     * @return array<string, bool>
     */
    public function checks(): array
    {
        return [
            'balanced_journals' => ! DB::table('journal_lines')->select('entry_id')->groupBy('entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->exists(),
            'wallet_liabilities' => ! DB::table('wallets as w')->whereRaw("w.available + w.held <> COALESCE((SELECT SUM(credit-debit) FROM journal_lines j WHERE j.user_id=w.user_id AND j.account IN ('wallet_liability','commission_held_liability')),0)")->exists(),
            'maturing_holds' => ! DB::table('wallets as w')->whereRaw("w.commission_held <> COALESCE((SELECT SUM(amount) FROM commission_allocations a WHERE a.user_id=w.user_id AND a.status='maturing'),0) + COALESCE((SELECT SUM(held-held_released) FROM opening_balance_entries e WHERE e.user_id=w.user_id),0)")->exists(),
            'withdrawal_reservations' => ! DB::table('wallets as w')->whereRaw("w.held-w.commission_held <> COALESCE((SELECT SUM(amount) FROM withdrawals x WHERE x.user_id=w.user_id AND x.status IN ('requested','approved')),0)")->exists(),
            'order_receipts' => ! DB::table('orders as o')->whereRaw('o.paid <> COALESCE((SELECT SUM(amount) FROM receipts r WHERE r.order_id=o.id),0)')->exists(),
            'funded_batches' => ! DB::table('commission_batches')->whereRaw('cash_basis <> company_share + total_allocated + unallocated OR refunded_amount > cash_basis')->exists(),
            // Commission rows must add up to the batch. The purchase surplus is the batch's
            // unallocated part, and settlement leftovers are carved out of an existing fund,
            // so neither is counted again here.
            'allocation_totals' => ! DB::table('commission_batches as b')->whereRaw("b.total_allocated <> COALESCE((SELECT SUM(COALESCE(a.original_amount,a.amount)) FROM commission_allocations a WHERE a.order_id=b.order_id AND a.category <> 'company_commission_benefits_fund' AND a.metadata->>'source_allocation_id' IS NULL),0)")->exists(),
            'nonnegative_balances' => ! DB::table('wallets')->whereRaw('available < 0 OR held < 0 OR commission_held < 0 OR commission_held > held')->exists(),
            'nonnegative_stock' => ! DB::table('products')->where('stock', '<', 0)->exists(),
            'opening_receivables' => ! DB::table('orders as o')->where('o.is_opening_balance', true)
                ->whereRaw("o.total-o.paid-o.due_credit <> COALESCE((SELECT SUM(j.debit-j.credit) FROM journal_lines j WHERE j.user_id=o.user_id AND j.account='opening_receivables'),0)")->exists(),
            'due_adjustments' => ! DB::table('orders as o')->whereRaw("o.due_credit <> COALESCE((SELECT SUM(CASE WHEN a.kind='credit' THEN a.amount ELSE -a.amount END) FROM due_adjustments a WHERE a.order_id=o.id AND a.status='approved'),0) OR o.due_credit < 0 OR o.paid+o.due_credit > o.total")->exists(),
            // Orders paid from a partner's account, less what went back there, must equal what is still set aside for them.
            'account_order_money' => (bool) DB::selectOne("SELECT COALESCE((SELECT SUM(amount) FROM receipts WHERE channel='Account'),0) - COALESCE((SELECT SUM(to_account) FROM order_refunds),0) = COALESCE((SELECT SUM(credit-debit) FROM journal_lines WHERE account='wallet_order_clearing'),0) AS agrees")->agrees,
            // A month-end payout credits the wallet and keeps its own payout row. Reward awards
            // use the same payout table but a different journal, so they stay out of this sum.
            // A later refund debits the wallet on its own journal line and leaves the original
            // payout amount as it was.
            'settlement_wallets' => (bool) DB::selectOne(<<<'SQL'
                SELECT NOT EXISTS (
                    SELECT 1
                    FROM (
                        SELECT user_id, SUM(signed_amount) AS signed_amount
                        FROM (
                            SELECT j.user_id, (j.credit - j.debit) AS signed_amount
                            FROM journal_lines j
                            INNER JOIN journal_entries e ON e.id = j.entry_id
                            WHERE j.account = 'wallet_liability'
                              AND j.user_id IS NOT NULL
                              AND e.event_key LIKE 'commissions.settlement.%'
                            UNION ALL
                            SELECT p.user_id, -p.amount AS signed_amount
                            FROM commission_settlement_payouts p
                            WHERE p.user_id IS NOT NULL
                              AND p.kind NOT LIKE 'award_%'
                        ) sides
                        GROUP BY user_id
                        HAVING SUM(signed_amount) <> 0
                    ) mismatches
                ) AS agrees
                SQL)->agrees,
            // MA-19: every fund and payout record must have the journal the system wrote for it.
            // A paid company fund spend has a fund.spend journal of the same amount, and a
            // fund.spend journal never exists for a spend that was not paid.
            'fund_spend_journals' => ! DB::table('reserve_disbursements as r')->where('r.program', FundSpending::Program)->where('r.status', 'paid')
                ->whereRaw("r.amount <> COALESCE((SELECT SUM(l.debit) FROM journal_entries e INNER JOIN journal_lines l ON l.entry_id = e.id WHERE e.event_key = 'fund.spend.' || r.id::text),0)")->exists()
                && ! DB::table('journal_entries as e')->whereRaw("e.event_key LIKE 'fund.spend.%'")
                    ->whereRaw("NOT EXISTS (SELECT 1 FROM reserve_disbursements r WHERE 'fund.spend.' || r.id::text = e.event_key AND r.program = ? AND r.status = 'paid')", [FundSpending::Program])->exists(),
            // A paid spend from a fund fed by commission rows drew exactly its amount from those rows,
            // and no such fund is spent below zero.
            'fund_balances' => $this->fundBalancesAgree(),
            // A paid withdrawal debits the partner wallet and credits payout clearing (manual-payout journal).
            'withdrawal_journals' => ! DB::table('withdrawals as w')->where('w.status', 'paid')
                ->whereRaw("(w.amount <> COALESCE((SELECT SUM(l.debit) FROM journal_entries e INNER JOIN journal_lines l ON l.entry_id = e.id WHERE e.event_key = 'manual-payout:' || w.id::text AND l.account = 'wallet_liability' AND l.user_id = w.user_id),0)
                    OR COALESCE(w.net_amount, w.amount) <> COALESCE((SELECT SUM(l.credit) FROM journal_entries e INNER JOIN journal_lines l ON l.entry_id = e.id WHERE e.event_key = 'manual-payout:' || w.id::text AND l.account = 'manual_payout_clearing'),0))")->exists(),
            // Approved payroll books the gross pay as expense; paid payroll clears the net pay (payroll.approved and payroll.paid journals).
            'payroll_journals' => ! DB::table('payroll_entries as p')->whereIn('p.status', ['approved', 'paid'])
                ->whereRaw("p.gross <> COALESCE((SELECT SUM(l.debit) FROM journal_entries e INNER JOIN journal_lines l ON l.entry_id = e.id WHERE e.event_key = 'payroll.approved.' || p.id::text AND l.account = 'payroll_expense'),0)")->exists()
                && ! DB::table('payroll_entries as p')->where('p.status', 'paid')
                    ->whereRaw("p.net <> COALESCE((SELECT SUM(l.debit) FROM journal_entries e INNER JOIN journal_lines l ON l.entry_id = e.id WHERE e.event_key = 'payroll.paid.' || p.id::text AND l.account = 'payroll_payable'),0)")->exists(),
            // The owners' benefits fund rows add up to what each batch left unallocated.
            'benefits_fund_batches' => ! DB::table('commission_batches as b')
                ->whereRaw("GREATEST(b.unallocated,0) <> COALESCE((SELECT SUM(COALESCE(a.original_amount,a.amount)) FROM commission_allocations a WHERE a.order_id=b.order_id AND a.category = ? AND a.metadata->>'source_allocation_id' IS NULL),0)", [RetainedFundLedger::BenefitsCategory])->exists(),
        ];
    }

    private function fundBalancesAgree(): bool
    {
        foreach (app(CompanyFunds::class)->balances() as $key => $fund) {
            if (in_array($key, CompanyFunds::AllocationFunds, true) && $fund['balance'] < 0) {
                return false;
            }
        }

        return ! DB::table('reserve_disbursements as r')->where('r.program', FundSpending::Program)->where('r.status', 'paid')
            ->whereIn('r.fund_key', CompanyFunds::AllocationFunds)
            ->whereRaw('r.amount <> COALESCE((SELECT SUM(s.amount) FROM reserve_expense_sources s WHERE s.disbursement_id = r.id),0)')->exists();
    }

    /** True only when every check above has just passed. */
    public function booksMatch(): bool
    {
        return ! in_array(false, $this->checks(), true);
    }

    /**
     * Names of the checks that did not pass.
     *
     * @param  array<string, bool>  $checks
     * @return list<string>
     */
    public static function failedNames(array $checks): array
    {
        return array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed));
    }

    /**
     * For callers that already hold the money lock and need the books frozen while they act.
     *
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        return DB::transaction(function (): array {
            CommissionWallet::lock();

            return $this->snapshot();
        }, 3);
    }

    /**
     * The daily check: reads one consistent picture of the books without taking the money lock,
     * so orders and payments carry on while the table-wide queries run. Outside an open
     * transaction it uses a read-only REPEATABLE READ snapshot; inside one (a caller's own
     * transaction) it reads within that transaction.
     *
     * @return array<string, mixed>
     */
    public function inspectWithoutLock(): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->snapshot();
        }

        return DB::transaction(function (): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->snapshot();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $checks = $this->checks();
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
        foreach (['orders', 'receipts', 'journal_entries', 'commission_batches', 'commission_refunds', 'withdrawals', 'payroll_entries', 'reserve_disbursements', 'due_adjustments'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $policy = app(CommissionPolicy::class)->snapshot();
        $digest = hash_init('sha256');
        foreach (DB::table('wallets')->orderBy('user_id')->cursor(['user_id', 'available', 'held', 'commission_held']) as $wallet) {
            hash_update($digest, json_encode($wallet, JSON_THROW_ON_ERROR));
        }
        foreach (DB::table('orders')->orderBy('id')->cursor(['id', 'total', 'paid', 'due_credit', 'status']) as $order) {
            hash_update($digest, json_encode($order, JSON_THROW_ON_ERROR));
        }
        // Rule names come back in the reader's language, so they stay out of the fingerprint.
        $policyRules = array_map(fn ($rule) => is_array($rule) ? array_diff_key($rule, ['name' => true]) : $rule, $policy['rules']);
        $snapshot = ['checks' => $checks, 'totals' => $totals, 'counts' => $counts, 'balance_digest' => hash_final($digest), 'policy' => $policyRules];

        return [...$snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'internally_reconciled' => ! in_array(false, $checks, true), 'posting_enabled' => (bool) config('madina.financial_activation'),
            'opening_verified' => DB::table('financial_verifications')->whereNotNull('verified_at')->exists()];
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
                    throw ValidationException::withMessages(['balances' => __('portal.err_ready_reconcile')]);
                }
                $expected = array_map(fn ($value): string => Money::decimal(Money::cents($value)), $totals);
                if ($expected != $snapshot['totals']) {
                    throw ValidationException::withMessages(['balances' => __('portal.err_ready_totals_mismatch')]);
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

    public function verify(User $actor, int $id, string $reason = '', bool $confirmedAlone = false): void
    {
        abort_unless($actor->allows('finance'), 403);
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $row = DB::table('financial_verifications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->verified_at) {
                throw ValidationException::withMessages(['balances' => __('self_approval.err_already_checked')]);
            }
            $selfApproved = $row->created_by === $actor->id;
            if ($selfApproved) {
                app(SelfApproval::class)->finishOwnStep($actor, 'balances', __('portal.err_ready_separate_verify'));
            }
            if ($row->fingerprint !== $this->inspect()['fingerprint']) {
                throw ValidationException::withMessages(['balances' => __('self_approval.err_snapshot_changed')]);
            }
            DB::table('financial_verifications')->where('id', $id)->update(['verified_by' => $actor->id, 'verified_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'opening_balances.verified', (string) $id, $selfApproved ? ['self_approved' => true, 'reason' => $reason] : ($reason === '' ? [] : ['reason' => $reason]));
        }, 3);
    }

    /**
     * Owner only: says there are no old balances to bring in, which opens the money gate. Paper
     * records can still be imported later; see {@see self::startedFresh()}.
     */
    public function freshStart(User $actor, string $reason, bool $confirmed): int
    {
        return DB::transaction(function () use ($actor, $reason, $confirmed): int {
            CommissionWallet::lock();
            $current = $actor->fresh();
            abort_unless($current->role === 'super_admin' && $current->status === 'active', 403);
            if (! $confirmed) {
                throw ValidationException::withMessages(['fresh_start' => __('self_approval.err_confirm')]);
            }
            if (ReasonFields::required('money.opening_fresh_start', $current) && mb_strlen(trim($reason)) < SelfApproval::MinimumReasonLength) {
                throw ValidationException::withMessages(['fresh_start' => __('self_approval.err_reason')]);
            }
            if (DB::table('financial_verifications')->whereNotNull('verified_at')->exists()) {
                throw ValidationException::withMessages(['fresh_start' => __('self_approval.err_fresh_already')]);
            }
            $snapshot = $this->inspect();
            if (! $snapshot['internally_reconciled']) {
                throw ValidationException::withMessages(['fresh_start' => __('portal.err_ready_reconcile')]);
            }
            $id = DB::table('financial_verifications')->insertGetId(['fingerprint' => $snapshot['fingerprint'], 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'expected_totals' => json_encode($snapshot['totals'], JSON_THROW_ON_ERROR), 'source_reference' => 'Fresh start', 'evidence_path' => '', 'evidence_hash' => '',
                'fresh_start' => true, 'created_by' => $actor->id, 'verified_by' => $actor->id, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'opening_balances.fresh_start', (string) $id, ['reason' => $reason, 'totals' => $snapshot['totals'], 'self_approved' => true]);
            // The starting-balance page shows the same choice, so keep it saying the books began from zero.
            app(IntegrationSettings::class)->markFreshStart($actor, $reason);

            return $id;
        }, 3);
    }

    /** True once any opening check, or the owner's fresh start, has opened the money gate. */
    public function booksStarted(): bool
    {
        return DB::table('financial_verifications')->whereNotNull('verified_at')->exists();
    }

    /** True while the only confirmation on record is the owner's fresh start. */
    public function startedFresh(): bool
    {
        $confirmed = DB::table('financial_verifications')->whereNotNull('verified_at');

        return (clone $confirmed)->where('fresh_start', true)->exists() && (clone $confirmed)->where('fresh_start', false)->doesntExist();
    }
}

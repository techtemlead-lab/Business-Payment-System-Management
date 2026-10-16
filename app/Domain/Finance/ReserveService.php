<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionWallet;
use App\Models\CommissionAllocation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReserveService
{
    public const Programs = ['tour' => 'sub_dealer_tour', 'additional_rank' => 'additional_rank_pool',
        'bike' => 'car_bike_incentive_fund', 'car' => 'car_bike_incentive_fund', 'marketing' => 'company_marketing_fund'];

    public function __construct(private CommissionWallet $wallets, private CommissionQualification $qualification, private PaymentAccounts $accounts) {}

    /** @param array<string, mixed> $data */
    public function request(User $actor, array $data): int
    {
        abort_unless($actor->allows('finance'), 403);

        return DB::transaction(function () use ($actor, $data): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $old = DB::table('reserve_disbursements')->where('request_key', $data['request_key'])->first();
            if ($old) {
                abort_if($old->program !== $data['program'] || $old->user_id !== ($data['user_id'] ?? null)
                    || Money::cents($old->amount) !== Money::cents($data['amount']) || $old->reason !== $data['reason']
                    || $old->payee_name !== ($data['payee_name'] ?? null), 409, 'Request key already used with different reward data.');

                return $old->id;
            }
            $awardKey = null;
            if ($data['program'] !== 'marketing') {
                $user = User::whereKey($data['user_id'])->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->firstOrFail();
                if ($data['program'] === 'tour' && $user->role !== 'sub_dealer') {
                    throw ValidationException::withMessages(['user_id' => 'Tour reserve belongs to the purchasing sub-dealer.']);
                }
                if (in_array($data['program'], ['bike', 'car'], true)) {
                    $awardKey = $data['program'].':'.$user->id;
                    if (! $this->qualification->milestone($user->id, $data['program'])['achieved']
                        || DB::table('reserve_disbursements')->where('award_key', $awardKey)->exists()) {
                        throw ValidationException::withMessages(['program' => 'This milestone must be achieved and can be awarded only once.']);
                    }
                }
            }
            $id = DB::table('reserve_disbursements')->insertGetId([...collect($data)->only(['program', 'user_id', 'payee_name', 'amount', 'request_key', 'reason'])->all(),
                'award_key' => $awardKey, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'reserve.requested', (string) $id, ['reason' => $data['reason']]);

            return $id;
        }, 3);
    }

    public function review(User $actor, int $id, string $decision, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $row = DB::table('reserve_disbursements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'requested' || in_array($actor->id, [$row->created_by, $row->user_id], true)) {
                throw ValidationException::withMessages(['reserve' => 'A different Finance reviewer must review a pending request.']);
            }
            if ($decision === 'approved') {
                if ($row->user_id) {
                    $partner = User::findOrFail($row->user_id);
                    abort_unless($partner->status === 'active' && in_array($partner->role, ['dealer', 'sub_dealer'], true), 422);
                }
                if (in_array($row->program, ['bike', 'car'], true) && ! $this->qualification->milestone($row->user_id, $row->program)['achieved']) {
                    throw ValidationException::withMessages(['reserve' => 'The milestone no longer qualifies after adjustments.']);
                }
                $remaining = Money::cents($row->amount);
                foreach ($this->funds($row->program, $row->user_id) as $fund) {
                    $amount = min($remaining, $this->remaining($fund));
                    if ($amount <= 0) {
                        continue;
                    }
                    if ($row->program === 'marketing') {
                        DB::table('reserve_expense_sources')->insert(['disbursement_id' => $id, 'allocation_id' => $fund->id,
                            'amount' => Money::decimal($amount), 'refunded_basis' => DB::table('commission_batches')->where('order_id', $fund->order_id)->value('refunded_amount') ?? '0.00', 'created_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $fund->id, 'user_id' => $row->user_id,
                            'kind' => 'award_'.$id, 'period_month' => $fund->metadata['period_month'], 'amount' => Money::decimal($amount),
                            'refunded_basis' => DB::table('commission_batches')->where('order_id', $fund->order_id)->value('refunded_amount') ?? '0.00',
                            'created_at' => now(), 'updated_at' => now()]);
                    }
                    $remaining -= $amount;
                    if ($remaining === 0) {
                        break;
                    }
                }
                if ($remaining > 0) {
                    throw ValidationException::withMessages(['amount' => 'The selected reserve has insufficient uncommitted funds.']);
                }
                if ($row->program !== 'marketing') {
                    $amount = Money::cents($row->amount);
                    $this->wallets->journal('reserve.award.'.$id, 'Approved '.$row->program.' reward', [
                        ['account' => 'commission_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                        ['account' => 'wallet_liability', 'user_id' => $row->user_id, 'debit' => 0, 'credit' => $amount],
                    ]);
                    $this->wallets->credit($row->user_id, $amount);
                    Audit::notice($row->user_id, 'Reward credited', $row->program.': '.$row->amount.' BDT credited to earnings.');
                    if (in_array($row->program, ['bike', 'car'], true)) {
                        $this->qualification->refreshMilestones($row->user_id);
                        DB::table('reward_milestone_progress')->where('user_id', $row->user_id)->where('milestone_type', $row->program)
                            ->update(['claimed' => true, 'claimed_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            DB::table('reserve_disbursements')->where('id', $id)->update(['status' => $decision === 'approved' && $row->program !== 'marketing' ? 'credited' : $decision,
                'approved_by' => $actor->id, 'award_key' => $decision === 'rejected' ? null : $row->award_key, 'updated_at' => now()]);
            Audit::record($actor->id, 'reserve.'.$decision, (string) $id, ['reason' => $reason]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function pay(User $actor, int $id, array $data, UploadedFile $receipt): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        $path = $receipt->storeAs('reserve-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($actor, $id, $data, $receipt, $path): void {
                CommissionWallet::lock();
                abort_unless($actor->fresh()->allows('finance'), 403);
                $row = DB::table('reserve_disbursements')->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                if ($row->program !== 'marketing' || $row->status !== 'approved' || $row->approved_by === $actor->id) {
                    throw ValidationException::withMessages(['reserve' => 'A separate Finance payer must record an approved company expense.']);
                }
                if (DB::table('reserve_expense_clawbacks')->whereColumn('recovered', '<', 'amount')->exists()) {
                    throw ValidationException::withMessages(['reserve' => 'Recover outstanding company reserve clawbacks before another expense payment.']);
                }
                foreach (DB::table('reserve_expense_sources')->where('disbursement_id', $id)->get() as $source) {
                    if ($this->remaining(CommissionAllocation::findOrFail($source->allocation_id)) < 0) {
                        throw ValidationException::withMessages(['reserve' => 'A refund reduced the reserved funding. Cancel this approval and submit a revised request.']);
                    }
                }
                $this->accounts->recordReference($actor, $data['payment_reference'], 'reserve:'.$id, (int) $data['payment_account_id']);
                $amount = Money::cents($row->amount);
                $this->wallets->journal('reserve.expense.'.$id, 'Company marketing expense paid', [
                    ['account' => 'company_marketing_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                    ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => $amount],
                ]);
                DB::table('reserve_disbursements')->where('id', $id)->update(['status' => 'paid', 'paid_by' => $actor->id,
                    'payment_reference' => Str::upper(trim($data['payment_reference'])), 'payment_account_id' => $data['payment_account_id'],
                    'paid_at' => $data['paid_at'], 'receipt_path' => $path, 'receipt_hash' => hash_file('sha256', $receipt->getRealPath()), 'updated_at' => now()]);
                Audit::record($actor->id, 'reserve.paid', (string) $id, ['reason' => $data['reason']]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function cancel(User $actor, int $id, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $row = DB::table('reserve_disbursements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->program === 'marketing' && in_array($row->status, ['requested', 'approved'], true), 422);
            DB::table('reserve_disbursements')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            Audit::record($actor->id, 'reserve.cancelled', (string) $id, ['reason' => $reason]);
        }, 3);
    }

    public function release(User $actor, int $id, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('finance'), 403);
            $fund = CommissionAllocation::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($fund->status !== 'held') {
                throw ValidationException::withMessages(['hold' => 'This allocation is no longer held.']);
            }
            $partner = User::find($fund->user_id);
            if (! $partner || $partner->status !== 'active' || ! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
                throw ValidationException::withMessages(['hold' => 'The earning recipient must be an active partner.']);
            }
            if (str_starts_with($fund->category, 'area_')) {
                $quota = $fund->metadata['monthly_quota'] ?? null;
                if ($quota === null || $this->qualification->monthlyVolume($partner->id, $fund->metadata['period_month']) < Money::cents($quota)) {
                    throw ValidationException::withMessages(['hold' => 'The original period quota must be captured and achieved before release. Legacy quotas need reconciliation.']);
                }
            }
            $held = ! empty($fund->metadata['maturation_due_at']) && CarbonImmutable::parse($fund->metadata['maturation_due_at'])->isFuture();
            $amount = Money::cents($fund->amount);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['hold' => 'No net commission remains after refunds.']);
            }
            $this->wallets->journal('commission.hold_release.'.$id, 'Reviewed held commission release', [
                ['account' => 'commission_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                ['account' => $held ? 'commission_held_liability' : 'wallet_liability', 'user_id' => $partner->id, 'debit' => 0, 'credit' => $amount],
            ]);
            $this->wallets->credit($partner->id, $amount, $held);
            $fund->update(['status' => $held ? 'maturing' : 'available', 'metadata' => [...$fund->metadata,
                'released_by' => $actor->id, 'release_reason' => $reason, 'released_at' => now()->toIso8601String()]]);
            Audit::record($actor->id, 'commission.hold_released', (string) $id, ['reason' => $reason]);
            Audit::notice($partner->id, 'Commission released', $fund->category.': '.Money::decimal($amount).' BDT.');
        }, 3);
    }

    /** @return Collection<int, CommissionAllocation> */
    private function funds(string $program, ?int $userId): Collection
    {
        return CommissionAllocation::where('category', self::Programs[$program])->when($program === 'tour', fn ($q) => $q->where('user_id', $userId))
            ->where('amount', '>', 0)->orderBy('id')->lockForUpdate()->get();
    }

    public function remaining(CommissionAllocation $fund): int
    {
        $walletPaid = DB::table('commission_settlement_payouts')->where('allocation_id', $fund->id)->selectRaw('COALESCE(SUM(amount - reversed_amount),0) AS total')->value('total');
        $expenses = DB::table('reserve_expense_sources')->join('reserve_disbursements', 'disbursement_id', '=', 'reserve_disbursements.id')
            ->where('allocation_id', $fund->id)->whereIn('reserve_disbursements.status', ['approved', 'paid'])
            ->selectRaw('COALESCE(SUM(reserve_expense_sources.amount - reserve_expense_sources.reversed_amount),0) AS total')->value('total');

        return Money::cents($fund->amount) - Money::cents((string) $walletPaid) - Money::cents((string) $expenses);
    }
}

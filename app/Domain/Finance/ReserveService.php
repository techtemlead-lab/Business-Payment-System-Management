<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Payouts\PayoutReceipts;
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
                    || $old->payee_name !== ($data['payee_name'] ?? null), 409, __('portal.err_idem_reward_key'));

                return $old->id;
            }
            $awardKey = null;
            if ($data['program'] !== 'marketing') {
                $user = User::whereKey($data['user_id'])->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->firstOrFail();
                if ($data['program'] === 'tour' && $user->role !== 'sub_dealer') {
                    throw ValidationException::withMessages(['user_id' => __('portal.err_reserve_tour_subdealer')]);
                }
                if (in_array($data['program'], ['bike', 'car'], true)) {
                    $awardKey = $data['program'].':'.$user->id;
                    if (! $this->qualification->milestone($user->id, $data['program'])['achieved']
                        || DB::table('reserve_disbursements')->where('award_key', $awardKey)->exists()) {
                        throw ValidationException::withMessages(['program' => __('portal.err_reserve_milestone_once')]);
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
                throw ValidationException::withMessages(['reserve' => __('portal.err_reserve_reviewer')]);
            }
            if ($decision === 'approved') {
                if ($row->user_id) {
                    $partner = User::findOrFail($row->user_id);
                    abort_unless($partner->status === 'active' && in_array($partner->role, ['dealer', 'sub_dealer'], true), 422);
                }
                if (in_array($row->program, ['bike', 'car'], true) && ! $this->qualification->milestone($row->user_id, $row->program)['achieved']) {
                    throw ValidationException::withMessages(['reserve' => __('portal.err_reserve_milestone_lost')]);
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
                    throw ValidationException::withMessages(['amount' => __('portal.err_reserve_insufficient')]);
                }
                if ($row->program !== 'marketing') {
                    $amount = Money::cents($row->amount);
                    $locale = User::find($row->user_id)?->preferredLocale() ?? app()->getLocale();
                    $programLabel = __('portal.reward_program_'.$row->program, [], $locale);
                    if ($programLabel === 'portal.reward_program_'.$row->program) {
                        $programLabel = (string) $row->program;
                    }
                    $this->wallets->journal('reserve.award.'.$id, __('portal.jr_reserve_award', [
                        'program' => $programLabel,
                    ], $locale), [
                        ['account' => 'commission_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                        ['account' => 'wallet_liability', 'user_id' => $row->user_id, 'debit' => 0, 'credit' => $amount],
                    ]);
                    $this->wallets->credit($row->user_id, $amount);
                    Audit::notice(
                        $row->user_id,
                        __('portal.notice_reward_credited_title', [], $locale),
                        __('portal.notice_reward_credited_body', [
                            'program' => $programLabel,
                            'amount' => $row->amount,
                        ], $locale),
                    );
                    $userId = (int) $row->user_id;
                    $amountText = (string) $row->amount;
                    DB::afterCommit(function () use ($userId, $programLabel, $amountText, $locale): void {
                        $partner = User::find($userId);
                        if (! $partner) {
                            return;
                        }
                        app(PartnerEventSms::class)->send(
                            $partner,
                            'reserve.credited',
                            __('sms.reward_credited', [
                                'program' => $programLabel,
                                'amount' => $amountText,
                            ], $partner->preferredLocale() ?: $locale),
                        );
                    });
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

    /**
     * Marks an approved reserve payout as paid by hand and issues its numbered payout receipt.
     * Cash needs the name of the person who took the money; every other method needs the payout
     * account, the transaction reference and the transfer proof.
     *
     * @param  array<string, mixed>  $data
     * @return string The new receipt number.
     */
    public function pay(User $actor, int $id, array $data, ?UploadedFile $receipt): string
    {
        abort_unless($actor->allows('finance'), 403);
        CommissionWallet::requireActive();
        $method = (string) ($data['method'] ?? '');
        $isCash = $method === 'cash';
        $reference = trim((string) ($data['payment_reference'] ?? ''));
        $reference = $reference === '' ? null : Str::upper($reference);
        if (! $isCash && ($reference === null || $receipt === null || empty($data['payment_account_id']))) {
            throw ValidationException::withMessages(['payment_reference' => __('portal.err_payout_details_needed')]);
        }
        $path = $receipt?->storeAs('reserve-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        $issued = null;
        try {
            DB::transaction(function () use ($actor, $id, $data, $receipt, $path, $isCash, $reference, &$issued): void {
                CommissionWallet::lock();
                abort_unless($actor->fresh()->allows('finance'), 403);
                $row = DB::table('reserve_disbursements')->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                if ($row->program !== 'marketing' || $row->status !== 'approved' || $row->approved_by === $actor->id) {
                    throw ValidationException::withMessages(['reserve' => __('portal.err_reserve_payer')]);
                }
                if (DB::table('reserve_expense_clawbacks')->whereColumn('recovered', '<', 'amount')->exists()) {
                    throw ValidationException::withMessages(['reserve' => __('portal.err_reserve_clawback')]);
                }
                foreach (DB::table('reserve_expense_sources')->where('disbursement_id', $id)->get() as $source) {
                    if ($this->remaining(CommissionAllocation::findOrFail($source->allocation_id)) < 0) {
                        throw ValidationException::withMessages(['reserve' => __('portal.err_reserve_funding_reduced')]);
                    }
                }
                // Cash is handed over at the office, not sent from a company payout account.
                if (! $isCash) {
                    $this->accounts->recordReference($actor, $reference, 'reserve:'.$id, (int) $data['payment_account_id']);
                } elseif ($reference !== null && DB::table('reserve_disbursements')->where('payment_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['payment_reference' => __('portal.err_pay_ref_duplicate')]);
                }
                $amount = Money::cents($row->amount);
                $this->wallets->journal('reserve.expense.'.$id, __('portal.jr_reserve_marketing_paid'), [
                    ['account' => 'company_marketing_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                    ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => $amount],
                ]);
                $issued = app(PayoutReceipts::class)->issueForReserve($actor, $row, $data, $reference, (string) $data['paid_at']);
                DB::table('reserve_disbursements')->where('id', $id)->update(['status' => 'paid', 'paid_by' => $actor->id,
                    'payment_reference' => $reference ?? $issued['number'], 'payment_account_id' => $isCash ? null : $data['payment_account_id'],
                    'paid_at' => $data['paid_at'], 'receipt_path' => $path, 'receipt_hash' => $receipt ? hash_file('sha256', $receipt->getRealPath()) : null, 'updated_at' => now()]);
                Audit::record($actor->id, 'reserve.paid', (string) $id, ['reason' => $data['reason'], 'method' => $data['method'], 'payout_receipt' => $issued['number']]);
                if ($row->user_id) {
                    $locale = User::find($row->user_id)?->preferredLocale() ?? app()->getLocale();
                    Audit::notice(
                        (int) $row->user_id,
                        __('portal.notice_reserve_paid_title', [], $locale),
                        __('portal.notice_reserve_paid_body', ['number' => $issued['number'], 'amount' => $row->amount], $locale),
                    );
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return $issued['number'];
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
            $this->creditHeldAllocation($fund, $actor->id, $reason);
        }, 3);
    }

    /**
     * Release area_* holds whose original period quota is now met.
     * Opt-in only via MADINA_AUTO_RELEASE_QUOTA_HOLDS after financial activation.
     *
     * @return array{status: string, released_count: int, total_amount: string}
     */
    /**
     * Month close for area commission (owner rule): a share still held at the end of its month
     * because the area dealer missed that month's quota goes to the company profit fund.
     * Shares whose quota was met are released first; holds for other reasons (a suspended
     * dealer, cooling-off) are left alone. Running it again moves nothing twice.
     *
     * @return array{status: string, period: string, forfeited_count: int, total_amount: string}
     */
    public function forfeitUnmetQuotaHolds(string $month, ?int $actorId = null): array
    {
        CommissionWallet::requireActive();
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            return ['status' => 'month_open', 'period' => $month, 'forfeited_count' => 0, 'total_amount' => '0.00'];
        }
        if (config('madina.auto_release_quota_holds')) {
            $this->releaseQualifiedQuotaHolds($actorId);
        }

        return DB::transaction(function () use ($month, $actorId): array {
            CommissionWallet::lock();
            $funds = CommissionAllocation::query()
                ->where('status', 'held')
                ->where('category', 'like', 'area_%')
                ->whereNotNull('user_id')
                ->where('metadata->period_month', $month)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $partners = User::query()->whereIn('id', $funds->pluck('user_id')->unique()->values())->get()->keyBy('id');
            $volumes = $this->qualification->monthlyVolumesFor($funds->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(), $month);
            $settlement = app(CommissionSettlement::class);
            $count = 0;
            $total = 0;
            foreach ($funds as $fund) {
                $partner = $partners->get($fund->user_id);
                $quota = $fund->metadata['monthly_quota'] ?? null;
                $quotaHold = ($fund->metadata['hold_reason'] ?? 'quota') === 'quota';
                if (! $partner || $partner->status !== 'active' || $quota === null || ! $quotaHold
                    || ($volumes[$partner->id] ?? 0) >= Money::cents((string) $quota)) {
                    continue;
                }
                $amount = Money::cents($fund->amount);
                $profitId = $settlement->toCompanyProfit($fund, $amount, $month, 'area_quota', null, 'retained_reason_profit_quota_missed');
                $fund->update(['status' => 'forfeited', 'metadata' => [...$fund->metadata, 'forfeited_at' => now()->toIso8601String(),
                    'forfeited_to' => CommissionSettlement::CompanyProfitCategory, 'company_profit_allocation_id' => $profitId]]);
                $count++;
                $total += $amount;
            }
            if ($count > 0) {
                Audit::record($actorId, 'commission.quota_holds_forfeited', $month, ['count' => $count, 'amount' => Money::decimal($total)]);
            }

            return ['status' => $count > 0 ? 'forfeited' : 'nothing_to_forfeit', 'period' => $month,
                'forfeited_count' => $count, 'total_amount' => Money::decimal($total)];
        }, 3);
    }

    /**
     * Month close for the 4% sub-dealer additional-rank fund (owner rule): money nobody
     * qualified for in the month goes to the company profit fund with its source. No
     * qualification rule is set yet, so the whole closed month goes. Re-running moves nothing.
     *
     * @return array{status: string, period: string, closed_count: int, total_amount: string}
     */
    public function closeAdditionalRankFund(string $month, ?int $actorId = null): array
    {
        CommissionWallet::requireActive();
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            return ['status' => 'month_open', 'period' => $month, 'closed_count' => 0, 'total_amount' => '0.00'];
        }

        return DB::transaction(function () use ($month, $actorId): array {
            CommissionWallet::lock();
            $funds = CommissionAllocation::query()->where('category', 'additional_rank_pool')->where('status', 'pending')
                ->where('metadata->period_month', $month)->orderBy('id')->lockForUpdate()->get();
            $settlement = app(CommissionSettlement::class);
            $total = 0;
            foreach ($funds as $fund) {
                $amount = Money::cents($fund->amount);
                $profitId = $settlement->toCompanyProfit($fund, $amount, $month, 'additional_rank', null, 'retained_reason_unclaimed_additional_rank');
                $fund->update(['status' => 'settled', 'metadata' => [...$fund->metadata, 'settled_at' => now()->toIso8601String(),
                    'settled_period' => $month, 'distributed' => '0.00', 'reserved' => Money::decimal($amount), 'settlement_reserve_allocation_id' => $profitId]]);
                $total += $amount;
            }
            if ($funds->isNotEmpty()) {
                Audit::record($actorId, 'commission.additional_rank_closed', $month, ['count' => $funds->count(), 'amount' => Money::decimal($total)]);
            }

            return ['status' => $funds->isNotEmpty() ? 'closed' : 'nothing_to_close', 'period' => $month,
                'closed_count' => $funds->count(), 'total_amount' => Money::decimal($total)];
        }, 3);
    }

    public function releaseQualifiedQuotaHolds(?int $actorId = null): array
    {
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actorId): array {
            CommissionWallet::lock();
            $funds = CommissionAllocation::query()
                ->where('status', 'held')
                ->where('category', 'like', 'area_%')
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $count = 0;
            $total = 0;
            // Each partner is read once and each partner's month volume once, not once per held share:
            // this runs every 15 minutes under the money lock, and unmet holds pile up month after month.
            $partners = User::query()->whereIn('id', $funds->pluck('user_id')->unique()->values())->get()->keyBy('id');
            $volumes = [];
            foreach ($funds->groupBy(fn (CommissionAllocation $fund): string => (string) ($fund->metadata['period_month'] ?? '')) as $period => $periodFunds) {
                if ($period !== '') {
                    $volumes[$period] = $this->qualification->monthlyVolumesFor($periodFunds->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(), $period);
                }
            }
            foreach ($funds as $fund) {
                $partner = $partners->get($fund->user_id);
                if (! $partner || $partner->status !== 'active' || ! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
                    continue;
                }
                $quota = $fund->metadata['monthly_quota'] ?? null;
                $period = $fund->metadata['period_month'] ?? null;
                if ($quota === null || $period === null || ($volumes[$period][$partner->id] ?? 0) < Money::cents($quota)) {
                    continue;
                }
                $amount = $this->creditHeldAllocation($fund, $actorId, 'release_reason_quota_auto');
                $count++;
                $total += $amount;
            }
            if ($count > 0) {
                Audit::record($actorId, 'commission.quota_holds_released', 'batch', [
                    'count' => $count,
                    'amount' => Money::decimal($total),
                ]);
            }

            return [
                'status' => $count > 0 ? 'success' : 'no_qualified_quota_holds',
                'released_count' => $count,
                'total_amount' => Money::decimal($total),
            ];
        }, 3);
    }

    /**
     * Credit a held allocation after eligibility checks. Preserves cooling-off as maturing.
     *
     * @return int Credited amount in cents
     */
    private function creditHeldAllocation(CommissionAllocation $fund, ?int $actorId, string $reason): int
    {
        if ($fund->status !== 'held') {
            throw ValidationException::withMessages(['hold' => __('portal.err_hold_not_held')]);
        }
        $partner = User::find($fund->user_id);
        if (! $partner || $partner->status !== 'active' || ! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['hold' => __('portal.err_hold_active_partner')]);
        }
        if (str_starts_with($fund->category, 'area_')) {
            $quota = $fund->metadata['monthly_quota'] ?? null;
            $period = $fund->metadata['period_month'] ?? null;
            if ($quota === null || $period === null || $this->qualification->monthlyVolume($partner->id, $period) < Money::cents($quota)) {
                throw ValidationException::withMessages(['hold' => __('portal.err_hold_quota')]);
            }
        }
        $held = ! empty($fund->metadata['maturation_due_at']) && CarbonImmutable::parse($fund->metadata['maturation_due_at'])->isFuture();
        $amount = Money::cents($fund->amount);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['hold' => __('portal.err_hold_zero')]);
        }
        $locale = $partner->preferredLocale();
        $this->wallets->journal('commission.hold_release.'.$fund->id, __('portal.jr_hold_release', [], $locale), [
            ['account' => 'commission_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
            ['account' => $held ? 'commission_held_liability' : 'wallet_liability', 'user_id' => $partner->id, 'debit' => 0, 'credit' => $amount],
        ]);
        $this->wallets->credit($partner->id, $amount, $held);
        $fund->update(['status' => $held ? 'maturing' : 'available', 'metadata' => [...$fund->metadata,
            'released_by' => $actorId, 'release_reason' => $reason, 'released_at' => now()->toIso8601String()]]);
        Audit::record($actorId, 'commission.hold_released', (string) $fund->id, ['reason' => $reason]);
        $categoryLabel = __('portal.report_cat_'.$fund->category, [], $locale);
        if ($categoryLabel === 'portal.report_cat_'.$fund->category) {
            $categoryLabel = (string) $fund->category;
        }
        $amountDecimal = Money::decimal($amount);
        Audit::notice(
            $partner->id,
            __('portal.notice_commission_released_title', [], $locale),
            __('portal.notice_commission_released_body', [
                'category' => $categoryLabel,
                'amount' => $amountDecimal,
            ], $locale),
        );
        $userId = (int) $partner->id;
        DB::afterCommit(function () use ($userId, $categoryLabel, $amountDecimal, $locale): void {
            $notified = User::find($userId);
            if (! $notified) {
                return;
            }
            app(PartnerEventSms::class)->send(
                $notified,
                'commission.hold_released',
                __('sms.commission_released', [
                    'category' => $categoryLabel,
                    'amount' => $amountDecimal,
                ], $notified->preferredLocale() ?: $locale),
            );
        });

        return $amount;
    }

    public function reassignHeld(User $actor, int $id, int $recipientId, string $reason, string $requestKey): void
    {
        abort_unless($actor->canAct('finance', 'edit') && $actor->canAct('finance', 'approve'), 403);
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $recipientId, $reason, $requestKey): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'edit') && $actor->fresh()->canAct('finance', 'approve'), 403);
            $existing = DB::table('audit_events')->where('action', 'commission.hold_reassigned')->where('metadata->request_key', $requestKey)->first();
            if ($existing) {
                $metadata = json_decode($existing->metadata, true, flags: JSON_THROW_ON_ERROR);
                abort_unless($existing->subject === (string) $id && $existing->actor_id === $actor->id && $metadata['recipient_id'] === $recipientId && $metadata['reason'] === $reason, 409, __('portal.err_idem_reassign_key'));

                return;
            }
            $fund = CommissionAllocation::whereKey($id)->lockForUpdate()->firstOrFail();
            $original = User::find($fund->user_id);
            if ($fund->status !== 'held' || ! in_array($fund->category, ['direct_referral', 'd2d_gen2', 'd2d_gen3'], true) || ! $original || $original->status === 'active'
                || ! DB::table('commission_batches')->where('order_id', $fund->order_id)->where('cash_basis', '>', 0)->exists()) {
                throw ValidationException::withMessages(['hold' => __('portal.err_hold_reassign_inactive')]);
            }
            $recipient = User::whereKey($recipientId)->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->first();
            $buyerId = DB::table('orders')->where('id', $fund->order_id)->value('user_id');
            $alreadyReceives = CommissionAllocation::where('order_id', $fund->order_id)->where('user_id', $recipientId)
                ->whereIn('category', ['direct_referral', 'd2d_gen2', 'd2d_gen3'])->where('amount', '>', 0)->exists();
            if (! $recipient || $recipientId === $buyerId || $alreadyReceives) {
                throw ValidationException::withMessages(['user_id' => __('portal.err_hold_reassign_recipient')]);
            }
            $metadata = ['original_user_id' => $original->id, 'recipient_id' => $recipientId, 'reason' => $reason, 'request_key' => $requestKey,
                'authorized_by' => $actor->id, 'authorized_at' => now()->toIso8601String(), 'amount' => $fund->amount];
            $fund->update(['user_id' => $recipientId, 'metadata' => [...$fund->metadata, 'recipient_reassignment' => $metadata]]);
            $this->release($actor, $id, $reason);
            Audit::record($actor->id, 'commission.hold_reassigned', (string) $id, $metadata);
        }, 3);
    }

    /** @return Collection<int, CommissionAllocation> */
    private function funds(string $program, ?int $userId): Collection
    {
        return CommissionAllocation::where('category', self::Programs[$program])->when($program === 'tour', fn ($q) => $q->where('user_id', $userId))
            ->where('amount', '>', 0)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * What is left in every reserve fund, per category, in cents: the same sum as remaining()
     * over each fund row, worked out by the database in one query. Pass a partner to count only
     * the funds kept in that partner's name (such as a sub-dealer's own tour fund).
     *
     * @return array<string, int>
     */
    public function remainingByCategory(?int $userId = null): array
    {
        $paid = DB::table('commission_settlement_payouts')->groupBy('allocation_id')
            ->selectRaw('allocation_id, SUM(amount - reversed_amount) AS total');
        $spent = DB::table('reserve_expense_sources')->join('reserve_disbursements', 'disbursement_id', '=', 'reserve_disbursements.id')
            ->whereIn('reserve_disbursements.status', ['approved', 'paid'])->groupBy('reserve_expense_sources.allocation_id')
            ->selectRaw('reserve_expense_sources.allocation_id, SUM(reserve_expense_sources.amount - reserve_expense_sources.reversed_amount) AS total');

        return DB::table('commission_allocations as funds')
            ->leftJoinSub($paid, 'paid', 'paid.allocation_id', '=', 'funds.id')
            ->leftJoinSub($spent, 'spent', 'spent.allocation_id', '=', 'funds.id')
            ->whereIn('funds.category', array_values(array_unique(self::Programs)))
            ->when($userId !== null, fn ($query) => $query->where('funds.user_id', $userId))
            ->groupBy('funds.category')
            ->selectRaw('funds.category, SUM(funds.amount - COALESCE(paid.total, 0) - COALESCE(spent.total, 0)) AS remaining')
            ->pluck('remaining', 'category')
            ->map(function ($remaining): int {
                $value = (string) $remaining;

                return str_starts_with($value, '-') ? -Money::cents(substr($value, 1)) : Money::cents($value);
            })
            ->all();
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

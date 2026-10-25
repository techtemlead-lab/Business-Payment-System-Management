<?php

namespace App\Domain\Commissions;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Finance\Percentage;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionSettlement
{
    public const DealerSalaryReserve = 'dealer_salary_settlement_reserve';

    public const SubDealerSalaryReserve = 'sub_dealer_salary_settlement_reserve';

    public const RankRoyaltyReserve = 'rank_royalty_settlement_reserve';

    /** @return list<string> */
    public static function settlementReserveCategories(): array
    {
        return [self::DealerSalaryReserve, self::SubDealerSalaryReserve, self::RankRoyaltyReserve];
    }

    public function __construct(private CommissionWallet $wallets, private CommissionQualification $qualification, private CommissionPolicy $policies) {}

    /** @return array<string, mixed> */
    public function settle(string $month, string $kind, ?int $actorId = null, ?string $requestKey = null): array
    {
        CommissionWallet::requireActive();
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            throw ValidationException::withMessages(['period_month' => __('portal.err_settle_month_open')]);
        }

        return DB::transaction(function () use ($month, $kind, $actorId, $requestKey): array {
            CommissionWallet::lock();
            if ($requestKey !== null) {
                $existing = DB::table('commission_settlement_runs')
                    ->where('period_month', $month)
                    ->where('kind', $kind)
                    ->where('request_key', $requestKey)
                    ->first();
                if ($existing) {
                    return json_decode((string) $existing->result, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $categories = $kind === 'salary' ? ['dealer_monthly_salary_pool', 'sub_dealer_salary'] : ['rank_royalty_pool'];
            $funds = CommissionAllocation::whereIn('category', $categories)->where('status', 'pending')
                ->where('metadata->period_month', $month)->orderBy('id')->lockForUpdate()->get();
            $total = $funds->sum(fn (CommissionAllocation $a): int => Money::cents($a->amount));
            if ($total === 0) {
                return $this->rememberSettlement($month, $kind, $requestKey, $actorId, [
                    'status' => 'empty_pool', 'total_pool' => '0.00', 'qualifiers_count' => 0,
                    'total_distributed' => '0.00', 'total_rolled_to_reserve' => '0.00',
                ]);
            }
            $distributed = [];
            $tiers = [];
            $rolled = 0;
            $dealers = User::query()->where('role', 'dealer')->where('status', 'active')->orderBy('id')->get();
            $dealerIds = $dealers->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $volumes = $this->qualification->monthlyVolumesFor($dealerIds, $month);
            $dealerNames = $dealers->pluck('name', 'id')->all();
            $subDealerNames = [];
            /** @var array<string, array<int, array<string, mixed>>> */
            $paidAsByRules = [];
            foreach ($funds as $fund) {
                $source = DB::table('commission_batches')->where('order_id', $fund->order_id)->value('policy');
                $rules = $source ? json_decode($source, true, 512, JSON_THROW_ON_ERROR)['rules'] : $this->policies->snapshot()['rules'];
                $parameters = $rules['dealer_monthly_salary']['parameters'];
                $minVolume = Money::cents((string) $parameters['min_monthly_volume']);
                $qualifiedIds = $dealers
                    ->filter(fn (User $user): bool => PartnerBenefitControls::waivesSalaryVolume($user)
                        || ($volumes[$user->id] ?? 0) >= $minVolume)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $cents = Money::cents($fund->amount);
                $shares = [];
                if ($fund->category === 'sub_dealer_salary') {
                    $partner = User::query()->find($fund->user_id);
                    if ($partner?->role === 'sub_dealer' && $partner->status === 'active') {
                        $shares[$partner->id] = $cents;
                        $subDealerNames[$partner->id] = $partner->name;
                        if (! array_key_exists($partner->id, $volumes)) {
                            $volumes[$partner->id] = $this->qualification->monthlyVolume($partner->id, $month);
                        }
                    }
                } elseif ($kind === 'salary') {
                    $shares = $this->divide($cents, $qualifiedIds);
                } else {
                    $buckets = $this->rankBuckets($cents, $rules['rank_royalty']['parameters']);
                    $rulesKey = hash('xxh3', json_encode($rules['rank_maintenance']['parameters'] ?? [], JSON_THROW_ON_ERROR));
                    if (! isset($paidAsByRules[$rulesKey])) {
                        $paidAsByRules[$rulesKey] = $this->qualification->paidAsFor($dealerIds, $month, $rules);
                    }
                    foreach (['mo', 'am', 'sm', 'me'] as $index => $code) {
                        $ids = array_values(array_filter(
                            $dealerIds,
                            fn (int $id): bool => ($paidAsByRules[$rulesKey][$id]['paid_as_rank'] ?? 'dealer') === $code,
                        ));
                        $tierShares = $this->divide($buckets[$index], $ids);
                        foreach ($tierShares as $id => $amount) {
                            $shares[$id] = ($shares[$id] ?? 0) + $amount;
                        }
                        $tiers[$code]['qualifiers'] = count($ids);
                        $tiers[$code]['allocated_cents'] = ($tiers[$code]['allocated_cents'] ?? 0) + array_sum($tierShares);
                        $tiers[$code]['reserve_cents'] = ($tiers[$code]['reserve_cents'] ?? 0) + $buckets[$index] - array_sum($tierShares);
                    }
                }
                foreach ($shares as $id => $amount) {
                    if ($amount > 0) {
                        $this->pay($fund, $id, $amount, $month, $kind);
                        $distributed[$id] = ($distributed[$id] ?? 0) + $amount;
                    }
                }
                $paidCents = array_sum($shares);
                $remainder = $cents - $paidCents;
                $reserveId = null;
                if ($remainder > 0) {
                    $reserveId = $this->rollUndistributed($fund, $remainder, $month, $kind);
                    $rolled += $remainder;
                }
                $fund->update(['status' => 'settled', 'metadata' => [...$fund->metadata, 'settled_at' => now()->toIso8601String(),
                    'settled_period' => $month, 'distributed' => Money::decimal($paidCents), 'reserved' => Money::decimal($remainder),
                    'settlement_reserve_allocation_id' => $reserveId]]);
            }
            $totalPaid = array_sum($distributed);
            $result = [];
            $names = $dealerNames + $subDealerNames;
            $missingVolumeIds = array_values(array_diff(array_keys($distributed), array_keys($volumes)));
            if ($missingVolumeIds !== []) {
                $volumes += $this->qualification->monthlyVolumesFor($missingVolumeIds, $month);
            }
            foreach ($distributed as $id => $amount) {
                $result[] = [
                    'user_id' => $id,
                    'user_name' => $names[$id] ?? User::query()->find($id)?->name,
                    'amount' => Money::decimal($amount),
                    'monthly_volume' => Money::decimal($volumes[$id] ?? 0),
                ];
            }
            foreach ($tiers as &$tier) {
                $tier['allocated'] = Money::decimal($tier['allocated_cents']);
                $tier['rolled_to_reserve'] = Money::decimal($tier['reserve_cents']);
                unset($tier['allocated_cents'], $tier['reserve_cents']);
            }
            Audit::record($actorId, 'commissions.'.$kind.'_pool_settled', $month, [
                'distributed' => Money::decimal($totalPaid),
                'rolled_to_reserve' => Money::decimal($rolled),
                'request_key' => $requestKey,
            ]);

            return $this->rememberSettlement($month, $kind, $requestKey, $actorId, [
                'status' => $totalPaid > 0 ? 'settled' : 'rolled_over', 'period' => $month, 'total_pool' => Money::decimal($total),
                'total_distributed' => Money::decimal($totalPaid), 'total_rolled_to_reserve' => Money::decimal($rolled),
                'qualifiers_count' => count($distributed), 'distributed' => $result, 'distributions' => $tiers,
            ]);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function rememberSettlement(string $month, string $kind, ?string $requestKey, ?int $actorId, array $result): array
    {
        if ($requestKey === null) {
            return $result;
        }
        DB::table('commission_settlement_runs')->insert([
            'period_month' => $month,
            'kind' => $kind,
            'request_key' => $requestKey,
            'actor_id' => $actorId,
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $result;
    }

    private function rollUndistributed(CommissionAllocation $fund, int $remainder, string $month, string $kind): int
    {
        $category = match ($fund->category) {
            'sub_dealer_salary' => self::SubDealerSalaryReserve,
            'dealer_monthly_salary_pool' => self::DealerSalaryReserve,
            default => self::RankRoyaltyReserve,
        };

        return CommissionAllocation::query()->create([
            'order_id' => $fund->order_id,
            'user_id' => null,
            'category' => $category,
            'rate_percentage' => $fund->rate_percentage,
            'cash_basis' => $fund->cash_basis,
            'amount' => Money::decimal($remainder),
            'original_amount' => Money::decimal($remainder),
            'status' => 'reserved',
            'metadata' => [
                'basis' => 'purchase_value',
                'period_month' => $month,
                'source_allocation_id' => $fund->id,
                'source_category' => $fund->category,
                'settlement_kind' => $kind,
                'retained_by' => 'settlement_rollover',
                'reason' => $kind === 'salary'
                    ? 'Undistributed salary share rolled to company settlement reserve.'
                    : 'Unfilled royalty tier share rolled to company settlement reserve.',
            ],
        ])->id;
    }

    /** @param list<int> $ids @return array<int, int> */
    private function divide(int $total, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $base = intdiv($total, count($ids));
        $remainder = $total % count($ids);
        $result = [];
        foreach ($ids as $index => $id) {
            $result[$id] = $base + ($index < $remainder ? 1 : 0);
        }

        return $result;
    }

    /** @param array<string,mixed> $parameters @return array<int,int> */
    private function rankBuckets(int $amount, array $parameters): array
    {
        $weights = [];
        foreach (['mo', 'am', 'sm', 'me'] as $key) {
            $weights[] = Percentage::from((string) $parameters[$key.'_share_pct'])->basisPoints;
        }
        $total = array_sum($weights);
        if ($total === 0) {
            throw ValidationException::withMessages(['rules' => __('portal.err_settle_royalty_shares')]);
        }
        $result = [];
        $fractions = [];
        foreach ($weights as $index => $weight) {
            $result[$index] = intdiv($amount * $weight, $total);
            $fractions[$index] = ($amount * $weight) % $total;
        }
        arsort($fractions, SORT_NUMERIC);
        $remainder = $amount - array_sum($result);
        foreach (array_keys($fractions) as $index) {
            if ($remainder-- <= 0) {
                break;
            } $result[$index]++;
        }

        return $result;
    }

    private function pay(CommissionAllocation $fund, int $userId, int $amount, string $month, string $kind): void
    {
        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $fund->id, 'user_id' => $userId, 'kind' => $kind,
            'refunded_basis' => DB::table('commission_batches')->where('order_id', $fund->order_id)->value('refunded_amount') ?? '0.00',
            'period_month' => $month, 'amount' => Money::decimal($amount), 'created_at' => now(), 'updated_at' => now()]);
        $this->wallets->journal('commissions.settlement.'.$fund->id.'.'.$userId.'.'.$kind, 'Commission '.$kind.' settlement '.$month, [
            ['user_id' => null, 'account' => 'commission_expense', 'debit' => $amount, 'credit' => 0],
            ['user_id' => $userId, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount],
        ]);
        $this->wallets->credit($userId, $amount);
        $partner = User::find($userId);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        Audit::notice(
            $userId,
            __('portal.notice_settle_title', [], $locale),
            __('portal.notice_settle_body', [
                'amount' => Money::decimal($amount),
                'kind' => $kind,
                'month' => $month,
            ], $locale),
        );
    }

    /** @return array<string, mixed> */
    public function incentive(int $userId, ?int $actorId = null, ?string $requestKey = null): array
    {
        CommissionWallet::requireActive();
        $period = CommissionQualification::currentMonth();

        return DB::transaction(function () use ($userId, $actorId, $requestKey, $period): array {
            CommissionWallet::lock();
            if ($requestKey !== null) {
                $existing = DB::table('commission_settlement_runs')
                    ->where('period_month', $period)
                    ->where('kind', 'rank_incentive')
                    ->where('request_key', $requestKey)
                    ->first();
                if ($existing) {
                    $result = json_decode((string) $existing->result, true, 512, JSON_THROW_ON_ERROR);
                    if ((int) ($result['user_id'] ?? 0) !== $userId) {
                        abort(409, 'Request key already used with different incentive data.');
                    }

                    return $result;
                }
                $award = DB::table('rank_incentive_awards')->where('request_key', $requestKey)->first();
                if ($award) {
                    if ((int) $award->user_id !== $userId) {
                        abort(409, 'Request key already used with different incentive data.');
                    }

                    return ['success' => true, 'user_id' => $userId, 'rank' => $award->rank, 'amount' => (string) $award->amount, 'paid_at' => $award->created_at];
                }
            }
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $rank = DealerRank::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $unconditionalIncentive = PartnerBenefitControls::waivesRankIncentive($user);
            $awardRank = $rank->current_rank;
            if ($unconditionalIncentive && ($awardRank === 'dealer' || $awardRank === null)) {
                $awardRank = PartnerBenefitControls::forcedRoyaltyRank($user) ?? 'mo';
            }
            if ($user->role !== 'dealer' || $user->status !== 'active' || ($awardRank === 'dealer' && ! $unconditionalIncentive)) {
                return ['success' => false, 'message' => __('portal.cmd_val_incentive_rank_required')];
            }
            if (DB::table('rank_incentive_awards')->where('user_id', $userId)->where('rank', $awardRank)->exists()) {
                return ['success' => false, 'message' => __('portal.cmd_val_incentive_already_paid')];
            }
            if ($rank->one_time_incentive_paid && ! DB::table('rank_incentive_awards')->where('user_id', $userId)->exists()) {
                return ['success' => false, 'message' => __('portal.cmd_val_incentive_legacy')];
            }
            $rules = $this->policies->snapshot()['rules'];
            if (! $unconditionalIncentive) {
                $paidAs = $this->qualification->paidAs($userId, $period, $rules);
                if (($paidAs['paid_as_rank'] ?? 'dealer') !== $rank->current_rank) {
                    return ['success' => false, 'message' => __('portal.cmd_val_incentive_qualification', [
                        'rank' => $rank->current_rank,
                    ])];
                }
            }
            $amount = Money::cents((string) $rules['rank_incentive']['parameters'][$awardRank.'_bonus']);
            $funds = CommissionAllocation::where('category', 'rank_incentive_pool')->whereIn('status', ['pending', 'partially_settled'])->orderBy('id')->lockForUpdate()->get();
            $available = $funds->sum(fn ($fund): int => Money::cents($fund->amount) - Money::cents((string) ($fund->metadata['incentive_consumed'] ?? '0.00')));
            if ($amount <= 0 || $available < $amount) {
                return ['success' => false, 'message' => __('portal.cmd_val_incentive_unfunded')];
            }
            $remaining = $amount;
            $sources = [];
            foreach ($funds as $fund) {
                $used = Money::cents((string) ($fund->metadata['incentive_consumed'] ?? '0.00'));
                $take = min($remaining, Money::cents($fund->amount) - $used);
                if ($take > 0) {
                    $this->pay($fund, $userId, $take, $period, 'rank_'.$awardRank);
                    $sources[$fund->id] = Money::decimal($take);
                    $fund->update(['status' => $used + $take === Money::cents($fund->amount) ? 'settled' : 'partially_settled',
                        'metadata' => [...$fund->metadata, 'incentive_consumed' => Money::decimal($used + $take)]]);
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            DB::table('rank_incentive_awards')->insert(['user_id' => $userId, 'rank' => $awardRank, 'amount' => Money::decimal($amount),
                'request_key' => $requestKey, 'sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $rank->update(['one_time_incentive_paid' => true, 'one_time_incentive_paid_at' => now()]);
            Audit::record($actorId, 'commissions.rank_incentive_paid', (string) $userId, [
                'beneficiary_id' => $userId, 'rank' => $awardRank, 'amount' => Money::decimal($amount),
                'sources' => $sources, 'request_key' => $requestKey, 'unconditional' => $unconditionalIncentive,
            ]);

            return $this->rememberSettlement($period, 'rank_incentive', $requestKey, $actorId, [
                'success' => true, 'user_id' => $userId, 'rank' => $awardRank,
                'amount' => Money::decimal($amount), 'paid_at' => now()->toIso8601String(),
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function release(?int $actorId = null, ?string $requestKey = null): array
    {
        CommissionWallet::requireActive();
        $period = CommissionQualification::currentMonth();

        return DB::transaction(function () use ($actorId, $requestKey, $period): array {
            CommissionWallet::lock();
            if ($requestKey !== null) {
                $existing = DB::table('commission_settlement_runs')
                    ->where('period_month', $period)
                    ->where('kind', 'matured')
                    ->where('request_key', $requestKey)
                    ->first();
                if ($existing) {
                    return json_decode((string) $existing->result, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $funds = CommissionAllocation::where('status', 'maturing')->whereNotNull('user_id')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            $total = 0;
            foreach ($funds as $fund) {
                $due = $fund->metadata['maturation_due_at'] ?? null;
                if (! $due || now()->lt(CarbonImmutable::parse($due)) || ! User::whereKey($fund->user_id)->where('status', 'active')->exists()) {
                    continue;
                }
                $amount = Money::cents($fund->amount);
                $result = $this->wallets->debit($fund->user_id, $amount, true);
                if ($result['unrecovered'] !== 0) {
                    throw ValidationException::withMessages(['commissions' => __('portal.err_settle_holds')]);
                }
                $this->wallets->journal('commissions.matured.'.$fund->id, 'Release matured commission', [
                    ['user_id' => $fund->user_id, 'account' => 'commission_held_liability', 'debit' => $amount, 'credit' => 0],
                    ['user_id' => $fund->user_id, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount],
                ]);
                $this->wallets->credit($fund->user_id, $amount);
                $fund->update(['status' => 'available', 'metadata' => [...$fund->metadata, 'maturation_released' => true, 'matured_at' => now()->toIso8601String()]]);
                $count++;
                $total += $amount;
            }

            if ($count > 0) {
                Audit::record($actorId, 'commissions.matured_released', 'batch', [
                    'count' => $count, 'amount' => Money::decimal($total), 'request_key' => $requestKey,
                ]);
            }

            return $this->rememberSettlement($period, 'matured', $requestKey, $actorId, [
                'status' => $count > 0 ? 'success' : 'no_matured_commissions',
                'released_count' => $count,
                'total_amount' => Money::decimal($total),
            ]);
        }, 3);
    }
}

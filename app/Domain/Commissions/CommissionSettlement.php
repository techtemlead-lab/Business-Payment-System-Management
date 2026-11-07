<?php

namespace App\Domain\Commissions;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Finance\Percentage;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Notifications\PartnerEventSms;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class CommissionSettlement
{
    public const DealerSalaryReserve = 'dealer_salary_settlement_reserve';

    public const SubDealerSalaryReserve = 'sub_dealer_salary_settlement_reserve';

    public const RankRoyaltyReserve = 'rank_royalty_settlement_reserve';

    /**
     * The company's general profit fund. Division salary money that nobody in
     * the division qualified for lands here — never in the owner's special
     * benefit fund (RetainedFundLedger::BenefitsCategory).
     */
    public const CompanyProfitCategory = 'company_profit_fund';

    /** @return list<string> */
    public static function settlementReserveCategories(): array
    {
        return [self::DealerSalaryReserve, self::SubDealerSalaryReserve, self::RankRoyaltyReserve];
    }

    public function __construct(
        private CommissionWallet $wallets,
        private CommissionQualification $qualification,
        private CommissionPolicy $policies,
        private PlaceGeographyResolver $geography,
    ) {}

    /** @return array<string, mixed> */
    public function settle(string $month, string $kind, ?int $actorId = null, ?string $requestKey = null): array
    {
        CommissionWallet::requireActive();
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            throw ValidationException::withMessages(['period_month' => __('portal.err_settle_month_open')]);
        }

        $replayed = false;
        $result = DB::transaction(function () use ($month, $kind, $actorId, $requestKey, &$replayed): array {
            CommissionWallet::lock();
            if ($requestKey !== null) {
                $existing = DB::table('commission_settlement_runs')
                    ->where('period_month', $month)
                    ->where('kind', $kind)
                    ->where('request_key', $requestKey)
                    ->first();
                if ($existing) {
                    $replayed = true;

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
            // Salary is shared within each division (বিভাগ): the dealer's division, resolved the same way an order resolves its buyer's.
            $dealerDivisions = $kind === 'salary' ? $this->dealerDivisions($dealers) : [];
            $divisionSummary = [];
            $toCompanyProfit = 0;
            $subDealerNames = [];
            /** @var array<string, array<int, array<string, mixed>>> */
            $paidAsByRules = [];
            $batchPolicies = $this->batchPolicies($funds);
            $buyerGeographies = $kind === 'salary' ? $this->fundBuyerGeographies($funds, $batchPolicies) : [];
            $partners = User::query()
                ->whereIn('id', $funds->where('category', 'sub_dealer_salary')->pluck('user_id')->filter()->unique()->values())
                ->get()
                ->keyBy('id');
            $partnerVolumes = $this->qualification->monthlyVolumesFor($partners
                ->filter(fn (User $partner): bool => $partner->role === 'sub_dealer' && $partner->status === 'active' && ! array_key_exists($partner->id, $volumes))
                ->keys()->map(fn ($id): int => (int) $id)->values()->all(), $month);
            $payees = $dealers->keyBy('id')->union($partners)->all();
            $split = FairPaisaSplit::forMonth($month);
            foreach ($funds as $fund) {
                [$rules, $fundDivision, $qualifiedIds] = $this->fundQualifiers($fund, $kind, $dealers, $dealerDivisions, $volumes, $batchPolicies, $buyerGeographies);
                $cents = Money::cents($fund->amount);
                $shares = [];
                if ($fund->category === 'sub_dealer_salary') {
                    $partner = $fund->user_id !== null ? $partners->get($fund->user_id) : null;
                    if ($partner?->role === 'sub_dealer' && $partner->status === 'active') {
                        $shares[$partner->id] = $cents;
                        $subDealerNames[$partner->id] = $partner->name;
                        if (! array_key_exists($partner->id, $volumes)) {
                            $volumes[$partner->id] = $partnerVolumes[$partner->id] ?? 0;
                        }
                    }
                } elseif ($kind === 'salary') {
                    $shares = $split->divide($cents, $qualifiedIds, 'salary');
                    [$divisionKey, $label] = $this->divisionSummaryKey($fundDivision);
                    $divisionSummary[$divisionKey]['name'] = $label;
                    $divisionSummary[$divisionKey]['pool_cents'] = ($divisionSummary[$divisionKey]['pool_cents'] ?? 0) + $cents;
                    $divisionSummary[$divisionKey]['qualifiers'] = max($divisionSummary[$divisionKey]['qualifiers'] ?? 0, count($qualifiedIds));
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
                        $tierShares = $split->divide($buckets[$index], $ids, 'royalty_'.$code);
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
                        $this->pay($fund, $id, $amount, $month, $kind, $payees[$id] ?? null);
                        $distributed[$id] = ($distributed[$id] ?? 0) + $amount;
                    }
                }
                $paidCents = array_sum($shares);
                $remainder = $cents - $paidCents;
                $reserveId = null;
                if ($remainder > 0) {
                    // Sub-dealer salary keeps its own reserve (unchanged). Unclaimed dealer
                    // salary and rank royalty go to the company's general profit fund.
                    if ($fund->category === 'sub_dealer_salary') {
                        $reserveId = $this->rollUndistributed($fund, $remainder, $month, $kind);
                        $rolled += $remainder;
                    } else {
                        $reserveId = $this->toCompanyProfit($fund, $remainder, $month, $kind, $fundDivision['name'] ?? null);
                        $toCompanyProfit += $remainder;
                        if ($kind === 'salary') {
                            [$divisionKey, $label] = $this->divisionSummaryKey($fundDivision);
                            $divisionSummary[$divisionKey]['name'] = $label;
                            $divisionSummary[$divisionKey]['to_company_profit_cents'] = ($divisionSummary[$divisionKey]['to_company_profit_cents'] ?? 0) + $remainder;
                        }
                    }
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
            $divisions = [];
            foreach ($divisionSummary as $row) {
                $divisions[] = [
                    'division' => $row['name'],
                    'pool' => Money::decimal($row['pool_cents'] ?? 0),
                    'qualifiers' => (int) ($row['qualifiers'] ?? 0),
                    'to_company_profit' => Money::decimal($row['to_company_profit_cents'] ?? 0),
                ];
            }
            Audit::record($actorId, 'commissions.'.$kind.'_pool_settled', $month, [
                'distributed' => Money::decimal($totalPaid),
                'rolled_to_reserve' => Money::decimal($rolled),
                'to_company_profit' => Money::decimal($toCompanyProfit),
                'request_key' => $requestKey,
            ]);

            return $this->rememberSettlement($month, $kind, $requestKey, $actorId, [
                'status' => $totalPaid > 0 ? 'settled' : 'rolled_over', 'period' => $month, 'total_pool' => Money::decimal($total),
                'total_distributed' => Money::decimal($totalPaid), 'total_rolled_to_reserve' => Money::decimal($rolled),
                'total_to_company_profit' => Money::decimal($toCompanyProfit),
                'qualifiers_count' => count($distributed), 'distributed' => $result, 'distributions' => $tiers,
                'divisions' => $divisions,
            ]);
        }, 3);

        if (! $replayed) {
            $this->notifySettlementCredits($result, $month, $kind);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifySettlementCredits(array $result, string $month, string $kind): void
    {
        $rows = $result['distributed'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return;
        }

        $sms = app(PartnerEventSms::class);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $userId = (int) ($row['user_id'] ?? 0);
            $amount = (string) ($row['amount'] ?? '0.00');
            if ($userId <= 0 || Money::cents($amount) <= 0) {
                continue;
            }
            $partner = User::find($userId);
            if (! $partner) {
                continue;
            }
            $locale = $partner->preferredLocale();
            $kindLabel = __('portal.settle_kind_'.$kind, [], $locale);
            $sms->send(
                $partner,
                'commission.settled',
                __('sms.commission_settled', [
                    'amount' => $amount,
                    'kind' => $kindLabel,
                    'month' => $month,
                ], $locale),
            );
        }
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

    /**
     * What settling a month's salary would pay, worked out with exactly the same rules,
     * divisions and volume waivers as settle(). Nothing is paid or saved. Pass the month's
     * pending salary funds when they are already loaded.
     *
     * @param  Collection<int, CommissionAllocation>|null  $pendingFunds
     * @return array{
     *   divisions: list<array{division: string, pool: string, qualifiers: int, per_head: string, to_company_profit: string}>,
     *   dealers: list<array{user_id: int, name: string, dealer_code: string|null, division: string, monthly_volume: string, share: string}>,
     *   dealer_pool: string, sub_dealer_salary: string, to_company_profit: string
     * }
     */
    public function salaryPlan(string $month, ?Collection $pendingFunds = null): array
    {
        $funds = ($pendingFunds ?? CommissionAllocation::whereIn('category', ['dealer_monthly_salary_pool', 'sub_dealer_salary'])
            ->where('status', 'pending')->where('metadata->period_month', $month)->get())->sortBy('id')->values();
        if ($funds->where('category', 'dealer_monthly_salary_pool')->isEmpty()) {
            $subDealer = $funds->sum(fn (CommissionAllocation $fund): int => Money::cents($fund->amount));

            return ['divisions' => [], 'dealers' => [], 'dealer_pool' => '0.00', 'sub_dealer_salary' => Money::decimal($subDealer), 'to_company_profit' => '0.00'];
        }
        $dealers = User::query()->where('role', 'dealer')->where('status', 'active')->orderBy('id')->get();
        $volumes = $this->qualification->monthlyVolumesFor($dealers->pluck('id')->map(fn ($id): int => (int) $id)->all(), $month);
        $dealerDivisions = $this->dealerDivisions($dealers);
        $batchPolicies = $this->batchPolicies($funds);
        $buyerGeographies = $this->fundBuyerGeographies($funds, $batchPolicies);
        $divisions = [];
        $shares = [];
        $shareDivision = [];
        $subDealerCents = 0;
        $split = FairPaisaSplit::forMonth($month);
        foreach ($funds as $fund) {
            $cents = Money::cents($fund->amount);
            if ($fund->category === 'sub_dealer_salary') {
                $subDealerCents += $cents;

                continue;
            }
            [, $fundDivision, $qualifiedIds] = $this->fundQualifiers($fund, 'salary', $dealers, $dealerDivisions, $volumes, $batchPolicies, $buyerGeographies);
            [$divisionKey, $label] = $this->divisionSummaryKey($fundDivision);
            $fundShares = $split->divide($cents, $qualifiedIds, 'salary');
            $divisions[$divisionKey]['name'] = $label;
            $divisions[$divisionKey]['pool'] = ($divisions[$divisionKey]['pool'] ?? 0) + $cents;
            $divisions[$divisionKey]['to_company'] = ($divisions[$divisionKey]['to_company'] ?? 0) + $cents - array_sum($fundShares);
            $divisions[$divisionKey]['qualifiers'] = max($divisions[$divisionKey]['qualifiers'] ?? 0, count($qualifiedIds));
            foreach ($fundShares as $id => $amount) {
                $shares[$id] = ($shares[$id] ?? 0) + $amount;
                $shareDivision[$id] = $label;
            }
        }
        uasort($divisions, fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        $byId = $dealers->keyBy('id');
        $dealerRows = [];
        foreach ($shares as $id => $amount) {
            $dealer = $byId->get($id);
            $dealerRows[] = [
                'user_id' => (int) $id,
                'name' => (string) $dealer?->name,
                'dealer_code' => $dealer?->dealer_code,
                'division' => $shareDivision[$id],
                'monthly_volume' => Money::decimal($volumes[$id] ?? 0),
                'share' => Money::decimal($amount),
            ];
        }
        usort($dealerRows, fn (array $a, array $b): int => [$a['division'], $a['name']] <=> [$b['division'], $b['name']]);

        return [
            'divisions' => array_map(fn (array $row): array => [
                'division' => $row['name'],
                'pool' => Money::decimal($row['pool']),
                'qualifiers' => (int) $row['qualifiers'],
                'per_head' => Money::decimal($row['qualifiers'] > 0 ? intdiv($row['pool'] - $row['to_company'], $row['qualifiers']) : 0),
                'to_company_profit' => Money::decimal($row['to_company']),
            ], array_values($divisions)),
            'dealers' => $dealerRows,
            'dealer_pool' => Money::decimal(array_sum(array_column($divisions, 'pool'))),
            'sub_dealer_salary' => Money::decimal($subDealerCents),
            'to_company_profit' => Money::decimal(array_sum(array_column($divisions, 'to_company'))),
        ];
    }

    /**
     * The commission rules saved with each fund's order, loaded in one query.
     *
     * @param  Collection<int, CommissionAllocation>  $funds
     * @return array<int|string, string|null>
     */
    private function batchPolicies(Collection $funds): array
    {
        return DB::table('commission_batches')
            ->whereIn('order_id', $funds->pluck('order_id')->filter()->unique()->values())
            ->pluck('policy', 'order_id')
            ->all();
    }

    /**
     * Each dealer's division, resolved the same way an order resolves its buyer's.
     *
     * @param  Collection<int, User>  $dealers
     * @return array<int, array{id: int|null, name: string|null}>
     */
    private function dealerDivisions(Collection $dealers): array
    {
        $geographies = $this->geography->forBuyers($dealers);

        return $dealers->mapWithKeys(fn (User $dealer): array => [
            (int) $dealer->id => $this->divisionOf($geographies[(int) $dealer->id]),
        ])->all();
    }

    /**
     * The order buyer's geography for every fund whose order kept no geography snapshot,
     * loaded together instead of one fund at a time.
     *
     * @param  Collection<int, CommissionAllocation>  $funds
     * @param  array<int|string, string|null>  $batchPolicies
     * @return array<int|string, array<string, mixed>|null> order id => buyer geography
     */
    private function fundBuyerGeographies(Collection $funds, array $batchPolicies): array
    {
        $orderIds = $funds
            ->filter(function (CommissionAllocation $fund) use ($batchPolicies): bool {
                $source = $batchPolicies[$fund->order_id] ?? null;
                $policy = $source ? json_decode((string) $source, true, 512, JSON_THROW_ON_ERROR) : null;

                return $fund->order_id !== null && ($policy['geography'] ?? null) === null;
            })
            ->pluck('order_id')
            ->unique()
            ->values();
        if ($orderIds->isEmpty()) {
            return [];
        }
        $buyerIds = DB::table('orders')->whereIn('id', $orderIds)->pluck('user_id', 'id');
        $buyers = User::query()->whereIn('id', $buyerIds->filter()->unique()->values())->get();
        $geographies = $this->geography->forBuyers($buyers);

        $byOrder = [];
        foreach ($orderIds as $orderId) {
            $buyerId = $buyerIds[$orderId] ?? null;
            $byOrder[$orderId] = $buyerId !== null ? ($geographies[(int) $buyerId] ?? null) : null;
        }

        return $byOrder;
    }

    /**
     * The rules, division and qualifying dealers for one salary or royalty fund: dealers in the
     * fund's division who met the monthly volume (or have it waived) under the order's rules.
     *
     * @param  Collection<int, User>  $dealers
     * @param  array<int, array<string, mixed>>  $dealerDivisions
     * @param  array<int, int>  $volumes
     * @param  array<int|string, string|null>  $batchPolicies
     * @param  array<int|string, array<string, mixed>|null>  $buyerGeographies  preloaded by fundBuyerGeographies()
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: list<int>}
     */
    private function fundQualifiers(CommissionAllocation $fund, string $kind, Collection $dealers, array $dealerDivisions, array $volumes, array $batchPolicies, array $buyerGeographies = []): array
    {
        $source = $batchPolicies[$fund->order_id] ?? null;
        $policy = $source ? json_decode((string) $source, true, 512, JSON_THROW_ON_ERROR) : null;
        $rules = $policy['rules'] ?? $this->policies->snapshot()['rules'];
        $minVolume = Money::cents((string) $rules['dealer_monthly_salary']['parameters']['min_monthly_volume']);
        $fundDivision = $kind === 'salary' ? $this->divisionOf($policy['geography'] ?? (array_key_exists($fund->order_id, $buyerGeographies)
            ? $buyerGeographies[$fund->order_id]
            : $this->orderBuyerGeography($fund))) : null;
        $qualifiedIds = $dealers
            ->filter(fn (User $user): bool => ($kind !== 'salary' || $this->sameDivision($dealerDivisions[(int) $user->id] ?? null, $fundDivision))
                && (PartnerBenefitControls::waivesSalaryVolume($user) || ($volumes[$user->id] ?? 0) >= $minVolume))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [$rules, $fundDivision, $qualifiedIds];
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
                'reason_key' => $kind === 'salary' ? 'retained_reason_settle_rollover_salary' : 'retained_reason_settle_rollover_royalty',
                'reason' => $kind === 'salary'
                    ? __('portal.retained_reason_settle_rollover_salary')
                    : __('portal.retained_reason_settle_rollover_royalty'),
            ],
        ])->id;
    }

    /**
     * Money nobody qualified for (a division with no qualifying dealer, or a
     * royalty rank with nobody at it) becomes company profit.
     */
    private function toCompanyProfit(CommissionAllocation $fund, int $amount, string $month, string $kind, ?string $division): int
    {
        return CommissionAllocation::query()->create([
            'order_id' => $fund->order_id,
            'user_id' => null,
            'category' => self::CompanyProfitCategory,
            'rate_percentage' => $fund->rate_percentage,
            'cash_basis' => $fund->cash_basis,
            'amount' => Money::decimal($amount),
            'original_amount' => Money::decimal($amount),
            'status' => 'reserved',
            'metadata' => [
                'basis' => 'purchase_value',
                'period_month' => $month,
                'source_allocation_id' => $fund->id,
                'source_category' => $fund->category,
                'settlement_kind' => $kind,
                'division' => $division,
                'retained_by' => 'company_profit_unclaimed',
                'reason_key' => $kind === 'salary' ? 'retained_reason_unclaimed_division_salary' : 'retained_reason_unclaimed_royalty',
                'reason' => $kind === 'salary'
                    ? __('portal.retained_reason_unclaimed_division_salary', ['division' => $division ?? __('portal.report_area_unknown')])
                    : __('portal.retained_reason_unclaimed_royalty'),
            ],
        ])->id;
    }

    /**
     * A division identified only by its place code id. The name is kept for display and
     * only when it came with that id; a name on its own (older orders, partners with no
     * place code) is never used to match, because "Dhaka" can be several places.
     *
     * @param  array<string, mixed>|null  $geography
     * @return array{id: int|null, name: string|null}
     */
    private function divisionOf(?array $geography): array
    {
        $id = $geography['place_ids']['division'] ?? null;
        $id = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        $name = $id !== null ? trim((string) ($geography['division'] ?? '')) : '';

        return [
            'id' => $id,
            'name' => $name === '' ? null : $name,
        ];
    }

    /**
     * Same division only when both sides carry the same division place code. A fund or a
     * dealer without one never matches, so that fund's salary goes to company profit.
     *
     * @param  array{id: int|null, name: string|null}|null  $dealer
     * @param  array{id: int|null, name: string|null}|null  $fund
     */
    private function sameDivision(?array $dealer, ?array $fund): bool
    {
        return $dealer !== null && $fund !== null
            && $dealer['id'] !== null && $dealer['id'] === $fund['id'];
    }

    /**
     * The row key and label of a fund's division in the salary summary: keyed by place
     * code id so two divisions that share a name never merge.
     *
     * @param  array{id: int|null, name: string|null}|null  $division
     * @return array{0: string, 1: string}
     */
    private function divisionSummaryKey(?array $division): array
    {
        $label = $division['name'] ?? __('portal.report_area_unknown');

        return [($division['id'] ?? null) !== null ? 'place:'.$division['id'] : 'unplaced', $label];
    }

    /** @return array<string, mixed>|null Fallback for funds whose order kept no geography snapshot. */
    private function orderBuyerGeography(CommissionAllocation $fund): ?array
    {
        $buyer = User::query()->whereKey(DB::table('orders')->where('id', $fund->order_id)->value('user_id'))->first();

        return $buyer ? $this->geography->forBuyer($buyer) : null;
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

    /** @param  User|null  $payee  the partner when already loaded, so a batch of payouts skips looking each one up */
    private function pay(CommissionAllocation $fund, int $userId, int $amount, string $month, string $kind, ?User $payee = null): void
    {
        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $fund->id, 'user_id' => $userId, 'kind' => $kind,
            'refunded_basis' => DB::table('commission_batches')->where('order_id', $fund->order_id)->value('refunded_amount') ?? '0.00',
            'period_month' => $month, 'amount' => Money::decimal($amount), 'created_at' => now(), 'updated_at' => now()]);
        $partner = $payee ?? User::find($userId);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        $kindLabel = __('portal.settle_kind_'.$kind, [], $locale);
        $this->wallets->journal('commissions.settlement.'.$fund->id.'.'.$userId.'.'.$kind, __('portal.jr_commission_settlement', [
            'kind' => $kindLabel,
            'month' => $month,
        ], $locale), [
            ['user_id' => null, 'account' => 'commission_expense', 'debit' => $amount, 'credit' => 0],
            ['user_id' => $userId, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount],
        ]);
        $this->wallets->credit($userId, $amount);
        Audit::notice(
            $userId,
            __('portal.notice_settle_title', [], $locale),
            __('portal.notice_settle_body', [
                'amount' => Money::decimal($amount),
                'kind' => $kindLabel,
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
                        abort(409, __('portal.err_idem_incentive_key'));
                    }

                    return $result;
                }
                $award = DB::table('rank_incentive_awards')->where('request_key', $requestKey)->first();
                if ($award) {
                    if ((int) $award->user_id !== $userId) {
                        abort(409, __('portal.err_idem_incentive_key'));
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
            $skipped = 0;
            foreach ($funds as $fund) {
                $due = $fund->metadata['maturation_due_at'] ?? null;
                if (! $due || now()->lt(CarbonImmutable::parse($due)) || ! User::whereKey($fund->user_id)->where('status', 'active')->exists()) {
                    continue;
                }
                $amount = Money::cents($fund->amount);
                // One wallet whose held balance has drifted must not stop everyone else's
                // commissions from maturing. Skip it, leave it maturing and raise an alert.
                $heldBalance = Money::cents((string) (DB::table('wallets')->where('user_id', $fund->user_id)->value('commission_held') ?? '0'));
                if ($heldBalance < $amount) {
                    Log::critical('commission.maturity_release_skipped', [
                        'allocation_id' => $fund->id, 'user_id' => $fund->user_id,
                        'amount' => Money::decimal($amount), 'commission_held' => Money::decimal($heldBalance),
                    ]);
                    $skipped++;

                    continue;
                }
                $result = $this->wallets->debit($fund->user_id, $amount, true);
                if ($result['unrecovered'] !== 0) {
                    throw ValidationException::withMessages(['commissions' => __('portal.err_settle_holds')]);
                }
                $partner = User::find($fund->user_id);
                $locale = $partner?->preferredLocale() ?? app()->getLocale();
                $this->wallets->journal('commissions.matured.'.$fund->id, __('portal.jr_commission_matured', [], $locale), [
                    ['user_id' => $fund->user_id, 'account' => 'commission_held_liability', 'debit' => $amount, 'credit' => 0],
                    ['user_id' => $fund->user_id, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount],
                ]);
                $this->wallets->credit($fund->user_id, $amount);
                $fund->update(['status' => 'available', 'metadata' => [...$fund->metadata, 'maturation_released' => true, 'matured_at' => now()->toIso8601String()]]);
                $amountDecimal = Money::decimal($amount);
                Audit::notice(
                    $fund->user_id,
                    __('portal.notice_commission_matured_title', [], $locale),
                    __('portal.notice_commission_matured_body', ['amount' => $amountDecimal], $locale),
                );
                $userId = (int) $fund->user_id;
                DB::afterCommit(function () use ($userId, $amountDecimal, $locale): void {
                    $partner = User::find($userId);
                    if (! $partner) {
                        return;
                    }
                    app(PartnerEventSms::class)->send(
                        $partner,
                        'commission.matured',
                        __('sms.commission_matured', ['amount' => $amountDecimal], $partner->preferredLocale() ?: $locale),
                    );
                });
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
                'skipped_count' => $skipped,
                'total_amount' => Money::decimal($total),
            ]);
        }, 3);
    }
}

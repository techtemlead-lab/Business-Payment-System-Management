<?php

namespace App\Domain\Commissions;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Finance\MonthCloseStatus;
use App\Domain\Finance\Percentage;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Notifications\PartnerEventEmail;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /** Marks a Commission extra fund row holding the paisa left over from an equal split. */
    public const SplitRemainder = 'split_remainder';

    /** Settlement payout rows written per insert statement. */
    private const PayoutInsertChunk = 2000;

    /** Funds closed per transaction, so a month close never holds the money lock for long (PQ-01). */
    public const CloseChunk = 200;

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

    /**
     * Month close for salary or royalty. The month's funds are closed a chunk at a time, each chunk
     * in its own short transaction under the shared money lock with the paid partners locked, so
     * orders and payouts keep running during a long close (PQ-01). A fund is closed once: a chunk
     * re-reads only funds still pending, so a run that stops halfway is finished by the next run.
     *
     * @return array<string, mixed>
     */
    public function settle(string $month, string $kind, ?int $actorId = null, ?string $requestKey = null): array
    {
        CommissionWallet::requireActive();
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            throw ValidationException::withMessages(['period_month' => __('portal.err_settle_month_open')]);
        }
        if ($requestKey !== null) {
            $existing = DB::table('commission_settlement_runs')->where('period_month', $month)->where('kind', $kind)->where('request_key', $requestKey)->first();
            if ($existing) {
                return json_decode((string) $existing->result, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $categories = $kind === 'salary' ? ['dealer_monthly_salary_pool', 'sub_dealer_salary'] : ['rank_royalty_pool'];
        $fundIds = CommissionAllocation::whereIn('category', $categories)->where('status', 'pending')
            ->where('metadata->period_month', $month)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $dealers = User::query()->where('role', 'dealer')->where('status', 'active')->orderBy('id')->get();
        $dealerIds = $dealers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        // Sub-dealers share the rank royalty too under the owner's rule of 2026-11-21 (only funds of orders captured
        // with that rule let them in; see settleChunk()).
        $subDealers = $kind === 'royalty' && $fundIds !== []
            ? User::query()->where('role', 'sub_dealer')->where('status', 'active')->orderBy('id')->get()
            : collect();
        $context = [
            'dealers' => $dealers,
            'dealerIds' => $dealerIds,
            'subDealerIds' => $subDealers->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'volumes' => $fundIds === [] ? [] : $this->qualification->monthlyVolumesFor($dealerIds, $month),
            // Salary is shared within each division (বিভাগ): the dealer's division, resolved the same way an order resolves its buyer's.
            'dealerDivisions' => $kind === 'salary' && $fundIds !== [] ? $this->dealerDivisions($dealers) : [],
            // Each dealer's kind sets his one monthly target (MonthlyTargets).
            'dealerKinds' => $kind === 'salary' && $fundIds !== [] ? app(DealerKinds::class)->for($dealerIds) : [],
            'paidAsByRules' => [],
            'subDealerNames' => $subDealers->pluck('name', 'id')->all(),
            'payees' => $dealers->keyBy('id')->all() + $subDealers->keyBy('id')->all(),
        ];
        $total = 0;
        $distributed = [];
        $tiers = [];
        $divisionSummary = [];
        $toCompanyProfit = 0;
        $toExtraFund = 0;
        foreach (array_chunk($fundIds, max(1, (int) config('madina.settlement_close_chunk', self::CloseChunk))) as $chunkIds) {
            $chunk = DB::transaction(fn (): array => $this->settleChunk($chunkIds, $month, $kind, $context), 3);
            $total += $chunk['total'];
            $toCompanyProfit += $chunk['to_company_profit'];
            $toExtraFund += $chunk['to_extra_fund'];
            foreach ($chunk['distributed'] as $id => $amount) {
                $distributed[$id] = ($distributed[$id] ?? 0) + $amount;
            }
            foreach ($chunk['tiers'] as $code => $tier) {
                $tiers[$code]['qualifiers'] = $tier['qualifiers'];
                $tiers[$code]['allocated_cents'] = ($tiers[$code]['allocated_cents'] ?? 0) + $tier['allocated_cents'];
                $tiers[$code]['reserve_cents'] = ($tiers[$code]['reserve_cents'] ?? 0) + $tier['reserve_cents'];
            }
            foreach ($chunk['divisions'] as $key => $division) {
                // A chunk names only the parts it saw for a division; the missing parts are zero.
                $division += ['pool_cents' => 0, 'qualifiers' => 0, 'to_company_profit_cents' => 0];
                $sofar = $divisionSummary[$key] ?? ['pool_cents' => 0, 'qualifiers' => 0, 'to_company_profit_cents' => 0];
                $divisionSummary[$key] = [
                    'name' => $division['name'],
                    'pool_cents' => $sofar['pool_cents'] + $division['pool_cents'],
                    'qualifiers' => max($sofar['qualifiers'], $division['qualifiers']),
                    'to_company_profit_cents' => $sofar['to_company_profit_cents'] + $division['to_company_profit_cents'],
                ];
            }
        }
        if ($total === 0) {
            return DB::transaction(function () use ($month, $kind, $requestKey, $actorId): array {
                CommissionWallet::lockShared();

                return $this->rememberSettlement($month, $kind, $requestKey, $actorId, [
                    'status' => 'empty_pool', 'total_pool' => '0.00', 'qualifiers_count' => 0,
                    'total_distributed' => '0.00', 'total_rolled_to_reserve' => '0.00',
                ]);
            }, 3);
        }
        $this->noticeSettlementCredits($distributed, $month, $kind, $context['payees']);
        $volumes = $context['volumes'];
        $missingVolumeIds = array_values(array_diff(array_keys($distributed), array_keys($volumes)));
        if ($missingVolumeIds !== []) {
            $volumes += $this->qualification->monthlyVolumesFor($missingVolumeIds, $month);
        }
        $names = $dealers->pluck('name', 'id')->all() + $context['subDealerNames'];
        $result = [];
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
        unset($tier);
        $divisions = [];
        foreach ($divisionSummary as $row) {
            $divisions[] = [
                'division' => $row['name'],
                'pool' => Money::decimal((int) $row['pool_cents']),
                'qualifiers' => (int) $row['qualifiers'],
                'to_company_profit' => Money::decimal((int) $row['to_company_profit_cents']),
            ];
        }
        $totalPaid = array_sum($distributed);
        $result = DB::transaction(function () use ($month, $kind, $requestKey, $actorId, $totalPaid, $toCompanyProfit, $toExtraFund, $total, $distributed, $result, $tiers, $divisions): array {
            CommissionWallet::lockShared();
            Audit::record($actorId, 'commissions.'.$kind.'_pool_settled', $month, [
                'distributed' => Money::decimal($totalPaid),
                'rolled_to_reserve' => '0.00',
                'to_company_profit' => Money::decimal($toCompanyProfit),
                'to_commission_extra_fund' => Money::decimal($toExtraFund),
                'request_key' => $requestKey,
            ]);

            return $this->rememberSettlement($month, $kind, $requestKey, $actorId, [
                'status' => $totalPaid > 0 ? 'settled' : 'rolled_over', 'period' => $month, 'total_pool' => Money::decimal($total),
                'total_distributed' => Money::decimal($totalPaid), 'total_rolled_to_reserve' => '0.00',
                'total_to_company_profit' => Money::decimal($toCompanyProfit),
                'total_to_commission_extra_fund' => Money::decimal($toExtraFund),
                'qualifiers_count' => count($distributed), 'distributed' => $result, 'distributions' => $tiers,
                'divisions' => $divisions,
            ]);
        }, 3);
        $this->notifySettlementCredits($result, $month, $kind);
        app(MonthCloseStatus::class)->clearFailure($kind, $month);

        return $result;
    }

    /**
     * Closes one chunk of a month's funds inside the caller's transaction. Totals come back for the
     * caller to add up, so a retried chunk is never counted twice. Shared look-ups (rank results,
     * volumes, names) are kept in $context between chunks.
     *
     * @param  list<int>  $fundIds
     * @param  array<string, mixed>  $context
     * @return array{total: int, to_company_profit: int, distributed: array<int, int>, tiers: array<string, array{qualifiers: int, allocated_cents: int, reserve_cents: int}>, divisions: array<string, array<string, mixed>>}
     */
    private function settleChunk(array $fundIds, string $month, string $kind, array &$context): array
    {
        CommissionWallet::lockShared();
        $funds = CommissionAllocation::query()->whereIn('id', $fundIds)->where('status', 'pending')->orderBy('id')->lockForUpdate()->get();
        $chunk = ['total' => 0, 'to_company_profit' => 0, 'to_extra_fund' => 0, 'distributed' => [], 'tiers' => [], 'divisions' => []];
        if ($funds->isEmpty()) {
            return $chunk;
        }
        $chunk['total'] = $funds->sum(fn (CommissionAllocation $a): int => Money::cents($a->amount));
        /** @var Collection<int, User> $dealers */
        $dealers = $context['dealers'];
        $dealerIds = $context['dealerIds'];
        $batchPolicies = $this->batchPolicies($funds);
        $buyerGeographies = $kind === 'salary' ? $this->fundBuyerGeographies($funds, $batchPolicies) : [];
        $partners = User::query()
            ->whereIn('id', $funds->where('category', 'sub_dealer_salary')->pluck('user_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');
        $partnerVolumes = $this->qualification->monthlyVolumesFor($partners
            ->filter(fn (User $partner): bool => $partner->role === 'sub_dealer' && $partner->status === 'active' && ! array_key_exists($partner->id, $context['volumes']))
            ->keys()->map(fn ($id): int => (int) $id)->values()->all(), $month);
        foreach ($partners as $id => $partner) {
            $context['payees'][$id] = $partner;
        }
        $refundedBasis = $this->refundedBasisByOrder($funds);
        $payoutRows = [];
        foreach ($funds as $fund) {
            [$rules, $fundDivision, $qualifiedIds] = $this->fundQualifiers($fund, $kind, $dealers, $context['dealerDivisions'], $context['volumes'], $batchPolicies, $buyerGeographies, $context['dealerKinds']);
            $cents = Money::cents($fund->amount);
            $shares = [];
            $tierReason = false;
            $leftover = 0;
            if ($fund->category === 'sub_dealer_salary') {
                $partner = $fund->user_id !== null ? $partners->get($fund->user_id) : null;
                if ($partner?->role === 'sub_dealer' && $partner->status === 'active') {
                    $context['subDealerNames'][$partner->id] = $partner->name;
                    if (! array_key_exists($partner->id, $context['volumes'])) {
                        $context['volumes'][$partner->id] = $partnerVolumes[$partner->id] ?? 0;
                    }
                    // Owner's rule of 2026-11-21: the sub-dealer is paid the tier his own month's purchase reached; the
                    // rest of the salary set aside goes to the company profit fund. Older orders paid all of it.
                    $tierBasisPoints = MonthlyTargets::salaryBasisPoints($rules, $context['volumes'][$partner->id]);
                    $tierReason = $tierBasisPoints !== null;
                    $paid = $tierBasisPoints === null ? $cents
                        : MonthlyTargets::tierShare($cents, $tierBasisPoints, Percentage::from((string) $rules['sub_dealer_salary']['percentage'])->basisPoints);
                    if ($paid > 0) {
                        $shares[$partner->id] = $paid;
                    }
                }
            } elseif ($kind === 'salary') {
                // Equal to the paisa; the paisa that do not divide go to the Commission extra fund (owner, 2026-11-21).
                // Nobody qualified: the whole fund goes to the company profit fund below.
                ['shares' => $shares, 'leftover' => $splitLeftover] = EqualSplit::among($cents, $qualifiedIds);
                $leftover = $qualifiedIds === [] ? 0 : $splitLeftover;
                [$divisionKey, $label] = $this->divisionSummaryKey($fundDivision);
                $chunk['divisions'][$divisionKey]['name'] = $label;
                $chunk['divisions'][$divisionKey]['pool_cents'] = ($chunk['divisions'][$divisionKey]['pool_cents'] ?? 0) + $cents;
                $chunk['divisions'][$divisionKey]['qualifiers'] = max($chunk['divisions'][$divisionKey]['qualifiers'] ?? 0, count($qualifiedIds));
            } else {
                // The four rank funds (1% each by default), each rounded down; the paisa left go to the Commission extra fund.
                ['parts' => $buckets, 'leftover' => $leftover] = EqualSplit::byWeights($cents, $this->rankWeights($rules['rank_royalty']['parameters']));
                // Who may share: dealers, and sub-dealers too when the order was captured under the owner's rule of 2026-11-21.
                $candidates = $dealerIds;
                if (MonthlyTargets::subDealersEarnRanks($rules)) {
                    $candidates = [...$dealerIds, ...$context['subDealerIds']];
                    sort($candidates);
                }
                $rulesKey = hash('xxh3', json_encode([
                    $rules['rank_maintenance']['parameters'] ?? [],
                    MonthlyTargets::usesMonthlyTargets($rules)
                        ? [$rules['dealer_monthly_salary']['parameters'] ?? [], $rules['rank_royalty']['parameters'][MonthlyTargets::SubDealerRoyaltyParameter] ?? null]
                        : null,
                ], JSON_THROW_ON_ERROR));
                if (! isset($context['paidAsByRules'][$rulesKey])) {
                    $context['paidAsByRules'][$rulesKey] = $this->qualification->paidAsFor($candidates, $month, $rules);
                }
                foreach (['mo', 'am', 'sm', 'me'] as $index => $code) {
                    $ids = array_values(array_filter(
                        $candidates,
                        fn (int $id): bool => ($context['paidAsByRules'][$rulesKey][$id]['paid_as_rank'] ?? 'dealer') === $code,
                    ));
                    // A rank fund nobody qualified for goes whole to the company profit fund (below); a shared one leaves
                    // only its undividable paisa, which go to the Commission extra fund.
                    ['shares' => $tierShares, 'leftover' => $tierLeftover] = EqualSplit::among($buckets[$index], $ids);
                    $leftover += $ids === [] ? 0 : $tierLeftover;
                    foreach ($tierShares as $id => $amount) {
                        $shares[$id] = ($shares[$id] ?? 0) + $amount;
                    }
                    $chunk['tiers'][$code]['qualifiers'] = count($ids);
                    $chunk['tiers'][$code]['allocated_cents'] = ($chunk['tiers'][$code]['allocated_cents'] ?? 0) + array_sum($tierShares);
                    $chunk['tiers'][$code]['reserve_cents'] = ($chunk['tiers'][$code]['reserve_cents'] ?? 0) + $buckets[$index] - array_sum($tierShares);
                }
            }
            // Every source fund keeps its own payout rows (refunds claw back per source),
            // but wallets and the journal are written once per partner for the chunk.
            foreach ($shares as $id => $amount) {
                if ($amount > 0) {
                    $payoutRows[] = ['allocation_id' => $fund->id, 'user_id' => $id, 'kind' => $kind,
                        'refunded_basis' => $refundedBasis[$fund->order_id] ?? '0.00', 'period_month' => $month,
                        'amount' => Money::decimal($amount), 'created_at' => now(), 'updated_at' => now()];
                    $chunk['distributed'][$id] = ($chunk['distributed'][$id] ?? 0) + $amount;
                }
            }
            if (count($payoutRows) >= self::PayoutInsertChunk) {
                $this->insertPayouts($payoutRows);
            }
            $paidCents = array_sum($shares);
            $extraId = null;
            if ($leftover > 0) {
                $extraId = $this->toCommissionExtraFund($fund, $leftover, $month, $kind);
                $chunk['to_extra_fund'] += $leftover;
            }
            $remainder = $cents - $paidCents - $leftover;
            $reserveId = null;
            if ($remainder > 0) {
                // Owner rule: every month closes in that month. Salary or royalty nobody earned
                // (including a sub-dealer who is no longer active) goes to the company profit fund.
                if ($fund->category === 'sub_dealer_salary') {
                    $reserveId = $this->toCompanyProfit($fund, $remainder, $month, $kind, null, $tierReason ? 'retained_reason_sub_dealer_salary_tier' : 'retained_reason_unclaimed_sub_dealer_salary');
                } else {
                    $reserveId = $this->toCompanyProfit($fund, $remainder, $month, $kind, $fundDivision['name'] ?? null,
                        ($fundDivision['nationwide'] ?? false) === true ? 'retained_reason_unclaimed_national_salary' : null);
                    if ($kind === 'salary') {
                        [$divisionKey, $label] = $this->divisionSummaryKey($fundDivision);
                        $chunk['divisions'][$divisionKey]['name'] = $label;
                        $chunk['divisions'][$divisionKey]['to_company_profit_cents'] = ($chunk['divisions'][$divisionKey]['to_company_profit_cents'] ?? 0) + $remainder;
                    }
                }
                $chunk['to_company_profit'] += $remainder;
            }
            $fund->update(['status' => 'settled', 'metadata' => [...$fund->metadata, 'settled_at' => now()->toIso8601String(),
                'settled_period' => $month, 'distributed' => Money::decimal($paidCents), 'reserved' => Money::decimal($remainder),
                'settlement_reserve_allocation_id' => $reserveId,
                ...($extraId !== null ? ['split_leftover' => Money::decimal($leftover), 'split_leftover_allocation_id' => $extraId] : [])]]);
        }
        $this->insertPayouts($payoutRows);
        $this->creditSettlement($chunk['distributed'], $month, $kind);

        return $chunk;
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
            $kindLabel = SmsText::make('portal.settle_kind_'.$kind);
            $sms->send(
                $partner,
                'commission.settled',
                SmsText::make('sms.commission_settled', [
                    'amount' => $amount,
                    'kind' => $kindLabel,
                    'month' => $month,
                ]),
            );
            // One email per person per run, like the SMS; the portal notices are per commission line.
            $locale = $partner->preferredLocale();
            app(PartnerEventEmail::class)->send(
                $partner,
                'commission.posted',
                __('portal.notice_settle_title', [], $locale),
                __('portal.notice_settle_body', ['amount' => Money::display($amount, $locale), 'kind' => __('portal.settle_kind_'.$kind, [], $locale), 'month' => self::monthLabel($month, $locale)], $locale),
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
     *   divisions: list<array{division: string, division_id: int|null, pool: string, qualifiers: int, per_head: string, to_company_profit: string}>,
     *   dealers: list<array{user_id: int, name: string, dealer_code: string|null, division: string, division_id: int|null, monthly_volume: string, share: string}>,
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
        $dealerKinds = app(DealerKinds::class)->for($dealers->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $batchPolicies = $this->batchPolicies($funds);
        $buyerGeographies = $this->fundBuyerGeographies($funds, $batchPolicies);
        $divisions = [];
        $shares = [];
        $shareDivision = [];
        $shareDivisionId = [];
        $shareNationwide = [];
        $subDealerCents = 0;
        foreach ($funds as $fund) {
            $cents = Money::cents($fund->amount);
            if ($fund->category === 'sub_dealer_salary') {
                $subDealerCents += $cents;

                continue;
            }
            [, $fundDivision, $qualifiedIds] = $this->fundQualifiers($fund, 'salary', $dealers, $dealerDivisions, $volumes, $batchPolicies, $buyerGeographies, $dealerKinds);
            [$divisionKey, $label] = $this->divisionSummaryKey($fundDivision);
            ['shares' => $fundShares, 'leftover' => $leftover] = EqualSplit::among($cents, $qualifiedIds);
            $leftover = $qualifiedIds === [] ? 0 : $leftover;
            $divisions[$divisionKey]['name'] = $label;
            $divisions[$divisionKey]['id'] = $fundDivision['id'] ?? null;
            $divisions[$divisionKey]['nationwide'] = (bool) ($fundDivision['nationwide'] ?? false);
            $divisions[$divisionKey]['pool'] = ($divisions[$divisionKey]['pool'] ?? 0) + $cents;
            $divisions[$divisionKey]['to_extra'] = ($divisions[$divisionKey]['to_extra'] ?? 0) + $leftover;
            $divisions[$divisionKey]['to_company'] = ($divisions[$divisionKey]['to_company'] ?? 0) + $cents - array_sum($fundShares) - $leftover;
            $divisions[$divisionKey]['qualifiers'] = max($divisions[$divisionKey]['qualifiers'] ?? 0, count($qualifiedIds));
            foreach ($fundShares as $id => $amount) {
                $shares[$id] = ($shares[$id] ?? 0) + $amount;
                $shareDivision[$id] = $label;
                $shareDivisionId[$id] = $fundDivision['id'] ?? null;
                $shareNationwide[$id] = (bool) ($fundDivision['nationwide'] ?? false);
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
                'division_id' => $shareDivisionId[$id] ?? null,
                'nationwide' => $shareNationwide[$id] ?? false,
                'monthly_volume' => Money::decimal($volumes[$id] ?? 0),
                'share' => Money::decimal($amount),
            ];
        }
        usort($dealerRows, fn (array $a, array $b): int => [$a['division'], $a['name']] <=> [$b['division'], $b['name']]);

        return [
            'divisions' => array_map(fn (array $row): array => [
                'division' => $row['name'],
                'division_id' => $row['id'],
                'pool' => Money::decimal($row['pool']),
                'qualifiers' => (int) $row['qualifiers'],
                'nationwide' => $row['nationwide'],
                'per_head' => Money::decimal($row['qualifiers'] > 0 ? intdiv($row['pool'] - $row['to_company'] - $row['to_extra'], $row['qualifiers']) : 0),
                'to_company_profit' => Money::decimal($row['to_company']),
                'to_commission_extra_fund' => Money::decimal($row['to_extra']),
            ], array_values($divisions)),
            'dealers' => $dealerRows,
            'dealer_pool' => Money::decimal(array_sum(array_column($divisions, 'pool'))),
            'sub_dealer_salary' => Money::decimal($subDealerCents),
            'to_company_profit' => Money::decimal(array_sum(array_column($divisions, 'to_company'))),
            'to_commission_extra_fund' => Money::decimal(array_sum(array_column($divisions, 'to_extra'))),
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
     * @param  array<int, string|null>  $dealerKinds  each dealer's kind (DealerKinds), which sets his monthly target
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: list<int>}
     */
    private function fundQualifiers(CommissionAllocation $fund, string $kind, Collection $dealers, array $dealerDivisions, array $volumes, array $batchPolicies, array $buyerGeographies = [], array $dealerKinds = []): array
    {
        $source = $batchPolicies[$fund->order_id] ?? null;
        $policy = $source ? json_decode((string) $source, true, 512, JSON_THROW_ON_ERROR) : null;
        $rules = $policy['rules'] ?? $this->policies->snapshot()['rules'];
        // Owner's rule of 2026-11-21: one salary pool for all of Bangladesh, shared by every dealer in the country who met
        // his own target. Older orders keep the salary pool of the buyer's division.
        $nationwide = MonthlyTargets::usesMonthlyTargets($rules);
        $fundDivision = match (true) {
            $kind !== 'salary' => null,
            $nationwide => ['id' => null, 'name' => __('portal.report_area_nationwide'), 'nationwide' => true],
            default => $this->divisionOf($policy['geography'] ?? (array_key_exists($fund->order_id, $buyerGeographies)
                ? $buyerGeographies[$fund->order_id]
                : $this->orderBuyerGeography($fund))),
        };
        // The order's rules say the target: each dealer's one target by kind, or (older orders) one minimum for all.
        $qualifiedIds = $dealers
            ->filter(fn (User $user): bool => ($kind !== 'salary' || $nationwide || $this->sameDivision($dealerDivisions[(int) $user->id] ?? null, $fundDivision))
                && (PartnerBenefitControls::waivesSalaryVolume($user) || ($volumes[$user->id] ?? 0) >= MonthlyTargets::dealerTarget($rules, $dealerKinds[(int) $user->id] ?? null)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [$rules, $fundDivision, $qualifiedIds];
    }

    /**
     * Money nobody qualified for (a division with no qualifying dealer, or a
     * royalty rank with nobody at it) becomes company profit.
     */
    public function toCompanyProfit(CommissionAllocation $fund, int $amount, string $month, string $kind, ?string $division, ?string $reasonKey = null): int
    {
        // A refund before the close already shrank the fund. Keep this row on the same footing,
        // so a later refund takes back only its fair share (CommissionReversal starts from original_amount).
        // Two paisa amounts multiplied pass PHP's integer limit once a fund row is above about ৳3 crore,
        // so the ratio is worked out in exact NUMERIC arithmetic, as CommissionReversal does.
        $fundNow = Money::cents($fund->amount);
        $originalAmount = $fundNow > 0
            ? self::scaled($amount, Money::cents($fund->original_amount ?? $fund->amount), $fundNow)
            : $amount;

        return CommissionAllocation::query()->create([
            'order_id' => $fund->order_id,
            'user_id' => null,
            'category' => self::CompanyProfitCategory,
            'rate_percentage' => $fund->rate_percentage,
            'cash_basis' => $fund->cash_basis,
            'amount' => Money::decimal($amount),
            'original_amount' => Money::decimal($originalAmount),
            'status' => 'reserved',
            'metadata' => [
                'basis' => 'purchase_value',
                'period_month' => $month,
                'source_allocation_id' => $fund->id,
                'source_category' => $fund->category,
                'settlement_kind' => $kind,
                'division' => $division,
                'intended_user_id' => $fund->user_id,
                'place_code' => $fund->metadata['place_code'] ?? null,
                'place_code_id' => $fund->metadata['place_code_id'] ?? null,
                'retained_by' => 'company_profit_unclaimed',
                'reason_key' => $reasonKey ?? ($kind === 'salary' ? 'retained_reason_unclaimed_division_salary' : 'retained_reason_unclaimed_royalty'),
                // A key with a dot names its own language file (money_core.*); a bare key lives in portal.
                'reason' => $reasonKey !== null
                    ? __(str_contains($reasonKey, '.') ? $reasonKey : 'portal.'.$reasonKey, ['place' => $fund->metadata['place_code'] ?? '—'])
                    : ($kind === 'salary'
                        ? __('portal.retained_reason_unclaimed_division_salary', ['division' => $division ?? __('portal.report_area_unknown')])
                        : __('portal.retained_reason_unclaimed_royalty')),
            ],
        ])->id;
    }

    /** $amount × $part ÷ $whole, rounded to the nearest paisa, with no 64-bit overflow at any order size. */
    public static function scaled(int $amount, int $part, int $whole): int
    {
        return (int) DB::scalar('SELECT ROUND(CAST(? AS NUMERIC) * CAST(? AS NUMERIC) / CAST(? AS NUMERIC))', [$amount, $part, $whole]);
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
     * @param  array{id: int|null, name: string|null, nationwide?: bool}|null  $division
     * @return array{0: string, 1: string}
     */
    private function divisionSummaryKey(?array $division): array
    {
        $label = $division['name'] ?? __('portal.report_area_unknown');
        if (($division['nationwide'] ?? false) === true) {
            return ['nationwide', $label];
        }

        return [($division['id'] ?? null) !== null ? 'place:'.$division['id'] : 'unplaced', $label];
    }

    /** @return array<string, mixed>|null Fallback for funds whose order kept no geography snapshot. */
    private function orderBuyerGeography(CommissionAllocation $fund): ?array
    {
        $buyer = User::query()->whereKey(DB::table('orders')->where('id', $fund->order_id)->value('user_id'))->first();

        return $buyer ? $this->geography->forBuyer($buyer) : null;
    }

    /**
     * The weights of the four rank funds (MO, AM, SM, ED; stored key me), from their share percents.
     *
     * @param  array<string, mixed>  $parameters
     * @return list<int>
     */
    private function rankWeights(array $parameters): array
    {
        $weights = [];
        foreach (['mo', 'am', 'sm', 'me'] as $key) {
            $weights[] = Percentage::from((string) $parameters[$key.'_share_pct'])->basisPoints;
        }
        if (array_sum($weights) === 0) {
            throw ValidationException::withMessages(['rules' => __('portal.err_settle_royalty_shares')]);
        }

        return $weights;
    }

    /**
     * The paisa left over when a fund is split equally (owner, 2026-11-21) go to the Commission extra fund (the fund
     * that also takes the purchase surplus), on their own row with the reason "split_remainder", so Treasury and
     * reports show them apart. The row keeps its source fund, so a later refund takes back its fair share as it does
     * for the company profit rows.
     */
    public function toCommissionExtraFund(CommissionAllocation $fund, int $amount, string $month, string $kind): int
    {
        $fundNow = Money::cents($fund->amount);
        $originalAmount = $fundNow > 0 ? self::scaled($amount, Money::cents($fund->original_amount ?? $fund->amount), $fundNow) : $amount;

        return CommissionAllocation::query()->create([
            'order_id' => $fund->order_id,
            'user_id' => null,
            'category' => RetainedFundLedger::BenefitsCategory,
            'rate_percentage' => $fund->rate_percentage,
            'cash_basis' => $fund->cash_basis,
            'amount' => Money::decimal($amount),
            'original_amount' => Money::decimal($originalAmount),
            'status' => 'reserved',
            'metadata' => [
                'basis' => 'purchase_value',
                'period_month' => $month,
                'source_allocation_id' => $fund->id,
                'source_category' => $fund->category,
                'settlement_kind' => $kind,
                'retained_by' => 'owner_commission_benefits_fund',
                'owner_only' => true,
                'source' => self::SplitRemainder,
                'reason_key' => 'money_core.reason_split_remainder',
                'reason' => __('money_core.reason_split_remainder'),
            ],
        ])->id;
    }

    /** @param  User|null  $payee  the partner when already loaded, so a batch of payouts skips looking each one up */
    /**
     * The refunded part of each source order at the time of this settlement, so a later
     * refund never claws back the same money twice.
     *
     * @param  Collection<int, CommissionAllocation>  $funds
     * @return array<string, string> order id => refunded amount
     */
    private function refundedBasisByOrder(Collection $funds): array
    {
        $basis = [];
        foreach ($funds->pluck('order_id')->unique()->values()->chunk(5000) as $orderIds) {
            foreach (DB::table('commission_batches')->whereIn('order_id', $orderIds->all())->get(['order_id', 'refunded_amount']) as $batch) {
                $basis[(string) $batch->order_id] = (string) $batch->refunded_amount;
            }
        }

        return $basis;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertPayouts(array &$rows): void
    {
        foreach (array_chunk($rows, self::PayoutInsertChunk) as $chunk) {
            DB::table('commission_settlement_payouts')->insert($chunk);
        }
        $rows = [];
    }

    /**
     * Credits each partner's settled total for one chunk: the partners are locked in id order,
     * then one wallet credit each and one balanced journal entry for the chunk.
     *
     * @param  array<int, int>  $distributed  user id => cents
     */
    private function creditSettlement(array $distributed, string $month, string $kind): void
    {
        $distributed = array_filter($distributed, fn (int $amount): bool => $amount > 0);
        if ($distributed === []) {
            return;
        }
        CommissionWallet::lockPartners(...array_keys($distributed));
        $lines = [['user_id' => null, 'account' => 'commission_expense', 'debit' => array_sum($distributed), 'credit' => 0]];
        foreach ($distributed as $userId => $amount) {
            $lines[] = ['user_id' => $userId, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount];
        }
        $this->wallets->journal('commissions.settlement.'.$kind.'.'.$month.'.'.Str::uuid(), __('portal.jr_commission_settlement', [
            'kind' => __('portal.settle_kind_'.$kind),
            'month' => $month,
        ]), $lines);
        foreach ($distributed as $userId => $amount) {
            $this->wallets->credit($userId, $amount);
        }
    }

    /**
     * One portal notice per partner for the whole close, however many chunks paid them.
     *
     * @param  array<int, int>  $distributed  user id => cents
     * @param  array<int, User>  $payees
     */
    private function noticeSettlementCredits(array $distributed, string $month, string $kind, array $payees): void
    {
        foreach (array_filter($distributed, fn (int $amount): bool => $amount > 0) as $userId => $amount) {
            $partner = $payees[$userId] ?? User::find($userId);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            Audit::notice(
                $userId,
                __('portal.notice_settle_title', [], $locale),
                __('portal.notice_settle_body', [
                    'amount' => Money::display(Money::decimal($amount), $locale),
                    'kind' => __('portal.settle_kind_'.$kind, [], $locale),
                    'month' => self::monthLabel($month, $locale),
                ], $locale),
            );
        }
    }

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
        // The one-time rank prize (kind rank_mo … rank_me) is not a monthly commission and may be drawn from several funds:
        // incentive() sends one notice for the whole prize instead.
        if (str_starts_with($kind, 'rank_')) {
            return;
        }
        Audit::notice(
            $userId,
            __('portal.notice_settle_title', [], $locale),
            __('portal.notice_settle_body', [
                'amount' => Money::display(Money::decimal($amount), $locale),
                'kind' => $kindLabel,
                'month' => self::monthLabel($month, $locale),
            ], $locale),
        );
    }

    /** "2027-03" written for the partner: month word and year in the partner's language and digits. */
    public static function monthLabel(string $month, string $locale): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if ($date === false) {
            return LocalDigits::text($month, $locale);
        }

        return LocalDigits::text(CarbonImmutable::instance($date)->locale($locale)->isoFormat('MMMM YYYY'), $locale);
    }

    /** Leadership ranks from lowest to highest. */
    public const LeadershipRanks = ['mo', 'am', 'sm', 'me'];

    public static function rankAtLeast(string $held, string $wanted): bool
    {
        $heldIndex = array_search($held, self::LeadershipRanks, true);
        $wantedIndex = array_search($wanted, self::LeadershipRanks, true);

        return $heldIndex !== false && $wantedIndex !== false && $heldIndex >= $wantedIndex;
    }

    /**
     * The lowest rank this leader actually reached (rank history, up to the current rank)
     * whose one-time reward has not been paid yet.
     */
    private function firstUnpaidRank(DealerRank $rank): ?string
    {
        $reached = collect($rank->qualification_history ?? [])->pluck('rank')->push($rank->current_rank)
            ->filter(fn ($code): bool => is_string($code) && self::rankAtLeast((string) $rank->current_rank, $code))
            ->unique();
        $paid = DB::table('rank_incentive_awards')->where('user_id', $rank->user_id)->pluck('rank')->all();
        foreach (self::LeadershipRanks as $code) {
            if ($reached->contains($code) && ! in_array($code, $paid, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * What is still in one rank prize fund row: its amount less the prizes paid from it and the surplus a month close
     * moved to the company profit fund (ReserveService::closeRankPrizeSurplus()).
     */
    public static function incentiveLeft(CommissionAllocation $fund): int
    {
        return max(0, Money::cents($fund->amount)
            - Money::cents((string) ($fund->metadata['incentive_consumed'] ?? '0.00'))
            - Money::cents((string) ($fund->metadata['moved_to_profit'] ?? '0.00')));
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
            // A leader promoted twice before the reward ran (e.g. MO and AM within the hour) still
            // earned the lower rank: its unpaid reward is paid first, then the next run pays the next.
            $awardRank = $this->firstUnpaidRank($rank) ?? $rank->current_rank;
            if ($unconditionalIncentive && ($awardRank === 'dealer' || $awardRank === null)) {
                $awardRank = PartnerBenefitControls::forcedRoyaltyRank($user) ?? 'mo';
            }
            if (! in_array($user->role, ['dealer', 'sub_dealer'], true) || $user->status !== 'active' || ($awardRank === 'dealer' && ! $unconditionalIncentive)) {
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
                if (($paidAs['paid_as_rank'] ?? 'dealer') !== $rank->current_rank || ! self::rankAtLeast($rank->current_rank, $awardRank)) {
                    return ['success' => false, 'message' => __('portal.cmd_val_incentive_qualification', [
                        'rank' => $rank->current_rank,
                    ])];
                }
            }
            $amount = Money::cents((string) $rules['rank_incentive']['parameters'][$awardRank.'_bonus']);
            $funds = CommissionAllocation::where('category', 'rank_incentive_pool')->whereIn('status', ['pending', 'partially_settled'])->orderBy('id')->lockForUpdate()->get();
            $available = $funds->sum(fn ($fund): int => self::incentiveLeft($fund));
            if ($amount <= 0 || $available < $amount) {
                return ['success' => false, 'message' => __('portal.cmd_val_incentive_unfunded')];
            }
            $remaining = $amount;
            $sources = [];
            foreach ($funds as $fund) {
                $used = Money::cents((string) ($fund->metadata['incentive_consumed'] ?? '0.00'));
                $left = self::incentiveLeft($fund);
                $take = min($remaining, $left);
                if ($take > 0) {
                    $this->pay($fund, $userId, $take, $period, 'rank_'.$awardRank);
                    $sources[$fund->id] = Money::decimal($take);
                    $fund->update(['status' => $take === $left ? 'settled' : 'partially_settled',
                        'metadata' => [...$fund->metadata, 'incentive_consumed' => Money::decimal($used + $take)]]);
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            $locale = $user->preferredLocale();
            Audit::notice(
                $userId,
                __('portal.notice_rank_prize_title', [], $locale),
                __('portal.notice_rank_prize_body', ['amount' => Money::display(Money::decimal($amount), $locale), 'kind' => __('portal.settle_kind_rank_'.$awardRank, [], $locale)], $locale),
            );
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
                    __('portal.notice_commission_matured_body', ['amount' => Money::display($amountDecimal, $locale)], $locale),
                    'commission.posted',
                );
                $userId = (int) $fund->user_id;
                DB::afterCommit(function () use ($userId, $amountDecimal): void {
                    $partner = User::find($userId);
                    if (! $partner) {
                        return;
                    }
                    app(PartnerEventSms::class)->send(
                        $partner,
                        'commission.matured',
                        SmsText::make('sms.commission_matured', ['amount' => $amountDecimal]),
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

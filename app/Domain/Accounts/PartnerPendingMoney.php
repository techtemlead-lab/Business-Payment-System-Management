<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\MonthlyTargets;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\Percentage;
use App\Domain\Finance\ReserveService;
use App\Models\DealerRank;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The money a partner will get only if the month's target is met, and the month-end salary: shown
 * as pending, never as received. It reads rows that already exist and writes nothing.
 *
 *  - an area share held because the area target is not met (allocation held, reason "quota"),
 *  - a sub-dealer's salary set aside for the month (allocation pending, paid when the month closes),
 *  - a dealer's Salary (the nationwide `dealer_monthly_salary_pool`): a line with NO amount.
 *    The fund is shared equally among every dealer of the country who meets the target, and that number changes until
 *    the last day, so no figure is shown before the month closes (owner's rule of 2026-11-22).
 *
 * Rank royalty, the dealer's bonus and the area shares of new orders are decided together on the month-end day, so they
 * are listed together under `month_end`; area shares of older orders (released as soon as the target is met) stay under
 * `lines`. None of it is in the wallet, so it can be neither withdrawn nor used to pay an order. Rank, reward, car, bike,
 * royalty and tour money is never counted in the total, nor is the dealer's bonus; prizes already earned but not yet
 * paid (rank prize, bike, car) are listed apart under `rewards`. What the month-end close does with it (pay it, or send
 * an unearned share to the company profit fund) is unchanged.
 */
final class PartnerPendingMoney
{
    public function __construct(private CommissionQualification $qualification, private PartnerRewardsWaiting $rewards) {}

    /**
     * @param  array{rules?: array<string, array<string, mixed>>, rank?: ?DealerRank, milestones?: Collection<string, RewardMilestoneProgress>}  $rewardContext  what the caller already holds, so the prize lines read nothing twice
     * @return array{
     *   role: string,
     *   total: string,
     *   count: int,
     *   more: int,
     *   has_estimate: bool,
     *   awaiting_close: list<array{month: string, amount: string}>,
     *   lines: list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string, reason: string, estimate: bool}>,
     *   month_end: list<array{id: string, head: string, what: string, month: string, amount: ?string, order_number: ?string, reason: string, estimate: bool}>,
     *   paid: list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string}>,
     *   rewards: list<array{id: string, head: string, what: string, amount: ?string, reason: string, state: string, since: ?string}>
     * }
     */
    public function for(User $partner, int $limit = 20, bool $withPaid = true, array $rewardContext = []): array
    {
        // Area income belongs to dealers only: a sub-dealer is never seated, so he reads and sees no area line.
        $isDealer = $partner->role === 'dealer';
        $area = $isDealer ? $this->areaLines($partner, $limit) : ['total' => 0, 'count' => 0, 'closed' => 0, 'lines' => []];
        $awaiting = [];
        // A share of a month that has ended without the target is already lost; it only waits for the month close to
        // move it. It is shown as not received, never as pending again.
        if ($area['closed'] > 0) {
            $missed = $this->missedAfterMonthEnd($partner);
            if ($missed['ids'] !== []) {
                $area = $this->areaLines($partner, $limit, $missed['ids']);
                $awaiting = $missed['months'];
            }
        }
        $salary = $this->salaryLines($partner);
        $withAmount = array_values(array_filter($salary, fn (array $line): bool => $line['amount'] !== null));
        $total = $area['total'] + array_sum(array_map(fn (array $line): int => FundCatalog::centsOf((string) $line['amount']), $withAmount));
        $monthlyArea = array_values(array_filter($area['lines'], fn (array $line): bool => str_starts_with($line['reason'], 'month_end')));
        $olderArea = array_values(array_filter($area['lines'], fn (array $line): bool => ! str_starts_with($line['reason'], 'month_end')));
        $rewards = $this->rewards->for($partner, $rewardContext);
        // Rank royalty is a month-end item like the bonus and the area shares, not an earned prize.
        $royalty = array_map(fn (array $line): array => [
            'id' => $line['id'], 'head' => $line['head'], 'what' => $line['what'], 'month' => CommissionQualification::currentMonth(),
            'amount' => null, 'order_number' => null, 'reason' => $line['reason'], 'estimate' => false,
        ], array_values(array_filter($rewards, fn (array $line): bool => $line['head'] === 'royalty')));

        return [
            'role' => (string) $partner->role,
            'total' => FundCatalog::decimalOf($total),
            'count' => $area['count'] + count($withAmount),
            'more' => max(0, $area['count'] - count($area['lines'])),
            'has_estimate' => in_array(true, array_column($withAmount, 'estimate'), true),
            'awaiting_close' => $awaiting,
            'lines' => $olderArea,
            // Decided together on the month-end day: rank royalty, the salary or bonus, and the area shares of new orders.
            'month_end' => [...$royalty, ...$salary, ...$monthlyArea],
            'paid' => $withPaid && $isDealer ? $this->paidAreaLines($partner) : [],
            // Earned prizes not in the wallet yet (rank prize, bike, car). Kept apart: they are not part of the pending total.
            'rewards' => array_values(array_filter($rewards, fn (array $line): bool => $line['head'] !== 'royalty')),
        ];
    }

    /**
     * Area shares held for the target: the lines (newest first, up to $limit) and the cents of all of them.
     * Only months under the target rule count; an older held share is not a target matter.
     *
     * @param  list<int>  $exclude  allocation ids that are not pending (their month ended without the target)
     * @return array{total: int, count: int, closed: int, lines: list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string, reason: string, estimate: bool}>}
     */
    private function areaLines(User $partner, int $limit, array $exclude = []): array
    {
        $rows = DB::table('commission_allocations as a')
            ->leftJoin('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.user_id', $partner->id)->where('a.status', 'held')->where('a.amount', '>', 0)
            ->where('a.category', 'like', 'area\_%')
            ->whereRaw("a.metadata->>'hold_reason' = 'quota'")
            ->whereRaw("a.metadata->>'period_month' >= ?", [ReserveService::AreaTargetRuleFirstMonth])
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('a.id', $exclude))
            ->orderByDesc('a.id')
            ->limit($limit)
            ->selectRaw("a.id, a.amount, a.category, o.number AS order_number, a.metadata->>'period_month' AS month, a.metadata->>'monthly_quota' AS quota, a.metadata->>'monthly_close' AS monthly_close, SUM(a.amount) OVER () AS total, COUNT(*) OVER () AS count, COUNT(*) FILTER (WHERE a.metadata->>'period_month' < ?) OVER () AS closed", [CommissionQualification::currentMonth()])
            ->get();
        if ($rows->isEmpty()) {
            return ['total' => 0, 'count' => 0, 'closed' => 0, 'lines' => []];
        }
        // A share of a new order (owner's rule of 2026-11-21, `monthly_close`) is decided on the month-end day, met target
        // or not. An older order's share whose month already reached the target only waits for the office to release it.
        $volumes = [];
        foreach ($rows->pluck('month')->unique() as $month) {
            $volumes[(string) $month] = $this->qualification->monthlyVolume((int) $partner->id, (string) $month);
        }
        $waived = PartnerBenefitControls::waivesSalaryVolume($partner);
        $customMonthlyTarget = PartnerBenefitControls::customMoneyCents($partner, 'monthly_target');

        return [
            'total' => FundCatalog::centsOf($rows->first()->total),
            'count' => (int) $rows->first()->count,
            'closed' => (int) $rows->first()->closed,
            'lines' => $rows->map(function (object $row) use ($volumes, $waived, $customMonthlyTarget): array {
                $targetCents = $waived
                    ? 0
                    : ($row->monthly_close === 'true' && $customMonthlyTarget !== null ? $customMonthlyTarget : ($row->quota !== null ? FundCatalog::centsOf($row->quota) : null));
                $met = $waived || ($targetCents !== null && $volumes[(string) $row->month] >= $targetCents);
                $reason = $row->monthly_close === 'true'
                    ? ($met ? 'month_end_area_met' : 'month_end_area')
                    : ($met ? 'target_met_waiting' : 'target_not_met');

                return [
                    'id' => 'area-'.$row->id,
                    'head' => (string) $row->category,
                    'what' => PartnerIncomeHeads::label(PartnerIncomeHeads::headOf(null, (string) $row->category, null, null)),
                    'month' => (string) $row->month,
                    'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount)),
                    'order_number' => $row->order_number !== null ? (string) $row->order_number : null,
                    'reason' => $reason,
                    'estimate' => false,
                ];
            })->all(),
        ];
    }

    /**
     * Held shares of months that have ended where the month's volume stayed below the target. The month close
     * forfeits them to the company fund; until it runs they are shown as not received, not as pending.
     *
     * @return array{ids: list<int>, months: list<array{month: string, amount: string}>}
     */
    private function missedAfterMonthEnd(User $partner): array
    {
        $rows = DB::table('commission_allocations')
            ->where('user_id', $partner->id)->where('status', 'held')->where('amount', '>', 0)
            ->where('category', 'like', 'area\_%')
            ->whereRaw("metadata->>'hold_reason' = 'quota'")
            ->whereRaw("metadata->>'period_month' >= ?", [ReserveService::AreaTargetRuleFirstMonth])
            ->whereRaw("metadata->>'period_month' < ?", [CommissionQualification::currentMonth()])
            ->selectRaw("id, amount, metadata->>'period_month' AS month, metadata->>'monthly_quota' AS quota")
            ->get();
        if (PartnerBenefitControls::waivesSalaryVolume($partner)) {
            return ['ids' => [], 'months' => []];
        }
        $customMonthlyTarget = PartnerBenefitControls::customMoneyCents($partner, 'monthly_target');
        $volumes = [];
        $ids = [];
        $months = [];
        foreach ($rows as $row) {
            $month = (string) $row->month;
            $volumes[$month] ??= $this->qualification->monthlyVolume((int) $partner->id, $month);
            $targetCents = $customMonthlyTarget ?? ($row->quota !== null ? FundCatalog::centsOf($row->quota) : null);
            if ($targetCents !== null && $volumes[$month] < $targetCents) {
                $ids[] = (int) $row->id;
                $months[$month] = ($months[$month] ?? 0) + FundCatalog::centsOf($row->amount);
            }
        }
        krsort($months);

        return [
            'ids' => $ids,
            'months' => array_map(fn (string $month, int $cents): array => ['month' => $month, 'amount' => FundCatalog::decimalOf($cents)], array_keys($months), array_values($months)),
        ];
    }

    /**
     * The per-month "not received" list: shares the month close already forfeited plus those it has not reached yet.
     *
     * @param  list<array{month: string, amount: string}>  $forfeited
     * @param  list<array{month: string, amount: string}>  $awaitingClose
     * @return list<array{month: string, amount: string}>
     */
    public static function notReceived(array $forfeited, array $awaitingClose): array
    {
        $byMonth = [];
        foreach ([...$forfeited, ...$awaitingClose] as $row) {
            $byMonth[$row['month']] = ($byMonth[$row['month']] ?? 0) + FundCatalog::centsOf($row['amount']);
        }
        krsort($byMonth);

        return array_map(fn (string $month, int $cents): array => ['month' => $month, 'amount' => FundCatalog::decimalOf($cents)], array_keys($byMonth), array_values($byMonth));
    }

    /**
     * The month-end salary: a sub-dealer's amount set aside so far (one line per month, his own purchases decide it), or
     * for a dealer one line per open month of the nationwide Salary with no amount: how many
     * dealers qualify is known only when the month ends, so any figure before that could be wrong.
     *
     * @return list<array{id: string, head: string, what: string, month: string, amount: ?string, order_number: ?string, reason: string, estimate: bool}>
     */
    private function salaryLines(User $partner): array
    {
        $what = PartnerIncomeHeads::label('salary');
        $line = fn (string $month, int $cents, bool $estimate): array => [
            'id' => 'salary-'.$month, 'head' => 'salary', 'what' => $what, 'month' => $month,
            'amount' => FundCatalog::decimalOf($cents), 'order_number' => null, 'reason' => 'month_end', 'estimate' => $estimate,
        ];
        if ($partner->role === 'sub_dealer') {
            $rows = DB::table('commission_allocations')->where('user_id', $partner->id)->where('category', 'sub_dealer_salary')->where('status', 'pending')
                ->where('amount', '>', 0)->groupByRaw("metadata->>'period_month'")->orderByRaw("metadata->>'period_month' DESC")
                ->selectRaw("metadata->>'period_month' AS month, SUM(amount) AS amount")->get();
            if ($rows->isEmpty()) {
                return [];
            }
            // Owner's rule of 2026-11-21: only the salary step his own month reached is his, so the line shows that part
            // of what was set aside (an estimate until the month closes). Older rules showed all of it.
            $rules = PartnerBenefitControls::effectiveSubDealerRules($partner, app(CommissionPolicy::class)->snapshot(false)['rules']);
            $setAsideRate = Percentage::from((string) $rules['sub_dealer_salary']['percentage'])->basisPoints;
            $waived = PartnerBenefitControls::waivesSalaryVolume($partner);

            return $rows->map(function (object $row) use ($partner, $rules, $setAsideRate, $line, $waived): array {
                $cents = FundCatalog::centsOf($row->amount);
                if ($waived) {
                    return $line((string) $row->month, $cents, false);
                }
                $step = MonthlyTargets::salaryBasisPoints($rules, $this->qualification->monthlyVolume((int) $partner->id, (string) $row->month));

                return $step === null ? $line((string) $row->month, $cents, false) : $line((string) $row->month, MonthlyTargets::tierShare($cents, $step, $setAsideRate), true);
            })->filter(fn (array $item): bool => FundCatalog::centsOf($item['amount']) > 0)->values()->all();
        }
        if ($partner->role !== 'dealer' || $partner->status !== 'active') {
            return [];
        }

        return DB::table('commission_allocations')->where('category', 'dealer_monthly_salary_pool')->where('status', 'pending')->where('amount', '>', 0)
            ->whereRaw("metadata->>'period_month' <= ?", [CommissionQualification::currentMonth()])
            ->selectRaw("DISTINCT metadata->>'period_month' AS month")->orderBy('month', 'desc')->pluck('month')
            ->map(fn (mixed $month): array => [
                'id' => 'salary-'.$month, 'head' => 'salary', 'what' => $what, 'month' => (string) $month,
                'amount' => null, 'order_number' => null, 'reason' => 'salary_shared_month_end', 'estimate' => false,
            ])->all();
    }

    /**
     * Area shares paid into the wallet this month because the target was met, newest first.
     *
     * @return list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string}>
     */
    private function paidAreaLines(User $partner): array
    {
        [$start] = CommissionQualification::period(CommissionQualification::currentMonth());

        return DB::table('commission_allocations as a')
            ->leftJoin('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.user_id', $partner->id)->whereIn('a.status', ['available', 'maturing'])
            ->where('a.category', 'like', 'area\_%')
            ->whereRaw("a.metadata->>'hold_reason' = 'quota'")
            ->whereRaw("(a.metadata->>'released_at')::timestamptz >= ?", [$start->utc()->toIso8601String()])
            ->orderByDesc('a.id')->limit(10)
            ->selectRaw("a.id, a.amount, a.category, o.number AS order_number, a.metadata->>'period_month' AS month")
            ->get()
            ->map(fn (object $row): array => [
                'id' => 'paid-'.$row->id,
                'head' => (string) $row->category,
                'what' => PartnerIncomeHeads::label(PartnerIncomeHeads::headOf(null, (string) $row->category, null, null)),
                'month' => (string) $row->month,
                'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount)),
                'order_number' => $row->order_number !== null ? (string) $row->order_number : null,
            ])->all();
    }
}

<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\ReserveService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The money a partner will get only if the month's target is met, and the month-end salary: shown
 * as pending, never as received. It reads rows that already exist and writes nothing.
 *
 *  - an area share held because the area target is not met (allocation held, reason "quota"),
 *  - a sub-dealer's salary set aside for the month (allocation pending, paid when the month closes),
 *  - a dealer's salary share, worked out with the same rules as the month close (an estimate).
 *
 * None of it is in the wallet, so it can be neither withdrawn nor used to pay an order. Rank,
 * reward, car, bike, royalty and tour money is never counted here. What the month-end close does
 * with it (pay it, or send an unearned share to the company profit fund) is unchanged.
 */
final class PartnerPendingMoney
{
    /** Seconds the dealers' salary shares of one month are kept, so a busy page does not recompute the plan. */
    private const SalaryShareSeconds = 60;

    public function __construct(private CommissionQualification $qualification, private CommissionSettlement $settlement) {}

    /**
     * @return array{
     *   total: string,
     *   count: int,
     *   has_estimate: bool,
     *   lines: list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string, reason: string, estimate: bool}>,
     *   paid: list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string}>
     * }
     */
    public function for(User $partner, int $limit = 20, bool $withPaid = true): array
    {
        $area = $this->areaLines($partner, $limit);
        $salary = $this->salaryLines($partner);
        $lines = [...$area['lines'], ...$salary];
        $total = $area['total'] + array_sum(array_map(fn (array $line): int => FundCatalog::centsOf($line['amount']), $salary));

        return [
            'total' => FundCatalog::decimalOf($total),
            'count' => $area['count'] + count($salary),
            'has_estimate' => in_array(true, array_column($salary, 'estimate'), true),
            'lines' => $lines,
            'paid' => $withPaid ? $this->paidAreaLines($partner) : [],
        ];
    }

    /**
     * Area shares held for the target: the lines (newest first, up to $limit) and the cents of all of them.
     * Only months under the target rule count; an older held share is not a target matter.
     *
     * @return array{total: int, count: int, lines: list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string, reason: string, estimate: bool}>}
     */
    private function areaLines(User $partner, int $limit): array
    {
        $rows = DB::table('commission_allocations as a')
            ->leftJoin('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.user_id', $partner->id)->where('a.status', 'held')->where('a.amount', '>', 0)
            ->where('a.category', 'like', 'area\_%')
            ->whereRaw("a.metadata->>'hold_reason' = 'quota'")
            ->whereRaw("a.metadata->>'period_month' >= ?", [ReserveService::AreaTargetRuleFirstMonth])
            ->orderByDesc('a.id')
            ->limit($limit)
            ->selectRaw("a.id, a.amount, a.category, o.number AS order_number, a.metadata->>'period_month' AS month, a.metadata->>'monthly_quota' AS quota, SUM(a.amount) OVER () AS total, COUNT(*) OVER () AS count")
            ->get();
        if ($rows->isEmpty()) {
            return ['total' => 0, 'count' => 0, 'lines' => []];
        }
        // A share whose month already reached the target is only waiting for the office to release it.
        $volumes = [];
        foreach ($rows->pluck('month')->unique() as $month) {
            $volumes[(string) $month] = $this->qualification->monthlyVolume((int) $partner->id, (string) $month);
        }

        return [
            'total' => FundCatalog::centsOf($rows->first()->total),
            'count' => (int) $rows->first()->count,
            'lines' => $rows->map(fn (object $row): array => [
                'id' => 'area-'.$row->id,
                'head' => (string) $row->category,
                'what' => PartnerIncomeHeads::label(PartnerIncomeHeads::headOf(null, (string) $row->category, null, null)),
                'month' => (string) $row->month,
                'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($row->amount)),
                'order_number' => $row->order_number !== null ? (string) $row->order_number : null,
                'reason' => $row->quota !== null && $volumes[(string) $row->month] >= FundCatalog::centsOf($row->quota) ? 'target_met_waiting' : 'target_not_met',
                'estimate' => false,
            ])->all(),
        ];
    }

    /**
     * The month-end salary: a sub-dealer's amount set aside so far (one line per month), or a dealer's
     * share by the month close rules. Both wait for the month to close.
     *
     * @return list<array{id: string, head: string, what: string, month: string, amount: string, order_number: ?string, reason: string, estimate: bool}>
     */
    private function salaryLines(User $partner): array
    {
        $what = PartnerIncomeHeads::label('salary');
        $line = fn (string $month, int $cents, bool $estimate): array => [
            'id' => 'salary-'.$month, 'head' => 'salary', 'what' => $what, 'month' => $month,
            'amount' => FundCatalog::decimalOf($cents), 'order_number' => null, 'reason' => 'month_end', 'estimate' => $estimate,
        ];
        if ($partner->role === 'sub_dealer') {
            return DB::table('commission_allocations')->where('user_id', $partner->id)->where('category', 'sub_dealer_salary')->where('status', 'pending')
                ->where('amount', '>', 0)->groupByRaw("metadata->>'period_month'")->orderByRaw("metadata->>'period_month' DESC")
                ->selectRaw("metadata->>'period_month' AS month, SUM(amount) AS amount")->get()
                ->map(fn (object $row): array => $line((string) $row->month, FundCatalog::centsOf($row->amount), false))->all();
        }
        if ($partner->role !== 'dealer' || $partner->status !== 'active') {
            return [];
        }
        $lines = [];
        $months = DB::table('commission_allocations')->where('category', 'dealer_monthly_salary_pool')->where('status', 'pending')
            ->whereRaw("metadata->>'period_month' <= ?", [CommissionQualification::currentMonth()])
            ->selectRaw("DISTINCT metadata->>'period_month' AS month")->orderBy('month', 'desc')->pluck('month');
        foreach ($months as $month) {
            $share = $this->salaryShares((string) $month)[(int) $partner->id] ?? 0;
            if ($share > 0) {
                $lines[] = $line((string) $month, $share, true);
            }
        }

        return $lines;
    }

    /**
     * What each qualified dealer would be paid if the month closed now, in cents, by dealer id.
     * Only dealers who already meet the salary target are in it.
     *
     * @return array<int, int>
     */
    private function salaryShares(string $month): array
    {
        return Cache::remember('wallet-pending:salary-shares:'.$month, self::SalaryShareSeconds, function () use ($month): array {
            $shares = [];
            foreach ($this->settlement->salaryPlan($month)['dealers'] as $row) {
                $shares[(int) $row['user_id']] = FundCatalog::centsOf($row['share']);
            }

            return $shares;
        });
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

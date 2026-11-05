<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionQualification;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The monthly top-sellers board: dealers ranked by their own confirmed purchases
 * (real taka order value) in a Dhaka calendar month.
 *
 * Only purchases are ranked and shown. Earnings and commission never appear here,
 * because Bangladesh's MLM Act 2013 (s.23) forbids income claims.
 *
 * @phpstan-type BoardEntry array{
 *   rank: int,
 *   name: string,
 *   dealer_code: string|null,
 *   place: string|null,
 *   amount: string|null,
 *   percent: float,
 *   is_me: bool,
 *   hidden?: bool
 * }
 */
final class TopSellersBoard
{
    /** How many places each list shows. */
    public const Size = 10;

    /** Closed months a partner can look back on, besides the current one. */
    public const ClosedMonths = 2;

    /**
     * This month first, then the last closed months, as "Y-m" in Dhaka time.
     *
     * @return list<string>
     */
    public static function months(): array
    {
        $timezone = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $current = CarbonImmutable::createFromFormat('!Y-m', CommissionQualification::currentMonth(), $timezone);
        $months = [];
        foreach (range(0, self::ClosedMonths) as $offset) {
            $months[] = $current->subMonthsNoOverflow($offset)->format('Y-m');
        }

        return $months;
    }

    /** The asked month when it is one of the offered months, otherwise this month. */
    public static function resolveMonth(?string $month): string
    {
        $months = self::months();

        return in_array($month, $months, true) ? (string) $month : $months[0];
    }

    /**
     * The board a dealer or sub-dealer sees: the national top 10 and the top 10 of
     * their own division, without dealers who chose to stay out of the public list,
     * plus the viewer's own place when they are a ranked dealer.
     *
     * @return array{
     *   month: string,
     *   months: list<string>,
     *   show_amounts: bool,
     *   division: string|null,
     *   national: list<BoardEntry>,
     *   divisional: list<BoardEntry>,
     *   me: array{national_rank: int, division_rank: int|null, amount: string|null, percent: float, in_top: bool}|null,
     *   can_rank: bool
     * }
     */
    public function forPartner(User $viewer, ?string $month): array
    {
        $month = self::resolveMonth($month);
        $division = $this->cleanDivision($viewer->division);
        $showAmounts = (bool) (DashboardManagement::get()['top_sellers_show_amounts'] ?? true);
        $viewerId = $viewer->role === 'dealer' ? (int) $viewer->id : null;
        $rows = $this->rankedRows($month, $division, $viewerId);

        $national = [];
        $divisional = [];
        $me = null;
        foreach ($rows as $row) {
            $isMe = $viewerId !== null && (int) $row->id === $viewerId;
            $listed = (bool) $row->directory_visible || $isMe;
            if ($listed && (int) $row->national_rank <= self::Size) {
                $national[] = $this->entry($row, (int) $row->national_rank, (string) $row->national_top, $isMe, $showAmounts);
            }
            if ($listed && $division !== null && $row->division === $division && (int) $row->division_rank <= self::Size) {
                $divisional[] = $this->entry($row, (int) $row->division_rank, (string) $row->division_top, $isMe, $showAmounts);
            }
            if ($isMe) {
                $me = [
                    'national_rank' => (int) $row->national_rank,
                    'division_rank' => $division !== null && $row->division === $division ? (int) $row->division_rank : null,
                    'amount' => $showAmounts ? Money::decimal(Money::cents((string) $row->amount)) : null,
                    'percent' => $this->percent((string) $row->amount, (string) $row->national_top),
                    'in_top' => (int) $row->national_rank <= self::Size,
                ];
            }
        }

        return [
            'month' => $month,
            'months' => self::months(),
            'show_amounts' => $showAmounts,
            'division' => $division,
            'national' => $national,
            'divisional' => $this->sortByRank($divisional),
            'me' => $me,
            'can_rank' => $viewerId !== null,
        ];
    }

    /**
     * The office view: the same ranking with amounts always shown, dealers who
     * are hidden from the public list marked, and any division to compare.
     *
     * @return array{
     *   month: string,
     *   months: list<string>,
     *   show_amounts: bool,
     *   division: string|null,
     *   divisions: list<string>,
     *   national: list<BoardEntry>,
     *   divisional: list<BoardEntry>
     * }
     */
    public function forStaff(?string $month, ?string $division): array
    {
        $month = self::resolveMonth($month);
        $division = $this->cleanDivision($division);
        $rows = $this->rankedRows($month, $division, null);

        $national = [];
        $divisional = [];
        foreach ($rows as $row) {
            if ((int) $row->national_rank <= self::Size) {
                $national[] = $this->entry($row, (int) $row->national_rank, (string) $row->national_top, false, true) + ['hidden' => ! $row->directory_visible];
            }
            if ($division !== null && $row->division === $division && (int) $row->division_rank <= self::Size) {
                $divisional[] = $this->entry($row, (int) $row->division_rank, (string) $row->division_top, false, true) + ['hidden' => ! $row->directory_visible];
            }
        }

        return [
            'month' => $month,
            'months' => self::months(),
            'show_amounts' => true,
            'division' => $division,
            'divisions' => DB::table('users')
                ->where('role', 'dealer')
                ->where('status', 'active')
                ->whereNotNull('division')
                ->where('division', '<>', '')
                ->distinct()
                ->orderBy('division')
                ->pluck('division')
                ->map(fn ($name): string => (string) $name)
                ->values()
                ->all(),
            'national' => $national,
            'divisional' => $this->sortByRank($divisional),
        ];
    }

    /**
     * One grouped query: each active dealer's confirmed purchases in the month,
     * ranked nationally and inside their division. Only the rows a board can show
     * come back: the national top, the asked division's top and the viewer.
     *
     * @return list<object>
     */
    private function rankedRows(string $month, ?string $division, ?int $viewerId): array
    {
        [$start, $end] = CommissionQualification::period($month);
        $statuses = OrderListFilter::Confirmed;
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));

        $sql = <<<SQL
            WITH totals AS (
                SELECT orders.user_id, SUM(orders.total) AS amount
                FROM orders
                WHERE orders.status IN ({$statusPlaceholders})
                  AND orders.created_at >= ?
                  AND orders.created_at < ?
                GROUP BY orders.user_id
            ), ranked AS (
                SELECT users.id, users.name, users.dealer_code, users.district, users.division, users.directory_visible, totals.amount,
                    RANK() OVER (ORDER BY totals.amount DESC) AS national_rank,
                    RANK() OVER (PARTITION BY users.division ORDER BY totals.amount DESC) AS division_rank,
                    MAX(totals.amount) OVER () AS national_top,
                    MAX(totals.amount) OVER (PARTITION BY users.division) AS division_top
                FROM totals
                JOIN users ON users.id = totals.user_id
                WHERE users.role = 'dealer'
                  AND users.status = 'active'
                  AND totals.amount > 0
            )
            SELECT * FROM ranked
            WHERE national_rank <= ?
               OR (division = ? AND division_rank <= ?)
               OR id = ?
            ORDER BY national_rank, name, id
            SQL;

        return DB::select($sql, [
            ...$statuses,
            $start->utc(),
            $end->utc(),
            self::Size,
            $division,
            self::Size,
            $viewerId,
        ]);
    }

    /**
     * @param  object  $row  One row from rankedRows().
     * @return BoardEntry
     */
    private function entry(object $row, int $rank, string $top, bool $isMe, bool $showAmounts): array
    {
        $district = trim((string) $row->district);
        $division = trim((string) $row->division);

        return [
            'rank' => $rank,
            'name' => (string) $row->name,
            'dealer_code' => $row->dealer_code !== null ? (string) $row->dealer_code : null,
            'place' => $district !== '' ? $district : ($division !== '' ? $division : null),
            'amount' => $showAmounts ? Money::decimal(Money::cents((string) $row->amount)) : null,
            'percent' => $this->percent((string) $row->amount, $top),
            'is_me' => $isMe,
        ];
    }

    /** Bar length against the month's number one, 0–100. */
    private function percent(string $amount, string $top): float
    {
        $topCents = Money::cents($top);
        if ($topCents <= 0) {
            return 0.0;
        }

        return min(100.0, round(Money::cents($amount) / $topCents * 100, 1));
    }

    /**
     * @param  list<BoardEntry>  $entries
     * @return list<BoardEntry>
     */
    private function sortByRank(array $entries): array
    {
        usort($entries, fn (array $left, array $right): int => [$left['rank'], $left['name']] <=> [$right['rank'], $right['name']]);

        return $entries;
    }

    private function cleanDivision(?string $division): ?string
    {
        $division = trim((string) $division);

        return $division === '' ? null : $division;
    }
}

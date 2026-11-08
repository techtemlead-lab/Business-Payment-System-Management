<?php

namespace App\Domain\Orders;

use App\Models\PartnerContest;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardManagement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Contests and their live standings. Standings only read orders and accounts: a contest never
 * writes to commission, wallets or settlements, and the company gives any prize by hand.
 *
 * Purchases count the same way as the top-sellers board: confirmed orders by the date placed.
 * Phone numbers are never part of the standings.
 */
final class PartnerContests
{
    /** How many places each contest lists. */
    public const TopShown = 20;

    /** How many contests a page shows; one standings query is run for each. */
    public const MaxShown = 6;

    /** An ended contest stays on the page this long, so its final standings can be read. */
    public const RecentDays = 30;

    public static function canManage(User $user): bool
    {
        return $user->owner();
    }

    /** @return array{can_manage: bool, metrics: list<string>, contests: list<array<string, mixed>>} */
    public function forViewer(User $viewer): array
    {
        $canManage = self::canManage($viewer);
        $today = CarbonImmutable::now((string) config('madina.business_timezone'))->startOfDay();
        $contests = PartnerContest::query()
            ->whereDate('ends_on', '>=', $today->subDays(self::RecentDays)->toDateString())
            ->when(! $canManage, fn ($query) => $query->whereDate('starts_on', '<=', $today->toDateString()))
            ->orderByDesc('ends_on')
            ->orderByDesc('id')
            ->limit(self::MaxShown)
            ->get();
        $showAmounts = $canManage || (bool) (DashboardManagement::get()['top_sellers_show_amounts'] ?? true);
        $viewerId = in_array($viewer->role, ['dealer', 'sub_dealer'], true) ? (int) $viewer->id : null;

        return [
            'can_manage' => $canManage,
            'metrics' => PartnerContest::Metrics,
            'contests' => $contests->map(fn (PartnerContest $contest): array => $this->present($contest, $today, $viewerId, $showAmounts))->all(),
        ];
    }

    /**
     * @param  array{title: string, starts_on: string, ends_on: string, metric: string, prize_text?: string|null}  $data
     */
    public function add(User $owner, array $data): PartnerContest
    {
        abort_unless(self::canManage($owner), 403);
        $contest = PartnerContest::query()->create([...$data, 'created_by' => $owner->id]);
        Audit::record($owner->id, 'contest.added', (string) $contest->id, ['title' => $contest->title, 'metric' => $contest->metric]);

        return $contest;
    }

    public function remove(User $owner, PartnerContest $contest): void
    {
        abort_unless(self::canManage($owner), 403);
        Audit::record($owner->id, 'contest.removed', (string) $contest->id, ['title' => $contest->title]);
        $contest->delete();
    }

    /** @return array<string, mixed> */
    private function present(PartnerContest $contest, CarbonImmutable $today, ?int $viewerId, bool $showAmounts): array
    {
        $startsOn = $contest->starts_on->toDateString();
        $endsOn = $contest->ends_on->toDateString();
        $status = match (true) {
            $startsOn > $today->toDateString() => 'upcoming',
            $endsOn < $today->toDateString() => 'ended',
            default => 'running',
        };
        $showValues = $contest->metric !== 'purchases' || $showAmounts;
        $rows = $status === 'upcoming' ? [] : $this->rankedRows($contest, $viewerId);
        $standings = [];
        $me = null;
        foreach ($rows as $row) {
            $isMe = $viewerId !== null && (int) $row->id === $viewerId;
            $value = $showValues ? $this->value((string) $contest->metric, (string) $row->value) : null;
            if ((int) $row->position <= self::TopShown) {
                $standings[] = [
                    'rank' => (int) $row->position,
                    'name' => (string) $row->name,
                    'dealer_code' => $row->dealer_code !== null ? (string) $row->dealer_code : null,
                    'place' => trim((string) $row->district) !== '' ? trim((string) $row->district) : (trim((string) $row->division) ?: null),
                    'value' => $value,
                    'is_me' => $isMe,
                ];
            }
            if ($isMe) {
                $me = ['rank' => (int) $row->position, 'value' => $value];
            }
        }

        return [
            'id' => (int) $contest->id,
            'title' => (string) $contest->title,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'metric' => (string) $contest->metric,
            'prize_text' => $contest->prize_text,
            'status' => $status,
            'standings' => $standings,
            'me' => $me,
        ];
    }

    private function value(string $metric, string $value): string
    {
        return $metric === 'purchases' ? Money::decimal(Money::cents($value)) : (string) (int) $value;
    }

    /**
     * One grouped query for a contest: the top places and the viewer's own row. Only active
     * dealers and sub-dealers with something to count are ranked; tied partners share a place.
     *
     * @return array<int, object>
     */
    private function rankedRows(PartnerContest $contest, ?int $viewerId): array
    {
        $timezone = (string) config('madina.business_timezone');
        $start = CarbonImmutable::parse($contest->starts_on->toDateString(), $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($contest->ends_on->toDateString(), $timezone)->addDay()->startOfDay()->utc();
        [$totals, $bindings] = $this->totalsQuery((string) $contest->metric, $start, $end);

        return DB::select(<<<SQL
            WITH totals AS ({$totals}), ranked AS (
                SELECT users.id, users.name, users.dealer_code, users.district, users.division, totals.value,
                    RANK() OVER (ORDER BY totals.value DESC) AS position
                FROM totals
                JOIN users ON users.id = totals.user_id
                WHERE users.role IN ('dealer', 'sub_dealer')
                  AND users.status = 'active'
                  AND totals.value > 0
            )
            SELECT * FROM ranked
            WHERE position <= ? OR id = ?
            ORDER BY position, name, id
            SQL, [...$bindings, self::TopShown, $viewerId]);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function totalsQuery(string $metric, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($metric === 'new_partners') {
            return [
                "SELECT sponsor_id AS user_id, COUNT(*) AS value FROM users WHERE sponsor_id IS NOT NULL AND status = 'active' AND role IN ('dealer', 'sub_dealer') AND created_at >= ? AND created_at < ? GROUP BY sponsor_id",
                [$start, $end],
            ];
        }
        $statuses = OrderListFilter::Confirmed;
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $aggregate = $metric === 'orders' ? 'COUNT(*)' : 'SUM(total)';

        return [
            "SELECT user_id, {$aggregate} AS value FROM orders WHERE status IN ({$placeholders}) AND is_opening_balance = false AND created_at >= ? AND created_at < ? GROUP BY user_id",
            [...$statuses, $start, $end],
        ];
    }
}

<?php

namespace App\Domain\Audience;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The owner's visitor count for the public website, for one chosen stretch of days.
 * Views and visitors come from the daily totals, which are kept for good. A visitor is counted
 * once for each day they came, so a week's visitors are its days added together. Devices,
 * sources and pages come from the page-by-page rows, which reach back one year.
 */
final class SiteVisitReport
{
    /** The views the page offers. `day` needs a from date and `custom` needs both dates. */
    public const Periods = ['today', 'yesterday', 'week', 'last_week', 'month', 'last_month', 'year', 'all', 'day', 'custom'];

    public const DefaultPeriod = 'month';

    /** A visitor seen within this many minutes counts as on the site now. */
    private const ActiveMinutes = 15;

    /** Up to this many days the chart has one bar a day; beyond it, one bar a month. */
    private const MostDailyBars = 62;

    private const MostPages = 10;

    private const MostProducts = 5;

    private const MostRecent = 10;

    /**
     * @return array{
     *   period: array{key: string, from: string, to: string, bucket: string},
     *   active_now: int,
     *   summary: array{views: int, visitors: int, views_change: ?int, visitors_change: ?int},
     *   trend: list<array{key: string, views: int, visitors: int}>,
     *   devices: array{mobile: int, desktop: int},
     *   sources: list<array{source: string, views: int}>,
     *   pages: list<array{path: string, views: int}>,
     *   products: list<array{id: int, name: string, views: int}>,
     *   recent: list<array{path: string, device: string, source: string, at: string}>,
     *   details_partial: bool,
     *   keep_months: int
     * }
     */
    public function for(?string $period, ?string $from, ?string $to): array
    {
        $now = CarbonImmutable::now($this->timezone());
        $range = $this->range($period, $from, $to, $now);
        $current = $this->totals($range['from'], $range['to']);
        $previous = $this->previousTotals($range, $now);
        $bucket = $this->bucket($range['from'], $range['to']);

        return [
            'period' => ['key' => $range['key'], 'from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString(), 'bucket' => $bucket],
            'active_now' => (int) DB::table('site_visits')->where('created_at', '>=', $now->subMinutes(self::ActiveMinutes)->utc())->distinct()->count('visitor_id'),
            'summary' => [
                ...$current,
                'views_change' => $this->change($current['views'], $previous['views'] ?? null),
                'visitors_change' => $this->change($current['visitors'], $previous['visitors'] ?? null),
            ],
            'trend' => $this->trend($bucket, $range['from'], $range['to']),
            'devices' => $this->devices($range['from'], $range['to']),
            'sources' => $this->sources($range['from'], $range['to']),
            'pages' => $this->pages($range['from'], $range['to']),
            'products' => $this->products($range['from'], $range['to']),
            'recent' => $this->recent(),
            'details_partial' => $this->detailsStopShort($range['from'], $range['to'], $now),
            'keep_months' => SiteVisitCounter::KeepVisitsMonths,
        ];
    }

    private function timezone(): string
    {
        return (string) config('madina.business_timezone', 'Asia/Dhaka');
    }

    /**
     * The days to show and the days to compare them with. A stretch still running (this week,
     * this month, this year) is compared with the same number of days at the start of the one
     * before. Weeks run Sunday to Saturday, as on the order pages.
     *
     * @return array{key: string, from: CarbonImmutable, to: CarbonImmutable, before: array{from: CarbonImmutable, to: CarbonImmutable}|null}
     */
    private function range(?string $period, ?string $from, ?string $to, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $key = in_array($period, self::Periods, true) ? $period : self::DefaultPeriod;
        $fromDay = $this->day($from);
        $toDay = $this->day($to);
        if (($key === 'day' && $fromDay === null) || ($key === 'custom' && ($fromDay === null || $toDay === null || $toDay->lt($fromDay)))) {
            $key = self::DefaultPeriod;
        }
        $weekStart = $today->startOfWeek(CarbonInterface::SUNDAY);
        $monthStart = $today->startOfMonth();

        [$start, $end, $beforeStart] = match ($key) {
            'today' => [$today, $today, $today->subDay()],
            'yesterday' => [$today->subDay(), $today->subDay(), $today->subDays(2)],
            'week' => [$weekStart, $today, $weekStart->subWeek()],
            'last_week' => [$weekStart->subWeek(), $weekStart->subDay(), $weekStart->subWeeks(2)],
            'last_month' => [$monthStart->subMonthNoOverflow(), $monthStart->subDay(), $monthStart->subMonthsNoOverflow(2)],
            'year' => [$today->startOfYear(), $today, $today->startOfYear()->subYear()],
            'all' => [$this->firstDay() ?? $today, $today, null],
            'day' => [$fromDay, $fromDay, $fromDay->subDay()],
            'custom' => [$fromDay, $toDay, $fromDay->subDays((int) $fromDay->diffInDays($toDay) + 1)],
            default => [$monthStart, $today, $monthStart->subMonthNoOverflow()],
        };

        $before = null;
        if ($beforeStart !== null) {
            $beforeEnd = $beforeStart->addDays((int) $start->diffInDays($end));
            // A short month before a long one must not borrow days from the stretch being shown.
            $before = ['from' => $beforeStart, 'to' => $beforeEnd->lt($start) ? $beforeEnd : $start->subDay()];
        }

        return ['key' => $key, 'from' => $start, 'to' => $end, 'before' => $before];
    }

    private function day(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, $this->timezone()) : null;
    }

    private function firstDay(): ?CarbonImmutable
    {
        $first = DB::table('site_visit_days')->min('day');

        return is_string($first) ? $this->day($first) : null;
    }

    /** @return array{views: int, visitors: int} */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('site_visit_days')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors')
            ->first();

        return ['views' => (int) ($row->views ?? 0), 'visitors' => (int) ($row->visitors ?? 0)];
    }

    /**
     * Totals of the stretch before. Today is only part of a day, so it is compared with
     * yesterday up to the same time rather than with the whole of yesterday.
     *
     * @param  array{key: string, from: CarbonImmutable, to: CarbonImmutable, before: array{from: CarbonImmutable, to: CarbonImmutable}|null}  $range
     * @return array{views: int, visitors: int}|null
     */
    private function previousTotals(array $range, CarbonImmutable $now): ?array
    {
        if ($range['before'] === null) {
            return null;
        }
        if (! $range['from']->isSameDay($now) || ! $range['to']->isSameDay($now)) {
            return $this->totals($range['before']['from'], $range['before']['to']);
        }
        $row = DB::table('site_visits')
            ->where('visited_on', $range['before']['from']->toDateString())
            ->where('created_at', '<=', $now->subDay()->utc())
            ->selectRaw('COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors')
            ->first();

        return ['views' => (int) ($row->views ?? 0), 'visitors' => (int) ($row->visitors ?? 0)];
    }

    /** Percent up or down against the time before, or null when there is nothing to compare with. */
    private function change(int $current, ?int $previous): ?int
    {
        return $previous === null || $previous <= 0 ? null : (int) round(($current - $previous) / $previous * 100);
    }

    private function bucket(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = (int) $from->diffInDays($to) + 1;

        return match (true) {
            $days === 1 => 'hour',
            $days <= self::MostDailyBars => 'day',
            default => 'month',
        };
    }

    /**
     * The chart: every hour of one day, every day of a short stretch or every month of a long
     * one, empty ones included.
     *
     * @return list<array{key: string, views: int, visitors: int}>
     */
    private function trend(string $bucket, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($bucket === 'hour') {
            $found = DB::table('site_visits')
                ->where('visited_on', $from->toDateString())
                ->selectRaw('EXTRACT(HOUR FROM created_at AT TIME ZONE ?)::int AS bucket, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors', [$this->timezone()])
                ->groupBy('bucket')
                ->get()
                ->keyBy(fn (object $row): string => str_pad((string) $row->bucket, 2, '0', STR_PAD_LEFT));
            $keys = array_map(fn (int $hour): string => str_pad((string) $hour, 2, '0', STR_PAD_LEFT), range(0, 23));
        } elseif ($bucket === 'day') {
            $found = DB::table('site_visit_days')
                ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->get(['day', 'views', 'visitors'])
                ->keyBy(fn (object $row): string => (string) $row->day);
            $keys = [];
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $keys[] = $day->toDateString();
            }
        } else {
            $found = DB::table('site_visit_days')
                ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->selectRaw("TO_CHAR(day, 'YYYY-MM') AS bucket, SUM(views) AS views, SUM(visitors) AS visitors")
                ->groupBy('bucket')
                ->get()
                ->keyBy(fn (object $row): string => (string) $row->bucket);
            $keys = [];
            for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonthNoOverflow()) {
                $keys[] = $month->format('Y-m');
            }
        }

        return array_map(fn (string $key): array => [
            'key' => $key,
            'views' => (int) ($found[$key]->views ?? 0),
            'visitors' => (int) ($found[$key]->visitors ?? 0),
        ], $keys);
    }

    /** @return array{mobile: int, desktop: int} */
    private function devices(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $found = DB::table('site_visits')
            ->whereBetween('visited_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('device, COUNT(*) AS views')
            ->groupBy('device')
            ->pluck('views', 'device');

        return ['mobile' => (int) ($found['mobile'] ?? 0), 'desktop' => (int) ($found['desktop'] ?? 0)];
    }

    /**
     * How visitors arrived, most first. Moving from one of our pages to another is not an arrival.
     *
     * @return list<array{source: string, views: int}>
     */
    private function sources(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('site_visits')
            ->whereBetween('visited_on', [$from->toDateString(), $to->toDateString()])
            ->where('source', '<>', 'internal')
            ->selectRaw('source, COUNT(*) AS views')
            ->groupBy('source')
            ->orderByDesc('views')
            ->orderBy('source')
            ->get()
            ->map(fn (object $row): array => ['source' => (string) $row->source, 'views' => (int) $row->views])
            ->all();
    }

    /** @return list<array{path: string, views: int}> */
    private function pages(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('site_visits')
            ->whereBetween('visited_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('path, COUNT(*) AS views')
            ->groupBy('path')
            ->orderByDesc('views')
            ->orderBy('path')
            ->limit(self::MostPages)
            ->get()
            ->map(fn (object $row): array => ['path' => (string) $row->path, 'views' => (int) $row->views])
            ->all();
    }

    /**
     * The product pages opened most, by name. A product since deleted is left out.
     *
     * @return list<array{id: int, name: string, views: int}>
     */
    private function products(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opened = DB::table('site_visits')
            ->whereBetween('visited_on', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("path ~ '^/products/[0-9]{1,18}$'")
            ->selectRaw('path, COUNT(*) AS views')
            ->groupBy('path')
            ->orderByDesc('views')
            ->orderBy('path')
            ->limit(self::MostProducts)
            ->pluck('views', 'path');
        if ($opened->isEmpty()) {
            return [];
        }
        $ids = $opened->keys()->map(fn (string $path): int => (int) substr($path, strlen('/products/')));
        $names = Product::query()->whereIn('id', $ids)->pluck('name', 'id');
        $products = [];
        foreach ($opened as $path => $views) {
            $id = (int) substr($path, strlen('/products/'));
            if (isset($names[$id])) {
                $products[] = ['id' => $id, 'name' => (string) $names[$id], 'views' => (int) $views];
            }
        }

        return $products;
    }

    /**
     * The newest page views, whatever stretch is chosen. The visitor's number is not shown.
     *
     * @return list<array{path: string, device: string, source: string, at: string}>
     */
    private function recent(): array
    {
        return DB::table('site_visits')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MostRecent)
            ->get(['path', 'device', 'source', 'created_at'])
            ->map(fn (object $row): array => [
                'path' => (string) $row->path,
                'device' => (string) $row->device,
                'source' => (string) $row->source,
                'at' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
            ])
            ->all();
    }

    /**
     * True when the stretch has days whose page-by-page rows were already removed, so the
     * devices, sources and pages cover less time than the totals do.
     */
    private function detailsStopShort(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): bool
    {
        $kept = $now->startOfDay()->subMonthsNoOverflow(SiteVisitCounter::KeepVisitsMonths);
        if (! $from->lt($kept)) {
            return false;
        }

        return DB::table('site_visit_days')
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', min($kept->toDateString(), $to->addDay()->toDateString()))
            ->where('views', '>', 0)
            ->exists();
    }
}

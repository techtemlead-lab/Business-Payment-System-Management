<?php

namespace App\Domain\Finance;

use App\Console\Commands\AutoApprovePayouts;
use App\Domain\Commissions\CommissionQualification;
use App\Support\LocalDigits;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where each monthly salary and royalty close stands, for the Settlements page (MA-01, MA-23):
 * which ended months still wait (and whether the hourly job will close them or a person must),
 * when each month was closed, and any automatic close that failed.
 */
final class MonthCloseStatus
{
    /** Cache key holding the automatic closes that failed and have not succeeded since. */
    public const FailuresKey = 'finance:month-close-failures';

    /** @var array<string, list<string>> */
    public const KindCategories = [
        'salary' => ['dealer_monthly_salary_pool', 'sub_dealer_salary'],
        'royalty' => ['rank_royalty_pool'],
    ];

    /**
     * Everything the Settlements page needs about month closes, read in one query.
     *
     * @return array{waiting: list<array{month: string, label: string, kind: string, amount: string, automatic: bool}>, closed: list<array{month: string, label: string, kind: string, closed_at: string, paid: string, to_company: string}>, failures: list<array<string, string>>, month_options: list<array{value: string, label: string}>, selected_closed: array<string, ?string>}
     */
    public function forPage(string $selectedMonth): array
    {
        $rows = $this->monthRows();

        return [
            'waiting' => $this->waitingFrom($rows),
            'closed' => $this->closedFrom($rows),
            'failures' => $this->failures(),
            'month_options' => $this->monthOptions(),
            'selected_closed' => [
                'salary' => $this->closedAtFrom($rows, $selectedMonth, 'salary'),
                'royalty' => $this->closedAtFrom($rows, $selectedMonth, 'royalty'),
            ],
        ];
    }

    /**
     * Ended months that still hold unpaid salary or royalty money, oldest first.
     *
     * @return list<array{month: string, label: string, kind: string, amount: string, automatic: bool}>
     */
    public function waitingMonths(): array
    {
        return $this->waitingFrom($this->monthRows());
    }

    /**
     * When a month's salary or royalty was closed, newest first.
     *
     * @return list<array{month: string, label: string, kind: string, closed_at: string, paid: string, to_company: string}>
     */
    public function closedMonths(int $limit = 6): array
    {
        return $this->closedFrom($this->monthRows(), $limit);
    }

    /** When the month's close for this kind happened, or null when it has not. */
    public function closedAt(string $month, string $kind): ?string
    {
        return $this->closedAtFrom($this->monthRows(), $month, $kind);
    }

    /**
     * Salary and royalty fund totals per kind, status and month: pending rows by their month,
     * closed rows by the month they closed for.
     *
     * @return list<object{kind: string, status: string, month: ?string, total: string, closed_at: ?string, paid: string, to_company: string}>
     */
    private function monthRows(): array
    {
        $kindSql = "CASE WHEN category = 'rank_royalty_pool' THEN 'royalty' ELSE 'salary' END";

        return DB::table('commission_allocations')
            ->whereIn('category', array_merge(...array_values(self::KindCategories)))
            ->whereIn('status', ['pending', 'settled'])
            ->groupByRaw("{$kindSql}, status, CASE WHEN status = 'settled' THEN metadata->>'settled_period' ELSE metadata->>'period_month' END")
            ->selectRaw("{$kindSql} AS kind, status, CASE WHEN status = 'settled' THEN metadata->>'settled_period' ELSE metadata->>'period_month' END AS month, "
                ."SUM(amount) AS total, MAX(metadata->>'settled_at') AS closed_at, "
                ."SUM(COALESCE((metadata->>'distributed')::numeric, 0)) AS paid, SUM(COALESCE((metadata->>'reserved')::numeric, 0)) AS to_company")
            ->get()->all();
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{month: string, label: string, kind: string, amount: string, automatic: bool}>
     */
    private function waitingFrom(array $rows): array
    {
        $current = CommissionQualification::currentMonth();
        $oldestAutomatic = CarbonImmutable::createFromFormat('!Y-m', $current)->subMonthsNoOverflow(AutoApprovePayouts::AutomaticMonthsBack)->format('Y-m');
        $months = [];
        foreach ($rows as $row) {
            if ($row->status !== 'pending' || ! is_string($row->month) || $row->month >= $current) {
                continue;
            }
            $months[] = ['month' => $row->month, 'label' => self::label($row->month), 'kind' => (string) $row->kind,
                'amount' => Money::decimal(Money::cents((string) $row->total)), 'automatic' => $row->month >= $oldestAutomatic];
        }
        usort($months, fn (array $first, array $second): int => [$first['month'], $first['kind']] <=> [$second['month'], $second['kind']]);

        return $months;
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{month: string, label: string, kind: string, closed_at: string, paid: string, to_company: string}>
     */
    private function closedFrom(array $rows, int $limit = 6): array
    {
        $closed = [];
        foreach ($rows as $row) {
            if ($row->status !== 'settled' || ! is_string($row->month)) {
                continue;
            }
            $closed[] = ['month' => $row->month, 'label' => self::label($row->month), 'kind' => (string) $row->kind,
                'closed_at' => (string) $row->closed_at, 'paid' => (string) $row->paid, 'to_company' => (string) $row->to_company];
        }
        usort($closed, fn (array $first, array $second): int => [$second['month'], $first['kind']] <=> [$first['month'], $second['kind']]);

        return array_slice($closed, 0, $limit * count(self::KindCategories));
    }

    /** @param  list<object>  $rows */
    private function closedAtFrom(array $rows, string $month, string $kind): ?string
    {
        foreach ($rows as $row) {
            if ($row->status === 'settled' && $row->kind === $kind && $row->month === $month && is_string($row->closed_at)) {
                return $row->closed_at;
            }
        }

        return null;
    }

    /**
     * Automatic closes that failed, newest first.
     *
     * @return list<array{kind: string, month: string, label: string, error: string, failed_at: string}>
     */
    public function failures(): array
    {
        $failures = array_values((array) Cache::get(self::FailuresKey, []));
        usort($failures, fn (array $first, array $second): int => strcmp((string) $second['failed_at'], (string) $first['failed_at']));

        return array_map(fn (array $failure): array => [...$failure, 'label' => self::label((string) $failure['month'])], $failures);
    }

    public function recordFailure(string $kind, string $month, string $error): void
    {
        $failures = (array) Cache::get(self::FailuresKey, []);
        $failures[$kind.':'.$month] = ['kind' => $kind, 'month' => $month, 'error' => mb_substr($error, 0, 300), 'failed_at' => now()->toIso8601String()];
        Cache::forever(self::FailuresKey, $failures);
    }

    public function clearFailure(string $kind, string $month): void
    {
        $failures = (array) Cache::get(self::FailuresKey, []);
        if (array_key_exists($kind.':'.$month, $failures)) {
            unset($failures[$kind.':'.$month]);
            Cache::forever(self::FailuresKey, $failures);
        }
    }

    /**
     * The last 24 months as a pick list, newest first, named in the reader's language.
     *
     * @return list<array{value: string, label: string}>
     */
    public function monthOptions(): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', CommissionQualification::currentMonth());
        $options = [];
        for ($back = 0; $back < 24; $back++) {
            $value = $month->subMonthsNoOverflow($back)->format('Y-m');
            $options[] = ['value' => $value, 'label' => self::label($value)];
        }

        return $options;
    }

    /** "জানুয়ারি ২০২৭" or "January 2027". */
    public static function label(string $month): string
    {
        $locale = app()->getLocale();

        return LocalDigits::text(CarbonImmutable::createFromFormat('!Y-m', $month)->locale($locale)->translatedFormat('F Y'), $locale);
    }
}

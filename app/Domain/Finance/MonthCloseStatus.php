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
     * Ended months that still hold unpaid salary or royalty money, oldest first.
     *
     * @return list<array{month: string, label: string, kind: string, amount: string, automatic: bool}>
     */
    public function waitingMonths(): array
    {
        $current = CommissionQualification::currentMonth();
        $oldestAutomatic = CarbonImmutable::createFromFormat('!Y-m', $current)->subMonthsNoOverflow(AutoApprovePayouts::AutomaticMonthsBack)->format('Y-m');
        $months = [];
        foreach (self::KindCategories as $kind => $categories) {
            $rows = DB::table('commission_allocations')->whereIn('category', $categories)->where('status', 'pending')
                ->groupByRaw("metadata->>'period_month'")->selectRaw("metadata->>'period_month' AS month, SUM(amount) AS total")->get();
            foreach ($rows as $row) {
                if (! is_string($row->month) || $row->month >= $current) {
                    continue;
                }
                $months[] = ['month' => $row->month, 'label' => self::label($row->month), 'kind' => $kind,
                    'amount' => Money::decimal(Money::cents((string) $row->total)), 'automatic' => $row->month >= $oldestAutomatic];
            }
        }
        usort($months, fn (array $first, array $second): int => [$first['month'], $first['kind']] <=> [$second['month'], $second['kind']]);

        return $months;
    }

    /**
     * When a month's salary or royalty was closed, newest first; null closed_by means the hourly job did it.
     *
     * @return list<array{month: string, label: string, kind: string, closed_at: string, paid: string, to_company: string}>
     */
    public function closedMonths(int $limit = 6): array
    {
        $closed = [];
        foreach (self::KindCategories as $kind => $categories) {
            $rows = DB::table('commission_allocations')->whereIn('category', $categories)->where('status', 'settled')
                ->whereNotNull('metadata->settled_period')
                ->groupByRaw("metadata->>'settled_period'")
                ->selectRaw("metadata->>'settled_period' AS month, MAX(metadata->>'settled_at') AS closed_at, "
                    ."SUM(COALESCE((metadata->>'distributed')::numeric, 0)) AS paid, SUM(COALESCE((metadata->>'reserved')::numeric, 0)) AS to_company")
                ->orderByDesc('month')->limit($limit)->get();
            foreach ($rows as $row) {
                $closed[] = ['month' => (string) $row->month, 'label' => self::label((string) $row->month), 'kind' => $kind,
                    'closed_at' => (string) $row->closed_at, 'paid' => (string) $row->paid, 'to_company' => (string) $row->to_company];
            }
        }
        usort($closed, fn (array $first, array $second): int => [$second['month'], $first['kind']] <=> [$first['month'], $second['kind']]);

        return $closed;
    }

    /** When the month's close for this kind happened, or null when it has not. */
    public function closedAt(string $month, string $kind): ?string
    {
        $value = DB::table('commission_allocations')->whereIn('category', self::KindCategories[$kind] ?? [])->where('status', 'settled')
            ->where('metadata->settled_period', $month)->selectRaw("MAX(metadata->>'settled_at') AS closed_at")->value('closed_at');

        return is_string($value) ? $value : null;
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

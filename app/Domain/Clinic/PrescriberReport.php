<?php

namespace App\Domain\Clinic;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * A prescriber's own numbers for a period: prescriptions written, patients seen and the
 * amounts billed. Partner totals are only what they typed. The owner-doctor also gets the
 * dispensed clinic money, read from ClinicDispensing's report and never recalculated here.
 */
final class PrescriberReport
{
    /** Periods longer than this are shown one row per month instead of one per day. */
    public const DailyRowsUpTo = 62;

    public function __construct(private ClinicDispensing $dispensing) {}

    /**
     * @return array{from: string, to: string, grouped_by: string, prescriptions: int, patients: int, billed: string, discount: string, clinic: array<string, mixed>|null, rows: list<array<string, mixed>>}
     */
    public function forPrescriber(User $prescriber, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byMonth = $from->startOfDay()->diffInDays($to->startOfDay(), true) > self::DailyRowsUpTo;
        $format = $byMonth ? 'YYYY-MM' : 'YYYY-MM-DD';
        $written = fn () => DB::table('prescriptions')
            ->where('prescriber_id', $prescriber->id)
            ->whereBetween('prescribed_on', [$from->toDateString(), $to->toDateString()]);
        $columns = 'count(*) as prescriptions, count(distinct patient_id) as patients, coalesce(sum(discount_total), 0) as discount, coalesce(sum(total), 0) as billed';

        $totals = $written()->selectRaw($columns)->first();
        $periods = $written()
            ->selectRaw("to_char(prescribed_on, '{$format}') as period, {$columns}")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->period => [
                'period' => (string) $row->period,
                'prescriptions' => (int) $row->prescriptions,
                'patients' => (int) $row->patients,
                'billed' => Money::decimal(Money::cents((string) $row->billed)),
            ]])
            ->all();

        $report = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'grouped_by' => $byMonth ? 'month' : 'day',
            'prescriptions' => (int) $totals->prescriptions,
            'patients' => (int) $totals->patients,
            'billed' => Money::decimal(Money::cents((string) $totals->billed)),
            'discount' => Money::decimal(Money::cents((string) $totals->discount)),
            'clinic' => null,
            'rows' => array_values($periods),
        ];

        return PrescriptionAccess::isOwnerDoctor($prescriber)
            ? $this->withClinicMoney($report, $periods, $from, $to, $byMonth)
            : $report;
    }

    /**
     * Adds dispensed sales, the manufacturer share and the clinic fund to each row.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function withClinicMoney(array $report, array $periods, CarbonImmutable $from, CarbonImmutable $to, bool $byMonth): array
    {
        $clinic = $this->dispensing->report($from, $to);
        $money = [];
        foreach ($clinic['days'] as $day) {
            $period = $byMonth ? substr($day['day'], 0, 7) : $day['day'];
            foreach (['sales', 'manufacturer_share', 'clinic_fund'] as $field) {
                $money[$period][$field] = ($money[$period][$field] ?? 0) + $this->signedCents($day[$field]);
            }
            $periods[$period] ??= ['period' => $period, 'prescriptions' => 0, 'patients' => 0, 'billed' => '0.00'];
        }
        ksort($periods);
        $report['clinic'] = Arr::only($clinic, ['sales', 'manufacturer_share', 'clinic_fund']);
        $report['rows'] = array_values(array_map(fn (array $row): array => [
            ...$row,
            'sales' => $this->signedDecimal($money[$row['period']]['sales'] ?? 0),
            'manufacturer_share' => $this->signedDecimal($money[$row['period']]['manufacturer_share'] ?? 0),
            'clinic_fund' => $this->signedDecimal($money[$row['period']]['clinic_fund'] ?? 0),
        ], $periods));

        return $report;
    }

    /** The clinic fund can be below zero when the owner allowed a low price. */
    private function signedCents(string $amount): int
    {
        return str_starts_with($amount, '-') ? -Money::cents(substr($amount, 1)) : Money::cents($amount);
    }

    private function signedDecimal(int $cents): string
    {
        return $cents < 0 ? '-'.Money::decimal(-$cents) : Money::decimal($cents);
    }
}

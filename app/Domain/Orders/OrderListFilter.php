<?php

namespace App\Domain\Orders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Applies the order list filters shared by the portal order screens and the
 * CSV export, so what staff see on screen is exactly what they download.
 */
final class OrderListFilter
{
    /** Sections whose list honours the period / date range filter. */
    public const DatedSections = ['orders', 'deliveries', 'reports'];

    /** Order states that still have goods waiting to be sent. */
    public const AwaitingDispatch = ['approved', 'partially_dispatched', 'dispatched'];

    /** Order states that count as a confirmed sale. */
    public const Confirmed = ['approved', 'partially_dispatched', 'dispatched', 'delivered', MixedOutcomeDecisions::PartlyDelivered];

    public function apply(Builder $query, Request $request, string $section, bool $staff): Builder
    {
        if ($request->filled('q')) {
            $term = '%'.self::escapeLike($request->string('q')->trim()->toString()).'%';
            $query->where(function (Builder $orders) use ($term): void {
                $orders->where('number', 'ilike', $term)
                    ->orWhereHas('user', fn (Builder $users) => $users->where('name', 'ilike', $term)
                        ->orWhere('dealer_code', 'ilike', $term)
                        ->orWhere('phone', 'ilike', $term));
            });
        }

        $overdueDeliveries = $section === 'deliveries' && self::wantsOverdueDeliveries($request);
        if ($request->filled('status') && ! $overdueDeliveries) {
            $query->where('status', $request->string('status')->toString());
        }

        if (in_array($section, self::DatedSections, true)) {
            $range = self::createdRange(
                $request->string('period')->toString(),
                $request->filled('from') ? $request->string('from')->toString() : null,
                $request->filled('to') ? $request->string('to')->toString() : null,
            );
            if ($range !== null) {
                $query->where('created_at', '>=', $range['from'])
                    ->where('created_at', '<=', $range['until']);
            }
        }

        if ($staff && $request->filled('buyer_role')) {
            $buyerRole = $request->string('buyer_role')->toString();
            $query->whereHas('user', fn (Builder $users) => $users->where('role', $buyerRole));
        }

        if ($overdueDeliveries) {
            // Sent but not marked delivered: every unit may already be on the road.
            app(OverdueDeliveries::class)->apply($query);
        } elseif ($section === 'deliveries') {
            $query->whereIn('status', self::AwaitingDispatch)
                ->whereHas('lines', fn (Builder $lines) => $lines->whereColumn('dispatched', '<', 'quantity'));
        }

        return $query;
    }

    /**
     * Resolve a period keyword (today, week, month, last_month, year, day, custom)
     * into a UTC range using the business timezone (Asia/Dhaka).
     *
     * @return array{from: CarbonImmutable, until: CarbonImmutable}|null
     */
    public static function createdRange(string $period, ?string $from, ?string $to): ?array
    {
        if ($period === '') {
            return null;
        }

        $timezone = (string) config('madina.business_timezone', config('app.timezone', 'Asia/Dhaka'));
        $now = CarbonImmutable::now($timezone);

        [$start, $end] = match (true) {
            $period === 'today' => [$now->startOfDay(), $now->endOfDay()],
            $period === 'week' => [$now->startOfWeek(CarbonInterface::SUNDAY), $now->endOfWeek(CarbonInterface::SATURDAY)],
            $period === 'month' => [$now->startOfMonth(), $now->endOfMonth()],
            $period === 'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            $period === 'year' => [$now->startOfYear(), $now->endOfYear()],
            $period === 'day' && self::isDate($from) => [
                CarbonImmutable::parse($from, $timezone)->startOfDay(),
                CarbonImmutable::parse($from, $timezone)->endOfDay(),
            ],
            $period === 'custom' && self::isDate($from) && self::isDate($to) => [
                CarbonImmutable::parse($from, $timezone)->startOfDay(),
                CarbonImmutable::parse($to, $timezone)->endOfDay(),
            ],
            default => [null, null],
        };

        if ($start === null || $end === null) {
            return null;
        }

        return ['from' => $start->utc(), 'until' => $end->utc()];
    }

    /**
     * Whether the deliveries list asks for orders sent but not marked delivered.
     */
    public static function wantsOverdueDeliveries(Request $request): bool
    {
        return $request->string('status')->toString() === OverdueDeliveries::Filter;
    }

    /**
     * Escape LIKE wildcards so a search for "50%" does not match everything.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function isDate(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}

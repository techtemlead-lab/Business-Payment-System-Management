<?php

namespace App\Domain\Finance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How many days of a payroll month a staff member who is no longer active still worked.
 *
 * Staff have no employment end date of their own, so the day the account stopped being active
 * is read from the activity log: the last "account.updated" entry that changed the status from
 * active to something else. A staff member with no such entry is never paid by guesswork.
 *
 * Business rule chosen while the owner has not decided (2026-11-21): pay by active days,
 * counting the day the status changed as worked, and mark the entry for review.
 */
final class PayrollActiveDays
{
    /**
     * Null when this person was not active at any time in the month (or the date is unknown);
     * otherwise the active days, the month's days and when the status changed.
     *
     * @return array{active_days: int, days_in_month: int, status_changed_at: string}|null
     */
    public function forInactive(User $staff, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): ?array
    {
        $changedAt = DB::table('audit_events')
            ->where('action', 'account.updated')
            ->where('subject', (string) $staff->id)
            ->whereRaw("metadata::jsonb #>> '{changes,status,before}' = 'active'")
            ->whereRaw("COALESCE(metadata::jsonb #>> '{changes,status,after}', '') <> 'active'")
            ->orderByDesc('created_at')->orderByDesc('id')
            ->value('created_at');
        if ($changedAt === null) {
            return null;
        }
        $changed = CarbonImmutable::parse((string) $changedAt, 'UTC')->setTimezone($monthStart->getTimezone());
        if ($changed->lessThan($monthStart)) {
            return null;
        }
        $daysInMonth = $monthStart->daysInMonth;
        // Changed after the month ended (before the payroll was prepared): the whole month was worked.
        $activeDays = $changed->greaterThanOrEqualTo($monthEnd) ? $daysInMonth : $changed->day;

        return ['active_days' => $activeDays, 'days_in_month' => $daysInMonth, 'status_changed_at' => $changed->toIso8601String()];
    }

    /** The share of an amount in cents for the active days, rounded half up. */
    public static function share(int $amount, int $activeDays, int $daysInMonth): int
    {
        return intdiv(2 * $amount * $activeDays + $daysInMonth, 2 * $daysInMonth);
    }
}

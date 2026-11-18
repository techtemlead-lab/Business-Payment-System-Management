<?php

namespace App\Domain\Finance;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The commission tasks that wait for a person, counted the same way for the office home and the
 * Treasury Money tasks list: commission whose waiting days are over and is ready to pay out
 * (MA-15), commission held (MA-03), and a month close that failed.
 */
final class MoneyTaskFigures
{
    /**
     * Commission still marked as waiting although its date has passed. The payout button on the
     * Settlements page releases it.
     *
     * @return array{count: int, amount: string}
     */
    public function readyToPay(): array
    {
        $row = DB::table('commission_allocations as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.status', 'maturing')->where('u.status', 'active')
            ->whereRaw("a.metadata->>'maturation_due_at' IS NOT NULL AND (a.metadata->>'maturation_due_at')::timestamptz <= ?", [now()->toIso8601String()])
            ->selectRaw('COUNT(*) AS lines, COALESCE(SUM(a.amount), 0) AS total')->first();

        return ['count' => (int) $row->lines, 'amount' => Money::decimal(Money::cents((string) $row->total))];
    }

    /**
     * Commission held for a person to look at.
     *
     * @return array{count: int, amount: string}
     */
    public function held(): array
    {
        $row = DB::table('commission_allocations')->where('status', 'held')
            ->selectRaw('COUNT(*) AS lines, COALESCE(SUM(amount), 0) AS total')->first();

        return ['count' => (int) $row->lines, 'amount' => Money::decimal(Money::cents((string) $row->total))];
    }

    /** How many automatic month closes failed and have not worked since. */
    public function failedCloses(): int
    {
        return count(app(MonthCloseStatus::class)->failures());
    }
}

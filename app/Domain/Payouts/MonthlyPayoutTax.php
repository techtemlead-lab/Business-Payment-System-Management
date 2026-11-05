<?php

namespace App\Domain\Payouts;

use App\Domain\Commissions\CommissionPolicy;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tax cut at source (TDS) on partner withdrawals, checked against the partner's
 * total for the calendar month (Asia/Dhaka), not each withdrawal on its own.
 *
 * The rule:
 * - The month's total is every withdrawal this partner asked for in the same
 *   Dhaka calendar month that was not rejected or cancelled, plus this one.
 * - While that total stays below the threshold, no tax is cut.
 * - When the total first reaches the threshold, tax is cut on the whole month's
 *   amount so far (catch-up), so splitting one withdrawal into small ones does
 *   not avoid tax. After that, every later withdrawal that month is taxed in full.
 * - Worked out as: tax due on the whole month total, minus the tax already cut
 *   on the month's other withdrawals. This also corrects itself when an earlier
 *   withdrawal is rejected later, and it never cuts more than this withdrawal.
 * - A new month starts again from zero.
 *
 * Callers must hold the partner's row lock and CommissionWallet::lock(), so two
 * requests at the same moment cannot both be counted as "first under the threshold".
 */
final class MonthlyPayoutTax
{
    /** Withdrawal statuses that do not count towards the month's total. */
    public const IgnoredStatuses = ['rejected', 'cancelled'];

    public function __construct(private CommissionPolicy $policies) {}

    /**
     * @param  int  $grossCents  The new withdrawal amount in paisa.
     * @return array{gross_amount: string, tds_pct: string, tds_amount: string, net_amount: string, month_total: string, month_withheld_before: string}
     */
    public function forWithdrawal(int $userId, int $grossCents, ?CarbonImmutable $at = null): array
    {
        $rules = $this->policies->snapshot()['rules'];
        $rate = CommissionPolicy::rate($rules, 'payout_tax_withholding');
        $threshold = Money::cents((string) $rules['payout_tax_withholding']['parameters']['threshold']);
        $local = ($at ?? CarbonImmutable::now())->setTimezone('Asia/Dhaka');
        $from = $local->startOfMonth()->utc();
        $until = $local->startOfMonth()->addMonth()->utc();

        $earlier = DB::table('withdrawals')
            ->where('user_id', $userId)
            ->whereNotIn('status', self::IgnoredStatuses)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->lockForUpdate()
            ->get(['amount', 'withheld_amount']);
        $earlierGross = $earlier->sum(fn (object $row): int => Money::cents((string) $row->amount));
        $earlierWithheld = $earlier->sum(fn (object $row): int => Money::cents((string) ($row->withheld_amount ?? '0.00')));
        $monthTotal = $earlierGross + $grossCents;

        $tax = 0;
        if ($monthTotal >= $threshold) {
            $tax = max(0, min($grossCents, $rate->of($monthTotal, 'half_up') - $earlierWithheld));
        }

        return [
            'gross_amount' => Money::decimal($grossCents),
            'tds_pct' => $rate->decimal(),
            'tds_amount' => Money::decimal($tax),
            'net_amount' => Money::decimal($grossCents - $tax),
            'month_total' => Money::decimal($monthTotal),
            'month_withheld_before' => Money::decimal($earlierWithheld),
        ];
    }
}

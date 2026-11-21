<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\DealerKinds;
use App\Domain\Commissions\MonthlyTargets;
use App\Domain\Finance\FundCatalog;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * How a dealer's monthly salary was worked out, for the last closed months: the division's salary
 * pool, how many dealers shared it equally, and the dealer's own share. A month closed without a
 * salary for the dealer says why in plain words. Read from the payouts the close wrote, so the
 * figures are exactly what was paid.
 */
final class PartnerSalaryNotes
{
    public function __construct(private CommissionQualification $qualification) {}

    /**
     * @return list<array{month: string, state: string, amount: string|null, pool: string|null, sharers: int|null, min_volume: string}>
     */
    public function for(User $partner, int $months = 3): array
    {
        if ($partner->role !== 'dealer') {
            return [];
        }
        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $joined = $partner->created_at?->timezone($tz)->format('Y-m') ?? '0000-00';
        // The dealer's one monthly target by kind of dealer (owner's rule of 2026-11-21).
        $minVolume = MonthlyTargets::dealerTarget(app(CommissionPolicy::class)->snapshot(false)['rules'], app(DealerKinds::class)->of((int) $partner->id));
        $closed = DB::table('commission_allocations')
            ->where('category', 'dealer_monthly_salary_pool')
            ->where('status', 'settled')
            ->whereRaw("metadata->>'settled_period' >= ?", [$joined])
            ->selectRaw("DISTINCT metadata->>'settled_period' AS month")
            ->orderByDesc('month')
            ->limit($months)
            ->pluck('month');

        return $closed->map(fn (string $month): array => $this->month($partner, $month, $minVolume))->all();
    }

    /**
     * @return array{month: string, state: string, amount: string|null, pool: string|null, sharers: int|null, min_volume: string}
     */
    private function month(User $partner, string $month, int $minVolume): array
    {
        $note = ['month' => $month, 'amount' => null, 'pool' => null, 'sharers' => null, 'min_volume' => Money::decimal($minVolume)];
        $paid = DB::table('commission_settlement_payouts as p')->join('commission_allocations as a', 'a.id', '=', 'p.allocation_id')
            ->where('p.user_id', $partner->id)->where('p.kind', 'salary')->where('p.period_month', $month)
            ->where('a.category', 'dealer_monthly_salary_pool')->sum('p.amount');
        if (FundCatalog::centsOf($paid) > 0) {
            $shared = DB::selectOne(<<<'SQL'
                SELECT COALESCE(SUM(f.amount), 0) AS pool, COALESCE(MAX(f.sharers), 0) AS sharers
                FROM (
                    SELECT a.id, a.amount, COUNT(p.user_id) AS sharers
                    FROM commission_allocations a
                    JOIN commission_settlement_payouts p ON p.allocation_id = a.id AND p.kind = 'salary'
                    WHERE a.category = 'dealer_monthly_salary_pool'
                      AND a.id IN (SELECT allocation_id FROM commission_settlement_payouts WHERE user_id = ? AND kind = 'salary' AND period_month = ?)
                    GROUP BY a.id, a.amount
                ) f
            SQL, [$partner->id, $month]);

            return [...$note, 'state' => 'paid', 'amount' => FundCatalog::decimalOf(FundCatalog::centsOf($paid)), 'pool' => FundCatalog::decimalOf(FundCatalog::centsOf($shared->pool)), 'sharers' => (int) $shared->sharers];
        }

        return [...$note, 'state' => $this->qualification->monthlyVolume((int) $partner->id, $month) < $minVolume ? 'none_low_volume' : 'none_other'];
    }
}

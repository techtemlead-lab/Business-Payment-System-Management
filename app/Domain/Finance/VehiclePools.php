<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPolicy;
use App\Models\CommissionAllocation;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The bike and car prizes each have their own pool inside the car and bike fund.
 *
 * Every row posted after the split carries metadata.vehicle_pool ("bike" or "car") and belongs wholly to
 * that pool. Rows posted before it have no tag: they are read as half bike and half car, worked out here when
 * asked, so no old row is changed and the rule gives the same answer every time. Prizes already paid are
 * counted against the pool of their own program; if a bike prize once took more than the bike half of an old row,
 * the extra came out of the car half, which is what really happened, so the two pools always add up to what the row holds.
 */
final class VehiclePools
{
    public const Programs = ['bike', 'car'];

    /** The fund category both pools live in. */
    public const Category = 'car_bike_incentive_fund';

    /** A fund closed at month end has moved its money to company profit. */
    private const ClosedStatus = 'settled';

    /**
     * What is left in each pool, in cents.
     *
     * @return array{bike: int, car: int}
     */
    public function balances(): array
    {
        $sums = DB::query()->fromSub($this->rowFigures(), 'x')
            ->selectRaw('COALESCE(SUM(x.bike_left), 0) AS bike, COALESCE(SUM(x.car_left), 0) AS car')->first();

        return ['bike' => $this->cents($sums->bike), 'car' => $this->cents($sums->car)];
    }

    /**
     * What is left of one fund row for one program, in cents.
     */
    public function remainingFor(CommissionAllocation $fund, string $program): int
    {
        $row = DB::query()->fromSub($this->rowFigures()->where('funds.id', $fund->id), 'x')->first();

        return $row === null ? 0 : $this->cents($row->{$program.'_left'});
    }

    /**
     * For each program: what its pool holds, the prize amount set in the reward rule (null while blank),
     * and how many whole prizes the pool can pay now.
     *
     * @return array{bike: array{balance: string, award: ?string, prizes_payable: ?int}, car: array{balance: string, award: ?string, prizes_payable: ?int}}
     */
    public function summary(): array
    {
        $parameters = app(CommissionPolicy::class)->snapshot(false)['rules']['car_bike_incentive']['parameters'];
        $balances = $this->balances();
        $result = [];
        foreach (self::Programs as $program) {
            $awardValue = $parameters[$program.'_award_amount'] ?? null;
            $award = $awardValue === null || $awardValue === '' ? 0 : Money::cents((string) $awardValue);
            $balance = max(0, $balances[$program]);
            $result[$program] = [
                'balance' => Money::decimal($balance),
                'award' => $award > 0 ? Money::decimal($award) : null,
                'prizes_payable' => $award > 0 ? intdiv($balance, $award) : null,
            ];
        }

        return $result;
    }

    /**
     * One line per live fund row with what is left of it for each program. The row's own total left is
     * amount - everything paid out of it; a tagged row gives all of it to its pool, an untagged row splits it.
     */
    private function rowFigures(): Builder
    {
        $paid = DB::table('commission_settlement_payouts')->groupBy('allocation_id')
            ->selectRaw('allocation_id, SUM(amount - reversed_amount) AS total');
        $spent = DB::table('reserve_expense_sources')->join('reserve_disbursements', 'disbursement_id', '=', 'reserve_disbursements.id')
            ->whereIn('reserve_disbursements.status', ['approved', 'paid'])->groupBy('reserve_expense_sources.allocation_id')
            ->selectRaw('reserve_expense_sources.allocation_id, SUM(reserve_expense_sources.amount - reserve_expense_sources.reversed_amount) AS total');
        $awardPaid = fn (string $program) => DB::table('commission_settlement_payouts as p')
            ->join('reserve_disbursements as d', 'p.kind', '=', DB::raw("'award_' || d.id::text"))
            ->where('d.program', $program)->groupBy('p.allocation_id')
            ->selectRaw('p.allocation_id, SUM(p.amount - p.reversed_amount) AS total');

        $left = '(funds.amount - COALESCE(paid.total, 0) - COALESCE(spent.total, 0))';
        $bikeHalf = 'FLOOR(funds.amount * 100 / 2) / 100';
        $untaggedBike = "LEAST({$left}, GREATEST(0, {$bikeHalf} - COALESCE(bike_paid.total, 0)))";

        return DB::table('commission_allocations as funds')
            ->leftJoinSub($paid, 'paid', 'paid.allocation_id', '=', 'funds.id')
            ->leftJoinSub($spent, 'spent', 'spent.allocation_id', '=', 'funds.id')
            ->leftJoinSub($awardPaid('bike'), 'bike_paid', 'bike_paid.allocation_id', '=', 'funds.id')
            ->where('funds.category', self::Category)
            ->where('funds.status', '!=', self::ClosedStatus)
            ->where('funds.amount', '>', 0)
            ->selectRaw("funds.id, ROUND(CASE funds.metadata->>'vehicle_pool' WHEN 'bike' THEN {$left} WHEN 'car' THEN 0 ELSE {$untaggedBike} END, 2) AS bike_left")
            ->selectRaw("ROUND(CASE funds.metadata->>'vehicle_pool' WHEN 'bike' THEN 0 WHEN 'car' THEN {$left} ELSE {$left} - {$untaggedBike} END, 2) AS car_left");
    }

    private function cents(mixed $value): int
    {
        $text = (string) $value;

        return str_starts_with($text, '-') ? -Money::cents(substr($text, 1)) : Money::cents($text);
    }
}

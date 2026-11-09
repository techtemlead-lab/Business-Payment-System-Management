<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionPolicy;
use App\Support\ListPage;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Leaders' progress toward the bike and car awards, for the office's car and bike fund page. One
 * award and one page of rows at a time, read from the progress saved as orders are delivered.
 */
final class RewardLeaderList
{
    public const Fund = 'car_bike';

    /** @var list<string> */
    public const Awards = ['bike', 'car'];

    /** @var list<string> */
    private const Stages = ['in_progress', 'reached', 'given'];

    public function __construct(private CommissionPolicy $policy) {}

    /**
     * The target and award set in the reward rule, null while the rule leaves one blank, and how
     * many leaders are on each list.
     *
     * @return array{targets: array{bike: ?string, car: ?string}, awards: array{bike: ?string, car: ?string}, counts: array{bike: int, car: int}}
     */
    public function summary(): array
    {
        $rule = $this->policy->snapshot()['rules']['car_bike_incentive']['parameters'];
        $counts = DB::table('reward_milestone_progress')->groupBy('milestone_type')->selectRaw('milestone_type, COUNT(*) AS total')->pluck('total', 'milestone_type');

        return [
            'targets' => ['bike' => $this->setAmount($rule['bike_target'] ?? null), 'car' => $this->setAmount($rule['car_target'] ?? null)],
            'awards' => ['bike' => $this->setAmount($rule['bike_award_amount'] ?? null), 'car' => $this->setAmount($rule['car_award_amount'] ?? null)],
            'counts' => ['bike' => (int) ($counts['bike'] ?? 0), 'car' => (int) ($counts['car'] ?? 0)],
        ];
    }

    /**
     * @param  ?string  $target  this award's target from {@see self::summary()}
     * @return array<string, mixed> MoneyTable payload
     */
    public function table(Request $request, string $award, ?string $target): array
    {
        $leaders = DB::table('reward_milestone_progress as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.milestone_type', $award)
            ->selectRaw("m.id, u.name AS partner_name, u.dealer_code AS partner_code, u.phone AS partner_phone, u.district AS partner_district, m.qualifying_volume, CASE WHEN m.claimed THEN 'given' WHEN m.achieved_at IS NOT NULL OR m.qualifying_volume >= ?::numeric THEN 'reached' ELSE 'in_progress' END AS stage", [$target]);

        $table = MoneyTable::make($request, DB::query()->fromSub($leaders, 'x'), new MoneyTableSpec(
            dateColumn: null,
            search: ['x.partner_name', 'x.partner_code', 'x.partner_phone', 'x.partner_district'],
            filters: [new MoneyTableFilter('stage', (string) __('treasury.filter_status'), 'x.stage', array_map(
                fn (string $stage): array => ['value' => $stage, 'label' => (string) __('portal.status_'.$stage)],
                self::Stages,
            ))],
            sorts: ['volume' => 'x.qualifying_volume', 'name' => 'x.partner_name', 'status' => "CASE x.stage WHEN 'given' THEN 2 WHEN 'reached' THEN 1 ELSE 0 END"],
            defaultSort: 'volume',
            tieBreaker: 'x.id',
            defaultPeriod: null,
            perPage: ListPage::PerPage,
        ));
        $targetCents = $target === null ? 0 : Money::cents($target);
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => (int) $row->id,
            'partner_name' => $row->partner_name,
            'partner_code' => $row->partner_code,
            'partner_phone' => $row->partner_phone,
            'partner_district' => $row->partner_district,
            'qualifying_volume' => (string) $row->qualifying_volume,
            'progress' => $targetCents > 0 ? (string) min(100, round(Money::cents((string) $row->qualifying_volume) / $targetCents * 100, 1)) : null,
            'stage' => $row->stage,
        ]);

        return $table;
    }

    /** A rule amount above zero as a plain decimal, or null when the rule has none. */
    private function setAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $cents = Money::cents((string) $value);

        return $cents > 0 ? Money::decimal($cents) : null;
    }
}

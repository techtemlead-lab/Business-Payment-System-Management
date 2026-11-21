<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Models\DealerRank;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One dealer's monthly targets: the salary target, the rank target and the royalty target.
 *
 * The numbers come from the commission rules the owner edits in the CMS. The owner can give one
 * dealer a different target for one month; that override is kept in partner_monthly_targets and
 * leaves the company rule untouched. Targets are progress markers only: no money moves here.
 */
final class PartnerMonthlyTargets
{
    public const Salary = 'salary';

    public const Rank = 'rank';

    public const Royalty = 'royalty';

    public const Incentive = 'incentive';

    /** @var list<string> */
    public const Kinds = [self::Salary, self::Rank, self::Royalty, self::Incentive];

    /** The home-page target row each kind replaces. */
    private const HomeKeys = [self::Salary => 'monthly_salary', self::Rank => 'rank_reward', self::Incentive => 'bike_incentive'];

    public function __construct(private CommissionQualification $qualification) {}

    /**
     * The three targets of one dealer for one month, with progress.
     *
     * @return list<array{kind: string, unit: string, label: string, hint: string, current: string, default_target: string, target: string, override: ?string, note: ?string, percent: float, remaining: string, has_target: bool}>
     */
    public function for(User $user, string $month): array
    {
        if ($user->role !== 'dealer') {
            return [];
        }
        CommissionQualification::period($month);
        $rules = app(CommissionPolicy::class)->snapshot(false)['rules'];
        $overrides = $this->overrides((int) $user->id, $month);
        $volume = $this->qualification->monthlyVolume((int) $user->id, $month);
        $rank = DealerRank::query()->where('user_id', $user->id)->first();
        $currentRank = $rank->current_rank ?? 'dealer';

        return [
            $this->salaryRow($rules, $volume, $overrides),
            $this->rankRow($user, $rules, $rank, $currentRank, $overrides),
            $this->royaltyRow($rules, $volume, $currentRank, $overrides),
            $this->incentiveRow($user, $rules, $overrides),
        ];
    }

    /**
     * Puts the owner's overrides into the target rows the dealer's home page already shows. Rows
     * without an override are left exactly as they were. Everything is worked out from what the
     * home page already read, so this costs one extra query.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    public function forHome(User $user, array $targets, string $month): array
    {
        if ($user->role !== 'dealer') {
            return $targets;
        }
        $overrides = $this->overrides((int) $user->id, $month);
        $homeKey = array_flip(self::HomeKeys);
        foreach ($targets as $index => $row) {
            $kind = $homeKey[$row['key'] ?? ''] ?? null;
            if ($kind === null || ! isset($overrides[$kind]) || $this->isUnconditional($user, $kind)) {
                continue;
            }
            $isMoney = str_contains((string) $row['current'], '.');
            $current = $isMoney ? Money::cents((string) $row['current']) : (int) $row['current'];
            $target = $isMoney ? Money::cents($overrides[$kind]['amount']) : (int) round((float) $overrides[$kind]['amount']);
            $targets[$index] = [
                ...$row,
                'target' => $isMoney ? Money::decimal($target) : (string) $target,
                'remaining' => $isMoney ? Money::decimal(max(0, $target - $current)) : (string) max(0, $target - $current),
                'percent' => $this->percent($current, $target),
            ];
        }
        return $targets;
    }

    /**
     * The owner's overrides for one dealer and month, as kind => amount.
     *
     * @return array<string, array{amount: string, note: ?string}>
     */
    public function overrides(int $userId, string $month): array
    {
        return DB::table('partner_monthly_targets')
            ->where('user_id', $userId)->where('month', $month)
            ->get(['kind', 'amount', 'note'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->kind => ['amount' => (string) $row->amount, 'note' => $row->note]])
            ->all();
    }

    /**
     * Sets this month's own target for one dealer. A target of zero or less is refused; use clear() to go back to the company rule.
     */
    public function set(User $actor, User $partner, string $month, string $kind, string $amount, ?string $note): void
    {
        $this->assertPartner($partner, $month, $kind);
        $cents = Money::cents($amount);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => __('partner_file.err_target_positive')]);
        }
        $isCount = $kind === self::Rank && $this->rankUnit($partner) === 'count';
        if ($isCount && $cents % 100 !== 0) {
            throw ValidationException::withMessages(['amount' => __('partner_file.err_target_whole')]);
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
        DB::transaction(function () use ($actor, $partner, $month, $kind, $cents, $note): void {
            $before = DB::table('partner_monthly_targets')->where('user_id', $partner->id)->where('month', $month)->where('kind', $kind)->value('amount');
            DB::table('partner_monthly_targets')->upsert(
                [['user_id' => $partner->id, 'month' => $month, 'kind' => $kind, 'amount' => Money::decimal($cents), 'note' => $note, 'set_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]],
                ['user_id', 'month', 'kind'],
                ['amount', 'note', 'set_by', 'updated_at'],
            );
            Audit::record($actor->id, 'partner.target_set', (string) $partner->id, ['month' => $month, 'kind' => $kind, 'from' => $before, 'to' => Money::decimal($cents)]);
        });
    }

    /** Goes back to the company rule for that dealer, month and kind. */
    public function clear(User $actor, User $partner, string $month, string $kind): void
    {
        $this->assertPartner($partner, $month, $kind);
        DB::transaction(function () use ($actor, $partner, $month, $kind): void {
            $before = DB::table('partner_monthly_targets')->where('user_id', $partner->id)->where('month', $month)->where('kind', $kind)->value('amount');
            if ($before === null) {
                return;
            }
            DB::table('partner_monthly_targets')->where('user_id', $partner->id)->where('month', $month)->where('kind', $kind)->delete();
            Audit::record($actor->id, 'partner.target_cleared', (string) $partner->id, ['month' => $month, 'kind' => $kind, 'from' => $before]);
        });
    }

    /** A dealer the owner pays without conditions has no target to chase for that benefit. */
    private function isUnconditional(User $user, string $kind): bool
    {
        $controls = PartnerBenefitControls::for($user);

        return $kind === self::Salary ? $controls['unconditional_salary'] : ($controls['unconditional_rank_royalty'] || $controls['unconditional_rank_incentive']);
    }

    private function assertPartner(User $partner, string $month, string $kind): void
    {
        CommissionQualification::period($month);
        if ($partner->role !== 'dealer' || ! in_array($kind, self::Kinds, true)) {
            throw ValidationException::withMessages(['kind' => __('partner_file.err_target_kind')]);
        }
    }

    private function rankUnit(User $partner): string
    {
        $rank = DealerRank::query()->where('user_id', $partner->id)->value('current_rank') ?? 'dealer';

        return $rank === 'dealer' ? 'money' : 'count';
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, array{amount: string, note: ?string}>  $overrides
     * @return array<string, mixed>
     */
    private function salaryRow(array $rules, int $volume, array $overrides): array
    {
        $default = Money::cents((string) ($rules['dealer_monthly_salary']['parameters']['min_monthly_volume'] ?? '10000.00'));

        return $this->moneyRow(self::Salary, __('partner_file.target_salary'), __('partner_file.target_salary_hint'), $volume, $default, $overrides);
    }

    /**
     * The bike incentive: the team's qualifying purchases against the bike target.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, array{amount: string, note: ?string}>  $overrides
     * @return array<string, mixed>
     */
    private function incentiveRow(User $user, array $rules, array $overrides): array
    {
        $stored = RewardMilestoneProgress::query()->where('user_id', $user->id)->where('milestone_type', 'bike')->first();
        $current = Money::cents((string) ($stored === null ? $this->qualification->milestone($user->id, 'bike')['qualifying_volume'] : $stored->qualifying_volume));
        $default = Money::cents((string) ($rules['car_bike_incentive']['parameters']['bike_target'] ?? '0'));

        return $this->moneyRow(self::Incentive, __('partner_file.target_incentive'), __('partner_file.target_incentive_hint'), $current, $default, $overrides);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, array{amount: string, note: ?string}>  $overrides
     * @return array<string, mixed>
     */
    private function royaltyRow(array $rules, int $volume, string $currentRank, array $overrides): array
    {
        // Royalty is paid from MO upward, so a plain dealer is shown the first rank's monthly minimum.
        $code = $currentRank === 'dealer' ? 'mo' : $currentRank;
        $default = Money::cents((string) ($rules['rank_maintenance']['parameters'][$code.'_min_monthly_volume'] ?? '0'));

        return $this->moneyRow(self::Royalty, __('partner_file.target_royalty', ['rank' => strtoupper($code)]), __('partner_file.target_royalty_hint', ['rank' => strtoupper($code)]), $volume, $default, $overrides);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, array{amount: string, note: ?string}>  $overrides
     * @return array<string, mixed>
     */
    private function rankRow(User $user, array $rules, ?DealerRank $rank, string $currentRank, array $overrides): array
    {
        $parameters = $rules['rank_royalty']['parameters'];
        if ($currentRank === 'dealer') {
            $default = Money::cents((string) ($parameters['mo_target'] ?? '250000.00'));
            $lifetime = Money::cents((string) ($rank->lifetime_purchase_total ?? '0'));

            return $this->moneyRow(self::Rank, __('partner_file.target_rank', ['rank' => 'MO']), __('partner_file.target_rank_mo_hint'), $lifetime, $default, $overrides);
        }
        $levels = CommissionQualification::RankLevels;
        $next = $levels[array_search($currentRank, $levels, true) + 1] ?? null;
        $required = $next !== null ? (CommissionQualification::TeamRankRequirements[$next] ?? null) : null;
        if ($next === null || $required === null) {
            return [...$this->countRow(self::Rank, __('partner_file.target_rank_top'), __('partner_file.target_rank_top_hint'), 0, 0, $overrides), 'has_target' => false];
        }
        $needed = (int) ($parameters[$next.'_count'] ?? 10);
        $depth = (int) ($parameters['qualification_depth'] ?? 3);
        $counts = $this->qualification->teamRankCounts([(int) $user->id], $depth)[(int) $user->id] ?? [];
        $have = CommissionQualification::leadersAtOrAbove($counts, $required);

        return $this->countRow(self::Rank, __('partner_file.target_rank', ['rank' => strtoupper($next)]), __('partner_file.target_rank_team_hint', ['needed' => $needed, 'rank' => strtoupper($required), 'depth' => $depth]), $have, $needed, $overrides);
    }

    /**
     * @param  array<string, array{amount: string, note: ?string}>  $overrides
     * @return array<string, mixed>
     */
    private function moneyRow(string $kind, string $label, string $hint, int $current, int $default, array $overrides): array
    {
        $override = isset($overrides[$kind]) ? Money::cents($overrides[$kind]['amount']) : null;
        $target = $override ?? $default;

        return [
            'kind' => $kind, 'unit' => 'money', 'label' => $label, 'hint' => $hint,
            'current' => Money::decimal($current),
            'default_target' => Money::decimal($default),
            'target' => Money::decimal($target),
            'override' => $override !== null ? Money::decimal($override) : null,
            'note' => $overrides[$kind]['note'] ?? null,
            'percent' => $this->percent($current, $target),
            'remaining' => Money::decimal(max(0, $target - $current)),
            'has_target' => $target > 0,
        ];
    }

    /**
     * @param  array<string, array{amount: string, note: ?string}>  $overrides
     * @return array<string, mixed>
     */
    private function countRow(string $kind, string $label, string $hint, int $have, int $default, array $overrides): array
    {
        $override = isset($overrides[$kind]) ? (int) round((float) $overrides[$kind]['amount']) : null;
        $target = $override ?? $default;

        return [
            'kind' => $kind, 'unit' => 'count', 'label' => $label, 'hint' => $hint,
            'current' => (string) $have,
            'default_target' => (string) $default,
            'target' => (string) $target,
            'override' => $override !== null ? (string) $override : null,
            'note' => $overrides[$kind]['note'] ?? null,
            'percent' => $this->percent($have, $target),
            'remaining' => (string) max(0, $target - $have),
            'has_target' => $target > 0,
        ];
    }

    private function percent(int $current, int $target): float
    {
        return $target > 0 ? min(100.0, round(($current / $target) * 100, 1)) : 0.0;
    }
}

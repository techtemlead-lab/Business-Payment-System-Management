<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Models\CommissionRuleParameter;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use App\Support\ReasonFields;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CommissionPolicy
{
    public const Keys = ['own_dealer_cashback', 'direct_referral', 'd2d_multitier', 'area_dealer_pool',
        'dealer_monthly_salary', 'rank_royalty', 'rank_incentive', 'company_marketing', 'car_bike_incentive'];

    /** Award amounts that may stay blank until the owner decides. They are only shown; nothing pays them automatically. */
    private const AwardAmountParameters = ['bike_award_amount', 'car_award_amount'];

    /** Sales targets: a target of zero would make every partner qualify, so it must stay above zero. */
    private const TargetParameters = ['bike_target', 'car_target', 'mo_target', 'division_target', 'district_target', 'upazila_target', 'union_target',
        'sub_dealer_royalty_target', 'tier1_volume', 'tier2_volume', 'tier3_volume'];

    /**
     * Where the sub-dealer "extra" percent (rule additional_rank) goes: 'extra_fund' is the company fund "Sub-dealer
     * extra" (the default from 2026-11-20, owner decision); 'rank_fund' is the rank reward fund it went to before and
     * stays an option. An order keeps the rules it was captured with, so a change here reaches new orders only, and an
     * order captured before this setting existed has no value and keeps the old fund (ExtraDestinationBefore).
     */
    public const ExtraDestinationParameter = 'destination';

    public const ExtraDestinations = ['rank_fund', 'extra_fund'];

    public const ExtraDestinationDefault = 'extra_fund';

    /** What an order captured before the setting existed does: the extra part went to the rank fund. */
    public const ExtraDestinationBefore = 'rank_fund';

    /** Months a held area share may wait before it goes to the company fund (0 = never). */
    private const HeldMonthsParameter = 'held_share_months';

    private const HeldMonthsLimit = 120;

    /**
     * The bike and car prizes have a pool each. The reserve rate is split by the bike share (default half,
     * so the usual 2% is 1% bike and 1% car); the car pool gets the rest, so the two always add up to the rule's rate.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array{bike: Percentage, car: Percentage}
     */
    public static function vehiclePoolRates(array $rules): array
    {
        $total = self::rate($rules, 'car_bike_incentive')->basisPoints;
        $share = Percentage::from((string) ($rules['car_bike_incentive']['parameters']['bike_pool_share_pct'] ?? '50.00'))->basisPoints;
        $bike = intdiv($total * $share + 5000, 10000);

        return ['bike' => Percentage::fromBasisPoints($bike), 'car' => Percentage::fromBasisPoints($total - $bike)];
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        $definitions = [
            'own_dealer_cashback' => ['cashback', 'Doctor Honorarium', '5.00', []],
            'direct_referral' => ['referral', 'Staff commission', '10.00', []],
            'd2d_multitier' => ['d2d', 'Team income (2nd and 3rd step)', '3.00', ['gen2_pct' => '2.00', 'gen3_pct' => '1.00', 'dynamic_compression' => false]],
            'area_dealer_pool' => ['area', 'Area dealer pool', '10.00', ['management_share_pct' => '2.00', 'division_share_pct' => '2.00', 'district_share_pct' => '2.00', 'upazila_share_pct' => '2.00', 'union_share_pct' => '2.00',
                'division_monthly_quota' => '50000.00', 'district_monthly_quota' => '40000.00', 'upazila_monthly_quota' => '20000.00', 'union_monthly_quota' => '10000.00',
                'held_share_months' => 3]],
            // One monthly target per dealer by the kind of dealer (owner, 2026-11-21); it unlocks the salary, the rank
            // royalty and the area income of that month. min_monthly_volume is the target of a dealer with no area yet.
            'dealer_monthly_salary' => ['salary', 'Salary', '2.00', ['min_monthly_volume' => '30000.00',
                'division_target' => '100000.00', 'district_target' => '100000.00', 'upazila_target' => '50000.00', 'union_target' => '30000.00']],
            'rank_royalty' => ['rank', 'Rank royalty pool', '4.00', ['mo_target' => '500000.00', 'am_count' => 10, 'sm_count' => 10, 'me_count' => 10, 'qualification_depth' => 3, 'mo_share_pct' => '1.00', 'am_share_pct' => '1.00', 'sm_share_pct' => '1.00', 'me_share_pct' => '1.00',
                'sub_dealer_royalty_target' => '30000.00']],
            'rank_incentive' => ['incentive', 'Rank incentive reserve', '2.00', ['mo_bonus' => '5000.00', 'am_bonus' => '50000.00', 'sm_bonus' => '250000.00', 'me_bonus' => '300000.00']],
            'company_marketing' => ['marketing', 'Marketing reserve', '2.00', []],
            // Owner's rule of 2026-11-21: bike at ৳1 crore of team sales (at most ৳20 lakh from one direct line),
            // prize ৳3 lakh; car at ৳50 crore (at most ৳8 crore from one direct line), prize ৳25 lakh.
            'car_bike_incentive' => ['rewards', 'Car and motorcycle reserve', '2.00', ['bike_target' => '10000000.00', 'bike_leg_cap' => '2000000.00', 'car_target' => '500000000.00', 'car_leg_cap' => '80000000.00', 'bike_award_amount' => '300000.00', 'car_award_amount' => '2500000.00', 'bike_pool_share_pct' => '50.00']],
            'additional_rank' => ['sub_dealer', 'Sub-dealer extra', '4.00', ['destination' => self::ExtraDestinationDefault]],
            // The salary is set aside at the full percent; at month end the sub-dealer is paid the tier his own month's
            // purchase reached and the rest goes to the company profit fund (owner, 2026-11-21).
            'sub_dealer_salary' => ['sub_dealer', 'Sub-dealer salary', '3.00', ['tier1_volume' => '10000.00', 'tier1_pct' => '1.00',
                'tier2_volume' => '20000.00', 'tier2_pct' => '2.00', 'tier3_volume' => '40000.00', 'tier3_pct' => '3.00']],
            'sub_dealer_tour' => ['sub_dealer', 'Tour', '3.00', []],
            'commission_cooling_off' => ['operations', 'Commission cooling-off', '0.00', ['cooling_off_days' => 0]],
            'payout_tax_withholding' => ['operations', 'Configured payout withholding', '0.00', ['threshold' => '0.00']],
            'rank_maintenance' => ['operations', 'Rank maintenance', '0.00', ['mo_min_monthly_volume' => '5000.00', 'am_min_monthly_volume' => '15000.00', 'sm_min_monthly_volume' => '30000.00', 'me_min_monthly_volume' => '50000.00', 'grace_period_days' => 30]],
        ];
        $rules = [];
        foreach ($definitions as $key => [$category, $name, $rate, $parameters]) {
            $rules[$key] = ['rule_key' => $key, 'category' => $category, 'name' => $name, 'percentage' => $rate,
                'parameters' => $parameters, 'amount_threshold' => '0.00', 'cap_limit' => '0.00', 'is_active' => true, 'version' => 1];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function snapshot(bool $validate = true, ?User $buyer = null, ?CarbonImmutable $at = null): array
    {
        $rules = self::defaults();
        foreach (CommissionRuleParameter::all() as $rule) {
            if (isset($rules[$rule->rule_key])) {
                $data = $rule->only(['percentage', 'is_active', 'version', 'amount_threshold', 'cap_limit']);
                $data['parameters'] = array_replace($rules[$rule->rule_key]['parameters'], array_intersect_key($rule->parameters ?? [], $rules[$rule->rule_key]['parameters']));
                $rules[$rule->rule_key] = array_replace($rules[$rule->rule_key], $data);
            }
        }
        $at ??= CarbonImmutable::now();
        $revision = DB::table('commission_policy_revisions as revisions')->leftJoin('commission_policy_schedules as schedules', 'schedules.id', '=', 'revisions.schedule_id')
            ->where('revisions.effective_at', '<=', $at)->where('revisions.created_at', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->where('revisions.source', 'admin')->orWhere(function ($query) use ($at): void {
                    $query->where('revisions.source', 'scheduled')->where('schedules.status', 'approved')->where('schedules.scope', 'global')
                        ->where(fn ($query) => $query->whereNull('schedules.expires_at')->orWhere('schedules.expires_at', '>', $at));
                });
            })->orderByDesc('revisions.effective_at')->orderByDesc('revisions.id')->first(['revisions.id', 'revisions.rules']);
        if ($revision) {
            $rules = json_decode($revision->rules, true, flags: JSON_THROW_ON_ERROR);
        }
        $override = $buyer ? $this->activeSchedule('buyer', $buyer->id, $at) : null;
        if ($override) {
            $rules = json_decode($override->rules, true, flags: JSON_THROW_ON_ERROR);
        }
        $rules['d2d_multitier']['parameters']['dynamic_compression'] = false;
        // Revisions saved before a setting existed (award amounts, standard area targets, the held-share limit)
        // do not carry it: the default applies until the owner saves the rule.
        foreach (self::defaults() as $key => $default) {
            if (isset($rules[$key]) && is_array($rules[$key])) {
                $rules[$key]['parameters'] = ($rules[$key]['parameters'] ?? []) + $default['parameters'];
            }
        }
        foreach ($rules as $key => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $translated = __('portal.rule_name_'.$key);
            if ($translated !== 'portal.rule_name_'.$key) {
                $rules[$key]['name'] = $translated;
            }
        }
        if ($validate) {
            $this->validateSchedule($rules);
        }

        return ['basis' => 'purchase_value', 'rounding' => 'half_up', 'company_mrp_percent' => '30.00',
            'captured_at' => $at->toIso8601String(), 'revision_id' => $revision?->id, 'buyer_schedule_id' => $override?->id, 'rules' => $rules];
    }

    public function activeSchedule(string $scope, ?int $userId, CarbonImmutable $at): ?object
    {
        return DB::table('commission_policy_schedules')->where('scope', $scope)->where('user_id', $userId)->where('status', 'approved')
            ->where('effective_at', '<=', $at)->where('approved_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $at))
            ->orderByDesc('effective_at')->orderByDesc('id')->first();
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    public function recipientOverrides(array $ids): array
    {
        $result = [];
        $at = CarbonImmutable::now();
        $rows = DB::table('commission_policy_schedules')->where('scope', 'recipient')->whereIn('user_id', array_unique($ids))->where('status', 'approved')
            ->where('effective_at', '<=', $at)->where('approved_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $at))
            ->orderByDesc('effective_at')->orderByDesc('id')->get()->unique('user_id');
        foreach ($rows as $row) {
            $result[$row->user_id] = ['schedule_id' => $row->id, 'rates' => json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR)];
        }

        return $result;
    }

    /** @param array<string, array<string, mixed>> $base @param array<string, mixed> $changes @return array<string, array<string, mixed>> */
    public function compose(array $base, array $changes): array
    {
        if ($changes === [] || array_diff_key($changes, self::defaults()) !== []) {
            throw ValidationException::withMessages(['rules' => __('portal.err_policy_unknown_keys')]);
        }
        foreach ($changes as $key => $input) {
            if (! is_array($input)) {
                throw ValidationException::withMessages(['rules' => __('portal.err_policy_rule_object')]);
            }
            $data = Validator::make($input, ['percentage' => 'sometimes|required|numeric|between:0,100|decimal:0,2',
                'parameters' => 'sometimes|array', 'is_active' => 'sometimes|boolean'])->validate();
            if (($data['parameters']['dynamic_compression'] ?? false) === true) {
                throw ValidationException::withMessages(['rules' => __('portal.err_policy_compression_disabled')]);
            }
            if (isset($data['parameters'])) {
                if (array_diff_key($data['parameters'], self::defaults()[$key]['parameters']) !== []) {
                    throw ValidationException::withMessages(['rules' => __('portal.err_policy_unsupported_params', ['key' => $key])]);
                }
                $data['parameters'] = array_replace($base[$key]['parameters'], $data['parameters']);
            }
            $base[$key] = array_replace($base[$key], $data);
        }
        $this->validateSchedule($base);

        return $base;
    }

    /** Only the company owner (Super Admin) approves commission rate changes (owner decision, 2026-11-05). */
    public static function approves(User $actor): bool
    {
        return $actor->status === 'active' && $actor->role === 'super_admin';
    }

    /**
     * Change commission rules. The owner's own change applies at once. Anyone else's change
     * is saved as a pending change: the old rates stay in force until the owner approves it.
     *
     * @param  array<string, mixed>  $rules
     * @return array{status: 'applied'|'pending', schedule_id: ?int}
     */
    public function update(User $actor, array $rules, ?string $reason = null): array
    {
        abort_unless($actor->canAct('finance', 'edit'), 403);
        if ($rules === [] || array_diff_key($rules, self::defaults()) !== []) {
            throw ValidationException::withMessages(['rules' => __('portal.err_policy_unknown_keys')]);
        }

        return DB::transaction(function () use ($actor, $rules, $reason): array {
            CommissionWallet::lock();
            CommissionRuleParameter::query()->orderBy('id')->lockForUpdate()->get();
            $live = $this->snapshot(false);
            $merged = $live['rules'];
            $before = $merged;
            foreach ($rules as $key => $input) {
                if (! is_array($input)) {
                    throw ValidationException::withMessages(['rules' => __('portal.err_policy_rule_object')]);
                }
                $data = Validator::make($input, [
                    'version' => ['sometimes', 'integer', 'min:1'],
                    'percentage' => ['sometimes', 'required', 'numeric', 'between:0,100', 'decimal:0,2'],
                    'amount_threshold' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
                    'cap_limit' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
                    'parameters' => ['sometimes', 'array'], 'is_active' => ['sometimes', 'boolean'],
                ])->validate();
                if (isset($data['version']) && (int) $data['version'] !== (int) $merged[$key]['version']) {
                    throw ValidationException::withMessages(['rules' => __('portal.err_policy_stale_version')]);
                }
                unset($data['version']);
                if (($data['parameters']['dynamic_compression'] ?? false) === true) {
                    throw ValidationException::withMessages(['rules' => __('portal.err_policy_compression_disabled')]);
                }
                if (isset($data['parameters'])) {
                    if (array_diff_key($data['parameters'], self::defaults()[$key]['parameters']) !== []) {
                        throw ValidationException::withMessages(['rules' => __('portal.err_policy_unsupported_params', ['key' => $key])]);
                    }
                    $data['parameters'] = $this->normalizeParameters($data['parameters']);
                    $data['parameters'] = array_replace($merged[$key]['parameters'] ?? [], $data['parameters']);
                }
                $merged[$key] = array_replace($merged[$key], $data);
            }
            $this->validateSchedule($merged);
            $changes = PolicyChangeSummary::diff(array_intersect_key($before, $rules), array_intersect_key($merged, $rules));
            if (! self::approves($actor)) {
                if ($changes === []) {
                    throw ValidationException::withMessages(['rules' => __('portal.err_policy_no_change')]);
                }
                $id = DB::table('commission_policy_schedules')->insertGetId([
                    'scope' => 'global', 'user_id' => null, 'origin' => 'rule_edit',
                    'rules' => json_encode($merged, JSON_THROW_ON_ERROR), 'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                    'base_revision_id' => $live['revision_id'], 'effective_at' => now(), 'expires_at' => null,
                    'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : __('portal.policy_change_default_reason'),
                    'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                Audit::record($actor->id, 'commissions.rules_change_proposed', (string) $id, [
                    'rule_keys' => array_keys($changes),
                    'changes' => $changes,
                ]);
                $this->tellOwners($actor, $changes);

                return ['status' => 'pending', 'schedule_id' => $id];
            }

            $this->apply($actor, $merged, array_keys($rules), null);
            Audit::record($actor->id, 'commissions.rules_updated', 'system', [
                'rule_keys' => array_keys($changes),
                'changes' => $changes,
                'proposed_by' => $actor->id,
                'approved_by' => $actor->id,
                ...(ReasonFields::text($reason) !== '' ? ['reason' => ReasonFields::text($reason)] : []),
            ]);

            return ['status' => 'applied', 'schedule_id' => null];
        }, 3);
    }

    /**
     * Make rules live: bump the per-rule versions and write a new revision. The caller holds the
     * commission lock and has validated the rules.
     *
     * @param  array<string, array<string, mixed>>  $merged
     * @param  list<string>  $keys
     */
    public function apply(User $approver, array $merged, array $keys, ?int $scheduleId): void
    {
        foreach ($keys as $key) {
            $row = CommissionRuleParameter::firstOrCreate(['rule_key' => $key], self::defaults()[$key]);
            $row->update([...collect($merged[$key])->only(['percentage', 'amount_threshold', 'cap_limit', 'parameters', 'is_active'])->all(),
                'version' => $row->version + 1, 'updated_by' => $approver->id]);
            $merged[$key]['version'] = $row->version;
        }
        DB::table('commission_policy_revisions')->insert(['rules' => json_encode($merged, JSON_THROW_ON_ERROR), 'schedule_id' => $scheduleId,
            'effective_at' => now(), 'created_by' => $approver->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $changes */
    private function tellOwners(User $actor, array $changes): void
    {
        $summary = PolicyChangeSummary::describe($changes);
        foreach (User::query()->where('role', 'super_admin')->where('status', 'active')->get() as $owner) {
            $locale = $owner->preferredLocale();
            Audit::notice(
                $owner->id,
                __('portal.notice_policy_change_pending_title', [], $locale),
                __('portal.notice_policy_change_pending_body', ['name' => $actor->name, 'summary' => $summary], $locale),
            );
        }
    }

    /** @param array<string, array<string, mixed>> $rules */
    public function validateSchedule(array $rules): void
    {
        try {
            $dealer = 0;
            foreach (self::Keys as $key) {
                $dealer += self::rate($rules, $key)->basisPoints;
            }
            $sub = $dealer;
            foreach (['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'] as $key) {
                $sub += self::rate($rules, $key)->basisPoints;
            }
            if ($dealer > 4000 || $sub > 5000) {
                throw new InvalidArgumentException(__('portal.err_policy_pool_limits'));
            }
            foreach ($rules as $rule) {
                foreach (($rule['parameters'] ?? []) as $name => $value) {
                    if ($name === 'dynamic_compression') {
                        if (! is_bool($value)) {
                            throw new InvalidArgumentException(__('portal.err_policy_compression_bool'));
                        }
                    } elseif ($name === self::ExtraDestinationParameter) {
                        if (! in_array($value, self::ExtraDestinations, true)) {
                            throw new InvalidArgumentException(__('commission_page.err_extra_destination'));
                        }
                    } elseif (in_array($name, self::AwardAmountParameters, true) && ($value === null || $value === '')) {
                        continue;
                    } elseif (str_ends_with($name, '_pct')) {
                        Percentage::from((string) $value);
                    } elseif ($name === self::HeldMonthsParameter) {
                        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 0 || $value > self::HeldMonthsLimit) {
                            throw new InvalidArgumentException(__('reward_settings.err_held_months'));
                        }
                    } elseif (in_array($name, ['cooling_off_days', 'grace_period_days', 'am_count', 'sm_count', 'me_count', 'qualification_depth'], true)) {
                        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 0 || $value > 366 || ((str_ends_with($name, '_count') || $name === 'qualification_depth') && $value < 1)) {
                            throw new InvalidArgumentException(__('portal.err_policy_invalid_int', ['name' => $name]));
                        }
                    } else {
                        if (Money::cents((string) $value) === 0 && in_array($name, self::TargetParameters, true)) {
                            throw new InvalidArgumentException(__('reward_settings.err_target_zero', ['name' => $name]));
                        }
                    }
                }
            }
            $d2d = $rules['d2d_multitier']['parameters'];
            if (Percentage::from((string) $d2d['gen2_pct'])->basisPoints + Percentage::from((string) $d2d['gen3_pct'])->basisPoints !== Percentage::from((string) $rules['d2d_multitier']['percentage'])->basisPoints) {
                throw new InvalidArgumentException(__('portal.err_policy_d2d_shares'));
            }
            $area = $rules['area_dealer_pool']['parameters'];
            $areaTotal = 0;
            foreach (['management', 'division', 'district', 'upazila', 'union'] as $level) {
                $areaTotal += Percentage::from((string) $area[$level.'_share_pct'])->basisPoints;
            }
            if ($areaTotal !== Percentage::from((string) $rules['area_dealer_pool']['percentage'])->basisPoints) {
                throw new InvalidArgumentException(__('portal.err_policy_area_shares'));
            }
            $royaltyTotal = 0;
            foreach (['mo', 'am', 'sm', 'me'] as $rank) {
                $royaltyTotal += Percentage::from((string) $rules['rank_royalty']['parameters'][$rank.'_share_pct'])->basisPoints;
            }
            if ($royaltyTotal !== Percentage::from((string) $rules['rank_royalty']['percentage'])->basisPoints) {
                throw new InvalidArgumentException(__('portal.err_policy_rank_shares'));
            }
            $this->validateSalaryTiers($rules);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rules' => $exception->getMessage()]);
        }
    }

    /**
     * Sub-dealer salary tiers: each tier needs more purchase than the one before, pays at least as much, and never
     * more than the salary percent set aside on every order. Rules saved before the tiers existed have none.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    private function validateSalaryTiers(array $rules): void
    {
        $tiers = MonthlyTargets::salaryTiers($rules);
        if ($tiers === []) {
            return;
        }
        $setAside = Percentage::from((string) $rules['sub_dealer_salary']['percentage'])->basisPoints;
        $previous = null;
        foreach ($tiers as $tier) {
            if ($tier['basis_points'] > $setAside || ($previous !== null && ($tier['volume'] <= $previous['volume'] || $tier['basis_points'] < $previous['basis_points']))) {
                throw new InvalidArgumentException(__('commission_page.err_salary_tiers'));
            }
            $previous = $tier;
        }
    }

    /**
     * The monthly target a newly seated area dealer starts with, from the area rule (the seat itself
     * can still be changed by hand). An unknown level has none. Orders captured under the owner's rule of
     * 2026-11-21 gate area income with the dealer's one monthly target (MonthlyTargets) instead.
     */
    public static function standardAreaQuota(?string $level): string
    {
        if (! in_array($level, ['division', 'district', 'upazila', 'union'], true)) {
            return '0.00';
        }
        $parameters = app(self::class)->snapshot(false)['rules']['area_dealer_pool']['parameters'];

        return Money::decimal(Money::cents((string) $parameters[$level.'_monthly_quota']));
    }

    /** Months a held area share may wait before it goes to the company fund; 0 means it never does. */
    public static function heldShareMonths(): int
    {
        return max(0, (int) app(self::class)->snapshot(false)['rules']['area_dealer_pool']['parameters'][self::HeldMonthsParameter]);
    }

    /**
     * Normalize Bangla digits, commas, integer fields, and money/percent strings before validation.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function normalizeParameters(array $parameters): array
    {
        $intKeys = ['held_share_months', 'cooling_off_days', 'grace_period_days', 'am_count', 'sm_count', 'me_count', 'qualification_depth'];
        $normalized = [];
        foreach ($parameters as $name => $value) {
            if (! is_string($value)) {
                $normalized[$name] = $value;

                continue;
            }
            $clean = str_replace([',', ' '], '', trim(LocalDigits::latin($value)));
            if (in_array($name, self::AwardAmountParameters, true) && $clean === '') {
                $normalized[$name] = null;

                continue;
            }
            if (in_array($name, $intKeys, true)) {
                $normalized[$name] = preg_match('/^-?\d+$/D', $clean) ? (int) $clean : $clean;

                continue;
            }
            if ($name === self::ExtraDestinationParameter || $name === 'dynamic_compression') {
                $normalized[$name] = $clean;

                continue;
            }
            if (str_ends_with($name, '_pct')) {
                try {
                    $normalized[$name] = Percentage::from($clean)->decimal();
                } catch (\Throwable) {
                    $normalized[$name] = $clean;
                }

                continue;
            }
            try {
                $normalized[$name] = Money::decimal(Money::cents($clean));
            } catch (\Throwable) {
                $normalized[$name] = $clean;
            }
        }

        return $normalized;
    }

    /** @param array<string, array<string, mixed>> $rules */
    public static function rate(array $rules, string $key): Percentage
    {
        return Percentage::from(($rules[$key]['is_active'] ?? false) ? (string) $rules[$key]['percentage'] : '0.00');
    }
}

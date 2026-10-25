<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Models\CommissionRuleParameter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CommissionPolicy
{
    public const Keys = ['own_dealer_cashback', 'direct_referral', 'd2d_multitier', 'area_dealer_pool',
        'dealer_monthly_salary', 'rank_royalty', 'rank_incentive', 'company_marketing', 'car_bike_incentive'];

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        $definitions = [
            'own_dealer_cashback' => ['cashback', 'Own purchase cashback', '5.00', []],
            'direct_referral' => ['referral', 'Direct referral', '10.00', []],
            'd2d_multitier' => ['d2d', 'Dealer-to-dealer referral', '3.00', ['gen2_pct' => '2.00', 'gen3_pct' => '1.00', 'dynamic_compression' => false]],
            'area_dealer_pool' => ['area', 'Area dealer pool', '10.00', ['management_share_pct' => '2.00', 'division_share_pct' => '2.00', 'district_share_pct' => '2.00', 'upazila_share_pct' => '2.00', 'union_share_pct' => '2.00']],
            'dealer_monthly_salary' => ['salary', 'Dealer monthly salary pool', '2.00', ['min_monthly_volume' => '10000.00']],
            'rank_royalty' => ['rank', 'Rank royalty pool', '4.00', ['mo_target' => '250000.00', 'am_count' => 10, 'sm_count' => 10, 'me_count' => 10, 'qualification_depth' => 3, 'mo_share_pct' => '1.00', 'am_share_pct' => '1.00', 'sm_share_pct' => '1.00', 'me_share_pct' => '1.00']],
            'rank_incentive' => ['incentive', 'Rank incentive reserve', '2.00', ['mo_bonus' => '5000.00', 'am_bonus' => '50000.00', 'sm_bonus' => '250000.00', 'me_bonus' => '300000.00']],
            'company_marketing' => ['marketing', 'Marketing reserve', '2.00', []],
            'car_bike_incentive' => ['rewards', 'Car and motorcycle reserve', '2.00', ['bike_target' => '5000000.00', 'bike_leg_cap' => '800000.00', 'car_target' => '250000000.00', 'car_leg_cap' => '20000000.00']],
            'additional_rank' => ['sub_dealer', 'Sub-dealer additional rank reserve', '4.00', []],
            'sub_dealer_salary' => ['sub_dealer', 'Sub-dealer monthly salary', '3.00', []],
            'sub_dealer_tour' => ['sub_dealer', 'Sub-dealer tour reserve', '3.00', []],
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

    /** @param array<string, mixed> $rules */
    public function update(User $actor, array $rules): void
    {
        abort_unless($actor->canAct('finance', 'edit'), 403);
        if ($rules === [] || array_diff_key($rules, self::defaults()) !== []) {
            throw ValidationException::withMessages(['rules' => __('portal.err_policy_unknown_keys')]);
        }
        DB::transaction(function () use ($actor, $rules): void {
            CommissionWallet::lock();
            CommissionRuleParameter::query()->orderBy('id')->lockForUpdate()->get();
            $merged = $this->snapshot(false)['rules'];
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
                    $data['parameters'] = array_replace($merged[$key]['parameters'] ?? [], $data['parameters']);
                }
                $merged[$key] = array_replace($merged[$key], $data);
            }
            $this->validateSchedule($merged);
            foreach (array_keys($rules) as $key) {
                $row = CommissionRuleParameter::firstOrCreate(['rule_key' => $key], self::defaults()[$key]);
                $row->update([...collect($merged[$key])->only(['percentage', 'amount_threshold', 'cap_limit', 'parameters', 'is_active'])->all(),
                    'version' => $row->version + 1, 'updated_by' => $actor->id]);
                $merged[$key]['version'] = $row->version;
            }
            DB::table('commission_policy_revisions')->insert(['rules' => json_encode($merged, JSON_THROW_ON_ERROR),
                'effective_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'commissions.rules_updated', 'system', ['rule_keys' => array_keys($rules)]);
        }, 3);
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
                    } elseif (str_ends_with($name, '_pct')) {
                        Percentage::from((string) $value);
                    } elseif (in_array($name, ['cooling_off_days', 'grace_period_days', 'am_count', 'sm_count', 'me_count', 'qualification_depth'], true)) {
                        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 0 || $value > 366 || (str_ends_with($name, '_count') && $value < 1)) {
                            throw new InvalidArgumentException(__('portal.err_policy_invalid_int', ['name' => $name]));
                        }
                    } else {
                        Money::cents((string) $value);
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
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rules' => $exception->getMessage()]);
        }
    }

    /** @param array<string, array<string, mixed>> $rules */
    public static function rate(array $rules, string $key): Percentage
    {
        return Percentage::from(($rules[$key]['is_active'] ?? false) ? (string) $rules[$key]['percentage'] : '0.00');
    }
}

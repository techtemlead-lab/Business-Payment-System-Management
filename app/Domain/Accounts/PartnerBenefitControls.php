<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\MonthlyTargets;
use App\Domain\Finance\Percentage;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use App\Support\RankLabel;
use App\Support\ReasonFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PartnerBenefitControls
{
    public const Ranks = ['mo', 'am', 'sm', 'me'];

    /**
     * Per-person customizable condition thresholds (higher or lower than the general rule).
     *
     * @var array<string, array{type: 'money'|'percent'|'int', min: float|int, max?: float|int, roles: list<string>}>
     */
    public const CustomConditions = [
        'monthly_target' => ['type' => 'money', 'min' => 0.01, 'roles' => ['dealer']],
        'sub_dealer_royalty_target' => ['type' => 'money', 'min' => 0.01, 'roles' => ['sub_dealer']],
        'tier1_volume' => ['type' => 'money', 'min' => 0.01, 'roles' => ['sub_dealer']],
        'tier1_pct' => ['type' => 'percent', 'min' => 0, 'max' => 100, 'roles' => ['sub_dealer']],
        'tier2_volume' => ['type' => 'money', 'min' => 0.01, 'roles' => ['sub_dealer']],
        'tier2_pct' => ['type' => 'percent', 'min' => 0, 'max' => 100, 'roles' => ['sub_dealer']],
        'tier3_volume' => ['type' => 'money', 'min' => 0.01, 'roles' => ['sub_dealer']],
        'tier3_pct' => ['type' => 'percent', 'min' => 0, 'max' => 100, 'roles' => ['sub_dealer']],
        'mo_target' => ['type' => 'money', 'min' => 0.01, 'roles' => ['dealer', 'sub_dealer']],
        'am_count' => ['type' => 'int', 'min' => 1, 'max' => 366, 'roles' => ['dealer', 'sub_dealer']],
        'sm_count' => ['type' => 'int', 'min' => 1, 'max' => 366, 'roles' => ['dealer', 'sub_dealer']],
        'me_count' => ['type' => 'int', 'min' => 1, 'max' => 366, 'roles' => ['dealer', 'sub_dealer']],
        'qualification_depth' => ['type' => 'int', 'min' => 1, 'max' => 366, 'roles' => ['dealer', 'sub_dealer']],
        'bike_target' => ['type' => 'money', 'min' => 0.01, 'roles' => ['dealer', 'sub_dealer']],
        'bike_leg_cap' => ['type' => 'money', 'min' => 0, 'roles' => ['dealer', 'sub_dealer']],
        'car_target' => ['type' => 'money', 'min' => 0.01, 'roles' => ['dealer', 'sub_dealer']],
        'car_leg_cap' => ['type' => 'money', 'min' => 0, 'roles' => ['dealer', 'sub_dealer']],
        'cooling_off_days' => ['type' => 'int', 'min' => 0, 'max' => 366, 'roles' => ['dealer', 'sub_dealer']],
    ];

    /**
     * @return array{
     *   unconditional_salary: bool,
     *   unconditional_rank_royalty: bool,
     *   forced_royalty_rank: string|null,
     *   unconditional_rank_incentive: bool,
     *   custom_conditions: array<string, string|int>,
     *   note: string|null,
     *   updated_by: int|null,
     *   updated_at: string|null
     * }
     */
    public static function for(User $user): array
    {
        $raw = $user->getAttribute('profile');
        $rawControls = is_array($raw) ? ($raw['benefit_controls'] ?? null) : null;
        /** @var array<string, mixed> $controls */
        $controls = is_array($rawControls) ? $rawControls : [];

        return [
            'unconditional_salary' => (bool) ($controls['unconditional_salary'] ?? false),
            'unconditional_rank_royalty' => (bool) ($controls['unconditional_rank_royalty'] ?? false),
            'forced_royalty_rank' => self::normalizeRank($controls['forced_royalty_rank'] ?? null),
            'unconditional_rank_incentive' => (bool) ($controls['unconditional_rank_incentive'] ?? false),
            'custom_conditions' => self::sanitizeStoredCustom($controls['custom_conditions'] ?? null),
            'note' => isset($controls['note']) ? (string) $controls['note'] : null,
            'updated_by' => isset($controls['updated_by']) ? (int) $controls['updated_by'] : null,
            'updated_at' => isset($controls['updated_at']) ? (string) $controls['updated_at'] : null,
        ];
    }

    public static function customMoneyCents(User $user, string $key): ?int
    {
        $custom = self::for($user)['custom_conditions'];
        if (! isset($custom[$key])) {
            return null;
        }

        return Money::cents((string) $custom[$key]);
    }

    public static function customInt(User $user, string $key): ?int
    {
        $custom = self::for($user)['custom_conditions'];
        if (! isset($custom[$key])) {
            return null;
        }

        return (int) $custom[$key];
    }

    /**
     * Dealer's effective monthly target in paisa:
     * single-month override -> per-person custom monthly target -> general kind target.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function effectiveDealerMonthlyTarget(User $user, array $rules, ?string $kind, ?int $monthException = null): int
    {
        if ($monthException !== null) {
            return $monthException;
        }
        $custom = self::customMoneyCents($user, 'monthly_target');
        if ($custom !== null) {
            return $custom;
        }

        return MonthlyTargets::dealerTarget($rules, $kind);
    }

    /**
     * Sub-dealer's effective monthly purchase target for rank royalty, in paisa.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function effectiveSubDealerRoyaltyTarget(User $user, array $rules): int
    {
        $custom = self::customMoneyCents($user, 'sub_dealer_royalty_target');

        return $custom ?? MonthlyTargets::subDealerRoyaltyTarget($rules);
    }

    /**
     * Rules array with any per-person sub-dealer salary tier overrides applied.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, array<string, mixed>>
     */
    public static function effectiveSubDealerRules(User $user, array $rules): array
    {
        $custom = self::for($user)['custom_conditions'];
        $tierKeys = ['tier1_volume', 'tier1_pct', 'tier2_volume', 'tier2_pct', 'tier3_volume', 'tier3_pct'];
        foreach ($tierKeys as $key) {
            if (isset($custom[$key]) && isset($rules['sub_dealer_salary']['parameters'])) {
                $rules['sub_dealer_salary']['parameters'][$key] = (string) $custom[$key];
            }
        }

        return $rules;
    }

    /**
     * Rank royalty parameters with any per-person overrides (mo_target, team counts, depth) applied.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function effectiveRankParameters(User $user, array $rules): array
    {
        $parameters = $rules['rank_royalty']['parameters'] ?? [];
        $custom = self::for($user)['custom_conditions'];
        foreach (['mo_target', 'am_count', 'sm_count', 'me_count', 'qualification_depth'] as $key) {
            if (isset($custom[$key])) {
                $parameters[$key] = $custom[$key];
            }
        }

        return $parameters;
    }

    /**
     * Car & bike incentive parameters with any per-person overrides applied.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function effectiveVehicleParameters(User $user, array $rules): array
    {
        $parameters = $rules['car_bike_incentive']['parameters'] ?? [];
        $custom = self::for($user)['custom_conditions'];
        foreach (['bike_target', 'bike_leg_cap', 'car_target', 'car_leg_cap'] as $key) {
            if (isset($custom[$key])) {
                $parameters[$key] = (string) $custom[$key];
            }
        }

        return $parameters;
    }

    /**
     * Effective cooling-off days for commissions earned by this partner.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function effectiveCoolingOffDays(User $user, array $rules): int
    {
        $custom = self::customInt($user, 'cooling_off_days');
        if ($custom !== null) {
            return $custom;
        }

        return ($rules['commission_cooling_off']['is_active'] ?? false)
            ? (int) ($rules['commission_cooling_off']['parameters']['cooling_off_days'] ?? 0)
            : 0;
    }

    public static function waivesSalaryVolume(User $user): bool
    {
        return self::for($user)['unconditional_salary'];
    }

    public static function waivesRankRoyalty(User $user): bool
    {
        return self::for($user)['unconditional_rank_royalty'];
    }

    public static function forcedRoyaltyRank(User $user): ?string
    {
        $controls = self::for($user);
        if (! $controls['unconditional_rank_royalty']) {
            return null;
        }

        return $controls['forced_royalty_rank'] ?? 'mo';
    }

    public static function waivesRankIncentive(User $user): bool
    {
        return self::for($user)['unconditional_rank_incentive'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, User $partner, array $input): array
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user_id' => __('portal.err_benefit_partners_only')]);
        }

        $rank = self::normalizeRank($input['forced_royalty_rank'] ?? 'mo') ?? 'mo';
        $customConditions = array_key_exists('custom_conditions', $input)
            ? $this->validateCustomConditions($partner, is_array($input['custom_conditions']) ? $input['custom_conditions'] : [])
            : self::for($partner)['custom_conditions'];
        $controls = [
            'unconditional_salary' => (bool) ($input['unconditional_salary'] ?? false),
            'unconditional_rank_royalty' => (bool) ($input['unconditional_rank_royalty'] ?? false),
            'forced_royalty_rank' => $rank,
            'unconditional_rank_incentive' => (bool) ($input['unconditional_rank_incentive'] ?? false),
            'custom_conditions' => $customConditions,
            'note' => trim((string) ($input['note'] ?? '')) ?: null,
            'updated_by' => $actor->id,
            'updated_at' => now()->toIso8601String(),
        ];

        $profile = is_array($partner->profile) ? $partner->profile : [];
        $profile['benefit_controls'] = $controls;
        $partner->forceFill(['profile' => $profile])->save();
        DealerRank::query()->firstOrCreate(['user_id' => $partner->id]);

        Audit::record($actor->id, 'accounts.benefit_controls_updated', (string) $partner->id, [
            'controls' => $controls,
            'reason' => (string) ($input['reason'] ?? ''),
        ]);

        $locale = $partner->preferredLocale();
        $summaryIn = function (string $language) use ($controls, $rank, $customConditions): string {
            $enabled = collect([
                $controls['unconditional_salary'] ? __('portal.pbc_flag_salary', [], $language) : null,
                $controls['unconditional_rank_royalty'] ? __('portal.pbc_flag_royalty', ['rank' => RankLabel::name($rank, $language)], $language) : null,
                $controls['unconditional_rank_incentive'] ? __('portal.pbc_flag_incentive', [], $language) : null,
                $customConditions !== [] ? __('portal.pbc_flag_custom', ['count' => count($customConditions)], $language) : null,
            ])->filter()->implode(', ');

            return $enabled !== '' ? $enabled : (string) __('portal.pbc_flag_none', [], $language);
        };
        $summary = $summaryIn($locale);
        $reason = trim((string) ($input['reason'] ?? ''));
        Audit::notice(
            $partner->id,
            __('portal.notice_benefit_controls_title', [], $locale),
            __('portal.notice_benefit_controls_body', [
                'summary' => $summary,
                'reason' => ReasonFields::shown($reason, $locale),
            ], $locale),
        );
        DB::afterCommit(function () use ($partner, $summaryIn): void {
            app(PartnerEventSms::class)->send(
                $partner->fresh() ?? $partner,
                'accounts.benefit_controls_updated',
                SmsText::make('sms.benefit_controls_updated', [
                    'summary' => $summaryIn(SmsText::Locale),
                ]),
            );
        });

        return $controls;
    }

    /**
     * Validate and normalize submitted custom condition thresholds for one partner.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int>
     */
    private function validateCustomConditions(User $partner, array $input): array
    {
        $clean = [];
        foreach ($input as $key => $raw) {
            if (! isset(self::CustomConditions[$key])) {
                continue;
            }
            $spec = self::CustomConditions[$key];
            if (! in_array($partner->role, $spec['roles'], true)) {
                continue;
            }
            if ($raw === null) {
                continue;
            }
            $text = str_replace([',', ' '], '', trim(LocalDigits::latin((string) $raw)));
            if ($text === '') {
                continue;
            }
            if ($spec['type'] === 'money') {
                try {
                    $cents = Money::cents($text);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["custom_conditions.{$key}" => __('commission_page.err_number')]);
                }
                if ($cents < (int) round(((float) $spec['min']) * 100)) {
                    throw ValidationException::withMessages(["custom_conditions.{$key}" => __('commission_page.err_above_zero')]);
                }
                $clean[$key] = Money::decimal($cents);
            } elseif ($spec['type'] === 'percent') {
                try {
                    $pct = Percentage::from($text);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["custom_conditions.{$key}" => __('commission_page.err_percent')]);
                }
                $clean[$key] = $pct->decimal();
            } else {
                if (! preg_match('/^-?\d+$/D', $text)) {
                    throw ValidationException::withMessages(["custom_conditions.{$key}" => __('commission_page.err_whole')]);
                }
                $number = (int) $text;
                $max = (int) $spec['max'];
                if ($number < (int) $spec['min'] || $number > $max) {
                    throw ValidationException::withMessages(["custom_conditions.{$key}" => __('commission_page.err_whole')]);
                }
                $clean[$key] = $number;
            }
        }

        $tierKeys = ['tier1_volume', 'tier1_pct', 'tier2_volume', 'tier2_pct', 'tier3_volume', 'tier3_pct'];
        if ($partner->role === 'sub_dealer' && array_intersect($tierKeys, array_keys($clean)) !== []) {
            $rules = app(CommissionPolicy::class)->snapshot(false)['rules'];
            foreach ($tierKeys as $key) {
                if (isset($clean[$key])) {
                    $rules['sub_dealer_salary']['parameters'][$key] = (string) $clean[$key];
                }
            }
            $tiers = MonthlyTargets::salaryTiers($rules);
            $setAside = Percentage::from((string) ($rules['sub_dealer_salary']['percentage'] ?? '0.00'))->basisPoints;
            $previous = null;
            foreach ($tiers as $tier) {
                if ($tier['basis_points'] > $setAside || ($previous !== null && ($tier['volume'] <= $previous['volume'] || $tier['basis_points'] < $previous['basis_points']))) {
                    throw ValidationException::withMessages(['custom_conditions' => __('commission_page.err_salary_tiers')]);
                }
                $previous = $tier;
            }
        }

        return $clean;
    }

    /**
     * Sanitize persisted custom conditions read from user profile JSON.
     *
     * @return array<string, string|int>
     */
    private static function sanitizeStoredCustom(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }
        $clean = [];
        foreach ($stored as $key => $value) {
            if (! is_string($key) || ! isset(self::CustomConditions[$key]) || $value === null || $value === '') {
                continue;
            }
            $spec = self::CustomConditions[$key];
            if ($spec['type'] === 'int') {
                if (is_numeric($value)) {
                    $clean[$key] = (int) $value;
                }
            } else {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }

    private static function normalizeRank(mixed $rank): ?string
    {
        $value = is_string($rank) ? strtolower(trim($rank)) : '';

        return in_array($value, self::Ranks, true) ? $value : null;
    }
}

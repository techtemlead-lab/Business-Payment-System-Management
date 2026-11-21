<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Support\Money;

/**
 * The monthly targets of the owner's rank and salary rules (2026-11-21), read from one set of commission rules.
 *
 *  - A dealer has ONE monthly target, set by the kind of dealer he is (union, upazila, district or division).
 *    Meeting it in a month unlocks that month's dealer salary, rank royalty and area income together.
 *  - A sub-dealer's salary is a tier of his own month's purchase (for example 1% from ৳10,000, 2% from ৳20,000,
 *    3% from ৳40,000); his rank royalty has its own target (for example ৳30,000 of his own purchase).
 *
 * An order keeps the rules it was captured with. Rules captured before these settings existed carry no kind
 * targets, so they keep the old single salary minimum ("min_monthly_volume") and the old rank-keeping
 * minimums; usesMonthlyTargets() tells the two apart. Nothing here reads the database.
 */
final class MonthlyTargets
{
    /** The kinds of dealer, from the widest area to the smallest. */
    public const DealerKinds = ['division', 'district', 'upazila', 'union'];

    /** Rule parameter holding each kind's monthly target, on the dealer salary rule. */
    public const KindParameters = ['division' => 'division_target', 'district' => 'district_target', 'upazila' => 'upazila_target', 'union' => 'union_target'];

    /** Rule parameter holding the target of a dealer with no appointed area yet (the old single salary minimum). */
    public const NoAreaParameter = 'min_monthly_volume';

    /** Rule parameter (rank royalty rule) holding a sub-dealer's own monthly purchase needed for the rank royalty. */
    public const SubDealerRoyaltyParameter = 'sub_dealer_royalty_target';

    /** The sub-dealer salary tiers, lowest first: [purchase parameter, percent parameter]. */
    public const SalaryTiers = [['tier1_volume', 'tier1_pct'], ['tier2_volume', 'tier2_pct'], ['tier3_volume', 'tier3_pct']];

    /**
     * True when the rules carry the monthly targets by dealer kind (rules saved on or after the owner's rule of 2026-11-21).
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function usesMonthlyTargets(array $rules): bool
    {
        return isset($rules['dealer_monthly_salary']['parameters'][self::KindParameters['union']]);
    }

    /**
     * A dealer's one monthly target in paisa. An unknown kind (no seat and no appointed place) uses the no-area
     * target. Old rules have one minimum for everybody.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function dealerTarget(array $rules, ?string $kind): int
    {
        $parameters = $rules['dealer_monthly_salary']['parameters'] ?? [];
        if (self::usesMonthlyTargets($rules) && $kind !== null && isset(self::KindParameters[$kind], $parameters[self::KindParameters[$kind]])) {
            return Money::cents((string) $parameters[self::KindParameters[$kind]]);
        }

        return Money::cents((string) ($parameters[self::NoAreaParameter] ?? '0'));
    }

    /**
     * What a partner must buy in the month to be paid the rank royalty, in paisa: a dealer's one monthly target, or a
     * sub-dealer's own royalty target. Null for old rules, which keep the rank-keeping minimum of each rank.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function royaltyTarget(array $rules, string $role, ?string $kind): ?int
    {
        if (! self::usesMonthlyTargets($rules)) {
            return null;
        }

        return $role === 'sub_dealer' ? self::subDealerRoyaltyTarget($rules) : self::dealerTarget($rules, $kind);
    }

    /** @param  array<string, array<string, mixed>>  $rules */
    public static function subDealerRoyaltyTarget(array $rules): int
    {
        return Money::cents((string) ($rules['rank_royalty']['parameters'][self::SubDealerRoyaltyParameter] ?? '0'));
    }

    /**
     * The sub-dealer salary tiers, lowest first, as purchase (paisa) and percent (basis points). Empty for old rules,
     * which paid the whole salary set aside.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{volume: int, basis_points: int}>
     */
    public static function salaryTiers(array $rules): array
    {
        $parameters = $rules['sub_dealer_salary']['parameters'] ?? [];
        $tiers = [];
        foreach (self::SalaryTiers as [$volume, $percent]) {
            if (! isset($parameters[$volume], $parameters[$percent])) {
                return [];
            }
            $tiers[] = ['volume' => Money::cents((string) $parameters[$volume]), 'basis_points' => Percentage::from((string) $parameters[$percent])->basisPoints];
        }

        return $tiers;
    }

    /**
     * The salary percent (basis points) a sub-dealer earns for a month's own purchase: the highest tier reached, or
     * zero below the first tier. Null for old rules.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function salaryBasisPoints(array $rules, int $volume): ?int
    {
        $tiers = self::salaryTiers($rules);
        if ($tiers === []) {
            return null;
        }
        $earned = 0;
        foreach ($tiers as $tier) {
            if ($volume >= $tier['volume']) {
                $earned = $tier['basis_points'];
            }
        }

        return $earned;
    }

    /**
     * The part of one salary fund row a sub-dealer is paid: the fund was set aside at the rule's full percent, so he
     * gets the tier's share of it, rounded half up and never more than the fund.
     */
    public static function tierShare(int $fund, int $tierBasisPoints, int $ruleBasisPoints): int
    {
        if ($fund <= 0 || $tierBasisPoints <= 0 || $ruleBasisPoints <= 0) {
            return 0;
        }

        return min($fund, intdiv($fund * $tierBasisPoints * 2 + $ruleBasisPoints, $ruleBasisPoints * 2));
    }

    /**
     * Whether sub-dealers can hold ranks under these rules (the owner's rule of 2026-11-21).
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function subDealersEarnRanks(array $rules): bool
    {
        return self::usesMonthlyTargets($rules);
    }
}

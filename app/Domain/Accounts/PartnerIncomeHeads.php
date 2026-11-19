<?php

namespace App\Domain\Accounts;

/**
 * The one ordered list of income heads a partner sees (CR-21 / V2). Every kind of income row the
 * wallet already reads (a commission category, a month-end settlement kind, a reward program,
 * the opening balance) belongs to exactly one head here, so every screen, statement and file
 * uses the same names in the same order.
 *
 * This only names and groups rows. It never decides an amount: a head's total is the sum of the
 * rows the wallet already counts, so the heads always add up to the wallet's total income.
 */
final class PartnerIncomeHeads
{
    /**
     * Head key => what it sums, in display order.
     *  - kinds: settlement kind from the journal key (commissions.settlement.*)
     *  - programs: reward program of a reserve award
     *  - categories: commission allocation category
     *  - audience: who sees it even at zero ('dealer', 'sub_dealer'); money in any head always shows
     *
     * @var array<string, array{kinds?: list<string>, programs?: list<string>, categories?: list<string>, types?: list<string>, audience: list<string>}>
     */
    public const Heads = [
        'own' => ['categories' => ['own_dealer_cashback'], 'audience' => ['dealer', 'sub_dealer']],
        'referral' => ['categories' => ['direct_referral'], 'audience' => ['dealer', 'sub_dealer']],
        'd2d_gen2' => ['categories' => ['d2d_gen2'], 'audience' => ['dealer', 'sub_dealer']],
        'd2d_gen3' => ['categories' => ['d2d_gen3'], 'audience' => ['dealer', 'sub_dealer']],
        'area_division' => ['categories' => ['area_division'], 'audience' => ['dealer']],
        'area_district' => ['categories' => ['area_district'], 'audience' => ['dealer']],
        'area_upazila' => ['categories' => ['area_upazila'], 'audience' => ['dealer']],
        'area_union' => ['categories' => ['area_union'], 'audience' => ['dealer']],
        'salary' => ['kinds' => ['salary'], 'categories' => ['dealer_monthly_salary_pool', 'sub_dealer_salary'], 'audience' => ['dealer', 'sub_dealer']],
        'rank_mo' => ['kinds' => ['rank_mo'], 'audience' => ['dealer']],
        'rank_am' => ['kinds' => ['rank_am'], 'audience' => ['dealer']],
        'rank_sm' => ['kinds' => ['rank_sm'], 'audience' => ['dealer']],
        'rank_me' => ['kinds' => ['rank_me'], 'audience' => ['dealer']],
        'royalty' => ['kinds' => ['royalty'], 'categories' => ['rank_royalty_pool'], 'audience' => ['dealer']],
        // DECISION: the sub-dealer "additional rank" award is a rank incentive, so it sums here.
        'incentive' => ['programs' => ['additional_rank'], 'categories' => ['rank_incentive_pool', 'additional_rank_pool'], 'types' => ['rank_bonus'], 'audience' => ['dealer', 'sub_dealer']],
        'bike' => ['programs' => ['bike'], 'audience' => ['dealer']],
        'car' => ['programs' => ['car'], 'audience' => ['dealer']],
        // DECISION: dealers see the tour head at ৳0 until the client sets a dealer tour rule; only sub-dealers earn it today.
        'tour' => ['programs' => ['tour'], 'categories' => ['sub_dealer_tour'], 'audience' => ['dealer', 'sub_dealer']],
        'opening' => ['types' => ['opening'], 'audience' => []],
        // Everything else the company pays (marketing awards, vehicle-fund rows with no program, area management share).
        'other' => ['programs' => ['marketing'], 'categories' => ['area_management', 'company_marketing_fund', 'car_bike_incentive_fund'], 'audience' => ['dealer', 'sub_dealer']],
    ];

    /** The statement types that are income, plus the opening balance (the wallet's income cards). */
    public const RowTypes = [...PartnerMoneyBook::IncomeTypes, 'opening'];

    /** The head a row lands in when nothing names it. */
    public const Fallback = 'other';

    /** The head that follows a statement type when no category, kind or program names it. */
    private const TypeFallback = ['salary' => 'salary', 'royalty' => 'royalty', 'rank_bonus' => 'incentive', 'opening' => 'opening'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::Heads);
    }

    /**
     * The heads a partner of this role sees even when empty. A head holding money is added by the caller.
     *
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        return array_keys(array_filter(self::Heads, fn (array $head): bool => in_array($role, $head['audience'], true)));
    }

    public static function label(string $head, ?string $locale = null): string
    {
        return (string) __('cr21_income.head_'.$head, [], $locale);
    }

    public static function hint(string $head, ?string $locale = null): string
    {
        return (string) __('cr21_income.hint_'.$head, [], $locale);
    }

    /**
     * The head of one income row. Kinds are checked first, then programs, then categories, then the type.
     */
    public static function headOf(?string $type, ?string $category, ?string $program, ?string $kind): string
    {
        foreach (self::lookups() as [$field, $value, $head]) {
            $given = match ($field) {
                'kind' => $kind,
                'program' => $program,
                default => $category,
            };
            if ($given === $value) {
                return $head;
            }
        }

        return self::TypeFallback[(string) $type] ?? self::Fallback;
    }

    /**
     * Whether a category, kind or program is named by a head on purpose, not by the fallback.
     */
    public static function names(string $field, string $value): bool
    {
        foreach (self::lookups() as [$lookupField, $lookupValue]) {
            if ($lookupField === $field && $lookupValue === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQL naming the head of a row of the statement (columns type, category, program, kind on the given alias).
     * It is NULL for a row that is not income, so a filter on it never picks a withdrawal.
     */
    public static function sql(string $alias): string
    {
        $when = [];
        foreach (self::lookups() as [$field, $value, $head]) {
            $when[] = "WHEN {$alias}.{$field} = ".self::quote($value).' THEN '.self::quote($head);
        }
        foreach (self::TypeFallback as $type => $head) {
            $when[] = "WHEN {$alias}.type = ".self::quote($type).' THEN '.self::quote($head);
        }

        return "CASE WHEN {$alias}.type IN (".implode(', ', array_map(self::quote(...), self::RowTypes)).') THEN CASE '
            .implode(' ', $when).' ELSE '.self::quote(self::Fallback).' END END';
    }

    /**
     * Every lookup in the order they are checked.
     *
     * @return list<array{0: string, 1: string, 2: string}> field, value, head
     */
    private static function lookups(): array
    {
        $found = ['kind' => [], 'program' => [], 'category' => []];
        foreach (self::Heads as $head => $rules) {
            foreach (['kind' => 'kinds', 'program' => 'programs', 'category' => 'categories'] as $field => $rule) {
                foreach ($rules[$rule] ?? [] as $value) {
                    $found[$field][] = [$field, $value, $head];
                }
            }
        }

        return [...$found['kind'], ...$found['program'], ...$found['category']];
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}

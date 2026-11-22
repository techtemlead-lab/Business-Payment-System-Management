<?php

namespace App\Domain\Commissions;

/**
 * The rank royalty (4% of each order by default) goes into four separate funds, one per rank: MO, AM, SM and ED (the
 * top rank, stored key `me`). Each order posts one row per fund at the rank's own share percent (owner's rule, repeated
 * 2026-11-22). Orders posted before that rule kept one row in the single old fund (`rank_royalty_pool`); those rows are
 * never rewritten, and the month close still splits each of them into the four rank funds by the share percents.
 */
final class RankRoyaltyFunds
{
    /** The single fund older orders were posted to. */
    public const LegacyCategory = 'rank_royalty_pool';

    /** Treasury's fund key for the older single fund. */
    public const LegacyFundKey = 'royalty_pool';

    /** @var array<string, string> rank key => commission row category */
    public const Categories = [
        'mo' => 'rank_royalty_mo_pool',
        'am' => 'rank_royalty_am_pool',
        'sm' => 'rank_royalty_sm_pool',
        'me' => 'rank_royalty_me_pool',
    ];

    /** @var array<string, string> rank key => Treasury fund key */
    public const FundKeys = [
        'mo' => 'royalty_mo',
        'am' => 'royalty_am',
        'sm' => 'royalty_sm',
        'me' => 'royalty_me',
    ];

    /**
     * Every category a rank royalty row can carry: the four rank funds and the older single fund.
     *
     * @return list<string>
     */
    public static function allCategories(): array
    {
        return [self::LegacyCategory, ...array_values(self::Categories)];
    }

    /** The rank a per-rank fund row belongs to, or null for the older single fund and any other category. */
    public static function rankOf(string $category): ?string
    {
        $rank = array_search($category, self::Categories, true);

        return $rank === false ? null : $rank;
    }

    /** Treasury's fund key for a rank royalty row, or null when the category is not a rank royalty one. */
    public static function fundKeyOf(string $category): ?string
    {
        if ($category === self::LegacyCategory) {
            return self::LegacyFundKey;
        }
        $rank = self::rankOf($category);

        return $rank === null ? null : self::FundKeys[$rank];
    }

    /** The categories as a quoted SQL list, for IN (...) clauses. */
    public static function sqlList(): string
    {
        return "'".implode("', '", self::allCategories())."'";
    }

    /** SQL CASE branches naming the Treasury fund of a rank royalty row on the given column. */
    public static function fundCaseSql(string $column): string
    {
        $sql = "WHEN {$column} = '".self::LegacyCategory."' THEN '".self::LegacyFundKey."' ";
        foreach (self::Categories as $rank => $category) {
            $sql .= "WHEN {$column} = '{$category}' THEN '".self::FundKeys[$rank]."' ";
        }

        return $sql;
    }
}

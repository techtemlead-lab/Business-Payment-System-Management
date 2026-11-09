<?php

namespace App\Domain\Finance;

use App\Support\Money;

/**
 * The funds Treasury shows, their names and the words for each kind of commission. The company
 * funds come from {@see CompanyFunds}; this adds the three month pools, which are in no company fund
 * and would otherwise appear on no card.
 */
final class FundCatalog
{
    /** Money held for the month close. They can never be spent by hand. */
    public const MonthPools = ['salary_pool', 'royalty_pool', 'rank_bonus'];

    /** Funds where a closed month is finished: everything in that row has gone out. */
    public const ClosedRowFunds = ['salary_pool', 'royalty_pool', 'rank_reward'];

    /** Old money only. The card appears only while something came in. */
    public const HiddenWhileEmpty = ['salary_reserve', 'geographic_reserve', 'referral_reserve'];

    /** The order the cards appear in. */
    private const Order = ['company_share', 'company_profit', 'management', 'marketing', 'tour', 'car_bike', 'salary_pool', 'royalty_pool', 'rank_bonus', 'rank_reward', 'clinic', 'benefits', 'salary_reserve', 'geographic_reserve', 'referral_reserve'];

    /** @return list<string> */
    public static function keys(): array
    {
        return self::Order;
    }

    /** @return list<string> */
    public static function visibleKeys(bool $showOwnerFunds): array
    {
        return $showOwnerFunds ? self::Order : array_values(array_diff(self::Order, CompanyFunds::OwnerOnly));
    }

    public static function name(string $fund): string
    {
        return (string) __('treasury.fund_'.$fund);
    }

    /**
     * SQL naming the fund of a commission row, on the alias `funds`. It is the company funds' own
     * rule plus the month pools, and it keeps a closed sub-dealer rank row in its fund.
     */
    public static function rowFundSql(): string
    {
        $pools = "WHEN funds.category = 'additional_rank_pool' THEN 'rank_reward' "
            ."WHEN funds.category IN ('dealer_monthly_salary_pool', 'sub_dealer_salary') THEN 'salary_pool' "
            ."WHEN funds.category = 'rank_royalty_pool' THEN 'royalty_pool' "
            ."WHEN funds.category = 'rank_incentive_pool' THEN 'rank_bonus' ";

        return 'CASE '.$pools.substr(CompanyFunds::fundSql(), strlen('CASE '));
    }

    /** The word for a kind of commission, in the reader's language. */
    public static function typeLabel(string $category): string
    {
        $base = (string) preg_replace('/_breakage$/', '', $category);
        if (str_ends_with($base, '_override_reserve')) {
            $base = 'old_referral';
        }
        $key = 'treasury.type_'.$base;
        $label = (string) __($key);

        return $label === $key ? $category : $label;
    }

    /** Text amount with an optional sign, as whole paisa. */
    public static function centsOf(mixed $value): int
    {
        $text = (string) ($value ?? '0');

        return str_starts_with($text, '-') ? -Money::cents(substr($text, 1)) : Money::cents($text);
    }

    /** Paisa as a decimal string, which may start with a minus. */
    public static function decimalOf(int $cents): string
    {
        return $cents < 0 ? '-'.Money::decimal(-$cents) : Money::decimal($cents);
    }
}

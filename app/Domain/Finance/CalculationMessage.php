<?php

namespace App\Domain\Finance;

/** Maps translator-free calculation exception text to locale-aware portal messages. */
final class CalculationMessage
{
    public static function localize(string $message): string
    {
        return match ($message) {
            'MRP must be positive.' => __('portal.err_draft_mrp_positive'),
            'Product discounts must be between 20% and 50%.' => __('portal.err_draft_discount_range'),
            'The schedule contains an unsupported allocation category.' => __('portal.err_draft_unsupported_category'),
            'Total allocations cannot exceed 100% of the purchase value.' => __('portal.err_draft_alloc_100'),
            'Only dealer and sub_dealer schedules are supported.' => __('portal.err_draft_schedule_type'),
            'A percentage must have at most two decimal places.' => __('portal.err_pct_decimals'),
            'A percentage cannot exceed 100%.' => __('portal.err_pct_max'),
            'The amount is outside the supported monetary range.' => __('portal.err_pct_amount_range'),
            'Choose reject or half_up rounding.' => __('portal.err_pct_rounding'),
            'This calculation needs an approved rounding rule.' => __('portal.err_pct_rounding_rule'),
            'Invalid monetary amount.' => __('portal.err_money_invalid'),
            'Negative monetary amount.' => __('portal.err_money_negative'),
            'Negative purchase amount.' => __('portal.err_money_purchase_negative'),
            default => $message,
        };
    }
}

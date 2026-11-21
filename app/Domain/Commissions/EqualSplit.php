<?php

namespace App\Domain\Commissions;

/**
 * Owner's rule (2026-11-21): money shared among people or funds is split equally to the paisa, and the paisa
 * that do not divide (the leftover) go to the Commission extra fund — never to one of the people, and never left
 * unallocated. Pure arithmetic: the caller posts the leftover (CommissionSettlement::toCommissionExtraFund()).
 */
final class EqualSplit
{
    /**
     * @param  list<int>  $ids
     * @return array{shares: array<int, int>, leftover: int} paisa per id (id order), and the paisa left over
     */
    public static function among(int $total, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === [] || $total <= 0) {
            return ['shares' => [], 'leftover' => max(0, $total)];
        }
        $each = intdiv($total, count($ids));
        $shares = $each > 0 ? array_fill_keys($ids, $each) : [];

        return ['shares' => $shares, 'leftover' => $total - $each * count($shares)];
    }

    /**
     * Splits by weights (for example the four rank funds at 1% each), each part rounded down to the paisa.
     *
     * @param  list<int>  $weights
     * @return array{parts: list<int>, leftover: int}
     */
    public static function byWeights(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0 || $total <= 0) {
            return ['parts' => array_fill(0, count($weights), 0), 'leftover' => max(0, $total)];
        }
        $parts = array_map(fn (int $weight): int => intdiv($total * $weight, $sum), $weights);

        return ['parts' => $parts, 'leftover' => $total - array_sum($parts)];
    }
}

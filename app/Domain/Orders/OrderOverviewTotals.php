<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Order figures for the home screen of any portal: how many orders, how many
 * wait for review, what confirmed orders are worth and what is still owed.
 * The order figures come from one pass over the orders table instead of a
 * separate count or sum for each number.
 */
final class OrderOverviewTotals
{
    /** Orders still waiting for a staff decision. */
    public const AwaitingReview = ['submitted', 'held'];

    /**
     * @param  Builder<Order>  $orders  Orders the viewer may see. It is cloned, never changed.
     * @param  CarbonInterface  $placedSince  Start of the window for orders_placed_since.
     * @return array{order_count: int, pending: int, orders_placed_since: int, purchases: string, dues: string, supply: mixed}
     */
    public function for(Builder $orders, CarbonInterface $placedSince): array
    {
        $confirmed = $this->placeholders(OrderListFilter::Confirmed);
        $awaitingReview = $this->placeholders(self::AwaitingReview);
        $row = (clone $orders)->toBase()->reorder()->selectRaw(
            'COUNT(*) AS order_count, '
            ."COUNT(*) FILTER (WHERE status IN ({$awaitingReview})) AS pending, "
            .'COUNT(*) FILTER (WHERE created_at >= ?) AS orders_placed_since, '
            ."COALESCE(SUM(total) FILTER (WHERE status IN ({$confirmed})), 0) AS purchases, "
            ."COALESCE(SUM(paid) FILTER (WHERE status IN ({$confirmed})), 0) AS paid, "
            ."COALESCE(SUM(due_credit) FILTER (WHERE status IN ({$confirmed})), 0) AS credits",
            [...self::AwaitingReview, $placedSince, ...OrderListFilter::Confirmed, ...OrderListFilter::Confirmed, ...OrderListFilter::Confirmed],
        )->first();

        $purchases = Money::cents((string) $row->purchases);

        return [
            'order_count' => (int) $row->order_count,
            'pending' => (int) $row->pending,
            'orders_placed_since' => (int) $row->orders_placed_since,
            'purchases' => Money::decimal($purchases),
            'dues' => Money::decimal($purchases - Money::cents((string) $row->paid) - Money::cents((string) $row->credits)),
            // Units ordered on confirmed orders and not sent yet.
            'supply' => DB::table('order_lines')
                ->whereIn('order_id', (clone $orders)->whereIn('status', OrderListFilter::Confirmed)->select('id'))
                ->sum(DB::raw('quantity-dispatched')),
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}

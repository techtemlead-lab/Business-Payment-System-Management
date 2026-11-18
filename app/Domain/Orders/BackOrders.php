<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Paid orders with goods still waiting for stock (OS-02, LC-04). The e-commerce guidelines ask for
 * delivery within 10 days outside the buyer's city and a refund within 10 days, so a paid back
 * order is counted from the day it was paid in full; staff are warned before and after that
 * limit and offered the two ways out: send from another branch, or close the rest and refund.
 */
final class BackOrders
{
    /** Days the guideline allows for delivery (and for a refund) after payment. */
    public const DeadlineDays = 10;

    /** From this day on staff see the warning. */
    public const WarnFromDays = 7;

    public const Statuses = ['approved', 'partially_dispatched'];

    /**
     * The back-order box on one order page, or null when nothing paid is waiting.
     *
     * @return array{days: int, late: bool, days_left: int, deadline_days: int, stock_elsewhere: list<array{branch: string, units: int}>}|null
     */
    public function forOrder(Order $order, User $viewer): ?array
    {
        if (! in_array($order->status, self::Statuses, true) || ! $order->lines()->whereRaw(OrderLines::unsentSql())->exists()) {
            return null;
        }
        $since = $this->paidSince($order);
        if ($since === null) {
            return null;
        }
        // Never below zero: a payment dated later than today (clock or data mistake) counts as today.
        $days = max(0, (int) $since->startOfDay()->diffInDays(CarbonImmutable::now('Asia/Dhaka')->startOfDay()));
        $staff = $viewer->staff() && (int) $viewer->id !== (int) $order->user_id;

        return [
            'days' => $days,
            'late' => $days >= self::DeadlineDays,
            'days_left' => max(0, self::DeadlineDays - $days),
            'deadline_days' => self::DeadlineDays,
            'warn' => $days >= self::WarnFromDays,
            'stock_elsewhere' => $staff ? $this->stockElsewhere($order) : [],
        ];
    }

    /**
     * Stock of each unsent product at every active stock hub, for the ship form's branch choice.
     *
     * @return list<array{branch_id: int, name: string, code: string, products: array<int, int>, can_send_all: bool}>
     */
    public function stockByBranch(Order $order): array
    {
        $need = DB::table('order_lines')->where('order_id', $order->id)->whereRaw(OrderLines::unsentSql())
            ->groupBy('product_id')->selectRaw('product_id, SUM('.OrderLines::unsentUnitsSql().') AS units')->pluck('units', 'product_id');
        if ($need->isEmpty()) {
            return [];
        }
        $rows = DB::table('company_branches as b')
            ->leftJoin('branch_product_stocks as s', fn ($join) => $join->on('s.branch_id', '=', 'b.id')->whereIn('s.product_id', $need->keys()))
            ->where('b.is_active', true)->where('b.is_stock_hub', true)
            ->orderByRaw('COALESCE(b.is_head_office, false) DESC')->orderBy('b.name')
            ->get(['b.id', 'b.name', 'b.code', 's.product_id', 's.quantity']);
        $branches = [];
        foreach ($rows as $row) {
            $branches[(int) $row->id] ??= ['branch_id' => (int) $row->id, 'name' => (string) $row->name, 'code' => (string) $row->code, 'products' => []];
            if ($row->product_id !== null) {
                $branches[(int) $row->id]['products'][(int) $row->product_id] = max(0, (int) $row->quantity);
            }
        }

        return array_values(array_map(function (array $branch) use ($need): array {
            $canSendAll = true;
            foreach ($need as $productId => $units) {
                if (($branch['products'][(int) $productId] ?? 0) < (int) $units) {
                    $canSendAll = false;
                }
            }

            return [...$branch, 'can_send_all' => $canSendAll];
        }, $branches));
    }

    /** Paid back orders at or past the warning day, for the staff dashboard. */
    public function lateCount(Builder $orders): int
    {
        return (int) (clone $orders)->whereIn('orders.status', self::Statuses)
            ->whereExists(fn ($lines) => $lines->selectRaw('1')->from('order_lines')->whereColumn('order_lines.order_id', 'orders.id')->whereRaw(OrderLines::unsentSql('order_lines')))
            ->whereRaw('orders.paid >= orders.total AND orders.total > 0')
            ->whereRaw('(SELECT MAX(r.created_at) FROM receipts r WHERE r.order_id = orders.id) <= ?', [now()->subDays(self::WarnFromDays)])
            ->count();
    }

    /** When the order was paid in full: the last receipt, or the approval for older orders. */
    private function paidSince(Order $order): ?CarbonImmutable
    {
        if ((float) $order->paid < (float) $order->total || (float) $order->total <= 0) {
            return null;
        }
        $at = DB::table('receipts')->where('order_id', $order->id)->max('created_at')
            ?? DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.approved')->max('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone('Asia/Dhaka');
    }

    /** @return list<array{branch: string, units: int}> other hubs holding stock for this order's unsent products */
    private function stockElsewhere(Order $order): array
    {
        $result = [];
        foreach ($this->stockByBranch($order) as $branch) {
            if ((int) $branch['branch_id'] === (int) $order->fulfillment_branch_id) {
                continue;
            }
            $units = array_sum($branch['products']);
            if ($units > 0) {
                $result[] = ['branch' => $branch['name'], 'units' => $units, 'can_send_all' => $branch['can_send_all']];
            }
        }

        return $result;
    }
}

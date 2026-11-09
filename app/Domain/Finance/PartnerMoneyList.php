<?php

namespace App\Domain\Finance;

use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partners' money for the office: what each dealer and sub-dealer has now, and their figures to
 * date. "Has now" and "waiting" are always today's; the view choice applies to the other columns,
 * and with no choice they are lifetime figures.
 */
final class PartnerMoneyList
{
    /** @return array<string, mixed> MoneyTable payload */
    public function table(Request $request): array
    {
        $range = MoneyTable::range($request, null);
        $partners = DB::table('users as u')->leftJoin('wallets as w', 'w.user_id', '=', 'u.id')
            ->leftJoinSub($this->delivered($range), 'delivered', 'delivered.user_id', '=', 'u.id')
            ->leftJoinSub($this->withdrawn($range), 'withdrawn', 'withdrawn.user_id', '=', 'u.id')
            ->leftJoinSub($this->spent($range), 'spent', 'spent.user_id', '=', 'u.id')
            ->whereIn('u.role', ['dealer', 'sub_dealer'])
            ->selectRaw("u.id, u.name, u.dealer_code AS code, u.role, COALESCE(u.profile->>'place_code', u.profile->>'area_code', u.district, u.division) AS area, COALESCE(w.available, 0) AS available, COALESCE(w.held, 0) AS waiting, COALESCE(delivered.ordered_mrp, 0) AS ordered_mrp, COALESCE(delivered.cash_given, 0) AS cash_given, COALESCE(withdrawn.total, 0) AS withdrawn, COALESCE(spent.total, 0) AS spent");

        $table = MoneyTable::make($request, DB::query()->fromSub($partners, 'x'), new MoneyTableSpec(
            dateColumn: null,
            search: ['x.name', 'x.code', 'x.area'],
            filters: [new MoneyTableFilter('role', (string) __('treasury.filter_buyer_role'), 'x.role', array_map(
                fn (string $role): array => ['value' => $role, 'label' => (string) __('treasury.role_'.$role)],
                ['dealer', 'sub_dealer'],
            ))],
            sorts: ['name' => 'x.name', 'available' => 'x.available', 'waiting' => 'x.waiting', 'ordered_mrp' => 'x.ordered_mrp', 'cash_given' => 'x.cash_given', 'withdrawn' => 'x.withdrawn', 'spent' => 'x.spent'],
            defaultSort: 'available',
            tieBreaker: 'x.id',
            sums: ['available' => 'x.available', 'waiting' => 'x.waiting', 'ordered_mrp' => 'x.ordered_mrp', 'cash_given' => 'x.cash_given', 'withdrawn' => 'x.withdrawn', 'spent' => 'x.spent'],
            defaultPeriod: null,
        ));
        $table['rows'] = $table['rows']->through(fn (object $row): array => [
            'id' => (int) $row->id, 'name' => $row->name, 'code' => $row->code, 'role' => $row->role, 'area' => $row->area,
            'available' => (string) $row->available, 'waiting' => (string) $row->waiting, 'ordered_mrp' => (string) $row->ordered_mrp,
            'cash_given' => (string) $row->cash_given, 'withdrawn' => (string) $row->withdrawn, 'spent' => (string) $row->spent,
        ]);

        return $table;
    }

    /**
     * @param  array{from: \Carbon\CarbonImmutable, until: \Carbon\CarbonImmutable}|null  $range
     */
    private function delivered(?array $range): Builder
    {
        return OrderMoneyReport::deliveredOrders()->when($range, fn (Builder $orders) => $orders->whereBetween('o.created_at', [$range['from'], $range['until']]))
            ->groupBy('o.user_id')
            ->selectRaw('o.user_id, SUM('.OrderMoneyReport::mrpSql().') AS ordered_mrp, SUM('.OrderMoneyReport::cashGivenSql().') AS cash_given');
    }

    /**
     * @param  array{from: \Carbon\CarbonImmutable, until: \Carbon\CarbonImmutable}|null  $range
     */
    private function withdrawn(?array $range): Builder
    {
        return DB::table('withdrawals')->where('status', 'paid')->when($range, fn (Builder $rows) => $rows->whereBetween('created_at', [$range['from'], $range['until']]))
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount) AS total');
    }

    /**
     * @param  array{from: \Carbon\CarbonImmutable, until: \Carbon\CarbonImmutable}|null  $range
     */
    private function spent(?array $range): Builder
    {
        $toAccount = OrderMoneyReport::refundToAccountSql();

        $perOrder = DB::table('receipts as r')->join('orders as o', 'o.id', '=', 'r.order_id')->leftJoin('order_refunds as f', 'f.order_id', '=', 'o.id')
            ->where('r.channel', 'Account')->when($range, fn (Builder $rows) => $rows->whereBetween('r.created_at', [$range['from'], $range['until']]))
            ->groupBy('o.user_id', 'o.id', 'f.id')
            ->selectRaw("o.user_id, SUM(r.amount) - {$toAccount} AS total");

        return DB::query()->fromSub($perOrder, 'per_order')->groupBy('user_id')->selectRaw('user_id, SUM(total) AS total');
    }
}

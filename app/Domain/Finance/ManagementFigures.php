<?php

namespace App\Domain\Finance;

use App\Domain\Inventory\StockLots;
use App\Models\Prescription;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The figures the journal never received: product sales, cost of goods, purchases, stock.
 * Read-only, worked out at report time from the operational records; nothing is posted.
 *
 * Every figure also comes back split into "earlier" and "in_period" so the result of earlier
 * periods stays correct and the balance sheet keeps adding up. Each one is balanced by another
 * figure here: a sale is money in, a goods receipt is stock against a supplier due, goods
 * leaving a branch are either in transit or sold.
 *
 * Two things already are in the journal and must not be added again. Clinic sales and fund
 * spends are read through their own journal entries, so they fall in the same period as the
 * journal and only change how those amounts are presented.
 *
 * @phpstan-type Window array{earlier: int, in_period: int}
 */
final class ManagementFigures
{
    /** How a head is read by the profit and loss (fund_expense_heads.report_as). */
    public const ReportAsExpense = 'expense';

    public const ReportAsSupplierPayment = 'supplier_payment';

    public const ReportAsOwnerWithdrawal = 'owner_withdrawal';

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    /**
     * @param  bool  $showOwnerHeads  False groups spends from the owners' own fund into one line.
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end, bool $showOwnerHeads = true): array
    {
        $this->start = $start;
        $this->end = $end;
        $sales = $this->sales();
        $stock = $this->stock();
        $cost = $this->costOfGoods();
        $purchases = $this->purchases();
        $orders = $this->orderMoney();
        $clinic = $this->clinic();
        $spends = $this->spends($showOwnerHeads);

        return [
            'sales' => $sales,
            'cost_of_goods' => $cost,
            'stock' => [...$stock, 'in_transit' => $cost['in_transit'], 'in_transit_estimated' => $cost['in_transit_estimated']],
            'purchases' => $purchases,
            'order_money' => $orders,
            'clinic' => $clinic,
            'spends' => $spends,
            'effect' => $this->resultEffect($sales, $cost, $stock, $spends),
            'manufacturer_adjustment' => $spends['manufacturer_restored'] - $stock['clinic_out'],
            'notes' => $this->notes($sales, $cost, $stock, $purchases, $spends),
        ];
    }

    /**
     * What these figures add to the journal's result, once for earlier periods and once for this one.
     *
     * @param  array<string, mixed>  $sales
     * @param  array<string, mixed>  $cost
     * @param  array<string, mixed>  $stock
     * @param  array<string, mixed>  $spends
     * @return Window
     */
    private function resultEffect(array $sales, array $cost, array $stock, array $spends): array
    {
        $effect = fn (string $window): int => $sales[$window] - $cost['actual'][$window] - $cost['estimate'][$window] - $stock['loss'][$window] + $spends['effect'][$window];

        return ['earlier' => $effect('earlier'), 'in_period' => $effect('in_period')];
    }

    /**
     * Orders that reached "delivered", with the moment the last parcel arrived. Opening-balance
     * orders are left out: they came in through the journal when balances were imported.
     */
    private function delivered(): Builder
    {
        return DB::table('orders as o')
            ->leftJoin('shipments as s', function ($join): void {
                $join->on('s.order_id', '=', 'o.id')->where('s.status', 'delivered');
            })
            ->where('o.status', 'delivered')
            ->whereRaw('o.is_opening_balance IS NOT TRUE')
            ->groupBy('o.id', 'o.total', 'o.updated_at')
            ->selectRaw('o.id, o.total, COALESCE(MAX(s.delivered_at), o.updated_at) AS delivered_at, MAX(s.delivered_at) IS NULL AS date_missing');
    }

    /** @return array{earlier: int, in_period: int, count: int, dates_missing: int} */
    private function sales(): array
    {
        $row = DB::query()->fromSub($this->delivered(), 'd')
            ->selectRaw(
                'COALESCE(SUM(d.total) FILTER (WHERE d.delivered_at < ?), 0) AS earlier, '
                .'COALESCE(SUM(d.total) FILTER (WHERE d.delivered_at >= ? AND d.delivered_at < ?), 0) AS in_period, '
                .'COUNT(*) FILTER (WHERE d.delivered_at >= ? AND d.delivered_at < ?) AS sold, '
                .'COUNT(*) FILTER (WHERE d.date_missing AND d.delivered_at >= ? AND d.delivered_at < ?) AS dates_missing',
                [$this->start, $this->start, $this->end, $this->start, $this->end, $this->start, $this->end],
            )->first();

        return ['earlier' => self::cents($row->earlier), 'in_period' => self::cents($row->in_period), 'count' => (int) $row->sold, 'dates_missing' => (int) $row->dates_missing];
    }

    /**
     * Each stock_lot_movements row with a value. Only a cost from a supplier goods receipt, or
     * carried over by a transfer from such a batch, counts as real. Any other batch cost may be
     * the product's price copied in when the batch was made, and the two cannot be told apart,
     * so it is an estimate. With no price anywhere the value stays zero and is reported as missing.
     */
    private function valuedMovements(): Builder
    {
        return DB::table('stock_lot_movements as m')
            ->join('stock_lots as l', 'l.id', '=', 'm.lot_id')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('goods_receipt_lines as gr', 'gr.stock_reference', '=', 'm.reference')
            ->leftJoin('goods_receipt_lines as lot_receipt', 'lot_receipt.stock_reference', '=', 'l.reference')
            ->leftJoin('stock_lots as parent', 'parent.id', '=', 'l.parent_lot_id')
            ->leftJoin('goods_receipt_lines as parent_receipt', 'parent_receipt.stock_reference', '=', 'parent.reference')
            ->where('m.created_at', '<', $this->end)
            ->selectRaw(
                'm.created_at AS moved_at, m.order_id, m.quantity, '
                .'m.quantity * COALESCE(NULLIF(l.unit_cost, 0), NULLIF(p.cost_price, 0), 0) AS value, '
                ."CASE WHEN COALESCE(NULLIF(l.unit_cost, 0), NULLIF(p.cost_price, 0)) IS NULL THEN 'missing' "
                ."WHEN NULLIF(l.unit_cost, 0) IS NOT NULL AND (lot_receipt.id IS NOT NULL OR parent_receipt.id IS NOT NULL) THEN 'actual' ELSE 'estimate' END AS basis, "
                ."CASE WHEN m.kind = 'opening' THEN 'opening' "
                ."WHEN m.kind = 'stock_in' AND gr.id IS NOT NULL THEN 'purchase' "
                ."WHEN m.kind = 'stock_in' THEN 'other_in' "
                ."WHEN m.kind IN ('dispatch', 'return') THEN 'customer' "
                ."WHEN m.kind = 'clinic_dispense' THEN 'clinic' "
                ."WHEN m.kind IN ('transfer_out', 'transfer') THEN 'transfer' "
                ."ELSE 'loss' END AS bucket",
            );
    }

    /**
     * Stock at cost at the end of the period, built up from every movement so any period can
     * be read. Goods moving between branches stay company stock, so transfers do not lower it.
     *
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        $rows = DB::query()->fromSub($this->valuedMovements(), 'x')
            ->groupBy('bucket', 'basis')
            ->selectRaw(
                'bucket, basis, '
                .'COALESCE(SUM(value) FILTER (WHERE moved_at < ?), 0) AS earlier, '
                .'COALESCE(SUM(value) FILTER (WHERE moved_at >= ?), 0) AS in_period, '
                .'COALESCE(SUM(quantity), 0) AS units',
                [$this->start, $this->start],
            )->get();

        $closing = ['actual' => 0, 'estimate' => 0];
        $missingUnits = 0;
        $totals = ['opening' => 0, 'other_in' => 0, 'clinic' => 0, 'purchase' => 0];
        $loss = ['earlier' => 0, 'in_period' => 0];
        foreach ($rows as $row) {
            if ($row->bucket === 'transfer') {
                continue;
            }
            $earlier = self::cents($row->earlier);
            $inPeriod = self::cents($row->in_period);
            if ($row->basis === 'missing') {
                $missingUnits += (int) $row->units;
            } else {
                $closing[$row->basis] += $earlier + $inPeriod;
            }
            if ($row->bucket === 'loss') {
                $loss['earlier'] -= $earlier;
                $loss['in_period'] -= $inPeriod;
            } elseif (isset($totals[$row->bucket])) {
                $totals[$row->bucket] += $earlier + $inPeriod;
            }
        }
        $lots = (int) DB::table('stock_lots')->sum('quantity');
        $moved = (int) DB::table('stock_lot_movements')->sum('quantity');

        return [
            'actual' => $closing['actual'],
            'estimate' => $closing['estimate'],
            'missing_units' => max(0, $missingUnits),
            'opening' => $totals['opening'],
            'other_in' => $totals['other_in'],
            'clinic_out' => -$totals['clinic'],
            'purchases_from_lots' => $totals['purchase'],
            'loss' => $loss,
            'lots_match_movements' => $lots === $moved,
        ];
    }

    /**
     * Cost of what delivered orders took out of stock, in the same period as their sale, and
     * of what is on its way but not delivered yet. Parcels sent before lots existed have no
     * lot record, so those use the product's cost price and count as an estimate.
     *
     * @return array<string, mixed>
     */
    private function costOfGoods(): array
    {
        $rows = DB::query()->fromSub($this->valuedMovements(), 'x')
            ->leftJoinSub($this->delivered(), 'd', 'd.id', '=', 'x.order_id')
            ->where('x.bucket', 'customer')
            ->groupBy('x.basis')
            ->selectRaw(
                'x.basis, '
                .'COALESCE(SUM(-x.value) FILTER (WHERE d.delivered_at < ?), 0) AS earlier, '
                .'COALESCE(SUM(-x.value) FILTER (WHERE d.delivered_at >= ? AND d.delivered_at < ?), 0) AS in_period, '
                .'COALESCE(SUM(-x.value) FILTER (WHERE d.delivered_at IS NULL OR d.delivered_at >= ?), 0) AS in_transit, '
                .'COALESCE(SUM(-x.quantity) FILTER (WHERE d.delivered_at >= ? AND d.delivered_at < ?), 0) AS units_sold',
                [$this->start, $this->start, $this->end, $this->end, $this->start, $this->end],
            )->get();

        $actual = $estimate = ['earlier' => 0, 'in_period' => 0];
        $inTransit = $inTransitEstimated = $missingUnits = 0;
        foreach ($rows as $row) {
            $amounts = ['earlier' => self::cents($row->earlier), 'in_period' => self::cents($row->in_period)];
            if ($row->basis === 'actual') {
                $actual = $amounts;
            } elseif ($row->basis === 'estimate') {
                $estimate = $amounts;
            } else {
                $missingUnits += (int) $row->units_sold;
            }
            $inTransit += self::cents($row->in_transit);
            if ($row->basis !== 'actual') {
                $inTransitEstimated += self::cents($row->in_transit);
            }
        }

        $legacy = DB::table('order_lines as ol')
            ->joinSub($this->delivered(), 'd', 'd.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->leftJoinSub(
                DB::table('stock_lot_movements')->whereIn('kind', [StockLots::KindDispatch, StockLots::KindReturn])->whereNotNull('order_line_id')
                    ->groupBy('order_line_id')->selectRaw('order_line_id, SUM(-quantity) AS quantity'),
                'lotted', 'lotted.order_line_id', '=', 'ol.id',
            )
            ->whereRaw('ol.dispatched > COALESCE(lotted.quantity, 0)')
            ->selectRaw(
                'COALESCE(SUM((ol.dispatched - COALESCE(lotted.quantity, 0)) * COALESCE(p.cost_price, 0)) FILTER (WHERE d.delivered_at < ?), 0) AS earlier, '
                .'COALESCE(SUM((ol.dispatched - COALESCE(lotted.quantity, 0)) * COALESCE(p.cost_price, 0)) FILTER (WHERE d.delivered_at >= ? AND d.delivered_at < ?), 0) AS in_period, '
                .'COALESCE(SUM(ol.dispatched - COALESCE(lotted.quantity, 0)) FILTER (WHERE NULLIF(p.cost_price, 0) IS NULL AND d.delivered_at >= ? AND d.delivered_at < ?), 0) AS missing_units',
                [$this->start, $this->start, $this->end, $this->start, $this->end],
            )->first();
        $legacyEarlier = self::cents($legacy->earlier);
        $legacyPeriod = self::cents($legacy->in_period);
        $estimate['earlier'] += $legacyEarlier;
        $estimate['in_period'] += $legacyPeriod;

        return [
            'actual' => $actual,
            'estimate' => $estimate,
            'without_lots' => $legacyEarlier + $legacyPeriod,
            'in_transit' => $inTransit,
            'in_transit_estimated' => $inTransitEstimated,
            'missing_units' => $missingUnits + (int) $legacy->missing_units,
        ];
    }

    /** @return array{earlier: int, in_period: int} Goods received from suppliers, at cost. */
    private function purchases(): array
    {
        $row = DB::table('goods_receipt_lines as line')
            ->join('goods_receipts as receipt', 'receipt.id', '=', 'line.goods_receipt_id')
            ->join('products as p', 'p.id', '=', 'line.product_id')
            ->where('receipt.created_at', '<', $this->end)
            ->selectRaw(
                'COALESCE(SUM(line.quantity * COALESCE(NULLIF(line.unit_cost, 0), NULLIF(p.cost_price, 0), 0)) FILTER (WHERE receipt.created_at < ?), 0) AS earlier, '
                .'COALESCE(SUM(line.quantity * COALESCE(NULLIF(line.unit_cost, 0), NULLIF(p.cost_price, 0), 0)) FILTER (WHERE receipt.created_at >= ?), 0) AS in_period',
                [$this->start, $this->start],
            )->first();

        return ['earlier' => self::cents($row->earlier), 'in_period' => self::cents($row->in_period)];
    }

    /**
     * Money received for orders at the end of the period: receipts less refunds, and which part
     * of that is for goods not delivered yet. A refund after dispatch is done by hand and is
     * not recorded, so the amount held can be too high on returned parcels.
     *
     * @return array{received: int, paid_not_delivered: int, delivered_not_paid: int, money_on_closed_orders: int, closed_orders: int, part_delivered: int, part_delivered_amount: int}
     */
    private function orderMoney(): array
    {
        $received = DB::table('receipts')->where('created_at', '<', $this->end)->groupBy('order_id')->selectRaw('order_id, SUM(amount) AS total');
        $refunded = DB::table('order_refunds')->where('created_at', '<', $this->end)->groupBy('order_id')->selectRaw('order_id, SUM(amount) AS total');
        $parcels = DB::table('shipments')->where('status', 'delivered')->groupBy('order_id')->selectRaw('order_id, COUNT(*) AS total');
        $perOrder = DB::table('orders as o')
            ->leftJoinSub($received, 'received', 'received.order_id', '=', 'o.id')
            ->leftJoinSub($refunded, 'refunded', 'refunded.order_id', '=', 'o.id')
            ->leftJoinSub($this->delivered(), 'd', 'd.id', '=', 'o.id')
            ->leftJoinSub($parcels, 'parcels', 'parcels.order_id', '=', 'o.id')
            ->whereRaw('o.is_opening_balance IS NOT TRUE')
            ->selectRaw(
                'COALESCE(received.total, 0) - COALESCE(refunded.total, 0) AS cash, '
                .'CASE WHEN d.delivered_at < ? THEN o.total ELSE 0 END AS sold, '
                ."o.status IN ('cancelled', 'rejected') AS closed, "
                ."o.status <> 'delivered' AND parcels.total IS NOT NULL AS part_delivered, o.total",
                [$this->end],
            );
        $row = DB::query()->fromSub($perOrder, 'x')->selectRaw(
            'COALESCE(SUM(cash), 0) AS received, '
            .'COALESCE(SUM(GREATEST(cash - sold, 0)), 0) AS paid_not_delivered, '
            .'COALESCE(SUM(GREATEST(sold - cash, 0)), 0) AS delivered_not_paid, '
            .'COALESCE(SUM(cash) FILTER (WHERE closed AND cash > 0), 0) AS money_on_closed_orders, '
            .'COUNT(*) FILTER (WHERE closed AND cash > 0) AS closed_orders, '
            .'COUNT(*) FILTER (WHERE part_delivered) AS part_delivered, '
            .'COALESCE(SUM(total) FILTER (WHERE part_delivered), 0) AS part_delivered_amount',
        )->first();

        return [
            'received' => self::cents($row->received),
            'paid_not_delivered' => self::cents($row->paid_not_delivered),
            'delivered_not_paid' => self::cents($row->delivered_not_paid),
            'money_on_closed_orders' => self::cents($row->money_on_closed_orders),
            'closed_orders' => (int) $row->closed_orders,
            'part_delivered' => (int) $row->part_delivered,
            'part_delivered_amount' => self::cents($row->part_delivered_amount),
        ];
    }

    /**
     * Clinic sales, read through their own journal entries so the amounts and dates match the
     * journal exactly. The journal has already split each sale; this only shows it whole.
     *
     * @return array{sales: int, manufacturer: int, clinic_fund: int, count: int, earlier_sales: int, earlier_manufacturer: int, earlier_clinic_fund: int}
     */
    private function clinic(): array
    {
        $row = DB::table('prescriptions as p')
            ->join('journal_entries as j', 'j.event_key', '=', DB::raw("'clinic.dispense.' || p.id::text"))
            ->where('p.kind', Prescription::KindClinic)
            ->where('p.status', Prescription::StatusDispensed)
            ->where('j.created_at', '<', $this->end)
            ->selectRaw(
                'COALESCE(SUM(p.manufacturer_share) FILTER (WHERE j.created_at < ?), 0) AS earlier_manufacturer, '
                .'COALESCE(SUM(p.clinic_fund) FILTER (WHERE j.created_at < ?), 0) AS earlier_clinic_fund, '
                .'COALESCE(SUM(p.manufacturer_share) FILTER (WHERE j.created_at >= ?), 0) AS manufacturer, '
                .'COALESCE(SUM(p.clinic_fund) FILTER (WHERE j.created_at >= ?), 0) AS clinic_fund, '
                .'COUNT(*) FILTER (WHERE j.created_at >= ?) AS sold',
                [$this->start, $this->start, $this->start, $this->start, $this->start],
            )->first();
        $manufacturer = self::cents($row->manufacturer);
        $clinicFund = self::cents($row->clinic_fund);
        $earlierManufacturer = self::cents($row->earlier_manufacturer);
        $earlierFund = self::cents($row->earlier_clinic_fund);

        return [
            'sales' => $manufacturer + $clinicFund,
            'manufacturer' => $manufacturer,
            'clinic_fund' => $clinicFund,
            'count' => (int) $row->sold,
            'earlier_sales' => $earlierManufacturer + $earlierFund,
            'earlier_manufacturer' => $earlierManufacturer,
            'earlier_clinic_fund' => $earlierFund,
        ];
    }

    /**
     * Fund spends through their journal entries, which is how each one avoids being counted
     * twice: only a head's own line is shown, and the matching journal amount is marked as
     * covered. Paying a manufacturer or an owner taking money out is not an expense, and a
     * spend from the company share is an expense the journal never put in the profit and loss.
     *
     * @return array<string, mixed>
     */
    private function spends(bool $showOwnerHeads): array
    {
        $rows = DB::table('reserve_disbursements as r')
            ->join('journal_entries as j', 'j.event_key', '=', DB::raw("'fund.spend.' || r.id::text"))
            ->join('journal_lines as l', 'l.entry_id', '=', 'j.id')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->where('r.program', FundSpending::Program)
            ->where('l.debit', '>', 0)
            ->where('j.created_at', '<', $this->end)
            ->groupBy('r.expense_head_id', 'h.name_en', 'h.name_bn', 'h.report_as', 'r.fund_key', 'l.account')
            ->selectRaw(
                'r.expense_head_id, h.name_en, h.name_bn, h.report_as, r.fund_key, l.account, '
                .'COALESCE(SUM(l.debit) FILTER (WHERE j.created_at < ?), 0) AS earlier, '
                .'COALESCE(SUM(l.debit) FILTER (WHERE j.created_at >= ?), 0) AS in_period',
                [$this->start, $this->start],
            )->get();

        $heads = [];
        $covered = ['earlier' => [], 'in_period' => []];
        $effect = $supplierPaid = $ownerDrawn = ['earlier' => 0, 'in_period' => 0];
        $manufacturerRestored = 0;
        $bangla = app()->getLocale() === 'bn';
        foreach ($rows as $row) {
            $treatment = $row->report_as ?? self::ReportAsExpense;
            $fromLiability = (AccountingStatements::AccountClasses[$row->account] ?? null) === AccountingStatements::Liability;
            $amounts = ['earlier' => self::cents($row->earlier), 'in_period' => self::cents($row->in_period)];
            foreach ($amounts as $window => $amount) {
                if ($amount === 0) {
                    continue;
                }
                if ($fromLiability) {
                    $manufacturerRestored += $amount;
                } else {
                    $covered[$window][$row->account] = ($covered[$window][$row->account] ?? 0) + $amount;
                }
                if ($treatment === self::ReportAsSupplierPayment) {
                    $supplierPaid[$window] += $amount;
                    $effect[$window] += $fromLiability ? 0 : $amount;
                } elseif ($treatment === self::ReportAsOwnerWithdrawal) {
                    $ownerDrawn[$window] += $amount;
                    $effect[$window] += $fromLiability ? 0 : $amount;
                } else {
                    $effect[$window] -= $fromLiability ? $amount : 0;
                }
            }
            if ($treatment !== self::ReportAsExpense || $amounts['in_period'] === 0) {
                continue;
            }
            $hidden = ! $showOwnerHeads && in_array($row->fund_key, CompanyFunds::OwnerOnly, true);
            $key = $hidden ? 'owners_own_fund' : (string) $row->expense_head_id;
            $heads[$key] ??= ['key' => $key, 'label' => $hidden ? __('accounting.head_owners_own_fund') : (string) ($bangla ? $row->name_bn : $row->name_en), 'amount' => 0];
            $heads[$key]['amount'] += $amounts['in_period'];
        }

        usort($heads, fn (array $left, array $right): int => $right['amount'] <=> $left['amount']);

        return [
            'heads' => $heads,
            'covered' => $covered,
            'effect' => $effect,
            'supplier_paid' => $supplierPaid['earlier'] + $supplierPaid['in_period'],
            'owner_drawn' => $ownerDrawn['earlier'] + $ownerDrawn['in_period'],
            'manufacturer_restored' => $manufacturerRestored,
        ];
    }

    /**
     * Plain warnings for this period's figures.
     *
     * @param  array<string, mixed>  $sales
     * @param  array<string, mixed>  $cost
     * @param  array<string, mixed>  $stock
     * @param  array{earlier: int, in_period: int}  $purchases
     * @param  array<string, mixed>  $spends
     * @return list<string>
     */
    private function notes(array $sales, array $cost, array $stock, array $purchases, array $spends): array
    {
        $notes = [];
        if ($cost['missing_units'] > 0) {
            $notes[] = __('accounting.note_cost_missing', ['units' => (string) $cost['missing_units']]);
        }
        if ($stock['missing_units'] > 0) {
            $notes[] = __('accounting.note_stock_cost_missing', ['units' => (string) $stock['missing_units']]);
        }
        if ($sales['dates_missing'] > 0) {
            $notes[] = __('accounting.note_delivery_dates', ['count' => (string) $sales['dates_missing']]);
        }
        if (! $stock['lots_match_movements']) {
            $notes[] = __('accounting.note_lots_do_not_match');
        }
        $received = $purchases['earlier'] + $purchases['in_period'];
        if ($received !== $stock['purchases_from_lots']) {
            $notes[] = __('accounting.note_purchases_do_not_match');
        }
        if ($spends['supplier_paid'] > $received) {
            $notes[] = __('accounting.note_supplier_overpaid');
        }

        return $notes;
    }

    /** SUM() of a signed column can start with a minus, which Money::cents does not accept. */
    private static function cents(mixed $value): int
    {
        $text = (string) $value;

        return str_starts_with($text, '-') ? -Money::cents(substr($text, 1)) : Money::cents($text);
    }
}

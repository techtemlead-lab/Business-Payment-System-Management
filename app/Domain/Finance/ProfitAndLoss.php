<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Inventory\StockLots;
use App\Domain\Orders\DeliveredMoney;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The owner's profit and loss for one period, in plain lines: what the delivered goods sold for,
 * what came back as refunds, what the goods cost, what the commission plan set aside for those
 * same sales, the staff salaries and office spending of the period, and what is left.
 *
 * A sale lands in the period its goods were delivered (the last delivered parcel, as in
 * {@see ManagementFigures}); a partly delivered order counts its delivered part only. Everything
 * tied to a sale follows that sale: its refunds after delivery and its commission rows, read as
 * they were posted and net of what a refund took back. So every figure traces to rows of orders,
 * order lines, commission batches and commission rows. Read-only; all amounts are paisa.
 *
 * Cost of goods is the batch cost (stock_lots.unit_cost) of the batches each line was sent from,
 * otherwise the product's cost price; a line with neither is listed as missing, not guessed.
 *
 * @phpstan-type FundLine array{key: string, name: string, amount: string}
 */
final class ProfitAndLoss
{
    /** The part of a dealer's price the commission plan pays out into commissions and funds. */
    public const PlanShareOfDealerPrice = 50;

    /** Commission rows already in a partner's wallet; every other partner row is still waiting. */
    private const CreditedStatuses = ['available', 'settled'];

    /** The funds the company keeps for itself. Spending from them on running costs is an expense. */
    private const KeptFunds = ['company_share', 'company_profit', 'benefits'];

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    /**
     * @param  string  $from  first day, Asia/Dhaka, inclusive
     * @param  string  $to  last day, Asia/Dhaka, inclusive
     * @return array<string, mixed>
     */
    public function build(string $from, string $to): array
    {
        $this->start = CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay()->utc();
        $this->end = CarbonImmutable::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->utc();

        $sales = $this->sales();
        $batches = $this->batches();
        $commission = $this->commission();
        $goods = $this->costOfGoods();
        $payroll = $this->payroll(substr($from, 0, 7), substr($to, 0, 7));
        $spending = $this->officeSpending($from, $to);

        $netSales = $sales['sold'] - $batches['refunded'];
        $grossMargin = $netSales - $goods['cost'];
        $setAside = $commission['credited'] + $commission['waiting'] + $commission['funds_total'];
        $profit = $grossMargin - $setAside - $payroll['amount'] - $spending;
        $kept = $batches['company_share'] + $commission['company_profit'] + $batches['owners_share'];
        $postedLeft = $batches['cash'] - $batches['refunded'];

        return [
            'period' => ['from' => $from, 'to' => $to],
            'orders' => ['count' => $sales['count'], 'with_commission' => $batches['count']],
            'figures' => [
                'sales' => Money::decimal($sales['sold']),
                'refunds' => Money::decimal($batches['refunded']),
                'net_sales' => FundCatalog::decimalOf($netSales),
                'cost_of_goods' => Money::decimal($goods['cost']),
                'gross_margin' => FundCatalog::decimalOf($grossMargin),
                'partners_credited' => Money::decimal($commission['credited']),
                'partners_waiting' => Money::decimal($commission['waiting']),
                'funds_total' => Money::decimal($commission['funds_total']),
                'set_aside_total' => Money::decimal($setAside),
                'courier' => null,
                'payroll' => Money::decimal($payroll['amount']),
                'office_spending' => Money::decimal($spending),
                'profit' => FundCatalog::decimalOf($profit),
            ],
            'funds' => $commission['funds'],
            'kept' => [
                'company_share' => Money::decimal($batches['company_share']),
                'company_profit' => Money::decimal($commission['company_profit']),
                'owners_share' => Money::decimal($batches['owners_share']),
                'total' => Money::decimal($kept),
            ],
            'cost_basis' => [
                'from_batches' => Money::decimal($goods['from_batches']),
                'from_product_price' => Money::decimal($goods['from_product_price']),
                'missing_units' => $goods['missing_units'],
            ],
            'payroll_slips' => $payroll['slips'],
            'plan_share' => [
                'limit' => (string) self::PlanShareOfDealerPrice,
                'actual' => $postedLeft > 0 ? self::percent($setAside, $postedLeft) : null,
            ],
            // What the commission rows and the company's kept shares add up to, against the money
            // the posted sales kept after refunds. A difference means a row does not tie out.
            'check' => [
                'adds_up' => $setAside + $kept === $postedLeft,
                'difference' => FundCatalog::decimalOf($postedLeft - $setAside - $kept),
            ],
            'warnings' => [
                'missing_cost' => $goods['missing'],
                'over_margin' => $this->overMargin(),
                'no_commission_yet' => [
                    'orders' => $sales['count'] - $batches['count'],
                    'sales' => Money::decimal($batches['unposted_sales']),
                ],
            ],
        ];
    }

    /**
     * Orders with goods delivered in the period, with the delivered value of each (alias `d`:
     * id, status, sold). The moment a sale lands is its last delivered parcel, as
     * ManagementFigures reads it. Opening-balance orders came in through the opening balances.
     */
    private function periodOrders(): Builder
    {
        $deliveredAt = 'COALESCE(MAX(s.delivered_at), o.updated_at)';

        return DB::table('orders as o')
            ->leftJoin('shipments as s', function ($join): void {
                $join->on('s.order_id', '=', 'o.id')->where('s.status', 'delivered');
            })
            ->whereRaw(DeliveredMoney::countedSql('o'))
            ->whereRaw('o.is_opening_balance IS NOT TRUE')
            ->groupBy('o.id')
            ->havingRaw("{$deliveredAt} >= ? AND {$deliveredAt} < ?", [$this->start, $this->end])
            ->selectRaw('o.id, o.status, '.DeliveredMoney::valueSql('o').' AS sold');
    }

    /** @return array{sold: int, count: int} */
    private function sales(): array
    {
        $row = DB::query()->fromSub($this->periodOrders(), 'd')->selectRaw('COALESCE(SUM(d.sold), 0) AS sold, COUNT(*) AS orders')->first();

        return ['sold' => FundCatalog::centsOf($row->sold), 'count' => (int) $row->orders];
    }

    /**
     * The commission batch of each sale: the money it was posted on, its refunds after delivery,
     * and the company share and owners' share left after those refunds.
     *
     * @return array{count: int, cash: int, refunded: int, company_share: int, owners_share: int, unposted_sales: int}
     */
    private function batches(): array
    {
        $left = '(b.cash_basis - b.refunded_amount) / NULLIF(b.cash_basis, 0)';
        $row = DB::query()->fromSub($this->periodOrders(), 'd')
            ->leftJoin('commission_batches as b', 'b.order_id', '=', 'd.id')
            ->selectRaw(
                'COUNT(b.id) AS batches, COALESCE(SUM(b.cash_basis), 0) AS cash, COALESCE(SUM(b.refunded_amount), 0) AS refunded, '
                ."COALESCE(SUM(ROUND(b.company_share * {$left}, 2)), 0) AS company_share, "
                ."COALESCE(SUM(ROUND(b.unallocated * {$left}, 2)), 0) AS owners_share, "
                .'COALESCE(SUM(d.sold) FILTER (WHERE b.id IS NULL), 0) AS unposted_sales',
            )->first();

        return [
            'count' => (int) $row->batches,
            'cash' => FundCatalog::centsOf($row->cash),
            'refunded' => FundCatalog::centsOf($row->refunded),
            'company_share' => FundCatalog::centsOf($row->company_share),
            'owners_share' => FundCatalog::centsOf($row->owners_share),
            'unposted_sales' => FundCatalog::centsOf($row->unposted_sales),
        ];
    }

    /**
     * The commission rows of the period's sales as they were posted, at what is left of each
     * after refunds. A row moved later (for example to company profit) is counted where it was
     * posted, so nothing counts twice; the one exception is the no-commission row, which
     * replaces the commission it took back. The owners' share row is the batch's own share.
     *
     * @return array{credited: int, waiting: int, company_profit: int, funds_total: int, funds: list<FundLine>}
     */
    private function commission(): array
    {
        $rows = DB::table('commission_allocations as funds')
            ->joinSub($this->periodOrders(), 'd', 'd.id', '=', 'funds.order_id')
            ->where('funds.category', '!=', RetainedFundLedger::BenefitsCategory)
            ->where(fn (Builder $query) => $query->whereRaw("funds.metadata->>'source_allocation_id' IS NULL")->orWhere('funds.category', CompanyFunds::NoCommissionCategory))
            ->selectRaw(FundCatalog::rowFundSql().' AS fund, funds.user_id IS NOT NULL AS to_partner, funds.status, funds.amount');
        $grouped = DB::query()->fromSub($rows, 'x')->groupBy('x.fund', 'x.to_partner', 'x.status')
            ->selectRaw('x.fund, x.to_partner, x.status, COALESCE(SUM(x.amount), 0) AS amount')->get();

        $credited = $waiting = $companyProfit = 0;
        $funds = [];
        foreach ($grouped as $row) {
            $amount = FundCatalog::centsOf($row->amount);
            if ($row->status === 'forfeited' || $row->fund === 'company_profit') {
                $companyProfit += $amount;
            } elseif ($row->fund !== null) {
                $funds[$row->fund] = ($funds[$row->fund] ?? 0) + $amount;
            } elseif ($row->to_partner && in_array($row->status, self::CreditedStatuses, true)) {
                $credited += $amount;
            } elseif ($row->to_partner) {
                $waiting += $amount;
            } else {
                $funds['other'] = ($funds['other'] ?? 0) + $amount;
            }
        }
        $order = array_flip(FundCatalog::keys());
        uksort($funds, fn (string $left, string $right): int => ($order[$left] ?? PHP_INT_MAX) <=> ($order[$right] ?? PHP_INT_MAX));
        $lines = [];
        foreach ($funds as $key => $amount) {
            if ($amount !== 0) {
                $lines[] = ['key' => $key, 'name' => $key === 'other' ? (string) __('profit_loss.fund_other') : FundCatalog::name($key), 'amount' => Money::decimal($amount)];
            }
        }

        return ['credited' => $credited, 'waiting' => $waiting, 'company_profit' => $companyProfit, 'funds_total' => array_sum($funds), 'funds' => $lines];
    }

    /**
     * Cost of the delivered units of every order line (free goods too: they cost the company
     * money and bring none in). A line's unit cost is the average cost of the batches it was sent
     * from; with no batch cost it is the product's cost price; with neither it is missing.
     *
     * @return array{cost: int, from_batches: int, from_product_price: int, missing_units: int, missing: list<array{product_id: int, name: string, sku: string, units: int, sales: string}>}
     */
    private function costOfGoods(): array
    {
        $batchCost = DB::table('stock_lot_movements as m')
            ->join('stock_lots as lot', 'lot.id', '=', 'm.lot_id')
            ->where('m.kind', StockLots::KindDispatch)
            ->whereNotNull('m.order_line_id')
            ->where('lot.unit_cost', '>', 0)
            ->groupBy('m.order_line_id')
            ->havingRaw('SUM(-m.quantity) > 0')
            ->selectRaw('m.order_line_id, ROUND(SUM(-m.quantity * lot.unit_cost) / SUM(-m.quantity), 2) AS unit_cost');
        $lines = DB::table('order_lines as l')
            ->joinSub($this->periodOrders(), 'd', 'd.id', '=', 'l.order_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoinSub($batchCost, 'bc', 'bc.order_line_id', '=', 'l.id')
            ->selectRaw(
                'l.product_id, '.DeliveredMoney::lineQuantitySql('d', 'l').' AS units, '.DeliveredMoney::lineValueSql('d', 'l').' AS sold, '
                .'COALESCE(bc.unit_cost, NULLIF(p.cost_price, 0)) AS unit_cost, '
                ."CASE WHEN bc.unit_cost IS NOT NULL THEN 'batch' WHEN NULLIF(p.cost_price, 0) IS NOT NULL THEN 'product' ELSE 'missing' END AS basis",
            );
        $rows = DB::query()->fromSub($lines, 'x')->where('x.units', '>', 0)->groupBy('x.product_id', 'x.basis')
            ->selectRaw('x.product_id, x.basis, SUM(x.units) AS units, COALESCE(SUM(x.sold), 0) AS sold, COALESCE(SUM(x.units * x.unit_cost), 0) AS cost')
            ->get();

        $totals = ['batch' => 0, 'product' => 0];
        $missing = [];
        foreach ($rows as $row) {
            if ($row->basis === 'missing') {
                $missing[(int) $row->product_id] = ['units' => (int) $row->units, 'sold' => FundCatalog::centsOf($row->sold)];

                continue;
            }
            $totals[$row->basis] += FundCatalog::centsOf($row->cost);
        }
        $products = DB::table('products')->whereIn('id', array_keys($missing))->get(['id', 'name', 'sku'])->keyBy('id');
        $missingList = [];
        foreach ($missing as $productId => $figures) {
            $product = $products->get($productId);
            $missingList[] = ['product_id' => $productId, 'name' => (string) $product?->name, 'sku' => (string) $product?->sku, 'units' => $figures['units'], 'sales' => Money::decimal($figures['sold'])];
        }
        usort($missingList, fn (array $left, array $right): int => Money::cents($right['sales']) <=> Money::cents($left['sales']));

        return [
            'cost' => $totals['batch'] + $totals['product'],
            'from_batches' => $totals['batch'],
            'from_product_price' => $totals['product'],
            'missing_units' => array_sum(array_column($missing, 'units')),
            'missing' => $missingList,
        ];
    }

    /**
     * Staff salaries approved or paid for the months the period touches. Salaries are worked out
     * by whole month, so a part of a month counts that month's salary in full.
     *
     * @return array{amount: int, slips: int}
     */
    private function payroll(string $fromMonth, string $toMonth): array
    {
        $row = DB::table('payroll_entries')->whereIn('status', ['approved', 'paid'])->whereBetween('period_month', [$fromMonth, $toMonth])
            ->selectRaw('COALESCE(SUM(gross), 0) AS gross, COUNT(*) AS slips')->first();

        return ['amount' => FundCatalog::centsOf($row->gross), 'slips' => (int) $row->slips];
    }

    /**
     * Running costs paid in the period from the money the company keeps (company share, company
     * profit, owners' share). Paying a supplier for goods is the cost of goods, and an owner
     * taking money out is not a cost, so those heads are left out. Spending from the other funds
     * is not added: their whole share is already set aside above.
     */
    private function officeSpending(string $from, string $to): int
    {
        $total = DB::table('reserve_disbursements as r')
            ->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->where('r.status', 'paid')
            ->where('r.program', FundSpending::Program)
            ->whereNotExists(fn ($reversed) => $reversed->from('fund_spend_reversals as v')->whereColumn('v.disbursement_id', 'r.id'))
            ->whereIn('r.fund_key', self::KeptFunds)
            ->whereRaw('COALESCE(h.report_as, ?) = ?', [ManagementFigures::ReportAsExpense, ManagementFigures::ReportAsExpense])
            ->whereRaw('COALESCE(r.spent_on, r.paid_at::date) BETWEEN ?::date AND ?::date', [$from, $to])
            ->sum('r.amount');

        return FundCatalog::centsOf($total);
    }

    /**
     * Active products whose cost is above the part of the dealer price the plan keeps for the
     * company: once commissions and funds pay out, each unit sold loses the difference. Cost is
     * the product's cost price, otherwise its newest batch cost.
     *
     * @return list<array{product_id: int, name: string, sku: string, dealer_price: string, cost: string, limit: string, over_by: string, cost_percent: string}>
     */
    private function overMargin(): array
    {
        $newestBatchCost = '(SELECT lot.unit_cost FROM stock_lots lot WHERE lot.product_id = p.id AND lot.unit_cost > 0 ORDER BY lot.received_on DESC, lot.id DESC LIMIT 1)';
        $products = DB::table('products as p')->where('p.active', true)->where('p.dealer_price', '>', 0)
            ->selectRaw("p.id, p.name, p.sku, p.dealer_price, COALESCE(NULLIF(p.cost_price, 0), {$newestBatchCost}) AS cost")
            ->orderBy('p.name')->get();

        $over = [];
        foreach ($products as $product) {
            if ($product->cost === null) {
                continue;
            }
            $cost = FundCatalog::centsOf($product->cost);
            $dealer = FundCatalog::centsOf($product->dealer_price);
            $limit = intdiv($dealer * (100 - self::PlanShareOfDealerPrice) + 50, 100);
            if ($cost <= $limit) {
                continue;
            }
            $over[] = [
                'product_id' => (int) $product->id, 'name' => (string) $product->name, 'sku' => (string) $product->sku,
                'dealer_price' => Money::decimal($dealer), 'cost' => Money::decimal($cost), 'limit' => Money::decimal($limit),
                'over_by' => Money::decimal($cost - $limit), 'cost_percent' => self::percent($cost, $dealer),
            ];
        }
        usort($over, fn (array $left, array $right): int => Money::cents($right['over_by']) <=> Money::cents($left['over_by']));

        return $over;
    }

    /** A share as a percent with two decimals, worked out in whole numbers (half up). */
    private static function percent(int $part, int $whole): string
    {
        $basisPoints = intdiv($part * 10000 + intdiv($whole, 2), $whole);

        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }
}

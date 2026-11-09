<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\StockStages;
use App\Domain\Orders\CourierBoard;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderListFilter;
use App\Domain\Orders\OverdueDeliveries;
use App\Domain\Orders\ShipmentTracking;
use App\Models\Order;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Paged order list shared by the overview, orders, deliveries and reports sections.
 */
final class OrdersSection implements WorkspaceSection
{
    /** Not paid yet. A refused or cancelled order owes nothing. */
    private const ToPaySql = "CASE WHEN orders.status NOT IN ('rejected', 'cancelled') THEN GREATEST(orders.total - orders.due_credit - orders.paid, 0) ELSE 0 END";

    /** The step a parcel is on, the same way the courier board counts it. */
    private const ParcelStepSql = "CASE WHEN shipments.courier_booking_status IN ('pending', 'booking') THEN 'booking' WHEN shipments.courier_booking_status = 'failed' THEN 'failed' ELSE COALESCE(shipments.courier_status, 'waiting_pickup') END";

    /** @var array<string, string> screen key => fixed SQL */
    private const OrderSorts = [
        'number' => 'orders.number',
        'created_at' => 'orders.created_at',
        'partner' => '(SELECT users.name FROM users WHERE users.id = orders.user_id)',
        'total' => 'orders.total',
        'paid' => 'orders.paid',
        'to_pay' => self::ToPaySql,
        'branch' => '(SELECT company_branches.name FROM company_branches WHERE company_branches.id = orders.fulfillment_branch_id)',
    ];

    private const StaffOrderRows = 25;

    private const PartnerOrderRows = 12;

    /** The office's Deliveries page shows one of these at a time. */
    private const DeliveryViews = ['send', 'parcels', 'failed', 'decisions'];

    public function data(SectionContext $context, array $data): array
    {
        if (! in_array($context->section, ['overview', 'orders', 'deliveries', 'reports'])) {
            return [];
        }
        $request = $context->request;
        $usesSharedTable = $context->isStaff && in_array($context->section, ['orders', 'deliveries'], true);
        $deliveryView = $this->deliveryView($context);
        $filteredOrders = app(OrderListFilter::class)->apply(clone $context->orders, $request, $context->section, $context->isStaff);
        // Overview only needs ordered and sent quantities for the items and delivered columns — keep the payload slim.
        $lineRelation = $context->section === 'overview' ? 'lines:id,order_id,quantity,dispatched' : 'lines';
        $orderWith = ['user:id,name,dealer_code,role,phone,division,district', $lineRelation];
        // Partners see which branch is sending their order; only the name and code are loaded.
        if (Schema::hasColumn('orders', 'fulfillment_branch_id')) {
            $orderWith[] = 'fulfillmentBranch:id,name,code';
        }
        // The side panel opened from a row shows these without asking the server again.
        if ($usesSharedTable) {
            $orderWith[] = 'paymentClaims:id,order_id,channel,reference,amount,status';
            $orderWith[] = 'shipments:id,order_id,courier,courier_provider,tracking,status,courier_status,courier_booking_status';
        }

        $listQuery = clone $filteredOrders;
        if ($context->section === 'deliveries' && OrderListFilter::wantsOverdueDeliveries($request)) {
            // Overdue rows also show the latest courier, tracking number and days since sending.
            app(OverdueDeliveries::class)->withShipmentDetails($listQuery);
        } else {
            $listQuery->select('orders.*');
        }

        $listQuery->with($orderWith)
            ->selectRaw(DeliveredMoney::valueSql().' AS delivered_value, '.DeliveredMoney::paidSql().' AS delivered_paid');
        [$sort, $direction, $sorts] = $this->chosenSort($request, $context->section);
        if ($usesSharedTable) {
            $listQuery->reorder()->orderByRaw(self::OrderSorts[$sort].' '.$direction.' NULLS LAST')->orderBy('orders.id', $direction);
        } else {
            $listQuery->latest();
        }

        $props = [];
        if ($deliveryView === 'send') {
            $props['orders'] = $listQuery->paginate($usesSharedTable ? self::StaffOrderRows : self::PartnerOrderRows)->withQueryString();
            if ($usesSharedTable) {
                $this->addParcelLinks($props['orders']);
            }
        }
        if ($context->section === 'orders') {
            $props['order_status_counts'] = $this->statusCounts($context);
            $props['order_totals'] = $this->listTotals($filteredOrders);
            if ($context->isStaff) {
                $totals = $props['order_totals'];
                $props['orders_table'] = $this->tableFacts($request, $sorts, $sort, $direction, ['count' => $totals['orders'], 'capped' => false, 'sums' => Arr::except($totals, 'orders')], ['buyer_role', 'branch_id']);
            }
        }
        if ($context->section === 'deliveries') {
            // On the other views the count on the "to send" tab must not follow their search box.
            $props['delivery_summary'] = $this->deliverySummary($deliveryView === 'send'
                ? $filteredOrders
                : app(OrderListFilter::class)->apply(clone $context->orders, $request->duplicate([]), $context->section, $context->isStaff));
            if ($context->isStaff) {
                // Courier parcels board: filters by step and courier, and the refused bookings.
                $orderIds = (clone $context->orders)->toBase()->reorder()->select('orders.id');
                $props['courier_board'] = app(CourierBoard::class)->for($request, clone $orderIds);
                $props['delivery_view'] = $deliveryView;
                $props['parcel_stages'] = $this->parcelStages(clone $orderIds);
                if ($deliveryView === 'send') {
                    $props['orders_table'] = $this->tableFacts($request, $sorts, $sort, $direction, ['count' => $props['orders']->total(), 'capped' => false, 'sums' => null], ['status', 'buyer_role', 'branch_id']);
                }
                if (in_array($deliveryView, ['parcels', 'failed'], true)) {
                    $props['parcels_table'] = $this->parcelsTable($request, clone $orderIds, $props['courier_board'], $deliveryView === 'failed');
                }
            }
        }

        return $props;
    }

    /**
     * Which view of the office's Deliveries page was asked for. Older links name a parcel filter but no view.
     */
    private function deliveryView(SectionContext $context): string
    {
        if ($context->section !== 'deliveries' || ! $context->isStaff) {
            return 'send';
        }
        $asked = $context->request->query('view');
        if (is_string($asked) && in_array($asked, self::DeliveryViews, true)) {
            return $asked;
        }

        return $context->request->filled('ship_status') || $context->request->filled('ship_courier') ? 'parcels' : 'send';
    }

    /**
     * The office's sort for one order list; anything not offered means newest first.
     *
     * @return array{0: string, 1: 'asc'|'desc', 2: list<string>}
     */
    private function chosenSort(Request $request, string $section): array
    {
        $sorts = $section === 'deliveries'
            ? ['number', 'created_at', 'partner', 'branch', 'total']
            : ['number', 'created_at', 'partner', 'total', 'paid', 'to_pay'];
        $sort = $request->string('sort')->toString();
        if (! in_array($sort, $sorts, true)) {
            return ['created_at', 'desc', $sorts];
        }

        return [$sort, $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc', $sorts];
    }

    /**
     * What the shared table needs beside the rows, which stay in `orders`.
     *
     * @param  list<string>  $sorts
     * @param  'asc'|'desc'  $direction
     * @param  array{count: int, capped: bool, sums: array<string, string>|null}  $totals
     * @param  list<string>  $filterNames
     * @return array{totals: array{count: int, capped: bool, sums: array<string, string>|null}, sortable: list<string>, extra_periods: list<string>, state: array{q: string, period: string, from: ?string, to: ?string, sort: string, dir: string, filters: array<string, string>}}
     */
    private function tableFacts(Request $request, array $sorts, string $sort, string $direction, array $totals, array $filterNames): array
    {
        $chosen = [];
        foreach ($filterNames as $name) {
            if ($request->filled($name)) {
                $chosen[$name] = $request->string($name)->toString();
            }
        }

        return [
            'totals' => $totals,
            'sortable' => $sorts,
            'extra_periods' => ['last_month'],
            'state' => [
                'q' => $request->string('q')->trim()->toString(),
                'period' => $request->string('period')->toString(),
                'from' => $request->filled('from') ? $request->string('from')->toString() : null,
                'to' => $request->filled('to') ? $request->string('to')->toString() : null,
                'sort' => $sort,
                'dir' => $direction,
                'filters' => $chosen,
            ],
        ];
    }

    /**
     * Gives each parcel in the list its courier's tracking page.
     *
     * @param  LengthAwarePaginator<int, Order>  $orders
     */
    private function addParcelLinks(LengthAwarePaginator $orders): void
    {
        $manualPages = app(CourierOptions::class)->manualTrackingPages();
        foreach ($orders->getCollection() as $order) {
            foreach ($order->shipments as $shipment) {
                $shipment->setAttribute('tracking_url', CourierOptions::trackingUrl($shipment->getAttribute('courier_provider'), $shipment->getAttribute('tracking'), null, $manualPages));
            }
        }
    }

    /**
     * How many parcels are at each stage. "With the courier" is the stock page's own stage: the
     * goods have left the branch and are neither delivered nor received back.
     *
     * @return array{with_courier: int, waiting_pickup: int, delivered: int, returned: int}
     */
    private function parcelStages(QueryBuilder $orderIds): array
    {
        $open = StockStages::OpenParcel;
        $row = DB::table('shipments')
            ->whereNotNull('courier_provider')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS with_courier', [$open])
            ->selectRaw("COUNT(*) FILTER (WHERE status = ? AND COALESCE(courier_status, 'waiting_pickup') = 'waiting_pickup') AS waiting_pickup", [$open])
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'delivered') AS delivered")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'returned') AS returned")
            ->first();

        return [
            'with_courier' => (int) $row->with_courier,
            'waiting_pickup' => (int) $row->waiting_pickup,
            'delivered' => (int) $row->delivered,
            'returned' => (int) $row->returned,
        ];
    }

    /**
     * Every parcel booked with a courier, or only the refused bookings, as one shared table.
     *
     * @param  array{counts: array<string, int>, couriers: list<array{key: string, label: string}>}  $board
     * @return array<string, mixed>
     */
    private function parcelsTable(Request $request, QueryBuilder $orderIds, array $board, bool $failedOnly): array
    {
        $parcels = DB::table('shipments')
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereNotNull('shipments.courier_provider')
            ->whereIn('shipments.order_id', $orderIds)
            ->when($failedOnly, fn ($query) => $query->where('shipments.courier_booking_status', 'failed'))
            ->select([
                'shipments.id', 'shipments.order_id', 'orders.number', 'users.name as partner', 'users.dealer_code', 'users.phone',
                'shipments.courier', 'shipments.courier_provider', 'shipments.tracking', 'shipments.courier_status', 'shipments.courier_booking_status',
                'shipments.courier_booking_error', 'shipments.courier_status_at', 'shipments.courier_timeline', 'shipments.updated_at',
            ])
            ->selectRaw(self::ParcelStepSql.' AS step');

        $courierChoices = array_map(fn (array $courier): array => ['value' => $courier['key'], 'label' => $courier['label']], $board['couriers']);
        $filters = [new MoneyTableFilter('ship_courier', (string) __('courier.filter_courier'), 'shipments.courier_provider', $courierChoices)];
        if (! $failedOnly) {
            $steps = array_map(fn (string $step): array => ['value' => $step, 'label' => (string) __($step === 'booking' ? 'courier.filter_booking' : 'courier.status_'.$step), 'count' => $board['counts'][$step] ?? 0], ['booking', ...ShipmentTracking::Statuses]);
            array_unshift($filters, new MoneyTableFilter('ship_status', (string) __('courier.filter_status'), self::ParcelStepSql, $steps));
        }

        $table = MoneyTable::make($request, $parcels, new MoneyTableSpec(
            dateColumn: 'shipments.updated_at',
            search: ['orders.number', 'users.name', 'users.dealer_code', 'shipments.tracking'],
            filters: $filters,
            sorts: [
                'number' => 'orders.number',
                'partner' => 'users.name',
                'courier' => 'shipments.courier',
                'step' => self::ParcelStepSql,
                'updated_at' => 'shipments.updated_at',
            ],
            defaultSort: 'updated_at',
            tieBreaker: 'shipments.id',
            defaultPeriod: null,
            perPage: 15,
            extraPeriods: ['last_month'],
        ));

        $manualPages = app(CourierOptions::class)->manualTrackingPages();
        $table['rows']->through(function (stdClass $row) use ($manualPages): stdClass {
            $row->tracking_url = CourierOptions::trackingUrl($row->courier_provider, $row->tracking, null, $manualPages);
            $row->courier_timeline = json_decode((string) ($row->courier_timeline ?? '[]'), true) ?: [];

            return $row;
        });

        return $table;
    }

    /**
     * Grand totals for every order in the filtered list, not only the 12 on screen. Value and paid
     * count delivered goods only; a partly delivered order counts its delivered part. Rejected and
     * cancelled orders count as orders but add nothing to any money figure.
     *
     * @return array{orders: int, value: string, paid: string, to_pay: string}
     */
    private function listTotals(Builder $filteredOrders): array
    {
        $row = (clone $filteredOrders)->toBase()->reorder()
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM('.DeliveredMoney::valueSql().'), 0) AS value, '
                .'COALESCE(SUM('.DeliveredMoney::paidSql().'), 0) AS paid, '
                .'COALESCE(SUM('.self::ToPaySql.'), 0) AS to_pay')
            ->first();

        return [
            'orders' => (int) $row->orders,
            'value' => Money::decimal(Money::cents((string) $row->value)),
            'paid' => Money::decimal(Money::cents((string) $row->paid)),
            'to_pay' => Money::decimal(Money::cents((string) $row->to_pay)),
        ];
    }

    /**
     * Totals above the deliveries list: orders and units still waiting to be sent.
     *
     * @return array{orders: int, units: int, oldest_days: int}
     */
    private function deliverySummary(Builder $filteredOrders): array
    {
        $today = now('Asia/Dhaka')->toDateString();
        $ids = (clone $filteredOrders)->toBase()->reorder()->select('orders.id');
        $units = (int) DB::table('order_lines')->whereIn('order_id', $ids)->sum(DB::raw('GREATEST(quantity - dispatched, 0)'));
        $row = (clone $filteredOrders)->toBase()->reorder()
            ->selectRaw("COUNT(*) AS orders, COALESCE(MAX(DATE '{$today}' - DATE(orders.created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka')), 0) AS oldest_days")
            ->first();

        return ['orders' => (int) $row->orders, 'units' => $units, 'oldest_days' => (int) $row->oldest_days];
    }

    /**
     * Orders per status under every other filter on screen (search, period,
     * buyer type), for the status tabs above the list — like Shopify or Daraz
     * Seller Center. The status filter itself is left out so every tab keeps
     * its count.
     *
     * @return array<string, int>
     */
    private function statusCounts(SectionContext $context): array
    {
        $withoutStatus = $context->request->duplicate($context->request->except('status'));

        return app(OrderListFilter::class)
            ->apply(clone $context->orders, $withoutStatus, $context->section, $context->isStaff)
            ->toBase()
            ->reorder()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}

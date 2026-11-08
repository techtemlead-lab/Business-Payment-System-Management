<?php

namespace App\Domain\Inventory;

use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\Audit;
use App\Support\SiteCustomization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the batch pages read: near expiry, recall, the reorder list, the batches a
 * shipment really carried, and the public batch check. Nothing here changes stock.
 */
final class StockLotReports
{
    /** @var list<int> */
    public const NearExpiryChoices = [30, 60, 90];

    public const DefaultNearExpiryDays = 90;

    /** How many days of sales the reorder suggestion covers. */
    public const ReorderCoverDays = 30;

    /** How many days of past sales the reorder average looks at. */
    public const ReorderAverageDays = 90;

    private const SettingKey = 'stock_near_expiry_days';

    private const RowLimit = 200;

    public function nearExpiryDays(): int
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $days = (int) ($stored['days'] ?? self::DefaultNearExpiryDays);

        return in_array($days, self::NearExpiryChoices, true) ? $days : self::DefaultNearExpiryDays;
    }

    public function saveNearExpiryDays(User $actor, int $days): void
    {
        if (! in_array($days, self::NearExpiryChoices, true)) {
            throw ValidationException::withMessages(['days' => __('stock_lots.err_invalid_days')]);
        }
        DB::transaction(function () use ($actor, $days): void {
            $before = $this->nearExpiryDays();
            $stored = DB::table('business_settings')->where('key', self::SettingKey)->lockForUpdate()->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode(['days' => $days], JSON_THROW_ON_ERROR),
                'version' => (int) ($stored->version ?? 0) + 1,
                'updated_by' => $actor->id,
                'created_at' => $stored->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'stock.near_expiry_days_set', self::SettingKey, ['before' => $before, 'after' => $days]);
        });
    }

    /**
     * Lots with stock that expire within the window, soonest first, and those already expired.
     *
     * @return array{near: list<array<string, mixed>>, expired: list<array<string, mixed>>}
     */
    public function expiryLists(BranchScope $scope, int $days): array
    {
        $today = StockLot::today();
        $limit = CarbonImmutable::parse($today)->addDays($days)->toDateString();
        $base = StockLot::query()
            ->with(['product:id,name,sku', 'branch:id,code,name'])
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_on');
        $scope->restrict($base, 'branch_id');

        return [
            'near' => $this->lotRows((clone $base)->whereBetween('expires_on', [$today, $limit])),
            'expired' => $this->lotRows((clone $base)->where('expires_on', '<', $today)),
        ];
    }

    /**
     * Everyone who received a batch: orders and partners (less anything that came back) and
     * clinic sales, plus what is left in stock. Clinic rows show only the prescription
     * number, because patients stay private to their own prescriber.
     *
     * @return array{batch: string, orders: list<array<string, mixed>>, clinic: list<array<string, mixed>>, in_stock: list<array<string, mixed>>}
     */
    public function recall(BranchScope $scope, string $batch): array
    {
        $batch = trim($batch);
        if ($batch === '') {
            return ['batch' => '', 'orders' => [], 'clinic' => [], 'in_stock' => []];
        }
        $base = DB::table('stock_lot_movements as m')
            ->join('stock_lots as l', 'l.id', '=', 'm.lot_id')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('company_branches as b', 'b.id', '=', 'm.branch_id')
            ->whereRaw('LOWER(l.batch_number) = ?', [mb_strtolower($batch)]);
        $scope->restrict($base, 'm.branch_id');

        $orders = (clone $base)
            ->whereIn('m.kind', [StockLots::KindDispatch, StockLots::KindReturn])
            ->join('orders as o', 'o.id', '=', 'm.order_id')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->groupBy('o.id', 'o.number', 'o.status', 'u.id', 'u.name', 'u.dealer_code', 'u.role', 'p.name', 'p.sku', 'l.batch_number', 'l.expires_on', 'b.code', 'b.name')
            ->orderByRaw('MIN(m.created_at) DESC')
            ->limit(self::RowLimit)
            ->selectRaw("o.id AS order_id, o.number AS order_number, o.status, u.name AS partner_name, u.dealer_code, u.role AS partner_role, p.name AS product, p.sku, l.batch_number, l.expires_on, b.code AS branch_code, b.name AS branch_name, SUM(CASE WHEN m.kind = 'dispatch' THEN -m.quantity ELSE 0 END) AS sent, SUM(CASE WHEN m.kind = 'return' THEN m.quantity ELSE 0 END) AS returned, MIN(m.created_at) AS sent_at")
            ->get()
            ->map(fn (object $row): array => [...(array) $row, 'sent' => (int) $row->sent, 'returned' => (int) $row->returned])
            ->all();

        $clinic = (clone $base)
            ->where('m.kind', StockLots::KindClinicDispense)
            ->join('prescriptions as rx', 'rx.id', '=', 'm.prescription_id')
            ->groupBy('rx.id', 'rx.number', 'p.name', 'p.sku', 'l.batch_number', 'l.expires_on', 'b.code', 'b.name')
            ->orderByRaw('MIN(m.created_at) DESC')
            ->limit(self::RowLimit)
            ->selectRaw('rx.number AS prescription_number, p.name AS product, p.sku, l.batch_number, l.expires_on, b.code AS branch_code, b.name AS branch_name, SUM(-m.quantity) AS quantity, MIN(m.created_at) AS sent_at')
            ->get()
            ->map(fn (object $row): array => [...(array) $row, 'quantity' => (int) $row->quantity])
            ->all();

        $stock = StockLot::query()
            ->with(['product:id,name,sku', 'branch:id,code,name'])
            ->where('quantity', '>', 0)
            ->whereRaw('LOWER(batch_number) = ?', [mb_strtolower($batch)]);
        $scope->restrict($stock, 'branch_id');

        return ['batch' => $batch, 'orders' => $orders, 'clinic' => $clinic, 'in_stock' => $this->lotRows($stock)];
    }

    /**
     * Products at or below their minimum at each branch, with a suggested order: the average
     * daily sales covered for {@see ReorderCoverDays}, plus the minimum, less what is
     * in stock or already on its way. Sales are dispatches and clinic sales, less returns.
     *
     * @return list<array<string, mixed>>
     */
    public function reorderSuggestions(BranchScope $scope): array
    {
        $since = now()->subDays(self::ReorderAverageDays);
        $sold = DB::table('stock_movements')
            ->where('created_at', '>=', $since)
            ->where(fn ($movements) => $movements
                ->where('kind', 'dispatch')
                ->orWhere('reference', 'like', 'clinic-%')
                ->orWhere('reference', 'like', 'shipment-return:%'))
            ->groupBy('branch_id', 'product_id')
            ->selectRaw('branch_id, product_id, SUM(-quantity) AS units')
            ->get()
            ->keyBy(fn (object $row): string => $row->branch_id.'-'.$row->product_id);
        $incoming = DB::table('stock_transfers')
            ->where('status', StockTransfer::StatusInTransit)
            ->groupBy('destination_branch_id', 'product_id')
            ->selectRaw('destination_branch_id, product_id, SUM(quantity) AS units')
            ->get()
            ->keyBy(fn (object $row): string => $row->destination_branch_id.'-'.$row->product_id);
        $query = DB::table('branch_product_stocks as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->join('company_branches as b', 'b.id', '=', 's.branch_id')
            ->where('p.active', true)
            ->whereColumn('s.quantity', '<=', 's.low_stock_at')
            ->orderBy('p.name')
            ->orderBy('b.code')
            ->limit(self::RowLimit)
            ->select('s.branch_id', 's.product_id', 's.quantity', 's.low_stock_at', 'p.name as product', 'p.sku', 'b.code as branch_code', 'b.name as branch_name');
        $scope->restrict($query, 's.branch_id');

        return $query->get()->map(function (object $row) use ($sold, $incoming): array {
            $key = $row->branch_id.'-'.$row->product_id;
            $average = max(0, (int) ($sold[$key]->units ?? 0)) / self::ReorderAverageDays;
            $onTheWay = (int) ($incoming[$key]->units ?? 0);
            $target = ($average * self::ReorderCoverDays) + (int) $row->low_stock_at;

            return [
                ...(array) $row,
                'quantity' => (int) $row->quantity,
                'low_stock_at' => (int) $row->low_stock_at,
                'average_daily' => number_format($average, 2, '.', ''),
                'incoming' => $onTheWay,
                'suggested' => (int) max(0, ceil($target - (int) $row->quantity - $onTheWay)),
            ];
        })->all();
    }

    /**
     * Lots on hold that still hold stock, with who held them and why.
     *
     * @return list<array<string, mixed>>
     */
    public function heldLots(BranchScope $scope): array
    {
        $query = StockLot::query()
            ->with(['product:id,name,sku', 'branch:id,code,name', 'heldBy:id,name'])
            ->where('quantity', '>', 0)
            ->whereNotNull('held_at');
        $scope->restrict($query, 'branch_id');

        return $this->lotRows($query);
    }

    /**
     * Lots with stock that could be put on hold, optionally narrowed by batch or product.
     *
     * @return list<array<string, mixed>>
     */
    public function holdCandidates(BranchScope $scope, string $search): array
    {
        $query = StockLot::query()
            ->with(['product:id,name,sku', 'branch:id,code,name'])
            ->where('quantity', '>', 0)
            ->whereNull('held_at');
        $scope->restrict($query, 'branch_id');
        $term = trim($search);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $lots) => $lots
                ->where('batch_number', 'ilike', $like)
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like)));
        }

        return $this->lotRows($query);
    }

    /**
     * Lots a stock count added with no batch number or expiry date. Nothing can warn about
     * their expiry until someone fills these in.
     *
     * @return list<array<string, mixed>>
     */
    public function lotsMissingDetails(BranchScope $scope): array
    {
        return $this->lotRows($this->missingDetailsQuery($scope)->with(['product:id,name,sku', 'branch:id,code,name']));
    }

    public function missingDetailsCount(BranchScope $scope): int
    {
        return $this->missingDetailsQuery($scope)->count();
    }

    /**
     * The batches each order line really left with, less anything that came back.
     *
     * @param  list<int>  $lineIds
     * @return array<int, list<array{batch_number: ?string, expires_on: ?string, quantity: int}>> Batches by order line id.
     */
    public function dispatchedBatches(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }
        $rows = DB::table('stock_lot_movements as m')
            ->join('stock_lots as l', 'l.id', '=', 'm.lot_id')
            ->whereIn('m.order_line_id', $lineIds)
            ->whereIn('m.kind', [StockLots::KindDispatch, StockLots::KindReturn])
            ->groupBy('m.order_line_id', 'l.batch_number', 'l.expires_on')
            ->orderByRaw('l.expires_on ASC NULLS LAST')
            ->selectRaw('m.order_line_id, l.batch_number, l.expires_on, SUM(-m.quantity) AS quantity')
            ->get();
        $batches = [];
        foreach ($rows as $row) {
            if ((int) $row->quantity > 0) {
                $batches[(int) $row->order_line_id][] = [
                    'batch_number' => $row->batch_number,
                    'expires_on' => $row->expires_on,
                    'quantity' => (int) $row->quantity,
                ];
            }
        }

        return $batches;
    }

    /**
     * The public check: product, expiry and maker, and nothing else. Exact match only,
     * so nobody can browse batches, and no quantity, branch or partner is ever read.
     *
     * @return list<array{product: string, expires_on: ?string, maker: string}>
     */
    public function publicCheck(string $batch): array
    {
        $batch = trim($batch);
        if ($batch === '') {
            return [];
        }
        $custom = SiteCustomization::all();
        $maker = filled($custom['invoice_manufacturer'] ?? null)
            ? (string) $custom['invoice_manufacturer']
            : (string) (SiteCustomization::defaults()['invoice_manufacturer'] ?? '');

        return DB::table('stock_lots as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereRaw('LOWER(l.batch_number) = ?', [mb_strtolower($batch)])
            ->select('p.name as product', 'l.expires_on')
            ->distinct()
            ->orderBy('p.name')
            ->orderBy('l.expires_on')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => ['product' => (string) $row->product, 'expires_on' => $row->expires_on, 'maker' => $maker])
            ->all();
    }

    /**
     * @return Builder<StockLot>
     */
    private function missingDetailsQuery(BranchScope $scope): Builder
    {
        $query = StockLot::query()
            ->where('quantity', '>', 0)
            ->where(fn (Builder $lots) => $lots->whereNull('batch_number')->orWhereNull('expires_on'))
            ->where(fn (Builder $lots) => $lots
                ->whereIn('reference', DB::table('stock_count_lines')->whereNotNull('stock_reference')->select('stock_reference'))
                ->orWhereIn('reference', DB::table('goods_receipt_lines')->select('stock_reference')));
        $scope->restrict($query, 'branch_id');

        return $query;
    }

    /**
     * @param  Builder<StockLot>  $query
     * @return list<array<string, mixed>>
     */
    private function lotRows(Builder $query): array
    {
        $today = CarbonImmutable::parse(StockLot::today());

        return $query->fefo()->limit(self::RowLimit)->get()->map(fn (StockLot $lot): array => [
            'id' => $lot->id,
            'product' => $lot->product?->name,
            'sku' => $lot->product?->sku,
            'branch_code' => $lot->branch?->code,
            'branch_name' => $lot->branch?->name,
            'batch_number' => $lot->batch_number,
            'expires_on' => $lot->expires_on?->toDateString(),
            'days_left' => $lot->expires_on !== null ? (int) $today->diffInDays(CarbonImmutable::parse($lot->expires_on->toDateString()), false) : null,
            'quantity' => (int) $lot->quantity,
            'received_on' => $lot->received_on?->toDateString(),
            'on_hold' => $lot->isOnHold(),
            'held_at' => $lot->held_at?->toIso8601String(),
            'hold_reason' => $lot->hold_reason,
            'held_by' => $lot->relationLoaded('heldBy') ? $lot->heldBy?->name : null,
        ])->all();
    }
}

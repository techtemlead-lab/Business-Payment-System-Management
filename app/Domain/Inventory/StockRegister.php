<?php

namespace App\Domain\Inventory;

use App\Domain\Orders\OrderListFilter;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\MoneyTable;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The server-side list behind the Stock page and the filters of the Product page: every stock
 * level, stage, quantity, expiry and product filter, worked out by the database so results and
 * totals are right across all pages. It only reads; nothing here changes stock.
 */
final class StockRegister
{
    private const Levels = ['in_stock', 'out_of_stock', 'low', 'full'];

    private const Stages = ['with_courier', 'on_hold', 'expired', 'booked', 'short'];

    private const ExpiryStates = ['expired', 'soon_30', 'soon_60', 'soon_90', 'has_date', 'no_date'];

    private const MissingDetails = ['no_batch', 'no_expiry', 'either'];

    private const IdleDays = [30, 60, 90];

    /**
     * The Stock page: one row per product in the chosen branch, or per product and branch on "All branches".
     *
     * @return array<string, mixed>
     */
    public function stockTable(Request $request, BranchScope $scope, User $viewer): array
    {
        $branchId = $scope->allBranches ? null : ($scope->branchIds[0] ?? null);
        $fields = $this->fields(branchChoice: $branchId === null, showsStock: true, branchChoices: $branchId === null ? $this->branchOptions() : []);
        $active = $this->activeFilters($request, $fields);
        $rows = $this->rows($branchId, wholeProduct: false, catalogRules: false);
        $this->applyFilters($rows, $active);
        if (($range = MoneyTable::range($request, null)) !== null) {
            $this->onlyMovedBetween($rows, $range['from'], $range['until']);
        }
        $showsCost = $viewer->allows('cost_read');
        $showsDealerPrice = $viewer->allows('pricing') || $viewer->allows('orders') || $viewer->allows('finance');
        $table = MoneyTable::make($request, $rows, new MoneyTableSpec(
            dateColumn: null,
            search: ['stock_row.name', 'stock_row.sku', 'stock_row.strength', 'stock_row.category', 'stock_row.sub_category', 'stock_row.unit', 'stock_row.next_batch', 'stock_row.branch_name'],
            filters: [],
            sorts: [
                'name' => 'stock_row.name',
                'sku' => 'stock_row.sku',
                'branch' => 'stock_row.branch_name',
                'in_stock' => 'stock_row.in_stock',
                'on_hold' => 'stock_row.on_hold',
                'with_courier' => 'stock_row.with_courier',
                'booked' => 'stock_row.booked',
                'expiry' => 'stock_row.row_expiry',
                'mrp' => 'stock_row.mrp',
                'stock_value' => 'stock_row.stock_value',
            ],
            defaultSort: 'name',
            tieBreaker: 'stock_row.row_key',
            defaultDir: 'asc',
            sums: [
                'in_stock' => 'stock_row.in_stock',
                'on_hold' => 'stock_row.on_hold',
                'with_courier' => 'stock_row.with_courier',
                'expired' => 'stock_row.expired',
                'stock_value' => 'stock_row.stock_value',
            ],
            defaultPeriod: null,
        ));
        $matchedExpiry = $this->matchedExpiries($active, $table['rows']->items());
        /** @var LengthAwarePaginator<int, object> $page */
        $page = $table['rows'];
        $page->through(fn (object $row): array => $this->rowForScreen($row, $matchedExpiry, $showsCost, $showsDealerPrice));
        // The shared table draws the main filters itself; the rest live in the "More filters" sheet.
        $main = array_values(array_filter($fields, fn (array $field): bool => $field['group'] === 'main'));
        $table['filters'] = array_map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label'], 'options' => $field['options']], $main);
        $table['state']['filters'] = array_intersect_key($active, array_flip(array_column($main, 'key')));
        $table['filter_bar'] = ['fields' => $fields, 'active' => $active];

        return $table;
    }

    /**
     * The filter bar of the Product page. A person without stock rights can narrow by product
     * details and by the plain in-stock label they already see, but not by units or stages.
     *
     * @return array{fields: list<array<string, mixed>>, active: array<string, string>}
     */
    public function catalogFilters(Request $request, bool $showsStock): array
    {
        $fields = $this->fields(branchChoice: $showsStock, showsStock: $showsStock, branchChoices: $showsStock ? $this->branchOptions() : []);

        return ['fields' => $fields, 'active' => $this->activeFilters($request, $fields)];
    }

    /**
     * Ids of the products that match the Product page's filters, for the page's own query.
     *
     * @param  array<string, string>  $active
     */
    public function matchingProductIds(array $active): Builder
    {
        $rows = $this->rows(isset($active['branch_id']) ? (int) $active['branch_id'] : null, wholeProduct: true, catalogRules: true);
        $this->applyFilters($rows, $active);

        return $rows->select('stock_row.id');
    }

    /**
     * Units on hold and with a courier for each listed product, for the product cards.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{on_hold: int, with_courier: int}>
     */
    public function stagesByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::query()->fromSub(StockStages::rows(), 'branch_stage')->whereIn('product_id', $productIds)->groupBy('product_id')
            ->selectRaw('product_id, CAST(SUM(on_hold) AS integer) AS on_hold, CAST(SUM(with_courier) AS integer) AS with_courier')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->product_id => ['on_hold' => (int) $row->on_hold, 'with_courier' => (int) $row->with_courier]])
            ->all();
    }

    /**
     * @return Builder rows named stock_row, one per product and branch or one per product
     */
    private function rows(?int $branchId, bool $wholeProduct, bool $catalogRules): Builder
    {
        $stages = StockStages::rows($branchId);
        if ($wholeProduct) {
            $stages = DB::query()->fromSub($stages, 'branch_stage')->groupBy('product_id')->selectRaw(
                'product_id, CAST(? AS bigint) AS branch_id, CAST(SUM(physical) AS integer) AS physical, CAST(SUM(in_stock) AS integer) AS in_stock, CAST(SUM(with_courier) AS integer) AS with_courier,'
                .' CAST(SUM(on_hold) AS integer) AS on_hold, CAST(SUM(expired) AS integer) AS expired, CAST(SUM(unlotted) AS integer) AS unlotted,'
                .' MIN(next_expiry) AS next_expiry, (ARRAY_AGG(next_batch ORDER BY next_expiry ASC NULLS LAST))[1] AS next_batch',
                [$branchId],
            );
        }
        $booked = DB::table('order_lines as booked_line')
            ->join('orders as booked_order', 'booked_order.id', '=', 'booked_line.order_id')
            ->whereIn('booked_order.status', ['approved', 'partially_dispatched'])
            ->whereColumn('booked_line.dispatched', '<', 'booked_line.quantity')
            ->groupBy('booked_line.product_id')
            ->selectRaw('booked_line.product_id, SUM(booked_line.quantity - booked_line.dispatched) AS units');
        $rowExpiry = 'CASE WHEN stage.next_expiry IS NOT NULL THEN stage.next_expiry WHEN stage.unlotted > 0 THEN products.expires_on ELSE NULL END';
        $rowBatch = 'CASE WHEN stage.next_batch IS NOT NULL THEN stage.next_batch WHEN stage.unlotted > 0 THEN products.batch_number ELSE NULL END';
        // The Product page speaks of what can be sold today, the Stock page of what a branch holds.
        $usesSellable = $catalogRules && $branchId === null;
        $unitsForLevel = $usesSellable ? SellableStock::sql() : 'stage.in_stock';
        $levelBindings = $usesSellable ? [...SellableStock::bindings(), ...SellableStock::bindings()] : [];
        $paused = $catalogRules ? 'COALESCE(products.is_stock_paused, false) OR ' : '';
        $level = "CASE WHEN {$paused}{$unitsForLevel} <= 0 THEN 'out_of_stock' WHEN {$unitsForLevel} <= products.low_stock_at THEN 'low' ELSE 'full' END";
        $branchColumn = $branchId !== null ? 'COALESCE(stage.branch_id, CAST(? AS bigint))' : 'stage.branch_id';
        $inner = DB::query()->fromSub($stages, 'stage')
            ->join('products', 'products.id', '=', 'stage.product_id')
            ->leftJoin('company_branches as branch', 'branch.id', '=', 'stage.branch_id')
            ->leftJoinSub($booked, 'booked', 'booked.product_id', '=', 'stage.product_id')
            ->selectRaw(
                "products.id, products.id || '-' || COALESCE(CAST(stage.branch_id AS text), '') AS row_key, products.name, products.sku, products.strength, products.category, products.sub_category, products.unit, products.mrp, products.cost_price, products.dealer_price, products.sub_dealer_price,"
                .' products.active, products.featured, COALESCE(products.is_stock_paused, false) AS is_stock_paused, products.low_stock_at, products.expires_on AS product_expires_on, products.batch_number AS product_batch, products.created_at,'
                ." {$branchColumn} AS branch_id, branch.name AS branch_name, branch.code AS branch_code,"
                .' stage.physical, stage.in_stock, stage.on_hold, stage.expired, stage.with_courier, stage.unlotted, stage.next_expiry, stage.next_batch,'
                ." {$rowExpiry} AS row_expiry, {$rowBatch} AS row_batch, CAST(COALESCE(booked.units, 0) AS integer) AS booked, stage.physical * products.mrp AS stock_value, {$level} AS level,"
                .' CAST('.SellableStock::sql().' AS integer) AS company_sellable',
                [...($branchId !== null ? [$branchId] : []), ...$levelBindings, ...SellableStock::bindings()],
            );

        return DB::query()->fromSub($inner, 'stock_row');
    }

    /**
     * @param  array<string, string>  $active
     */
    private function applyFilters(Builder $rows, array $active): void
    {
        $today = StockLot::today();
        foreach ($active as $key => $value) {
            match ($key) {
                'level' => $value === 'in_stock'
                    ? $rows->where('stock_row.level', '<>', 'out_of_stock')
                    : $rows->where('stock_row.level', $value),
                'stage' => $value === 'short'
                    ? $rows->whereColumn('stock_row.booked', '>', 'stock_row.company_sellable')
                    : $rows->where('stock_row.'.$value, '>', 0),
                'branch_id' => $rows->where('stock_row.branch_id', (int) $value),
                'category', 'sub_category', 'unit' => $rows->where('stock_row.'.$key, $value),
                'qty_min' => $rows->where('stock_row.in_stock', '>=', (int) $value),
                'qty_max' => $rows->where('stock_row.in_stock', '<=', (int) $value),
                'min_mrp' => $rows->where('stock_row.mrp', '>=', $value),
                'max_mrp' => $rows->where('stock_row.mrp', '<=', $value),
                'product_state' => $rows->where('stock_row.active', $value === 'active'),
                'featured' => $rows->where('stock_row.featured', $value === 'yes'),
                'paused' => $rows->where('stock_row.is_stock_paused', $value === 'yes'),
                'batch' => $this->onHandMatches($rows, 'filter_lot.batch_number ILIKE ?', ['%'.OrderListFilter::escapeLike($value).'%'], 'stock_row.product_batch ILIKE ?', ['%'.OrderListFilter::escapeLike($value).'%']),
                'expiry_from' => $this->onHandMatches($rows, 'filter_lot.expires_on >= ?', [$value], 'stock_row.product_expires_on >= ?', [$value]),
                'expiry_to' => $this->onHandMatches($rows, 'filter_lot.expires_on <= ?', [$value], 'stock_row.product_expires_on <= ?', [$value]),
                'expiry' => $this->applyExpiryState($rows, $value, $today),
                'missing' => $this->applyMissingDetails($rows, $value),
                'idle' => $this->onlyIdleFor($rows, (int) $value),
                default => null,
            };
        }
    }

    private function applyExpiryState(Builder $rows, string $state, string $today): void
    {
        if ($state === 'expired') {
            $rows->where('stock_row.expired', '>', 0);

            return;
        }
        if ($state === 'has_date') {
            $this->onHandMatches($rows, 'filter_lot.expires_on IS NOT NULL', [], 'stock_row.product_expires_on IS NOT NULL', []);

            return;
        }
        if ($state === 'no_date') {
            $this->onHandMatches($rows, 'filter_lot.expires_on IS NULL', [], 'stock_row.product_expires_on IS NULL', []);

            return;
        }
        $until = CarbonImmutable::parse($today)->addDays((int) substr($state, 5))->toDateString();
        $this->onHandMatches($rows, 'filter_lot.expires_on BETWEEN ? AND ?', [$today, $until], 'stock_row.product_expires_on BETWEEN ? AND ?', [$today, $until]);
    }

    private function applyMissingDetails(Builder $rows, string $which): void
    {
        $noBatch = fn (string $column): string => "NULLIF(TRIM({$column}), '') IS NULL";
        $lotBatch = $noBatch('filter_lot.batch_number');
        $ownBatch = $noBatch('stock_row.product_batch');
        match ($which) {
            'no_batch' => $this->onHandMatches($rows, $lotBatch, [], $ownBatch, []),
            'no_expiry' => $this->onHandMatches($rows, 'filter_lot.expires_on IS NULL', [], 'stock_row.product_expires_on IS NULL', []),
            default => $this->onHandMatches($rows, "({$lotBatch} OR filter_lot.expires_on IS NULL)", [], "({$ownBatch} OR stock_row.product_expires_on IS NULL)", []),
        };
    }

    /**
     * A row matches when a batch still on hand at its branch matches, or when it holds units from
     * before batches were recorded and the product's own details match.
     *
     * @param  list<string>  $lotBindings
     * @param  list<string>  $ownBindings
     */
    private function onHandMatches(Builder $rows, string $lotCondition, array $lotBindings, string $ownCondition, array $ownBindings): void
    {
        $rows->where(function (Builder $row) use ($lotCondition, $lotBindings, $ownCondition, $ownBindings): void {
            $row->whereRaw("EXISTS (SELECT 1 FROM stock_lots filter_lot WHERE filter_lot.product_id = stock_row.id AND filter_lot.quantity > 0 AND (stock_row.branch_id IS NULL OR filter_lot.branch_id = stock_row.branch_id) AND {$lotCondition})", $lotBindings)
                ->orWhereRaw("(stock_row.unlotted > 0 AND {$ownCondition})", $ownBindings);
        });
    }

    private function onlyMovedBetween(Builder $rows, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $rows->whereExists(fn (Builder $movement) => $movement->from('stock_movements as period_move')
            ->selectRaw('1')
            ->whereColumn('period_move.product_id', 'stock_row.id')
            ->whereRaw('(stock_row.branch_id IS NULL OR period_move.branch_id = stock_row.branch_id)')
            ->where('period_move.created_at', '>=', $from)
            ->where('period_move.created_at', '<=', $until));
    }

    /** Goods on the shelf that have not gone out to anyone for this many days. */
    private function onlyIdleFor(Builder $rows, int $days): void
    {
        $since = now()->subDays($days);
        $rows->where('stock_row.physical', '>', 0)->whereNotExists(fn (Builder $movement) => $movement->from('stock_movements as idle_move')
            ->selectRaw('1')
            ->whereColumn('idle_move.product_id', 'stock_row.id')
            ->whereRaw('(stock_row.branch_id IS NULL OR idle_move.branch_id = stock_row.branch_id)')
            ->where('idle_move.kind', 'dispatch')
            ->where('idle_move.created_at', '>=', $since));
    }

    /**
     * When a date range is on, a row names the batch that matched, even if another batch goes
     * out first.
     *
     * @param  array<string, string>  $active
     * @param  array<int, object>  $rows
     * @return array<string, string> Soonest matching date by row key.
     */
    private function matchedExpiries(array $active, array $rows): array
    {
        if ((! isset($active['expiry_from']) && ! isset($active['expiry_to'])) || $rows === []) {
            return [];
        }
        $query = DB::table('stock_lots')->where('quantity', '>', 0)->whereNotNull('expires_on')
            ->whereIn('product_id', array_map(fn (object $row): int => (int) $row->id, $rows))
            ->when(isset($active['expiry_from']), fn (Builder $lots) => $lots->where('expires_on', '>=', $active['expiry_from']))
            ->when(isset($active['expiry_to']), fn (Builder $lots) => $lots->where('expires_on', '<=', $active['expiry_to']))
            ->groupBy('product_id', 'branch_id')
            ->selectRaw('product_id, branch_id, MIN(expires_on) AS expires_on');
        $byProduct = [];
        $byBranch = [];
        foreach ($query->get() as $lot) {
            $byBranch[$lot->product_id.'-'.$lot->branch_id] = (string) $lot->expires_on;
            if (! isset($byProduct[$lot->product_id]) || (string) $lot->expires_on < $byProduct[$lot->product_id]) {
                $byProduct[$lot->product_id] = (string) $lot->expires_on;
            }
        }
        $matched = [];
        foreach ($rows as $row) {
            $date = $row->branch_id !== null ? ($byBranch[$row->id.'-'.$row->branch_id] ?? null) : ($byProduct[$row->id] ?? null);
            if ($date !== null) {
                $matched[(string) $row->row_key] = $date;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, string>  $matchedExpiry
     * @return array<string, mixed>
     */
    private function rowForScreen(object $row, array $matchedExpiry, bool $showsCost, bool $showsDealerPrice): array
    {
        $product = new Product;
        $product->forceFill(['name' => $row->name, 'strength' => $row->strength, 'mrp' => $row->mrp]);

        return [
            'id' => (int) $row->id,
            'row_key' => (string) $row->row_key,
            'name' => $row->name,
            'register_name' => $product->registerName(),
            'sku' => $row->sku,
            'category' => $row->category,
            'sub_category' => $row->sub_category,
            'unit' => $row->unit,
            'mrp' => $row->mrp,
            'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            'branch_name' => $row->branch_name,
            'branch_code' => $row->branch_code,
            'physical' => (int) $row->physical,
            'in_stock' => (int) $row->in_stock,
            'on_hold' => (int) $row->on_hold,
            'expired' => (int) $row->expired,
            'with_courier' => (int) $row->with_courier,
            'booked' => (int) $row->booked,
            'free_to_sell' => (int) $row->company_sellable - (int) $row->booked,
            'level' => $row->level,
            'low_stock_at' => (int) $row->low_stock_at,
            'stock_value' => (string) $row->stock_value,
            'is_stock_paused' => (bool) $row->is_stock_paused,
            'active' => (bool) $row->active,
            'expires_on' => $matchedExpiry[(string) $row->row_key] ?? ($row->row_expiry !== null ? substr((string) $row->row_expiry, 0, 10) : null),
            'batch_number' => $row->row_batch,
            ...($showsCost ? ['cost_price' => $row->cost_price] : []),
            ...($showsDealerPrice ? [
                'dealer_price' => $row->dealer_price,
                'sub_dealer_price' => $row->sub_dealer_price,
                'dealer_discount_pct' => $product->discountPercentFromRetail($row->dealer_price),
                'sub_dealer_discount_pct' => $product->discountPercentFromRetail($row->sub_dealer_price),
            ] : []),
        ];
    }

    /**
     * What the filter bar offers. "main" filters are always in view, the rest go in the
     * "More filters" sheet.
     *
     * @param  list<array{value: string, label: string}>  $branchChoices
     * @return list<array<string, mixed>>
     */
    private function fields(bool $branchChoice, bool $showsStock, array $branchChoices): array
    {
        $levels = $showsStock ? self::Levels : ['in_stock', 'out_of_stock'];
        $choices = $this->productChoices();
        $fields = [
            $this->select('level', __('stock_lots.filter_level'), $this->labelled($levels, 'stock_lots.level_'), 'main'),
            $this->select('category', __('portal.col_category'), $choices['category'], 'main'),
        ];
        if ($showsStock) {
            $fields[] = $this->select('stage', __('stock_lots.filter_stage'), $this->labelled(self::Stages, 'stock_lots.stage_filter_'), 'main');
            if ($branchChoice && $branchChoices !== []) {
                $fields[] = $this->select('branch_id', __('portal.col_branch'), $branchChoices, 'main');
            }
        }
        $fields[] = $this->select('sub_category', __('portal.col_subcategory'), $choices['sub_category'], 'more');
        $fields[] = $this->select('unit', __('portal.col_unit'), $choices['unit'], 'more');
        if ($showsStock) {
            $fields[] = ['key' => 'qty', 'type' => 'range', 'label' => __('stock_lots.filter_qty'), 'from' => 'qty_min', 'to' => 'qty_max', 'group' => 'more'];
        }
        $fields[] = ['key' => 'mrp', 'type' => 'range', 'label' => __('stock_lots.filter_mrp'), 'from' => 'min_mrp', 'to' => 'max_mrp', 'group' => 'more', 'decimal' => true];
        if ($showsStock) {
            $fields[] = ['key' => 'expiry_range', 'type' => 'date_range', 'label' => __('stock_lots.filter_expiry_range'), 'from' => 'expiry_from', 'to' => 'expiry_to', 'group' => 'more'];
            $fields[] = $this->select('expiry', __('stock_lots.filter_expiry'), $this->labelled(self::ExpiryStates, 'stock_lots.expiry_filter_'), 'more');
            $fields[] = ['key' => 'batch', 'type' => 'text', 'label' => __('stock_lots.filter_batch'), 'group' => 'more'];
            $fields[] = $this->select('missing', __('stock_lots.filter_missing'), $this->labelled(self::MissingDetails, 'stock_lots.missing_filter_'), 'more');
            $fields[] = $this->select('idle', __('stock_lots.filter_idle'), array_map(fn (int $days): array => ['value' => (string) $days, 'label' => __('stock_lots.days_option', ['days' => $days])], self::IdleDays), 'more');
        }
        $fields[] = $this->select('product_state', __('stock_lots.filter_product_state'), $this->labelled(['active', 'archived'], 'stock_lots.product_state_'), 'more');
        $fields[] = $this->select('featured', __('stock_lots.filter_featured'), $this->labelled(['yes', 'no'], 'stock_lots.answer_'), 'more');
        $fields[] = $this->select('paused', __('stock_lots.filter_paused'), $this->labelled(['yes', 'no'], 'stock_lots.answer_'), 'more');

        return $fields;
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return array{key: string, type: string, label: string, options: list<array{value: string, label: string}>, group: string}
     */
    private function select(string $key, string $label, array $options, string $group): array
    {
        return ['key' => $key, 'type' => 'select', 'label' => $label, 'options' => $options, 'group' => $group];
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function labelled(array $values, string $keyPrefix): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => __($keyPrefix.$value)], $values);
    }

    /**
     * The categories, sub-categories and units in use, read in one query.
     *
     * @return array{category: list<array{value: string, label: string}>, sub_category: list<array{value: string, label: string}>, unit: list<array{value: string, label: string}>}
     */
    private function productChoices(): array
    {
        $rows = DB::table('products')->distinct()->get(['category', 'sub_category', 'unit']);
        $choices = [];
        foreach (['category', 'sub_category', 'unit'] as $column) {
            $values = $rows->pluck($column)->filter(fn (mixed $value): bool => $value !== null && $value !== '')->unique()->sort()->values();
            $choices[$column] = $values->map(fn (mixed $value): array => ['value' => (string) $value, 'label' => (string) $value])->all();
        }

        return $choices;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function branchOptions(): array
    {
        return CompanyBranch::query()->orderBy('name')->get(['id', 'name', 'code'])
            ->map(fn (CompanyBranch $branch): array => ['value' => (string) $branch->id, 'label' => $branch->name.' · '.$branch->code])->values()->all();
    }

    /**
     * What the address really asks for, kept to the choices offered: anything else is ignored.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function activeFilters(Request $request, array $fields): array
    {
        $active = [];
        foreach ($fields as $field) {
            match ($field['type']) {
                'select' => $this->keepChoice($active, $request, $field),
                'range' => $this->keepRange($active, $request, $field),
                'date_range' => $this->keepDates($active, $request, $field),
                default => $this->keepText($active, $request, $field),
            };
        }

        return $active;
    }

    /**
     * @param  array<string, string>  $active
     * @param  array<string, mixed>  $field
     */
    private function keepChoice(array &$active, Request $request, array $field): void
    {
        $value = $this->queryText($request, $field['key']);
        if (in_array($value, array_column($field['options'], 'value'), true)) {
            $active[(string) $field['key']] = $value;
        }
    }

    /**
     * @param  array<string, string>  $active
     * @param  array<string, mixed>  $field
     */
    private function keepRange(array &$active, Request $request, array $field): void
    {
        foreach ([(string) $field['from'], (string) $field['to']] as $name) {
            $value = LocalDigits::latin(trim($this->queryText($request, $name)));
            $valid = $field['decimal'] ?? false ? preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $value) === 1 : preg_match('/^\d{1,9}$/D', $value) === 1;
            if ($valid) {
                $active[$name] = $value;
            }
        }
    }

    /**
     * @param  array<string, string>  $active
     * @param  array<string, mixed>  $field
     */
    private function keepDates(array &$active, Request $request, array $field): void
    {
        $dates = [];
        foreach ([(string) $field['from'], (string) $field['to']] as $name) {
            $value = $this->queryText($request, $name);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $dates[$name] = $value;
            }
        }
        // Dates picked the wrong way round still mean the same period.
        if (count($dates) === 2 && $dates[$field['from']] > $dates[$field['to']]) {
            [$dates[$field['from']], $dates[$field['to']]] = [$dates[$field['to']], $dates[$field['from']]];
        }
        $active += $dates;
    }

    /**
     * @param  array<string, string>  $active
     * @param  array<string, mixed>  $field
     */
    private function keepText(array &$active, Request $request, array $field): void
    {
        $value = mb_substr(trim((string) preg_replace('/\p{Cc}+/u', ' ', mb_scrub($this->queryText($request, $field['key']), 'UTF-8'))), 0, 60);
        if ($value !== '') {
            $active[(string) $field['key']] = $value;
        }
    }

    private function queryText(Request $request, string $name): string
    {
        $value = $request->query($name);

        return is_string($value) ? $value : '';
    }
}

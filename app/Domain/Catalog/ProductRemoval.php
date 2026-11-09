<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Support\LocalDigits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells a person, before and after they press Remove, why a product can only be archived.
 * It only reads. The decision itself stays with Product::isReferenced().
 */
final class ProductRemoval
{
    /** Reasons in the order they are read out. Stock comes first because it is the one a person can change. */
    private const ReasonOrder = ['stock', 'orders', 'stock_history', 'purchases', 'prescriptions', 'schemes', 'reviews', 'health_tags', 'other'];

    /**
     * @return array{can_delete: bool, reasons: list<string>}
     */
    public function forProduct(Product $product, bool $showUnits): array
    {
        return $this->forProducts(collect([$product]), $showUnits)[$product->id];
    }

    /**
     * One query however many products are listed. A person without stock rights is told there is
     * stock, never how much.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array{can_delete: bool, reasons: list<string>}>
     */
    public function forProducts(Collection $products, bool $showUnits): array
    {
        $usesByProduct = $this->usesByProduct($products->map(fn (Product $product): int => (int) $product->id)->all());
        $facts = [];
        foreach ($products as $product) {
            $uses = $usesByProduct[$product->id] ?? [];
            if ((int) $product->stock > 0) {
                $uses['stock'] = (int) $product->stock;
            }
            $reasons = [];
            foreach (self::ReasonOrder as $reason) {
                if (isset($uses[$reason])) {
                    $reasons[] = $this->inWords($reason, $uses[$reason], $showUnits);
                }
            }
            $facts[$product->id] = ['can_delete' => $reasons === [], 'reasons' => $reasons];
        }

        return $facts;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, int>>
     */
    private function usesByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $uses = DB::table('products')
            ->whereIn('products.id', $productIds)
            ->whereRaw('EXISTS (SELECT 1 FROM dated_schemes WHERE CAST(dated_schemes.product_ids AS jsonb) @> to_jsonb(products.id))')
            ->selectRaw("'schemes' AS reason, products.id AS product_id, 1 AS uses");
        $uses->unionAll(
            DB::table('products')
                ->whereIn('products.id', $productIds)
                ->whereRaw("EXISTS (SELECT 1 FROM prescription_templates WHERE CAST(prescription_templates.lines AS jsonb) @> jsonb_build_array(jsonb_build_object('product_id', products.id)))")
                ->selectRaw("'prescriptions' AS reason, products.id AS product_id, 1 AS uses"),
        );
        foreach (Product::ReferencingColumns as $table => $column) {
            $reason = $this->reasonFor($table);
            $uses->unionAll(
                DB::table($table)->whereIn($column, $productIds)->groupBy($column)
                    ->selectRaw("'{$reason}' AS reason, {$column} AS product_id, COUNT(*) AS uses"),
            );
        }
        $byProduct = [];
        foreach ($uses->get() as $row) {
            $byProduct[(int) $row->product_id][(string) $row->reason] = ($byProduct[(int) $row->product_id][(string) $row->reason] ?? 0) + (int) $row->uses;
        }

        return $byProduct;
    }

    private function reasonFor(string $table): string
    {
        return match ($table) {
            'order_lines' => 'orders',
            'branch_product_stocks', 'stock_count_lines', 'stock_lot_movements', 'stock_lots', 'stock_movements', 'stock_transfers' => 'stock_history',
            'goods_receipt_lines', 'purchase_order_lines' => 'purchases',
            'prescription_lines' => 'prescriptions',
            'dated_schemes' => 'schemes',
            'site_feedback' => 'reviews',
            'product_concern_product' => 'health_tags',
            default => 'other',
        };
    }

    private function inWords(string $reason, int $uses, bool $showUnits): string
    {
        $count = LocalDigits::text($uses);

        return match ($reason) {
            'stock' => $showUnits ? (string) __('office_stock.reason_stock_units', ['count' => $count]) : (string) __('office_stock.reason_stock'),
            'orders' => (string) __('office_stock.reason_orders', ['count' => $count]),
            default => (string) __('office_stock.reason_'.$reason),
        };
    }
}

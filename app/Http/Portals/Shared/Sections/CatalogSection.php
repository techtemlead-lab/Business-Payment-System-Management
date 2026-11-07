<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Catalog\CatalogTaxonomy;
use App\Models\Product;
use App\Support\CatalogCms;
use App\Support\Money;
use App\Support\SiteCustomization;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Product list for the catalog, inventory and purchase sections, priced and
 * trimmed for the viewer. Overview never renders the product grid, so it skips
 * this heavy hydrate.
 */
final class CatalogSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        $user = $context->user;
        $listsProducts = in_array($context->section, ['catalog', 'inventory', 'purchase'], true)
            && (! $context->isStaff || $user->allows('catalog') || $user->allows('pricing') || $user->allows('inventory') || $user->allows('orders') || $user->allows('finance'));
        if (! $listsProducts) {
            return [];
        }
        $request = $context->request;
        $props = [];
        $productQuery = Product::query()
            ->when(! $context->isStaff, fn ($q) => $q->where('active', true))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($match) => $match->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('sku', 'ilike', '%'.$request->string('q').'%')->orWhere('category', 'ilike', '%'.$request->string('q').'%')))
            ->when($context->section === 'catalog' && $request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($context->section === 'catalog' && $request->filled('sub_category'), fn ($q) => $q->where('sub_category', $request->string('sub_category')))
            ->when($context->section === 'catalog' && $request->filled('unit'), fn ($q) => $q->where('unit', $request->string('unit')))
            ->when($context->section === 'catalog' && $request->filled('min_mrp'), fn ($q) => $q->where('mrp', '>=', $request->input('min_mrp')))
            ->when($context->section === 'catalog' && $request->filled('max_mrp'), fn ($q) => $q->where('mrp', '<=', $request->input('max_mrp')))
            ->when($context->section === 'catalog' && $request->string('availability')->toString() === 'published', fn ($q) => $q->where('active', true))
            ->when($context->section === 'catalog' && $request->string('availability')->toString() === 'draft', fn ($q) => $q->where('active', false))
            ->when($context->section === 'catalog' && $request->string('availability')->toString() === 'in_stock', fn ($q) => $q->where('stock', '>', 0)->where(fn ($stock) => $stock->where('is_stock_paused', false)->orWhereNull('is_stock_paused')))
            ->when($context->section === 'catalog' && $request->string('availability')->toString() === 'out_of_stock', fn ($q) => $q->where(fn ($stock) => $stock->where('stock', '<=', 0)->orWhere('is_stock_paused', true)))
            ->when(
                $context->section === 'catalog' && in_array($request->string('sort')->toString(), ['name_desc', 'price_asc', 'price_desc', 'newest', 'stock_asc', 'stock_desc'], true),
                function ($q) use ($request) {
                    match ($request->string('sort')->toString()) {
                        'name_desc' => $q->orderByDesc('name'),
                        'price_asc' => $q->orderBy('mrp')->orderBy('name'),
                        'price_desc' => $q->orderByDesc('mrp')->orderBy('name'),
                        'newest' => $q->orderByDesc('created_at')->orderByDesc('id'),
                        'stock_asc' => $q->orderBy('stock')->orderBy('name'),
                        'stock_desc' => $q->orderByDesc('stock')->orderBy('name'),
                    };
                },
                fn ($q) => $q->orderBy('name'),
            );
        $productLimit = $context->isStaff ? 250 : 200;
        $props['products_truncated'] = (clone $productQuery)->count() > $productLimit;
        $products = (clone $productQuery)->limit($productLimit)->get();
        $revealStock = ! $context->isStaff || $user->allows('inventory');
        $revealDealerPrice = ! $context->isStaff || $user->allows('pricing') || $user->allows('orders') || $user->allows('finance');
        // Each Schema::hasColumn() call reads the database catalogue, so ask once per request, not once per product.
        $supportsProductExpiry = $context->section === 'inventory' && Schema::hasColumn('products', 'expires_on');
        $props['products'] = $products->map(function ($p) use ($user, $context, $revealStock, $revealDealerPrice, $supportsProductExpiry) {
            $base = [...$p->only(['id', 'sku', 'name', 'category', 'sub_category', 'strength', 'description', 'unit', 'mrp', 'active', 'featured', 'tone', 'is_stock_paused']), 'stock_status' => $p->stockStatus(), ...$p->media(), ...($user->allows('cost_read') ? ['cost_price' => $p->cost_price] : [])];
            if (! $context->isStaff) {
                try {
                    $base['price'] = Money::decimal($context->pricing->unitPrice($p, $user));
                } catch (ValidationException) {
                    $base['price'] = null;
                }
            } elseif ($revealDealerPrice) {
                $base['price'] = $p->dealer_price;
            }
            if ($revealStock) {
                $base['stock'] = $p->stock;
                $base['low_stock_at'] = $p->low_stock_at;
            }
            if ($context->section === 'inventory') {
                $base['register_name'] = $p->registerName();
                $base['expires_on'] = $supportsProductExpiry ? $p->expires_on?->toDateString() : null;
                $base['batch_number'] = $p->batch_number;
                if ($revealDealerPrice) {
                    $base['dealer_price'] = $p->dealer_price;
                    $base['sub_dealer_price'] = $p->sub_dealer_price;
                    $base['dealer_discount_pct'] = $p->discountPercentFromRetail($p->dealer_price);
                    $base['sub_dealer_discount_pct'] = $p->discountPercentFromRetail($p->sub_dealer_price);
                }
            }

            return $base;
        });
        if (in_array($context->section, ['catalog', 'purchase'], true)) {
            $taxonomy = app(CatalogTaxonomy::class);
            $props['categories'] = SiteCustomization::getConfiguredCategoryNames();
            $props['sub_categories'] = SiteCustomization::getConfiguredSubCategories();
            $props['catalog_categories'] = $context->isStaff
                ? $taxonomy->categoriesForEditors(activeOnly: false)
                : CatalogCms::categoryOptions();
            $props['catalog_units'] = $context->isStaff
                ? $taxonomy->unitsForEditors(activeOnly: false)
                : CatalogCms::unitOptions();
            $props['supports_product_expiry'] = Schema::hasColumn('products', 'expires_on');
            if ($context->isStaff) {
                $props['customization'] = SiteCustomization::raw();
                $props['defaults'] = SiteCustomization::defaults();
                $pickerQuery = Product::query()
                    ->where('active', true)
                    ->when($request->filled('q'), fn ($q) => $q->where(fn ($match) => $match
                        ->where('name', 'ilike', '%'.$request->string('q').'%')
                        ->orWhere('sku', 'ilike', '%'.$request->string('q').'%')
                        ->orWhere('category', 'ilike', '%'.$request->string('q').'%')))
                    ->orderBy('name');
                $pickerLimit = 250;
                $props['all_catalog_products_truncated'] = (clone $pickerQuery)->count() > $pickerLimit;
                $props['all_catalog_products'] = (clone $pickerQuery)
                    ->limit($pickerLimit)
                    ->get()
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'sku' => $p->sku,
                        'mrp' => $p->mrp,
                        ...($revealDealerPrice ? ['dealer_price' => $p->dealer_price] : []),
                        'category' => $p->category,
                        'sub_category' => $p->sub_category,
                        'strength' => $p->strength,
                        'featured' => (bool) $p->featured,
                        ...($revealStock ? ['stock' => (int) $p->stock] : []),
                        'image' => $p->media()['image_url'] ?? '',
                    ]);
            }
        }

        return $props;
    }
}

<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CatalogCms
{
    /**
     * @return list<array{id:int,name:string,subcategories:list<array{id:int,name:string}>}>
     */
    public static function categoryOptions(?Product $product = null): array
    {
        if (! Schema::hasTable('product_categories')) {
            return [];
        }

        $currentSubcategoryId = $product?->product_subcategory_id;
        $currentCategoryId = $product?->subcategory?->product_category_id;

        if ($product && $currentSubcategoryId && ! $currentCategoryId) {
            $currentCategoryId = ProductSubcategory::query()->whereKey($currentSubcategoryId)->value('product_category_id');
        }

        /** @var Collection<int, ProductCategory> $categories */
        $categories = ProductCategory::query()
            ->with(['subcategories' => function ($query) use ($currentSubcategoryId): void {
                $query->where(function ($activeOrCurrent) use ($currentSubcategoryId): void {
                    $activeOrCurrent->where('is_active', true);
                    if ($currentSubcategoryId) {
                        $activeOrCurrent->orWhere('id', $currentSubcategoryId);
                    }
                })->orderBy('sort_order')->orderBy('name');
            }])
            ->where(function ($activeOrCurrent) use ($currentCategoryId): void {
                $activeOrCurrent->where('is_active', true);
                if ($currentCategoryId) {
                    $activeOrCurrent->orWhere('id', $currentCategoryId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $categories->map(fn (ProductCategory $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'subcategories' => $category->subcategories->map(fn (ProductSubcategory $subcategory): array => [
                'id' => $subcategory->id,
                'name' => $subcategory->name,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @return list<array{id:int,name:string,code:string}>
     */
    public static function unitOptions(?Product $product = null): array
    {
        if (! Schema::hasTable('product_units')) {
            return [];
        }

        $currentUnitId = $product?->product_unit_id;

        return ProductUnit::query()
            ->where(function ($activeOrCurrent) use ($currentUnitId): void {
                $activeOrCurrent->where('is_active', true);
                if ($currentUnitId) {
                    $activeOrCurrent->orWhere('id', $currentUnitId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (ProductUnit $unit): array => [
                'id' => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
            ])
            ->values()
            ->all();
    }
}

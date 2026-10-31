<?php

namespace App\Domain\Catalog;

use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogTaxonomy
{
    /**
     * Active categories with nested active subcategories for portal editors.
     *
     * @return list<array{
     *   id:int,name:string,slug:string,sort_order:int,is_active:bool,
     *   subcategories:list<array{id:int,product_category_id:int,name:string,slug:string,sort_order:int,is_active:bool}>
     * }>
     */
    public function categoriesForEditors(bool $activeOnly = true): array
    {
        return ProductCategory::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with(['subcategories' => function ($query) use ($activeOnly): void {
                $query->when($activeOnly, fn ($q) => $q->where('is_active', true))
                    ->orderBy('sort_order')
                    ->orderBy('name');
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category): array => $this->categoryPayload($category))
            ->all();
    }

    /**
     * @return list<array{id:int,name:string,code:string,sort_order:int,is_active:bool}>
     */
    public function unitsForEditors(bool $activeOnly = true): array
    {
        return ProductUnit::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductUnit $unit): array => $this->unitPayload($unit))
            ->all();
    }

    /**
     * @return list<string>
     */
    public function activeCategoryNames(): array
    {
        return ProductCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function activeSubcategoryNames(?string $categoryName = null): array
    {
        return ProductSubcategory::query()
            ->where('is_active', true)
            ->whereHas('category', function ($query) use ($categoryName): void {
                $query->where('is_active', true);
                if ($categoryName !== null && $categoryName !== '') {
                    $query->where('name', $categoryName);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{name:string,slug?:?string,sort_order?:int,is_active?:bool}  $input
     */
    public function createCategory(array $input, ?int $actorId = null): ProductCategory
    {
        $payload = $this->validatedCategoryPayload($input);

        return DB::transaction(function () use ($payload, $actorId): ProductCategory {
            $category = ProductCategory::create($payload);
            Audit::record($actorId, 'product_category.created', (string) $category->id, [
                'name' => $category->name,
                'slug' => $category->slug,
            ]);

            return $category;
        }, 3);
    }

    /**
     * @param  array{name?:string,slug?:?string,sort_order?:int,is_active?:bool}  $input
     */
    public function updateCategory(ProductCategory $category, array $input, ?int $actorId = null): ProductCategory
    {
        $payload = $this->validatedCategoryPayload([
            'name' => $input['name'] ?? $category->name,
            'slug' => array_key_exists('slug', $input) ? $input['slug'] : $category->slug,
            'sort_order' => $input['sort_order'] ?? $category->sort_order,
            'is_active' => $input['is_active'] ?? $category->is_active,
        ], $category);

        return DB::transaction(function () use ($category, $payload, $actorId): ProductCategory {
            $category->update($payload);
            Audit::record($actorId, 'product_category.updated', (string) $category->id, [
                'name' => $category->name,
                'slug' => $category->slug,
            ]);

            return $category->fresh();
        }, 3);
    }

    /**
     * @param  array{product_category_id:int,name:string,slug?:?string,sort_order?:int,is_active?:bool}  $input
     */
    public function createSubcategory(array $input, ?int $actorId = null): ProductSubcategory
    {
        $payload = $this->validatedSubcategoryPayload($input);

        return DB::transaction(function () use ($payload, $actorId): ProductSubcategory {
            $subcategory = ProductSubcategory::create($payload);
            Audit::record($actorId, 'product_subcategory.created', (string) $subcategory->id, [
                'product_category_id' => $subcategory->product_category_id,
                'name' => $subcategory->name,
                'slug' => $subcategory->slug,
            ]);

            return $subcategory->load('category');
        }, 3);
    }

    /**
     * @param  array{product_category_id?:int,name?:string,slug?:?string,sort_order?:int,is_active?:bool}  $input
     */
    public function updateSubcategory(ProductSubcategory $subcategory, array $input, ?int $actorId = null): ProductSubcategory
    {
        $payload = $this->validatedSubcategoryPayload([
            'product_category_id' => $input['product_category_id'] ?? $subcategory->product_category_id,
            'name' => $input['name'] ?? $subcategory->name,
            'slug' => array_key_exists('slug', $input) ? $input['slug'] : $subcategory->slug,
            'sort_order' => $input['sort_order'] ?? $subcategory->sort_order,
            'is_active' => $input['is_active'] ?? $subcategory->is_active,
        ], $subcategory);

        return DB::transaction(function () use ($subcategory, $payload, $actorId): ProductSubcategory {
            $subcategory->update($payload);
            Audit::record($actorId, 'product_subcategory.updated', (string) $subcategory->id, [
                'product_category_id' => $subcategory->product_category_id,
                'name' => $subcategory->name,
                'slug' => $subcategory->slug,
            ]);

            return $subcategory->fresh('category');
        }, 3);
    }

    /**
     * @param  array{name:string,code?:?string,sort_order?:int,is_active?:bool}  $input
     */
    public function createUnit(array $input, ?int $actorId = null): ProductUnit
    {
        $payload = $this->validatedUnitPayload($input);

        return DB::transaction(function () use ($payload, $actorId): ProductUnit {
            $unit = ProductUnit::create($payload);
            Audit::record($actorId, 'product_unit.created', (string) $unit->id, [
                'name' => $unit->name,
                'code' => $unit->code,
            ]);

            return $unit;
        }, 3);
    }

    /**
     * @param  array{name?:string,code?:?string,sort_order?:int,is_active?:bool}  $input
     */
    public function updateUnit(ProductUnit $unit, array $input, ?int $actorId = null): ProductUnit
    {
        $payload = $this->validatedUnitPayload([
            'name' => $input['name'] ?? $unit->name,
            'code' => array_key_exists('code', $input) ? $input['code'] : $unit->code,
            'sort_order' => $input['sort_order'] ?? $unit->sort_order,
            'is_active' => $input['is_active'] ?? $unit->is_active,
        ], $unit);

        return DB::transaction(function () use ($unit, $payload, $actorId): ProductUnit {
            $unit->update($payload);
            Audit::record($actorId, 'product_unit.updated', (string) $unit->id, [
                'name' => $unit->name,
                'code' => $unit->code,
            ]);

            return $unit->fresh();
        }, 3);
    }

    /**
     * @return array{id:int,name:string,slug:string,sort_order:int,is_active:bool,subcategories:list<array{id:int,product_category_id:int,name:string,slug:string,sort_order:int,is_active:bool}>}
     */
    public function categoryPayload(ProductCategory $category): array
    {
        $subcategories = $category->relationLoaded('subcategories')
            ? $category->subcategories
            : $category->subcategories()->orderBy('sort_order')->orderBy('name')->get();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'subcategories' => $subcategories
                ->map(fn (ProductSubcategory $subcategory): array => $this->subcategoryPayload($subcategory))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id:int,product_category_id:int,name:string,slug:string,sort_order:int,is_active:bool}
     */
    public function subcategoryPayload(ProductSubcategory $subcategory): array
    {
        return [
            'id' => $subcategory->id,
            'product_category_id' => (int) $subcategory->product_category_id,
            'name' => $subcategory->name,
            'slug' => $subcategory->slug,
            'sort_order' => (int) $subcategory->sort_order,
            'is_active' => (bool) $subcategory->is_active,
        ];
    }

    /**
     * @return array{id:int,name:string,code:string,sort_order:int,is_active:bool}
     */
    public function unitPayload(ProductUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'code' => $unit->code,
            'sort_order' => (int) $unit->sort_order,
            'is_active' => (bool) $unit->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name:string,slug:string,sort_order:int,is_active:bool}
     */
    private function validatedCategoryPayload(array $input, ?ProductCategory $existing = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('portal.err_catalog_name_required')]);
        }

        $slugSource = array_key_exists('slug', $input) && filled($input['slug'])
            ? (string) $input['slug']
            : $name;
        $slug = $this->normalizeSlug($slugSource);
        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => __('portal.err_catalog_slug_required')]);
        }

        $duplicate = ProductCategory::query()
            ->where('slug', $slug)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['slug' => __('portal.err_catalog_slug_taken')]);
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{product_category_id:int,name:string,slug:string,sort_order:int,is_active:bool}
     */
    private function validatedSubcategoryPayload(array $input, ?ProductSubcategory $existing = null): array
    {
        $categoryId = (int) ($input['product_category_id'] ?? 0);
        $category = ProductCategory::query()->whereKey($categoryId)->first();
        if (! $category) {
            throw ValidationException::withMessages(['product_category_id' => __('portal.err_catalog_category_required')]);
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('portal.err_catalog_name_required')]);
        }

        $slugSource = array_key_exists('slug', $input) && filled($input['slug'])
            ? (string) $input['slug']
            : $name;
        $slug = $this->normalizeSlug($slugSource);
        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => __('portal.err_catalog_slug_required')]);
        }

        $duplicate = ProductSubcategory::query()
            ->where('product_category_id', $categoryId)
            ->where('slug', $slug)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['slug' => __('portal.err_catalog_slug_taken')]);
        }

        return [
            'product_category_id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name:string,code:string,sort_order:int,is_active:bool}
     */
    private function validatedUnitPayload(array $input, ?ProductUnit $existing = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('portal.err_catalog_name_required')]);
        }

        $codeSource = array_key_exists('code', $input) && filled($input['code'])
            ? (string) $input['code']
            : $name;
        $code = $this->normalizeCode($codeSource);
        if ($code === '') {
            throw ValidationException::withMessages(['code' => __('portal.err_catalog_unit_code_required')]);
        }

        $duplicate = ProductUnit::query()
            ->where('code', $code)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['code' => __('portal.err_catalog_unit_code_taken')]);
        }

        return [
            'name' => $name,
            'code' => $code,
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }

    private function normalizeSlug(string $value): string
    {
        return Str::slug(trim($value));
    }

    private function normalizeCode(string $value): string
    {
        $slug = Str::slug(trim($value));

        return $slug !== '' ? $slug : Str::lower(preg_replace('/\s+/', '-', trim($value)) ?? '');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_subcategory_id')->nullable()->after('sub_category')->constrained('product_subcategories')->nullOnDelete();
            $table->foreignId('product_unit_id')->nullable()->after('unit')->constrained('product_units')->nullOnDelete();
            $table->date('expires_on')->nullable()->after('strength');
        });

        $this->backfillCatalogFromLegacyStrings();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_subcategory_id');
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn('expires_on');
        });
    }

    private function backfillCatalogFromLegacyStrings(): void
    {
        $now = now();
        $categoryIdsByKey = [];
        $sort = 0;

        foreach (DB::table('products')->select('category')->whereNotNull('category')->distinct()->orderBy('category')->cursor() as $row) {
            $name = trim((string) $row->category);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($categoryIdsByKey[$key])) {
                continue;
            }

            $id = DB::table('product_categories')->insertGetId([
                'name' => $name,
                'slug' => $this->uniqueSlug('product_categories', $name),
                'sort_order' => $sort++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $categoryIdsByKey[$key] = $id;
        }

        $subcategoryIdsByKey = [];
        $subSort = [];

        foreach (
            DB::table('products')
                ->select('category', 'sub_category')
                ->whereNotNull('category')
                ->whereNotNull('sub_category')
                ->distinct()
                ->orderBy('category')
                ->orderBy('sub_category')
                ->cursor() as $row
        ) {
            $categoryName = trim((string) $row->category);
            $subName = trim((string) $row->sub_category);
            if ($categoryName === '' || $subName === '') {
                continue;
            }

            $categoryKey = mb_strtolower($categoryName);
            $categoryId = $categoryIdsByKey[$categoryKey] ?? null;
            if ($categoryId === null) {
                continue;
            }

            $subKey = $categoryId.'|'.mb_strtolower($subName);
            if (isset($subcategoryIdsByKey[$subKey])) {
                continue;
            }

            $order = $subSort[$categoryId] ?? 0;
            $subSort[$categoryId] = $order + 1;

            $id = DB::table('product_subcategories')->insertGetId([
                'product_category_id' => $categoryId,
                'name' => $subName,
                'slug' => $this->uniqueSlug('product_subcategories', $subName, ['product_category_id' => $categoryId]),
                'sort_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $subcategoryIdsByKey[$subKey] = $id;
        }

        $unitIdsByKey = [];
        $unitSort = 0;

        foreach (DB::table('products')->select('unit')->whereNotNull('unit')->distinct()->orderBy('unit')->cursor() as $row) {
            $name = trim((string) $row->unit);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($unitIdsByKey[$key])) {
                continue;
            }

            $id = DB::table('product_units')->insertGetId([
                'name' => $name,
                'code' => $this->uniqueCode($name),
                'sort_order' => $unitSort++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $unitIdsByKey[$key] = $id;
        }

        foreach (DB::table('products')->select('id', 'category', 'sub_category', 'unit')->orderBy('id')->cursor() as $product) {
            $updates = [];

            $categoryName = trim((string) ($product->category ?? ''));
            $subName = trim((string) ($product->sub_category ?? ''));
            if ($categoryName !== '' && $subName !== '') {
                $categoryId = $categoryIdsByKey[mb_strtolower($categoryName)] ?? null;
                if ($categoryId !== null) {
                    $subId = $subcategoryIdsByKey[$categoryId.'|'.mb_strtolower($subName)] ?? null;
                    if ($subId !== null) {
                        $updates['product_subcategory_id'] = $subId;
                    }
                }
            }

            $unitName = trim((string) ($product->unit ?? ''));
            if ($unitName !== '') {
                $unitId = $unitIdsByKey[mb_strtolower($unitName)] ?? null;
                if ($unitId !== null) {
                    $updates['product_unit_id'] = $unitId;
                }
            }

            if ($updates !== []) {
                DB::table('products')->where('id', $product->id)->update($updates);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function uniqueSlug(string $table, string $name, array $scope = []): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->slugExists($table, $slug, $scope)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'unit';
        }

        $code = $base;
        $suffix = 2;
        while (DB::table('product_units')->where('code', $code)->exists()) {
            $code = $base.'-'.$suffix;
            $suffix++;
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function slugExists(string $table, string $slug, array $scope = []): bool
    {
        $query = DB::table($table)->where('slug', $slug);
        foreach ($scope as $column => $value) {
            $query->where($column, $value);
        }

        return $query->exists();
    }
};

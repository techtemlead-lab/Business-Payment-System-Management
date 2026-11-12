<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\PortalHost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ties sizes of one product together for display and choosing. Each size stays its own product,
 * so nothing here may touch a price, stock, batch, order, commission or ledger record.
 */
final class ProductSizeGroups
{
    /** One list card per group: the group's id made negative, or the product's own id when it has no group. */
    public const CardKeySql = 'COALESCE(-products.product_group_id, products.id)';

    /** How many products the size search offers at once. */
    private const CandidatesShown = 12;

    /** The size written in this language or else the other one, or null when none is written. */
    public static function label(Product $product, ?string $language = null): ?string
    {
        $first = ($language ?? app()->getLocale()) === 'bn' ? 'bn' : 'en';
        foreach ([$first, $first === 'bn' ? 'en' : 'bn'] as $tried) {
            $written = trim((string) $product->getAttribute('pack_size_'.$tried));
            if ($written !== '') {
                return $written;
            }
        }

        return null;
    }

    /** What a size button says. A size whose words were later cleared still needs a button. */
    public static function buttonLabel(Product $product, ?string $language = null): string
    {
        $strength = trim((string) $product->strength);

        return self::label($product, $language) ?? ($strength !== '' ? $strength : (string) $product->sku);
    }

    /**
     * Smallest first. A bigger pack costs more, so the retail price orders sizes in any language.
     *
     * @param  Collection<int, Product>  $sizes
     * @return Collection<int, Product>
     */
    public static function inSizeOrder(Collection $sizes): Collection
    {
        return $sizes
            ->sort(fn (Product $first, Product $second): int => (Money::cents((string) $first->mrp) <=> Money::cents((string) $second->mrp))
                ?: strnatcasecmp(self::buttonLabel($first), self::buttonLabel($second))
                ?: ((int) $first->id <=> (int) $second->id))
            ->values();
    }

    /**
     * Each grouped product's place among the sizes in this list. Reads nothing from the database.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, int> product id => place, smallest size first
     */
    public static function positions(Collection $products): array
    {
        $positions = [];
        foreach ($products->whereNotNull('product_group_id')->groupBy('product_group_id') as $sizes) {
            foreach (self::inSizeOrder($sizes) as $position => $size) {
                $positions[(int) $size->id] = $position;
            }
        }

        return $positions;
    }

    /**
     * The sizes on sale in these groups, in one query. Archived and draft sizes are left out.
     *
     * @param  list<int>  $groupIds
     * @return array<int, Collection<int, Product>> group id => sizes, smallest first
     */
    public static function onSaleByGroup(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        return Product::query()
            ->where('active', true)
            ->withSellableStock()
            ->whereIn('product_group_id', $groupIds)
            ->get()
            ->groupBy('product_group_id')
            ->map(fn (Collection $sizes): Collection => self::inSizeOrder($sizes))
            ->all();
    }

    /**
     * The size a card opens on: the first in stock, or simply the first when none is.
     *
     * @param  Collection<int, Product>  $sizes
     */
    public static function sizeToShow(Collection $sizes): ?Product
    {
        return $sizes->first(fn (Product $size): bool => $size->stockStatus() !== 'out_of_stock') ?? $sizes->first();
    }

    /**
     * Size buttons for a public card or page. Empty when fewer than two sizes are on sale,
     * so that a lone size looks like an ordinary product.
     *
     * @param  Collection<int, Product>  $sizes
     * @return list<array{id: int, name: string, labels: array{bn: string, en: string}, mrp: string, category: string, image_path: ?string, stock_status: string, url: string, selected: bool}>
     */
    public static function publicSizes(Collection $sizes, int $selectedId): array
    {
        if ($sizes->count() < 2) {
            return [];
        }

        return $sizes->map(fn (Product $size): array => [
            'id' => (int) $size->id,
            'name' => (string) $size->name,
            // Both languages: the home page list is cached once for every visitor.
            'labels' => ['bn' => self::buttonLabel($size, 'bn'), 'en' => self::buttonLabel($size, 'en')],
            'mrp' => (string) $size->mrp,
            'category' => (string) $size->category,
            'image_path' => $size->media()['image_url'],
            'stock_status' => $size->stockStatus(),
            'url' => PortalHost::url('public', '/products/'.$size->id),
            'selected' => (int) $size->id === $selectedId,
        ])->values()->all();
    }

    /**
     * Size buttons for one product's own page.
     *
     * @return list<array{id: int, name: string, labels: array{bn: string, en: string}, mrp: string, category: string, image_path: ?string, stock_status: string, url: string, selected: bool}>
     */
    public static function publicSizesOf(Product $product): array
    {
        if ($product->product_group_id === null) {
            return [];
        }
        $groupId = (int) $product->product_group_id;

        return self::publicSizes(self::onSaleByGroup([$groupId])[$groupId] ?? collect(), (int) $product->id);
    }

    /**
     * Every size in this product's group for the office, whatever its state.
     *
     * @return array{sizes: list<array{id: int, name: string, sku: string, label: string, active: bool, archived: bool, is_stock_paused: bool, current: bool}>}
     */
    public static function forEditor(Product $product): array
    {
        if ($product->product_group_id === null) {
            return ['sizes' => []];
        }
        $sizes = self::inSizeOrder(Product::query()->where('product_group_id', $product->product_group_id)->get());

        return ['sizes' => $sizes->map(fn (Product $size): array => [
            'id' => (int) $size->id,
            'name' => (string) $size->name,
            'sku' => (string) $size->sku,
            'label' => self::buttonLabel($size),
            'active' => (bool) $size->active,
            'archived' => $size->isArchived(),
            'is_stock_paused' => (bool) $size->is_stock_paused,
            'current' => (int) $size->id === (int) $product->id,
        ])->values()->all()];
    }

    /**
     * Products the office can add as another size of this one.
     *
     * @return list<array{id: int, name: string, sku: string, label: ?string, in_group: bool}>
     */
    public static function candidates(Product $product, string $search): array
    {
        return Product::query()
            ->whereKeyNot($product->id)
            ->whereNull('archived_at')
            ->when($product->product_group_id !== null, fn ($query) => $query->whereNull('product_group_id'))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('sku', 'ilike', '%'.$search.'%')))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::CandidatesShown)
            ->get()
            ->map(fn (Product $candidate): array => [
                'id' => (int) $candidate->id,
                'name' => (string) $candidate->name,
                'sku' => (string) $candidate->sku,
                'label' => self::label($candidate),
                'in_group' => $candidate->product_group_id !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * Puts two products in one group. When one is already in a group the other joins it.
     */
    public static function add(Product $product, int $otherProductId, User $actor): void
    {
        DB::transaction(function () use ($product, $otherProductId, $actor): void {
            $locked = Product::query()->whereIn('id', [$product->id, $otherProductId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $locked->get($product->id);
            $other = $locked->get($otherProductId);
            if (! $current instanceof Product || ! $other instanceof Product || (int) $current->id === (int) $other->id) {
                self::refuse('err_choose_other');
            }
            $pair = collect([$current, $other]);
            if ($current->product_group_id !== null && $current->product_group_id === $other->product_group_id) {
                self::refuse('err_already_together');
            }
            if ($current->product_group_id !== null && $other->product_group_id !== null) {
                self::refuse('err_in_another_group', ['name' => (string) $other->name]);
            }
            foreach ($pair as $size) {
                if ($size->isArchived()) {
                    self::refuse('err_archived', ['name' => (string) $size->name]);
                }
                if (self::label($size) === null) {
                    self::refuse('err_no_size_written', ['name' => (string) $size->name]);
                }
            }

            $joining = $pair->whereNull('product_group_id')->values();
            $existingGroupId = $current->product_group_id ?? $other->product_group_id;
            if ($existingGroupId === null) {
                $groupId = (int) ProductGroup::query()->create()->id;
                $alreadyInGroup = collect();
            } else {
                $groupId = (int) $existingGroupId;
                ProductGroup::query()->whereKey($groupId)->lockForUpdate()->firstOrFail();
                $alreadyInGroup = Product::query()->where('product_group_id', $groupId)->get();
            }
            self::refuseRepeatedSize($alreadyInGroup->concat($joining));

            foreach ($joining as $size) {
                $size->product_group_id = $groupId;
                $size->saveQuietly();
                Audit::record($actor->id, 'product.size_group_added', (string) $size->id, [
                    'product_group_id' => $groupId,
                    'with_product_id' => (int) ((int) $size->id === (int) $current->id ? $other->id : $current->id),
                ]);
            }
        }, 3);
    }

    /**
     * Takes a product out of its group. A group left with one size closes.
     *
     * @return bool false when the product was not in a group
     */
    public static function remove(Product $product, User $actor): bool
    {
        return DB::transaction(function () use ($product, $actor): bool {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->product_group_id === null) {
                return false;
            }
            $groupId = (int) $locked->product_group_id;
            ProductGroup::query()->whereKey($groupId)->lockForUpdate()->firstOrFail();
            $locked->product_group_id = null;
            $locked->saveQuietly();
            Audit::record($actor->id, 'product.size_group_removed', (string) $locked->id, ['product_group_id' => $groupId]);
            self::closeIfOneLeft($groupId, $actor);

            return true;
        }, 3);
    }

    /** One size is not a group, so the last one goes back to being an ordinary product. */
    public static function closeIfOneLeft(int $groupId, User $actor): void
    {
        $left = Product::query()->where('product_group_id', $groupId)->lockForUpdate()->get();
        if ($left->count() > 1) {
            return;
        }
        foreach ($left as $lastSize) {
            $lastSize->product_group_id = null;
            $lastSize->saveQuietly();
            Audit::record($actor->id, 'product.size_group_removed', (string) $lastSize->id, ['product_group_id' => $groupId, 'last_size_left' => true]);
        }
        ProductGroup::query()->whereKey($groupId)->delete();
    }

    /**
     * Two buttons saying "100 ml" would be no choice at all. Archived sizes do not count, so a
     * replacement can take the place of a size taken off sale.
     *
     * @param  Collection<int, Product>  $sizes
     */
    private static function refuseRepeatedSize(Collection $sizes): void
    {
        $repeated = self::repeatedSize($sizes);
        if ($repeated !== null) {
            self::refuse('err_size_repeated', ['size' => $repeated['size']]);
        }
    }

    /**
     * Stops a pack size being changed to the words another size of the same product already uses.
     * Call after the product is saved, inside the same transaction, so the refusal undoes the save.
     */
    public static function refuseRepeatedSizeAfterEdit(Product $product): void
    {
        if ($product->product_group_id === null || $product->isArchived()) {
            return;
        }
        ProductGroup::query()->whereKey($product->product_group_id)->lockForUpdate()->first();
        $repeated = self::repeatedSize(Product::query()->where('product_group_id', $product->product_group_id)->get());
        if ($repeated !== null) {
            throw ValidationException::withMessages([$repeated['column'] => __('size_groups.err_size_repeated', ['size' => $repeated['size']])]);
        }
    }

    /**
     * The first pack size written the same way on two sizes, ignoring spaces and letter case.
     *
     * @param  Collection<int, Product>  $sizes
     * @return array{column: string, size: string}|null
     */
    private static function repeatedSize(Collection $sizes): ?array
    {
        foreach (['pack_size_en', 'pack_size_bn'] as $column) {
            $seen = [];
            foreach ($sizes->reject(fn (Product $size): bool => $size->isArchived()) as $size) {
                $written = (string) $size->getAttribute($column);
                $comparable = mb_strtolower((string) preg_replace('/\s+/u', '', $written));
                if ($comparable === '') {
                    continue;
                }
                if (isset($seen[$comparable])) {
                    return ['column' => $column, 'size' => trim($written)];
                }
                $seen[$comparable] = true;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function refuse(string $message, array $replace = []): never
    {
        throw ValidationException::withMessages(['with_product_id' => __('size_groups.'.$message, $replace)]);
    }
}

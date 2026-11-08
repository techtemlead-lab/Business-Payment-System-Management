<?php

namespace App\Domain\Orders;

use App\Models\DatedScheme;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;

/**
 * Dated schemes, shown to partners on the purchase page as information only.
 *
 * Applying a benefit is deliberately left out. Commission posting builds its MRP basis, the
 * company's share and its funding check from every order line's MRP times quantity, so a free
 * line added to an order would change those figures. Until that is settled, the office applies
 * any benefit by hand and this class never touches an order, a price or a commission.
 */
final class DatedSchemes
{
    public static function canManage(User $user): bool
    {
        return $user->owner();
    }

    /** @return array{schemes: list<array<string, mixed>>, benefit_types: list<string>, products: list<array{id: int, name: string}>} */
    public function forOwner(User $owner): array
    {
        abort_unless(self::canManage($owner), 403);
        $products = Product::query()->where('active', true)->orderBy('name')->limit(500)->get(['id', 'name', 'strength']);

        return [
            'schemes' => DatedScheme::query()->orderByDesc('ends_on')->orderByDesc('id')->limit(200)->get()
                ->map(fn (DatedScheme $scheme): array => $this->present($scheme))->all(),
            'benefit_types' => DatedScheme::BenefitTypes,
            'products' => $products->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'name' => trim($product->name.' '.($product->strength ?? '')),
            ])->all(),
        ];
    }

    /**
     * Schemes running today, with the products they cover.
     *
     * @return list<array<string, mixed>>
     */
    public function runningToday(): array
    {
        $today = now((string) config('madina.business_timezone'))->toDateString();
        $schemes = DatedScheme::query()
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('ends_on')
            ->orderBy('id')
            ->get();
        if ($schemes->isEmpty()) {
            return [];
        }
        $names = Product::query()
            ->whereIn('id', $schemes->flatMap(fn (DatedScheme $scheme): array => $scheme->product_ids)->unique()->values()->all())
            ->where('active', true)
            ->get(['id', 'name', 'strength'])
            ->mapWithKeys(fn (Product $product): array => [(int) $product->id => trim($product->name.' '.($product->strength ?? ''))]);

        return $schemes->map(function (DatedScheme $scheme) use ($names): array {
            $productIds = array_values(array_filter(array_map('intval', $scheme->product_ids), fn (int $id): bool => $names->has($id)));

            return [...$this->present($scheme), 'product_ids' => $productIds, 'product_names' => array_map(fn (int $id): string => (string) $names[$id], $productIds)];
        })->filter(fn (array $scheme): bool => $scheme['product_ids'] !== [])->values()->all();
    }

    /**
     * @param  array{title: string, starts_on: string, ends_on: string, product_ids: list<int>, min_quantity: int, benefit_type: string, benefit_text: string}  $data
     */
    public function add(User $owner, array $data): DatedScheme
    {
        abort_unless(self::canManage($owner), 403);
        $scheme = DatedScheme::query()->create([...$data, 'product_ids' => array_map('intval', $data['product_ids']), 'created_by' => $owner->id]);
        Audit::record($owner->id, 'dated_scheme.added', (string) $scheme->id, ['title' => $scheme->title]);

        return $scheme;
    }

    public function remove(User $owner, DatedScheme $scheme): void
    {
        abort_unless(self::canManage($owner), 403);
        Audit::record($owner->id, 'dated_scheme.removed', (string) $scheme->id, ['title' => $scheme->title]);
        $scheme->delete();
    }

    /** @return array<string, mixed> */
    private function present(DatedScheme $scheme): array
    {
        return [
            'id' => (int) $scheme->id,
            'title' => (string) $scheme->title,
            'starts_on' => $scheme->starts_on->toDateString(),
            'ends_on' => $scheme->ends_on->toDateString(),
            'product_ids' => array_map('intval', $scheme->product_ids),
            'min_quantity' => (int) $scheme->min_quantity,
            'benefit_type' => (string) $scheme->benefit_type,
            'benefit_text' => (string) $scheme->benefit_text,
        ];
    }
}

<?php

namespace App\Domain\Orders;

use App\Models\DatedScheme;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dated schemes, shown to partners on the purchase page.
 *
 * This class only manages and lists the schemes. A free-goods scheme with a free product and
 * quantity becomes free order lines through SchemeFreeLines when the order is placed. Any other
 * scheme is information only, and the office applies its benefit by hand.
 */
final class DatedSchemes
{
    /** What the offer is. Once an order has got free goods from a scheme, these stay as they were. */
    private const FixedOnceUsed = ['starts_on', 'product_ids', 'min_quantity', 'benefit_type', 'free_product_id', 'free_quantity'];

    public static function canManage(User $user): bool
    {
        return $user->owner();
    }

    /** @return array{schemes: list<array<string, mixed>>, benefit_types: list<string>, products: list<array{id: int, name: string, active: bool}>} */
    public function forOwner(User $owner): array
    {
        abort_unless(self::canManage($owner), 403);
        $schemes = DatedScheme::query()->orderByDesc('ends_on')->orderByDesc('id')->limit(200)->get();
        $onSchemes = $schemes->flatMap(fn (DatedScheme $scheme): array => [...$scheme->product_ids, $scheme->free_product_id])->filter()->unique()->values()->all();
        $products = Product::query()
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $onSchemes))
            ->orderBy('name')->limit(500)->get(['id', 'name', 'strength', 'active']);
        $usage = $schemes->isEmpty() ? [] : $this->usage($schemes->map(fn (DatedScheme $scheme): int => (int) $scheme->id)->all());

        return [
            'schemes' => $schemes->map(fn (DatedScheme $scheme): array => [
                ...$this->present($scheme),
                'orders_using' => $usage[(int) $scheme->id]['orders'] ?? 0,
                'earliest_end' => $usage[(int) $scheme->id]['earliest_end'] ?? null,
            ])->all(),
            'benefit_types' => DatedScheme::BenefitTypes,
            'products' => $products->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'name' => trim($product->name.' '.($product->strength ?? '')),
                'active' => (bool) $product->active,
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
     * @param  array{title: string, starts_on: string, ends_on: string, product_ids: list<int>, min_quantity: int, benefit_type: string, benefit_text: string, free_product_id?: int|null, free_quantity?: int|null}  $data
     */
    public function add(User $owner, array $data): DatedScheme
    {
        abort_unless(self::canManage($owner), 403);
        $scheme = DatedScheme::query()->create([...$data, 'product_ids' => array_map('intval', $data['product_ids']), 'created_by' => $owner->id]);
        Audit::record($owner->id, 'dated_scheme.added', (string) $scheme->id, ['title' => $scheme->title]);

        return $scheme;
    }

    /**
     * Saves an edit. Free lines already on orders are copies made when the order was placed, so
     * nothing here reaches them. Once a scheme has given free goods its offer stays as it was, so
     * that every order under it followed the same offer; the words and the end date can still change.
     *
     * @param  array{title: string, starts_on: string, ends_on: string, product_ids: list<int>, min_quantity: int, benefit_type: string, benefit_text: string, free_product_id?: int|null, free_quantity?: int|null, reason: string}  $data
     */
    public function update(User $owner, DatedScheme $scheme, array $data): DatedScheme
    {
        abort_unless(self::canManage($owner), 403);

        return DB::transaction(function () use ($owner, $scheme, $data): DatedScheme {
            $locked = DatedScheme::query()->whereKey($scheme->id)->lockForUpdate()->firstOrFail();
            $before = $this->editableValues([
                ...$locked->only(['title', 'product_ids', 'min_quantity', 'benefit_type', 'benefit_text', 'free_product_id', 'free_quantity']),
                'starts_on' => $locked->starts_on->toDateString(),
                'ends_on' => $locked->ends_on->toDateString(),
            ]);
            $after = $this->editableValues($data);
            $earliestEnd = $this->usage([(int) $locked->id])[(int) $locked->id]['earliest_end'] ?? null;
            if ($earliestEnd !== null) {
                $this->refuseChangesToUsedScheme($before, $after, $earliestEnd);
            }
            $locked->update($after);
            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
            Audit::record($owner->id, 'dated_scheme.updated', (string) $locked->id, [
                'title' => $locked->title,
                'fields' => $changed,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
                'reason' => $data['reason'],
            ]);

            return $locked;
        });
    }

    public function remove(User $owner, DatedScheme $scheme): void
    {
        abort_unless(self::canManage($owner), 403);
        Audit::record($owner->id, 'dated_scheme.removed', (string) $scheme->id, ['title' => $scheme->title]);
        $scheme->delete();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function refuseChangesToUsedScheme(array $before, array $after, string $earliestEnd): void
    {
        $refusals = [];
        foreach (self::FixedOnceUsed as $field) {
            if ($after[$field] !== $before[$field]) {
                $refusals[$field] = __('dealer_tools.scheme_term_locked');
            }
        }
        if ($after['ends_on'] < $earliestEnd) {
            $date = CarbonImmutable::parse($earliestEnd)->locale(app()->getLocale())->translatedFormat('j F Y');
            $refusals['ends_on'] = __('dealer_tools.scheme_end_before_order', ['date' => LocalDigits::text($date)]);
        }
        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }
    }

    /**
     * The fields an edit can touch, in one form so the old and new values can be compared.
     *
     * @param  array<string, mixed>  $values
     * @return array{title: string, starts_on: string, ends_on: string, product_ids: list<int>, min_quantity: int, benefit_type: string, benefit_text: string, free_product_id: int|null, free_quantity: int|null}
     */
    private function editableValues(array $values): array
    {
        $productIds = array_values(array_unique(array_map('intval', $values['product_ids'])));
        sort($productIds);

        return [
            'title' => (string) $values['title'],
            'starts_on' => (string) $values['starts_on'],
            'ends_on' => (string) $values['ends_on'],
            'product_ids' => $productIds,
            'min_quantity' => (int) $values['min_quantity'],
            'benefit_type' => (string) $values['benefit_type'],
            'benefit_text' => (string) $values['benefit_text'],
            'free_product_id' => isset($values['free_product_id']) ? (int) $values['free_product_id'] : null,
            'free_quantity' => isset($values['free_quantity']) ? (int) $values['free_quantity'] : null,
        ];
    }

    /**
     * How many orders got free goods from each scheme, and the day of the latest one in the
     * business timezone, which is the earliest the scheme may now end. Orders since cancelled
     * count too: their free lines are still on record.
     *
     * @param  list<int>  $schemeIds
     * @return array<int, array{orders: int, earliest_end: string}>
     */
    private function usage(array $schemeIds): array
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('order_lines.dated_scheme_id', $schemeIds)
            ->groupBy('order_lines.dated_scheme_id')
            ->selectRaw('order_lines.dated_scheme_id AS scheme_id, COUNT(DISTINCT order_lines.order_id) AS order_count, MAX(orders.created_at) AS latest_order_at')
            ->get()
            ->mapWithKeys(fn (\stdClass $row): array => [(int) $row->scheme_id => [
                'orders' => (int) $row->order_count,
                'earliest_end' => CarbonImmutable::parse((string) $row->latest_order_at, (string) config('app.timezone'))
                    ->setTimezone((string) config('madina.business_timezone'))
                    ->toDateString(),
            ]])
            ->all();
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
            'free_product_id' => $scheme->free_product_id === null ? null : (int) $scheme->free_product_id,
            'free_quantity' => $scheme->free_quantity === null ? null : (int) $scheme->free_quantity,
        ];
    }
}

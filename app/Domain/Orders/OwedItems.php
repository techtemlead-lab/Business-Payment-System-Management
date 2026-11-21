<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods the company owes a partner (owner's rule, 2026-11-21): no goods on credit, but when a
 * partner paid in full and stock ran short at dispatch, staff may record the missing pieces as
 * owed. The rest of that order line is closed, so the source order can finish; the paid money
 * stays where it is and the owed record explains it. The pieces then go out free with the
 * partner's next order (a zero-price line, stock deducted at dispatch as usual), or their value
 * is refunded through the order's normal refund step.
 */
final class OwedItems
{
    public const StatusOpen = 'open';

    public const StatusShipped = 'shipped';

    public const StatusCancelled = 'cancelled';

    /** Next-order statuses that can still take an extra line before goods leave. */
    public const ReceivingStatuses = ['submitted', 'held', 'approved'];

    /** Record every unsent piece of one paid line as owed. A second click returns the same record. */
    public function record(User $actor, Order $order, int $lineId, string $note): int
    {
        $this->assertStaff($actor, $order);

        return DB::transaction(function () use ($actor, $order, $lineId, $note): int {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('owed_items')->where('source_line_id', $lineId)->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }
            /** @var OrderLine|null $line */
            $line = $locked->lines()->whereKey($lineId)->lockForUpdate()->first();
            abort_unless($line !== null, 404);
            if ($locked->status !== 'partially_dispatched') {
                throw ValidationException::withMessages(['line' => __('owed_items.err_not_partly_sent')]);
            }
            if (Money::cents((string) $locked->paid) < Money::cents((string) $locked->total) || Money::cents((string) $locked->total) <= 0) {
                throw ValidationException::withMessages(['line' => __('owed_items.err_not_paid')]);
            }
            $left = (int) $line->quantity - (int) $line->dispatched - (int) $line->getAttribute('closed');
            if ($line->is_free || $left <= 0) {
                throw ValidationException::withMessages(['line' => __('owed_items.err_nothing_left')]);
            }
            $line->increment('closed', $left);
            $id = (int) DB::table('owed_items')->insertGetId([
                'partner_id' => $locked->user_id,
                'product_id' => $line->product_id,
                'quantity' => $left,
                'unit_price' => $line->unit_price,
                'source_order_id' => $locked->id,
                'source_line_id' => $line->id,
                'status' => self::StatusOpen,
                'note' => mb_substr(trim($note), 0, 500),
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stillToSend = $locked->lines()->whereRaw(OrderLines::unsentSql())->exists();
            $onTheRoad = DB::table('shipments')->where('order_id', $locked->id)->where('status', 'dispatched')->exists();
            if (! $stillToSend && $onTheRoad) {
                $locked->update(['status' => 'dispatched']);
            }
            MixedOutcomeDecisions::closeIfFinished($locked, $actor->id);
            $meta = ['owed_item_id' => $id, 'line_id' => $line->id, 'quantity' => $left, 'note' => $note];
            $this->event($locked->id, $actor->id, 'order.owed_recorded', $meta);
            Audit::record($actor->id, 'order.owed_recorded', $locked->id, $meta);
            Audit::notice((int) $locked->user_id, __('owed_items.notice_title'),
                __('owed_items.notice_body', ['quantity' => $left, 'product' => $line->name, 'number' => $locked->number]));

            return $id;
        }, 3);
    }

    /**
     * Add every open owed item of the partner to their next order as free lines, in one click.
     *
     * @return int the number of items added
     */
    public function shipWith(User $actor, Order $next): int
    {
        $this->assertStaff($actor, $next);

        return DB::transaction(function () use ($actor, $next): int {
            $locked = Order::whereKey($next->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, self::ReceivingStatuses, true) || $locked->is_opening_balance) {
                throw ValidationException::withMessages(['order' => __('owed_items.err_order_cannot_take')]);
            }
            $items = self::openQuery()->join('orders as src', 'src.id', '=', 'o.source_order_id')
                ->join('products as p', 'p.id', '=', 'o.product_id')
                ->where('o.partner_id', $locked->user_id)
                ->where('o.source_order_id', '!=', $locked->id)->lockForUpdate()
                ->get(['o.id', 'o.quantity', 'p.id as product_id', 'p.name', 'p.sku', 'p.category', 'p.mrp', 'src.number']);
            foreach ($items as $item) {
                $lineId = (int) $locked->lines()->create([
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'category_snapshot' => $item->category,
                    'quantity' => (int) $item->quantity,
                    'mrp' => $item->mrp,
                    'unit_price' => '0.00',
                    'total' => '0.00',
                    'is_free' => true,
                    'scheme_title' => mb_substr(__('owed_items.invoice_label', ['number' => $item->number]), 0, 150),
                ])->getKey();
                DB::table('owed_items')->where('id', $item->id)->update([
                    'status' => self::StatusShipped, 'shipped_in_order_id' => $locked->id, 'shipped_line_id' => $lineId, 'updated_at' => now(),
                ]);
                $meta = ['owed_item_id' => (int) $item->id, 'line_id' => $lineId, 'quantity' => (int) $item->quantity];
                $this->event($locked->id, $actor->id, 'order.owed_added', $meta);
                Audit::record($actor->id, 'order.owed_added', $locked->id, $meta);
            }

            return $items->count();
        }, 3);
    }

    /**
     * The partner prefers money: the owed item is closed and its value goes back through the
     * order's existing refund step (second-person rule included).
     */
    public function cancelWithRefund(User $actor, int $owedId, string $reason): void
    {
        abort_unless(OrderRefund::canRefund($actor), 403);
        DB::transaction(function () use ($actor, $owedId, $reason): void {
            $item = DB::table('owed_items')->where('id', $owedId)->lockForUpdate()->first();
            abort_unless($item !== null, 404);
            if (! self::openQuery()->where('o.id', $owedId)->exists()) {
                return;
            }
            $order = Order::query()->findOrFail($item->source_order_id);
            $amount = Money::decimal(Money::cents((string) $item->unit_price) * (int) $item->quantity);
            app(OrderRefund::class)->refund($actor, $order, $amount, $reason);
            DB::table('owed_items')->where('id', $owedId)->update(['status' => self::StatusCancelled, 'closed_by' => $actor->id, 'updated_at' => now()]);
            $meta = ['owed_item_id' => $owedId, 'amount' => $amount, 'reason' => $reason];
            $this->event($order->id, $actor->id, 'order.owed_refunded', $meta);
            Audit::record($actor->id, 'order.owed_refunded', $order->id, $meta);
        }, 3);
    }

    /**
     * Open owed items, for one partner or (null) for everyone.
     *
     * @return list<array{id: int, partner_id: int, partner: string, product: string, quantity: int, value: string, source_order_id: string, source_number: string, note: ?string, created_at: string}>
     */
    public function open(?int $partnerId = null, int $limit = 50): array
    {
        return self::openQuery()
            ->join('orders as src', 'src.id', '=', 'o.source_order_id')
            ->join('products as p', 'p.id', '=', 'o.product_id')
            ->join('users as u', 'u.id', '=', 'o.partner_id')
            ->when($partnerId !== null, fn ($query) => $query->where('o.partner_id', $partnerId))
            ->orderBy('o.created_at')->limit($limit)
            ->get(['o.id', 'o.partner_id', 'u.name as partner', 'p.name as product', 'o.quantity', 'o.unit_price', 'o.source_order_id', 'src.number', 'o.note', 'o.created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'partner_id' => (int) $row->partner_id,
                'partner' => (string) $row->partner,
                'product' => (string) $row->product,
                'quantity' => (int) $row->quantity,
                'value' => Money::decimal(Money::cents((string) $row->unit_price) * (int) $row->quantity),
                'source_order_id' => (string) $row->source_order_id,
                'source_number' => (string) $row->number,
                'note' => $row->note,
                'created_at' => (string) $row->created_at,
            ])->values()->all();
    }

    /**
     * Owed items still owed (alias `o`): open ones, and ones added to a next order that was then
     * cancelled or turned down before its free line left the warehouse, so nothing owed is lost.
     */
    public static function openQuery(): QueryBuilder
    {
        return DB::table('owed_items as o')->where(fn (QueryBuilder $query) => $query->where('o.status', self::StatusOpen)
            ->orWhere(fn (QueryBuilder $stale) => $stale->where('o.status', self::StatusShipped)->whereExists(fn (QueryBuilder $dead) => $dead->selectRaw('1')
                ->from('orders as next_order')->join('order_lines as owed_line', 'owed_line.order_id', '=', 'next_order.id')
                ->whereColumn('next_order.id', 'o.shipped_in_order_id')->whereColumn('owed_line.id', 'o.shipped_line_id')
                ->whereIn('next_order.status', ['cancelled', 'rejected'])->where('owed_line.dispatched', 0))));
    }

    private function assertStaff(User $actor, Order $order): void
    {
        abort_unless($actor->staff() && $actor->allows('inventory'), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403);
    }

    /** @param array<string, mixed> $meta */
    private function event(string $orderId, int $actorId, string $action, array $meta): void
    {
        DB::table('order_events')->insert(['order_id' => $orderId, 'actor_id' => $actorId, 'action' => $action,
            'metadata' => json_encode($meta, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}

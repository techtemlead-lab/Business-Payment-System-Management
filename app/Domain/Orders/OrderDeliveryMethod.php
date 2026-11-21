<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Inventory\BranchOperations;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff side of "courier or collect from the office".
 *
 * - Staff may switch an order between courier and office pickup before anything is sent, the same
 *   way they may change the courier; the partner is told and the change is on the order history.
 * - Staff record that the partner took the goods at the office: one step that takes the goods out
 *   of the stock (the normal dispatch, saved as "Office pickup", with no courier booking and no
 *   tracking number) and confirms the delivery (the normal delivery step, which settles the
 *   commission). A courier order handed over this way becomes an office pickup first, and the
 *   partner is told.
 */
final class OrderDeliveryMethod
{
    /** Order states in which the way the goods go can still change. */
    private const ChangeableStatuses = ['submitted', 'held', 'approved'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly BranchOperations $branches,
    ) {}

    public function change(User $actor, Order $order, string $method, ?string $note): void
    {
        abort_unless($actor->allows('inventory') || $actor->allows('order_review'), 403);
        $this->assertNotOwnOrder($actor, $order);
        abort_unless($this->branches->mayOpenOrder($actor, $order), 403);
        abort_unless(in_array($method, OfficePickup::Methods, true), 422);
        $note = $note !== null && trim($note) !== '' ? Str::limit(trim($note), 500, '') : null;
        DB::transaction(function () use ($actor, $order, $method, $note): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_method === $method) {
                throw ValidationException::withMessages(['delivery_method' => __('order_delivery.err_same_method')]);
            }
            if (! in_array($locked->status, self::ChangeableStatuses, true) || DB::table('shipments')->where('order_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['delivery_method' => __('order_delivery.err_change_too_late')]);
            }
            $this->switchTo($actor, $locked, $method, $note);
        }, 3);
    }

    /**
     * The partner took the goods at the office: everything still to send leaves the chosen branch's
     * stock and the order is delivered. A repeat with the same key changes nothing.
     *
     * @param  array{idempotency_key: string, branch_id?: ?int, note?: ?string}  $data
     */
    public function handOver(User $actor, Order $order, array $data): void
    {
        $this->branches->assertCompanyHandler($actor);
        abort_unless($actor->allows('inventory'), 403);
        $this->assertNotOwnOrder($actor, $order);
        abort_unless($this->branches->mayOpenOrder($actor, $order), 403);
        $note = isset($data['note']) && trim((string) $data['note']) !== '' ? Str::limit(trim((string) $data['note']), 500, '') : null;
        DB::transaction(function () use ($actor, $order, $data, $note): void {
            // The delivery step takes the money lock before the order; take them in the same order here.
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (DB::table('shipments')->where('order_id', $locked->id)->where('idempotency_key', $data['idempotency_key'])->exists()) {
                return;
            }
            if (! in_array($locked->status, ['approved', 'partially_dispatched'], true)) {
                throw ValidationException::withMessages(['handover' => __('order_delivery.err_handover_status')]);
            }
            $items = $locked->lines()->whereRaw(OrderLines::unsentSql())->orderBy('id')->get()
                ->map(fn (Model $line): array => ['line_id' => (int) $line->getKey(),
                    'quantity' => (int) $line->getAttribute('quantity') - (int) $line->getAttribute('dispatched') - (int) $line->getAttribute('closed')])
                ->all();
            if ($items === []) {
                throw ValidationException::withMessages(['handover' => __('order_delivery.err_handover_nothing')]);
            }
            if (! OfficePickup::isPickupOrder($locked)) {
                // A parcel already with a courier cannot also be handed over at the office.
                if (DB::table('shipments')->where('order_id', $locked->id)->where('status', 'dispatched')->whereNotNull('courier_provider')->exists()) {
                    throw ValidationException::withMessages(['handover' => __('order_delivery.err_change_too_late')]);
                }
                $this->switchTo($actor, $locked, OfficePickup::MethodOffice, $note);
            }
            $this->orders->dispatch($actor, $locked, [
                'courier' => OfficePickup::Courier,
                'tracking' => '',
                'courier_provider' => null,
                'branch_id' => $data['branch_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'items' => $items,
            ], announce: false);
            $shipmentId = (int) DB::table('shipments')->where('order_id', $locked->id)->where('idempotency_key', $data['idempotency_key'])->value('id');
            $this->record($locked, $actor, 'order.office_handover', ['shipment_id' => $shipmentId, 'note' => $note]);
            $this->orders->deliver($actor, $locked->refresh(), $shipmentId, $note ?? __('order_delivery.handover_default_reason'));
        }, 3);
    }

    /** Saves the new way, writes it on the history and tells the partner. Runs inside the caller's transaction. */
    private function switchTo(User $actor, Order $locked, string $method, ?string $note): void
    {
        $from = (string) $locked->delivery_method;
        $locked->update([
            'delivery_method' => $method,
            // An office pickup has no courier; switching back leaves the courier to the company.
            'courier_choice' => null,
        ]);
        $this->record($locked, $actor, 'order.delivery_changed', ['from' => $from, 'to' => $method, 'note' => $note,
            'from_label' => $this->methodLabel($from), 'to_label' => $this->methodLabel($method)]);
        $partner = User::find($locked->user_id);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        $replace = ['number' => $locked->number, 'from' => $this->methodLabel($from, $locale), 'to' => $this->methodLabel($method, $locale), 'note' => (string) $note];
        Audit::notice(
            $locked->user_id,
            __('order_delivery.notice_changed_title', $replace, $locale),
            __($note !== null ? 'order_delivery.notice_changed_body_with_note' : 'order_delivery.notice_changed_body', $replace, $locale),
        );
    }

    private function methodLabel(string $method, ?string $locale = null): string
    {
        return __($method === OfficePickup::MethodOffice ? 'order_delivery.method_office_staff' : 'order_delivery.method_courier_staff', [], $locale);
    }

    /** @param array<string, mixed> $metadata */
    private function record(Order $order, User $actor, string $action, array $metadata): void
    {
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record($actor->id, $action, $order->id, $metadata);
    }

    private function assertNotOwnOrder(User $actor, Order $order): void
    {
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
    }
}

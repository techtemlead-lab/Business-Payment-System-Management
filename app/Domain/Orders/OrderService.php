<?php

namespace App\Domain\Orders;

use App\Models\{Order, Product, User};
use App\Support\{Audit, Money};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(User $user, array $data): Order
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true) && $user->status === 'active', 403);

        return DB::transaction(function () use ($user, $data): Order {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hash = $this->hash($data);
            $existing = Order::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if($existing->getRawOriginal('payload_hash') !== $hash, 409, 'Request key already used for different data.');
                return $existing;
            }
            $lines = [];
            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::whereKey($item['product_id'])->where('active', true)->firstOrFail();
                $mrp = Money::cents($product->mrp);
                if ($user->role === 'sub_dealer') {
                    if (($mrp * 60) % 100 !== 0) {
                        throw ValidationException::withMessages(['items' => 'This price needs an approved rounding rule.']);
                    }
                    $price = intdiv($mrp * 60, 100);
                } else {
                    if ($product->dealer_price === null) {
                        throw ValidationException::withMessages(['items' => 'A dealer price has not been configured for '.$product->name.'.']);
                    }
                    $price = Money::cents($product->dealer_price);
                }
                $lineTotal = $price * $item['quantity'];
                $total += $lineTotal;
                $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                    'quantity' => $item['quantity'], 'mrp' => $product->mrp, 'unit_price' => Money::decimal($price), 'total' => Money::decimal($lineTotal)];
            }
            if ($total <= 0 || $total > 9999999999900) {
                throw ValidationException::withMessages(['items' => 'Order amount exceeds the allowed limit.']);
            }
            $order = Order::create(['number' => 'MN-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'user_id' => $user->id, 'total' => Money::decimal($total), 'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash, 'note' => $data['note'] ?? null]);
            $order->lines()->createMany($lines);
            if (!empty($data['reference'])) {
                $this->insertClaim($order, $data);
            }
            $this->event($order, $user, 'order.submitted');
            Audit::notice($user->id, 'Order submitted', $order->number.' is awaiting review.');
            return $order->refresh();
        }, 3);
    }

    public function claim(User $actor, Order $order, array $data): void
    {
        abort_unless($order->user_id === $actor->id && in_array($actor->role, ['dealer', 'sub_dealer'], true), 404);
        DB::transaction(function () use ($actor, $order, $data): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['reference' => 'This order is closed.']);
            }
            $reference = Str::upper(trim($data['reference']));
            if (DB::table('payment_claims')->where('order_id', $locked->id)->where('channel', $data['channel'])->where('reference', $reference)->where('status', '!=', 'rejected')->exists()) {
                throw ValidationException::withMessages(['reference' => 'This payment reference is already submitted for this order.']);
            }
            $id = $this->insertClaim($locked, $data);
            $this->event($locked, $actor, 'payment.claimed', ['claim_id' => $id]);
        }, 3);
    }

    public function decision(User $actor, Order $order, string $status, string $reason, bool $allowCredit = false): void
    {
        abort_unless($actor->allows('order_review'), 403);
        DB::transaction(function () use ($actor, $order, $status, $reason, $allowCredit): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status, ['submitted', 'held'], true)) {
                throw ValidationException::withMessages(['status' => 'This order has already been decided.']);
            }
            $changes = ['status' => $status, 'decision_reason' => $reason];
            if ($status === 'approved' && Money::cents($locked->paid) < Money::cents($locked->total)) {
                if (!$allowCredit || !$actor->allows('finance')) {
                    throw ValidationException::withMessages(['allow_credit' => 'Verify full payment or have Finance explicitly authorize this credit sale.']);
                }
                $changes['credit_authorized_by'] = $actor->id;
                $changes['credit_authorized_at'] = now();
            }
            if ($status === 'rejected' && Money::cents($locked->paid) > 0) {
                throw ValidationException::withMessages(['status' => 'A paid order needs the approved refund workflow.']);
            }
            $locked->update($changes);
            $this->event($locked, $actor, 'order.'.$status, ['reason' => $reason, 'credit_authorized' => isset($changes['credit_authorized_by'])]);
            Audit::notice($locked->user_id, 'Order '.$status, $locked->number.': '.$reason);
        }, 3);
    }

    public function verify(User $actor, int $claimId, bool $approve, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        try {
            DB::transaction(function () use ($actor, $claimId, $approve, $reason): void {
                $initial = DB::table('payment_claims')->find($claimId);
                abort_unless($initial, 404);
                $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
                $claim = DB::table('payment_claims')->where('id', $claimId)->lockForUpdate()->first();
                if ($claim->status !== 'pending') {
                    throw ValidationException::withMessages(['payment' => 'Payment claim already reviewed.']);
                }
                if ($approve) {
                    if (in_array($order->status, ['rejected', 'cancelled'], true)) {
                        throw ValidationException::withMessages(['payment' => 'Cannot verify payment for a closed order.']);
                    }
                    if (DB::table('receipts')->where('channel', $claim->channel)->where('reference', $claim->reference)->exists()) {
                        throw ValidationException::withMessages(['payment' => 'This payment reference is already recorded.']);
                    }
                    $paid = Money::cents($order->paid) + Money::cents($claim->amount);
                    if ($paid > Money::cents($order->total)) {
                        throw ValidationException::withMessages(['payment' => 'This would overpay the order.']);
                    }
                    DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim->id, 'channel' => $claim->channel,
                        'reference' => $claim->reference, 'amount' => $claim->amount, 'created_at' => now(), 'updated_at' => now()]);
                    $order->update(['paid' => Money::decimal($paid)]);
                }
                DB::table('payment_claims')->where('id', $claimId)->update(['status' => $approve ? 'verified' : 'rejected',
                    'verified_by' => $actor->id, 'reason' => $reason, 'updated_at' => now()]);
                $this->event($order, $actor, $approve ? 'payment.verified' : 'payment.rejected', ['claim_id' => $claimId, 'reason' => $reason]);
                Audit::notice($order->user_id, 'Payment reviewed', $order->number.': payment '.($approve ? 'verified' : 'rejected').'.');
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['payment' => 'This payment reference is already recorded.']);
            }
            throw $exception;
        }
    }

    public function dispatch(User $actor, Order $order, array $data): void
    {
        abort_unless($actor->allows('inventory'), 403);
        DB::transaction(function () use ($actor, $order, $data): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $hash = $this->hash($data);
            $existing = DB::table('shipments')->where('order_id', $locked->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if($existing->payload_hash !== $hash, 409, 'Dispatch key already used for different data.');
                return;
            }
            if (!in_array($locked->status, ['approved', 'partially_dispatched'], true)) {
                throw ValidationException::withMessages(['shipment' => 'Approve the order before dispatching.']);
            }
            $orderLines = $locked->lines()->whereIn('id', array_column($data['items'], 'line_id'))->get()->keyBy('id');
            abort_unless($orderLines->count() === count($data['items']), 404);
            $products = Product::whereIn('id', $orderLines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $shipmentLines = [];
            foreach ($data['items'] as $item) {
                $line = $orderLines[$item['line_id']];
                $product = $products[$line->product_id];
                $quantity = $item['quantity'];
                if ($quantity > $line->quantity - $line->dispatched || $quantity > $product->stock) {
                    throw ValidationException::withMessages(['shipment' => 'Quantity exceeds remaining supply or stock for '.$product->name.'.']);
                }
                $product->decrement('stock', $quantity);
                $line->increment('dispatched', $quantity);
                DB::table('stock_movements')->insert(['product_id' => $product->id, 'quantity' => -$quantity, 'kind' => 'dispatch',
                    'reference' => (string) Str::uuid(), 'reason' => $locked->number, 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $shipmentLines[] = ['line_id' => $line->id, 'name' => $line->name, 'quantity' => $quantity];
            }
            $shipmentId = DB::table('shipments')->insertGetId(['order_id' => $locked->id, 'courier' => $data['courier'], 'tracking' => $data['tracking'],
                'eta' => $data['eta'] ?? null, 'lines' => json_encode($shipmentLines, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id,
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $remaining = $locked->lines()->whereColumn('dispatched', '<', 'quantity')->exists();
            $locked->update(['status' => $remaining ? 'partially_dispatched' : 'dispatched']);
            $this->event($locked, $actor, 'order.dispatched', ['shipment_id' => $shipmentId]);
            Audit::notice($locked->user_id, 'Shipment dispatched', $locked->number.' · '.$data['courier'].' · '.$data['tracking']);
        }, 3);
    }

    public function deliver(User $actor, Order $order, int $shipmentId, string $reason): void
    {
        abort_unless($actor->allows('inventory'), 403);
        DB::transaction(function () use ($actor, $order, $shipmentId, $reason): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('id', $shipmentId)->where('order_id', $locked->id)->lockForUpdate()->first();
            abort_unless($shipment, 404);
            if ($shipment->status === 'delivered') {
                return;
            }
            abort_unless($shipment->status === 'dispatched', 422);
            DB::table('shipments')->where('id', $shipmentId)->update(['status' => 'delivered', 'delivered_at' => now(), 'delivered_by' => $actor->id, 'updated_at' => now()]);
            $remaining = $locked->lines()->whereColumn('dispatched', '<', 'quantity')->exists();
            $openShipment = DB::table('shipments')->where('order_id', $locked->id)->where('status', '!=', 'delivered')->exists();
            if (!$remaining && !$openShipment) {
                $locked->update(['status' => 'delivered']);
            }
            $this->event($locked, $actor, 'shipment.delivered', ['shipment_id' => $shipmentId, 'reason' => $reason]);
            Audit::notice($locked->user_id, 'Delivery confirmed', $locked->number.': shipment delivery recorded by company staff.');
        }, 3);
    }

    private function insertClaim(Order $order, array $data): int
    {
        $amount = Money::cents($data['amount']);
        if ($amount <= 0 || $amount > Money::cents($order->total) - Money::cents($order->paid ?? '0')) {
            throw ValidationException::withMessages(['amount' => 'The payment claim must be positive and cannot exceed the unpaid order amount.']);
        }
        return DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => $data['channel'],
            'reference' => Str::upper(trim($data['reference'])), 'amount' => Money::decimal($amount), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function event(Order $order, User $actor, string $action, array $metadata = []): void
    {
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record($actor->id, $action, $order->id, $metadata);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}

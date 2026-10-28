<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Integrations\PaymentGatewayClient;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockNotifications;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Pricing\ProductPricing;
use App\Domain\Staff\TerritoryAccess;
use App\Models\BranchProductStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardManagement;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly ProductPricing $pricing,
        private readonly CommissionPosting $commissions,
        private readonly StockNotifications $stockNotifications,
        private readonly BranchOperations $branches,
    ) {}

    public function create(User $user, array $data, ?User $submittedBy = null): Order
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true) && $user->status === 'active', 403);

        return DB::transaction(function () use ($user, $data, $submittedBy): Order {
            CommissionWallet::lock();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($submittedBy) {
                app(TerritoryAccess::class)->assertPartner($submittedBy->fresh(), $user->id, 'sr_orders');
            }
            abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true) && $user->status === 'active', 403);
            $hash = $this->hash($data);
            $existing = Order::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_if($existing->getRawOriginal('payload_hash') !== $hash, 409, __('portal.err_idem_order_key'));

                return $existing;
            }
            $lines = [];
            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::whereKey($item['product_id'])->where('active', true)->firstOrFail();
                if ($product->is_stock_paused) {
                    throw ValidationException::withMessages(['items' => __('portal.err_order_paused', ['product' => $product->name])]);
                }
                $price = $this->pricing->unitPrice($product, $user);
                $lineTotal = $price * $item['quantity'];
                $total += $lineTotal;
                $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'category_snapshot' => $product->category,
                    'quantity' => $item['quantity'], 'mrp' => $product->mrp, 'unit_price' => Money::decimal($price), 'total' => Money::decimal($lineTotal)];
            }
            if ($total <= 0 || $total > 9999999999900) {
                throw ValidationException::withMessages(['items' => __('portal.err_order_limit')]);
            }
            DashboardManagement::assertPartnerCanOrder($user, $total);
            $order = Order::create(['number' => 'MN-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'user_id' => $user->id, 'submitted_by' => $submittedBy?->id, 'total' => Money::decimal($total), 'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash, 'note' => $data['note'] ?? null]);
            $order->lines()->createMany($lines);
            $this->commissions->capture($order, $user);
            $claimedWithOrder = ! empty($data['reference']);
            if ($claimedWithOrder) {
                $this->insertClaim($order, $data);
            }
            $this->event($order, $submittedBy ?? $user, 'order.submitted');
            $this->partnerNotice($user->id, 'portal.notice_order_submitted_title', 'portal.notice_order_submitted_body', [
                'number' => $order->number,
            ]);
            $this->partnerSms($user->id, 'order.submitted', 'sms.order_submitted', [
                'number' => $order->number,
            ]);
            if ($claimedWithOrder) {
                $this->partnerNotice($user->id, 'portal.notice_payment_claimed_title', 'portal.notice_payment_claimed_body', [
                    'number' => $order->number,
                    'amount' => (string) $data['amount'],
                    'channel' => (string) $data['channel'],
                ]);
                $this->partnerSms($user->id, 'payment.claimed', 'sms.payment_claimed', [
                    'number' => $order->number,
                    'amount' => (string) $data['amount'],
                    'channel' => (string) $data['channel'],
                ]);
            }

            return $order->refresh();
        }, 3);
    }

    public function claim(User $actor, Order $order, array $data): void
    {
        abort_unless($order->user_id === $actor->id && in_array($actor->role, ['dealer', 'sub_dealer'], true), 404);
        DashboardManagement::assertPartnerFeature($actor, 'claims');
        DB::transaction(function () use ($actor, $order, $data): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['reference' => __('portal.err_order_closed')]);
            }
            $reference = Str::upper(trim($data['reference']));
            if (DB::table('payment_claims')->where('channel', $data['channel'])->where('reference', $reference)->exists()
                || DB::table('receipts')->where('channel', $data['channel'])->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages(['reference' => __('portal.err_payment_ref_used')]);
            }
            $id = $this->insertClaim($locked, $data);
            $this->event($locked, $actor, 'payment.claimed', ['claim_id' => $id]);
            $this->partnerNotice($locked->user_id, 'portal.notice_payment_claimed_title', 'portal.notice_payment_claimed_body', [
                'number' => $locked->number,
                'amount' => (string) $data['amount'],
                'channel' => (string) $data['channel'],
            ]);
            $this->partnerSms($locked->user_id, 'payment.claimed', 'sms.payment_claimed', [
                'number' => $locked->number,
                'amount' => (string) $data['amount'],
                'channel' => (string) $data['channel'],
            ]);
        }, 3);
    }

    public function decision(User $actor, Order $order, string $status, string $reason, bool $allowCredit = false, array $replacements = []): void
    {
        abort_unless($actor->allows('order_review'), 403);
        if (! in_array($status, ['approved', 'held', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => __('portal.err_order_status_choice')]);
        }
        DB::transaction(function () use ($actor, $order, $status, $reason, $allowCredit, $replacements): void {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['submitted', 'held'], true)) {
                throw ValidationException::withMessages(['status' => __('portal.err_order_already_decided')]);
            }
            $changes = ['status' => $status, 'decision_reason' => $reason];
            if ($status === 'approved' && Money::cents($locked->outstanding()) > 0) {
                if (! $allowCredit || ! $actor->allows('finance')) {
                    throw ValidationException::withMessages(['allow_credit' => __('portal.err_order_credit_auth')]);
                }
                $changes['credit_authorized_by'] = $actor->id;
                $changes['credit_authorized_at'] = now();
            }
            if ($status === 'rejected' && Money::cents($locked->paid) > 0) {
                throw ValidationException::withMessages(['status' => __('portal.err_order_paid_needs_refund')]);
            }
            if ($status === 'approved') {
                $this->commissions->capture($locked, $locked->user, 'approval', $replacements, $actor);
            } elseif ($replacements !== []) {
                throw ValidationException::withMessages(['replacement' => __('portal.err_order_replacement_on_approve')]);
            }
            $locked->update($changes);
            $this->event($locked, $actor, 'order.'.$status, ['reason' => $reason, 'credit_authorized' => isset($changes['credit_authorized_by']),
                'referral_replacements' => $status === 'approved' ? $locked->commission_policy_snapshot['referral_replacements'] ?? [] : []]);
            if ($status === 'approved') {
                Audit::record($actor->id, 'commissions.policy_approved', $locked->id, ['reason' => $reason, 'revision_id' => $locked->commission_policy_snapshot['revision_id'],
                    'referral_replacements' => $locked->commission_policy_snapshot['referral_replacements'], 'funding_decisions' => $locked->commission_policy_snapshot['funding_decisions']]);
            }
            $partner = User::find($locked->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $statusLabel = __('portal.order_status_'.$status, [], $locale);
            $this->partnerNotice($locked->user_id, 'portal.notice_order_decision_title', 'portal.notice_order_decision_body', [
                'status' => $statusLabel,
                'number' => $locked->number,
                'reason' => $reason,
            ]);
            $this->partnerSms($locked->user_id, 'order.'.$status, 'sms.order_decision', [
                'status' => $statusLabel,
                'number' => $locked->number,
                'reason' => $reason,
            ]);
        }, 3);
    }

    public function verify(User $actor, int $claimId, bool $approve, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        try {
            DB::transaction(function () use ($actor, $claimId, $approve, $reason): void {
                CommissionWallet::lock();
                $initial = DB::table('payment_claims')->find($claimId);
                abort_unless($initial, 404);
                $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
                $claim = DB::table('payment_claims')->where('id', $claimId)->lockForUpdate()->first();
                if ($claim->status !== 'pending') {
                    throw ValidationException::withMessages(['payment' => __('portal.err_payment_already_reviewed')]);
                }
                if ($approve) {
                    if (in_array($order->status, ['rejected', 'cancelled'], true)) {
                        throw ValidationException::withMessages(['payment' => __('portal.err_payment_closed_order')]);
                    }
                    if (DB::table('receipts')->where('channel', $claim->channel)->where('reference', $claim->reference)->exists()) {
                        throw ValidationException::withMessages(['payment' => __('portal.err_payment_ref_used')]);
                    }
                    $gateway = app(PaymentGatewayClient::class)->lookup((string) $claim->channel, (string) $claim->reference, (string) $claim->amount);
                    if ($gateway['checked'] && ! $gateway['matched']) {
                        throw ValidationException::withMessages(['payment' => __('portal.err_payment_gateway_manual', ['message' => $gateway['message']])]);
                    }
                    $paid = Money::cents($order->paid) + Money::cents($claim->amount);
                    if ($paid > Money::cents($order->total) - Money::cents($order->due_credit)) {
                        throw ValidationException::withMessages(['payment' => __('portal.err_payment_overpay')]);
                    }
                    DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim->id, 'channel' => $claim->channel,
                        'reference' => $claim->reference, 'amount' => $claim->amount, 'payment_account_id' => $claim->payment_account_id, 'created_at' => now(), 'updated_at' => now()]);
                    $partner = User::find($order->user_id);
                    $locale = $partner?->preferredLocale() ?? app()->getLocale();
                    if ($order->is_opening_balance) {
                        CommissionWallet::requireActive();
                        app(CommissionWallet::class)->journal('opening.collection.'.$claim->id, __('portal.jr_opening_collection', ['number' => $order->number], $locale), [
                            ['account' => 'manual_collection_clearing', 'user_id' => null, 'debit' => Money::cents($claim->amount), 'credit' => 0],
                            ['account' => 'opening_receivables', 'user_id' => $order->user_id, 'debit' => 0, 'credit' => Money::cents($claim->amount)],
                        ]);
                    }
                    $order->update(['paid' => Money::decimal($paid)]);
                    if ($paid >= Money::cents($order->total)) {
                        $this->commissions->process($order, $actor->id);
                    }
                }
                DB::table('payment_claims')->where('id', $claimId)->update(['status' => $approve ? 'verified' : 'rejected',
                    'verified_by' => $actor->id, 'reason' => $reason, 'updated_at' => now()]);
                $this->event($order, $actor, $approve ? 'payment.verified' : 'payment.rejected', ['claim_id' => $claimId, 'reason' => $reason]);
                $partner = User::find($order->user_id);
                $locale = $partner?->preferredLocale() ?? app()->getLocale();
                $outcome = __($approve ? 'portal.payment_outcome_verified' : 'portal.payment_outcome_rejected', [], $locale);
                $this->partnerNotice($order->user_id, 'portal.notice_payment_reviewed_title', 'portal.notice_payment_reviewed_body', [
                    'number' => $order->number,
                    'outcome' => $outcome,
                ]);
                $this->partnerSms($order->user_id, $approve ? 'payment.verified' : 'payment.rejected', 'sms.payment_reviewed', [
                    'number' => $order->number,
                    'outcome' => $outcome,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['payment' => __('portal.err_payment_ref_used')]);
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
                abort_if($existing->payload_hash !== $hash, 409, __('portal.err_idem_dispatch_key'));

                return;
            }
            if (! in_array($locked->status, ['approved', 'partially_dispatched'], true)) {
                throw ValidationException::withMessages(['shipment' => __('portal.err_shipment_approve_first')]);
            }
            $fulfillmentBranch = $this->branches->resolveWritableBranch($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
            $orderLines = $locked->lines()->whereIn('id', array_column($data['items'], 'line_id'))->get()->keyBy('id');
            abort_unless($orderLines->count() === count($data['items']), 404);
            $products = Product::whereIn('id', $orderLines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $shipmentLines = [];
            foreach ($data['items'] as $item) {
                $line = $orderLines[$item['line_id']];
                $product = $products[$line->product_id];
                $quantity = $item['quantity'];
                $branchStock = $this->branches->ensureBranchStock($fulfillmentBranch, $product);
                if ($quantity > $line->quantity - $line->dispatched || $quantity > $branchStock->quantity) {
                    throw ValidationException::withMessages(['shipment' => __('portal.err_shipment_qty', ['product' => $product->name])]);
                }
                $previousStock = $product->stock;
                $branchStock->quantity = (int) $branchStock->quantity - $quantity;
                $branchStock->save();
                $companyTotal = (int) BranchProductStock::query()->where('product_id', $product->id)->sum('quantity');
                $product->forceFill(['stock' => $companyTotal])->save();
                $this->stockNotifications->recordChange($product, $previousStock, $previousStock > 0 && ! $product->is_stock_paused);
                $line->increment('dispatched', $quantity);
                DB::table('stock_movements')->insert([
                    'product_id' => $product->id,
                    'branch_id' => $fulfillmentBranch->id,
                    'quantity' => -$quantity,
                    'kind' => 'dispatch',
                    'reference' => (string) Str::uuid(),
                    'reason' => $locked->number,
                    'actor_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $shipmentLines[] = ['line_id' => $line->id, 'name' => $line->name, 'quantity' => $quantity];
            }
            $courier = (string) $data['courier'];
            $tracking = trim((string) ($data['tracking'] ?? ''));
            $provider = $data['courier_provider'] ?? null;
            $gatewayMeta = null;
            if (is_string($provider) && $provider !== '') {
                $partner = User::query()->find($locked->user_id);
                $created = app(CourierGatewayClient::class)->create($provider, [
                    'invoice' => $locked->number,
                    'recipient_name' => $partner?->name,
                    'recipient_phone' => $partner?->phone,
                    'recipient_address' => collect([$partner?->union, $partner?->upazila, $partner?->district, $partner?->division])->filter()->implode(', '),
                    'amount' => $locked->outstanding(),
                    'note' => $locked->number,
                ]);
                if (! $created['success']) {
                    throw ValidationException::withMessages(['courier_provider' => $created['message']]);
                }
                $tracking = $created['tracking'] ?: $tracking;
                $courier = $courier !== '' ? $courier : ucfirst($provider);
                $gatewayMeta = ['provider' => $provider, 'consignment_id' => $created['consignment_id']];
            }
            if ($tracking === '') {
                throw ValidationException::withMessages(['tracking' => __('portal.err_shipment_tracking')]);
            }
            $shipmentId = DB::table('shipments')->insertGetId([
                'order_id' => $locked->id,
                'branch_id' => $fulfillmentBranch->id,
                'courier' => $courier,
                'tracking' => $tracking,
                'eta' => $data['eta'] ?? null,
                'lines' => json_encode($shipmentLines, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $remaining = $locked->lines()->whereColumn('dispatched', '<', 'quantity')->exists();
            $locked->update([
                'status' => $remaining ? 'partially_dispatched' : 'dispatched',
                'fulfillment_branch_id' => $fulfillmentBranch->id,
            ]);
            $this->event($locked, $actor, 'order.dispatched', [
                'shipment_id' => $shipmentId,
                'courier_gateway' => $gatewayMeta,
                'branch_id' => $fulfillmentBranch->id,
                'branch_code' => $fulfillmentBranch->code,
            ]);
            $this->partnerNotice($locked->user_id, 'portal.notice_shipment_dispatched_title', 'portal.notice_shipment_dispatched_body', [
                'number' => $locked->number,
                'courier' => $courier,
                'tracking' => $tracking,
            ]);
            $this->partnerSms($locked->user_id, 'order.dispatched', 'sms.shipment_dispatched', [
                'number' => $locked->number,
                'courier' => $courier,
                'tracking' => $tracking,
            ]);
        }, 3);
    }

    public function deliver(User $actor, Order $order, int $shipmentId, string $reason): void
    {
        abort_unless($actor->allows('inventory'), 403);
        DB::transaction(function () use ($actor, $order, $shipmentId, $reason): void {
            CommissionWallet::lock();
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
            if (! $remaining && ! $openShipment) {
                $locked->update(['status' => 'delivered']);
                $this->commissions->process($locked, $actor->id);
            }
            $this->event($locked, $actor, 'shipment.delivered', ['shipment_id' => $shipmentId, 'reason' => $reason]);
            $this->partnerNotice($locked->user_id, 'portal.notice_delivery_confirmed_title', 'portal.notice_delivery_confirmed_body', [
                'number' => $locked->number,
            ]);
            $this->partnerSms($locked->user_id, 'shipment.delivered', 'sms.delivery_confirmed', [
                'number' => $locked->number,
            ]);
        }, 3);
    }

    private function partnerNotice(int $userId, string $titleKey, string $bodyKey, array $replace = []): void
    {
        $partner = User::find($userId);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        Audit::notice(
            $userId,
            __($titleKey, $replace, $locale),
            __($bodyKey, $replace, $locale),
        );
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function partnerSms(int $userId, string $event, string $template, array $replace = []): void
    {
        DB::afterCommit(function () use ($userId, $event, $template, $replace): void {
            $partner = User::find($userId);
            if (! $partner) {
                return;
            }
            $locale = $partner->preferredLocale();
            app(PartnerEventSms::class)->send(
                $partner,
                $event,
                __($template, $replace, $locale),
            );
        });
    }

    private function insertClaim(Order $order, array $data): int
    {
        $amount = Money::cents((string) $data['amount']);
        if ($amount <= 0 || $amount > Money::cents($order->outstanding())) {
            throw ValidationException::withMessages(['amount' => __('portal.err_claim_amount')]);
        }

        $account = app(PaymentAccounts::class)->collection(isset($data['payment_account_id']) ? (int) $data['payment_account_id'] : null, $data['channel']);
        $reference = Str::upper(trim($data['reference']));
        if (DB::table('payment_claims')->where('channel', $data['channel'])->where('reference', $reference)->exists()
            || DB::table('receipts')->where('channel', $data['channel'])->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => __('portal.err_payment_ref_used')]);
        }

        try {
            return DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => $data['channel'],
                'payment_account_id' => $account['id'] ?? null, 'account_snapshot' => $account ? json_encode(['encrypted' => encrypt($account)], JSON_THROW_ON_ERROR) : null,
                'reference' => $reference, 'amount' => Money::decimal($amount), 'created_at' => now(), 'updated_at' => now()]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['reference' => __('portal.err_payment_ref_used')]);
            }
            throw $exception;
        }
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

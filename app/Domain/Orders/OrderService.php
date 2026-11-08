<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Integrations\PaymentGatewayClient;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLots;
use App\Domain\Inventory\StockNotifications;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Domain\Pricing\ProductPricing;
use App\Domain\Staff\TerritoryAccess;
use App\Jobs\BookShipmentCourier;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardManagement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /** Order states in which items can still be waiting for stock. */
    public const SupplyDateStatuses = ['submitted', 'held', 'approved', 'partially_dispatched'];

    public function __construct(
        private readonly ProductPricing $pricing,
        private readonly CommissionPosting $commissions,
        private readonly StockNotifications $stockNotifications,
        private readonly BranchOperations $branches,
        private readonly StockLots $stockLots,
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
                    'batch_number' => $product->batch_number, 'expires_on' => $product->expires_on?->toDateString(),
                    'quantity' => $item['quantity'], 'mrp' => $product->mrp, 'unit_price' => Money::decimal($price), 'total' => Money::decimal($lineTotal)];
            }
            if ($total <= 0 || $total > 9999999999900) {
                throw ValidationException::withMessages(['items' => __('portal.err_order_limit')]);
            }
            $lines = [...$lines, ...app(SchemeFreeLines::class)->for($lines)];
            DashboardManagement::assertPartnerCanOrder($user, $total);
            $this->assertPaidInFullWithOrder($data, $total);
            $order = Order::create(['number' => 'MN-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'user_id' => $user->id, 'submitted_by' => $submittedBy?->id, 'total' => Money::decimal($total), 'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash, 'note' => $data['note'] ?? null, 'courier_choice' => CourierOptions::stored($data['courier_choice'] ?? null)]);
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
            Audit::notifyAdminsAndStaff(
                ['orders', 'finance'],
                __('portal.notice_admin_new_order_title', ['number' => $order->number]),
                __('portal.notice_admin_new_order_body', [
                    'name' => $user->name,
                    'code' => $user->dealer_code ?: '—',
                    'amount' => Money::decimal($total),
                    'number' => $order->number,
                ]),
                $submittedBy?->id
            );
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
                Audit::notifyAdminsAndStaff(
                    ['finance', 'orders'],
                    __('portal.notice_admin_payment_claim_title', ['number' => $order->number]),
                    __('portal.notice_admin_payment_claim_body', [
                        'name' => $user->name,
                        'amount' => (string) $data['amount'],
                        'channel' => (string) $data['channel'],
                        'number' => $order->number,
                        'ref' => (string) $data['reference'],
                    ]),
                );
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
            if (app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled'] && Money::cents((string) $data['amount']) !== Money::cents($locked->outstanding())) {
                throw ValidationException::withMessages(['amount' => __('portal.err_claim_full_amount', ['amount' => Money::grouped($locked->outstanding())])]);
            }
            $reference = Str::upper(trim($data['reference']));
            if (DB::table('payment_claims')->where('channel', $data['channel'])->where('reference', $reference)->where('status', '<>', 'rejected')->exists()
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
            Audit::notifyAdminsAndStaff(
                ['finance', 'orders'],
                __('portal.notice_admin_payment_claim_title', ['number' => $locked->number]),
                __('portal.notice_admin_payment_claim_body', [
                    'name' => $actor->name,
                    'amount' => (string) $data['amount'],
                    'channel' => (string) $data['channel'],
                    'number' => $locked->number,
                    'ref' => (string) $data['reference'],
                ]),
                $actor->id
            );
        }, 3);
    }

    public function decision(User $actor, Order $order, string $status, string $reason, array $replacements = []): void
    {
        abort_unless($actor->allows('order_review'), 403);
        $this->assertNotOwnOrder($actor, $order);
        if (! in_array($status, ['approved', 'held', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => __('portal.err_order_status_choice')]);
        }
        DB::transaction(function () use ($actor, $order, $status, $reason, $replacements): void {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['submitted', 'held'], true)) {
                throw ValidationException::withMessages(['status' => __('portal.err_order_already_decided')]);
            }
            $changes = ['status' => $status, 'decision_reason' => $reason];
            // No goods on credit: a dealer pays the full amount (and the payment is
            // verified) before an order can be approved.
            if ($status === 'approved' && Money::cents($locked->outstanding()) > 0) {
                throw ValidationException::withMessages(['status' => __('portal.err_order_needs_full_payment', ['amount' => Money::grouped($locked->outstanding())])]);
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
            $this->event($locked, $actor, 'order.'.$status, ['reason' => $reason,
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
                'status' => SmsText::make('portal.order_status_'.$status),
                'number' => $locked->number,
                'reason' => $reason,
            ]);
            if ($status === 'approved') {
                // The dealer picked a courier: the parcel is booked now, without waiting for manual dispatch.
                $this->autoShip($actor, $locked);
            }
        }, 3);
    }

    public function verify(User $actor, int $claimId, bool $approve, string $reason): void
    {
        abort_unless($actor->allows('finance'), 403);
        // The gateway can take up to 30 seconds. Ask it before taking the money lock so a
        // slow provider does not stall every other order, payout and settlement.
        $checked = $approve ? $this->gatewayLookupBeforeLock($claimId) : null;
        try {
            DB::transaction(function () use ($actor, $claimId, $approve, $reason, $checked): void {
                CommissionWallet::lock();
                $initial = DB::table('payment_claims')->find($claimId);
                abort_unless($initial, 404);
                $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
                $this->assertNotOwnOrder($actor, $order);
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
                    $gateway = $checked !== null && $checked['claim'] === $this->gatewayLookupKey($claim)
                        ? $checked['result']
                        : app(PaymentGatewayClient::class)->lookup((string) $claim->channel, (string) $claim->reference, (string) $claim->amount);
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
                $this->event($order, $actor, $approve ? 'payment.verified' : 'payment.rejected', [
                    'claim_id' => $claimId, 'reason' => $reason, 'channel' => $claim->channel, 'reference' => $claim->reference,
                    'amount' => $claim->amount, 'order_paid_after' => $order->paid, 'order_total' => $order->total,
                ]);
                $partner = User::find($order->user_id);
                $locale = $partner?->preferredLocale() ?? app()->getLocale();
                $outcome = __($approve ? 'portal.payment_outcome_verified' : 'portal.payment_outcome_rejected', [], $locale);
                $this->partnerNotice($order->user_id, 'portal.notice_payment_reviewed_title', 'portal.notice_payment_reviewed_body', [
                    'number' => $order->number,
                    'outcome' => $outcome,
                ]);
                $this->partnerSms($order->user_id, $approve ? 'payment.verified' : 'payment.rejected', 'sms.payment_reviewed', [
                    'number' => $order->number,
                    'outcome' => SmsText::make($approve ? 'portal.payment_outcome_verified' : 'portal.payment_outcome_rejected'),
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
        $this->branches->assertCompanyHandler($actor);
        abort_unless($actor->allows('inventory'), 403);
        $this->assertNotOwnOrder($actor, $order);
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
            // Second line of defence for older credit orders: goods go out only once fully paid.
            if (Money::cents($locked->outstanding()) > 0) {
                throw ValidationException::withMessages(['shipment' => __('portal.err_shipment_needs_full_payment', ['amount' => Money::grouped($locked->outstanding())])]);
            }
            $fulfillmentBranch = $this->branches->resolveWritableBranch($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
            $shipmentLines = $this->takeStock($actor, $locked, $fulfillmentBranch, $data['items']);
            $courier = (string) $data['courier'];
            $tracking = trim((string) ($data['tracking'] ?? ''));
            $provider = $data['courier_provider'] ?? null;
            $gatewayMeta = null;
            // The courier is booked only after this transaction commits (BookShipmentCourier),
            // so a rolled-back dispatch never leaves a parcel booked with the courier.
            $manualCourier = CourierOptions::isManual($provider);
            if ($manualCourier && ! app(CourierOptions::class)->isEnabled($provider)) {
                throw ValidationException::withMessages(['courier_provider' => __('courier_more.err_manual_not_enabled')]);
            }
            $bookCourier = is_string($provider) && $provider !== '' && ! $manualCourier;
            if ($bookCourier) {
                $courier = $courier !== '' ? $courier : ucfirst($provider);
                $gatewayMeta = ['provider' => $provider, 'booking' => 'pending'];
            } elseif ($tracking === '') {
                throw ValidationException::withMessages(['tracking' => __('portal.err_shipment_tracking')]);
            }
            $shipmentId = DB::table('shipments')->insertGetId([
                'order_id' => $locked->id,
                'branch_id' => $fulfillmentBranch->id,
                'courier' => $courier,
                'tracking' => $tracking,
                'courier_contact' => filled($data['courier_contact'] ?? null) ? trim((string) $data['courier_contact']) : null,
                'eta' => $data['eta'] ?? null,
                'lines' => json_encode($shipmentLines, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
                'courier_provider' => $bookCourier || $manualCourier ? $provider : null,
                'courier_booking_status' => $bookCourier ? 'pending' : ($manualCourier ? 'manual' : null),
                ...($manualCourier ? $this->manualParcelStart() : []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->stockLots->linkShipment($shipmentLines, $shipmentId);
            if ($bookCourier) {
                DB::afterCommit(fn () => BookShipmentCourier::dispatch($shipmentId));
            }
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
            if ($bookCourier) {
                // The partner hears about it once the courier has given a tracking number.
                return;
            }
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
        $this->assertNotOwnOrder($actor, $order);
        DB::transaction(function () use ($actor, $order, $shipmentId, $reason): void {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('id', $shipmentId)->where('order_id', $locked->id)->lockForUpdate()->first();
            abort_unless($shipment, 404);
            if ($shipment->status === 'delivered') {
                return;
            }
            abort_unless($shipment->status === 'dispatched', 422);
            // The parcel came back, so confirming it would release commission for goods the dealer never got.
            if ($shipment->courier_status === 'returned') {
                throw ValidationException::withMessages(['shipment' => __('courier.returned_cannot_confirm')]);
            }
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

    /**
     * A parcel the courier returned is physically back at the branch. The shipped quantity
     * goes back into that branch's stock exactly once, and the order closes when nothing else
     * is left on it. No commission is posted; any money is returned by hand outside the system.
     */
    public function receiveReturnedParcel(User $actor, Order $order, int $shipmentId, ?string $note): void
    {
        $this->branches->assertCompanyHandler($actor);
        abort_unless($actor->allows('inventory'), 403);
        $this->assertNotOwnOrder($actor, $order);
        $note = $note !== null && trim($note) !== '' ? Str::limit(trim($note), 500, '') : null;
        DB::transaction(function () use ($actor, $order, $shipmentId, $note): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('id', $shipmentId)->where('order_id', $locked->id)->lockForUpdate()->first();
            abort_unless($shipment !== null, 404);
            if ($shipment->status === 'returned') {
                return;
            }
            if ($shipment->status !== 'dispatched' || $shipment->courier_status !== 'returned') {
                throw ValidationException::withMessages(['shipment' => __('courier.err_received_back_not_returned')]);
            }
            /** @var list<array{line_id: int, name: string, quantity: int, lots?: list<array{lot_id: int, quantity: int}>}> $shipmentLines */
            $shipmentLines = json_decode((string) $shipment->lines, true, 512, JSON_THROW_ON_ERROR);
            $orderLines = $locked->lines()->whereIn('id', array_column($shipmentLines, 'line_id'))->get()->keyBy('id');
            foreach ($shipmentLines as $line) {
                $this->branches->adjustStock($actor, [
                    'product_id' => (int) $orderLines[$line['line_id']]->getAttribute('product_id'),
                    'quantity' => (int) $line['quantity'],
                    'kind' => BranchOperations::KindProductIn,
                    'reason_code' => 'return',
                    'reason' => $locked->number,
                    'reference' => 'shipment-return:'.$shipment->id.':'.$line['line_id'],
                    'branch_id' => (int) $shipment->branch_id,
                    // Older parcels have no lot record, so they come back as a new lot with the batch on the order line.
                    'return_lots' => $line['lots'] ?? [],
                    'lot_source' => StockLots::SourceReturn,
                    'batch_number' => $orderLines[$line['line_id']]->getAttribute('batch_number'),
                    'expires_on' => $orderLines[$line['line_id']]->getRawOriginal('expires_on'),
                    'lot_context' => ['order_id' => $locked->id, 'order_line_id' => (int) $line['line_id'], 'shipment_id' => (int) $shipment->id],
                ]);
            }
            DB::table('shipments')->where('id', $shipment->id)->update(['status' => 'returned', 'updated_at' => now()]);
            $unsent = $locked->lines()->whereColumn('dispatched', '<', 'quantity')->exists();
            $otherParcels = DB::table('shipments')->where('order_id', $locked->id)->where('status', '!=', 'returned')->exists();
            $orderClosed = ! $unsent && ! $otherParcels;
            if ($orderClosed) {
                $locked->update(['status' => 'cancelled', 'decision_reason' => __('courier.received_back_reason')]);
            }
            $this->event($locked, $actor, 'shipment.received_back', [
                'shipment_id' => $shipment->id,
                'reason' => $note,
                'order_closed' => $orderClosed,
                'branch_id' => $shipment->branch_id,
            ]);
            $this->partnerNotice($locked->user_id, 'courier.notice_received_back_title', 'courier.notice_received_back_body', [
                'number' => $locked->number,
            ]);
        }, 3);
    }

    /**
     * Staff or the owner switch the order to another courier: before the booking, or after
     * the courier refused it (closed, not serving that area, ...). The optional note is
     * shown to the dealer. A waiting or failed booking is then sent to the new courier; an
     * approved order that was not booked yet is booked now.
     */
    public function changeCourier(User $actor, Order $order, string $provider, ?string $note): void
    {
        abort_unless($actor->allows('inventory') || $actor->allows('order_review'), 403);
        $this->assertNotOwnOrder($actor, $order);
        $options = app(CourierOptions::class);
        if (! $options->isEnabled($provider)) {
            throw ValidationException::withMessages(['courier' => __('courier.err_courier_not_enabled')]);
        }
        $note = $note !== null && trim($note) !== '' ? Str::limit(trim($note), 500, '') : null;
        DB::transaction(function () use ($actor, $order, $provider, $note): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('order_id', $locked->id)->whereNotNull('courier_provider')
                ->orderByDesc('id')->lockForUpdate()->first();
            $from = $locked->courier_choice;
            if ($shipment) {
                if (! in_array($shipment->courier_booking_status, ['pending', 'failed'], true)) {
                    throw ValidationException::withMessages(['courier' => __('courier.err_change_after_booking')]);
                }
                if ($shipment->status !== 'dispatched') {
                    throw ValidationException::withMessages(['courier' => __('courier.err_change_closed_order')]);
                }
                $from = $shipment->courier_provider;
            } elseif (! in_array($locked->status, ['submitted', 'held', 'approved'], true)) {
                throw ValidationException::withMessages(['courier' => __('courier.err_change_closed_order')]);
            }
            if ($from === $provider) {
                throw ValidationException::withMessages(['courier' => __('courier.err_same_courier')]);
            }
            $locked->update(['courier_choice' => $provider]);
            if ($shipment) {
                DB::table('shipments')->where('id', $shipment->id)->update([
                    'courier_provider' => $provider,
                    'courier' => CourierOptions::label($provider),
                    'courier_booking_status' => CourierOptions::isManual($provider) ? 'manual' : 'pending',
                    'courier_booking_error' => null,
                    'updated_at' => now(),
                    ...(CourierOptions::isManual($provider) ? $this->manualParcelStart() : []),
                ]);
                if (! CourierOptions::isManual($provider)) {
                    DB::afterCommit(fn () => BookShipmentCourier::dispatch((int) $shipment->id));
                }
            }
            $this->event($locked, $actor, 'order.courier_changed', [
                'from' => $from,
                'to' => $provider,
                'from_label' => CourierOptions::label($from),
                'to_label' => CourierOptions::label($provider),
                'note' => $note,
                'shipment_id' => $shipment?->id,
            ]);
            $partner = User::find($locked->user_id);
            $locale = $partner?->preferredLocale() ?? app()->getLocale();
            $replace = ['number' => $locked->number, 'from' => CourierOptions::label($from, $locale), 'to' => CourierOptions::label($provider, $locale), 'note' => (string) $note];
            Audit::notice(
                $locked->user_id,
                __('courier.notice_changed_title', $replace, $locale),
                __($note !== null ? 'courier.notice_changed_body_with_note' : 'courier.notice_changed_body', $replace, $locale),
            );
            if (! $shipment && $locked->status === 'approved') {
                $this->autoShip($actor, $locked);
            }
        }, 3);
    }

    /**
     * Sends a refused courier booking again, to the same courier.
     */
    public function retryCourierBooking(User $actor, Order $order, int $shipmentId): void
    {
        abort_unless($actor->allows('inventory') || $actor->allows('order_review'), 403);
        $this->assertNotOwnOrder($actor, $order);
        DB::transaction(function () use ($actor, $order, $shipmentId): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('id', $shipmentId)->where('order_id', $locked->id)->lockForUpdate()->first();
            abort_if($shipment === null, 404);
            if ($shipment->courier_booking_status !== 'failed' || $shipment->status !== 'dispatched') {
                throw ValidationException::withMessages(['shipment' => __('courier.err_retry_not_failed')]);
            }
            if (! app(CourierOptions::class)->isEnabled($shipment->courier_provider)) {
                throw ValidationException::withMessages(['shipment' => __('courier.err_courier_not_enabled')]);
            }
            DB::table('shipments')->where('id', $shipment->id)->update(['courier_booking_status' => 'pending', 'courier_booking_error' => null, 'updated_at' => now()]);
            $this->event($locked, $actor, 'order.courier_booking_retried', ['shipment_id' => $shipment->id, 'provider' => $shipment->courier_provider]);
            DB::afterCommit(fn () => BookShipmentCourier::dispatch((int) $shipment->id));
        }, 3);
    }

    /**
     * After approval, an order whose dealer picked a courier is packed from the fulfilling
     * branch (the head office unless another branch was chosen) and booked with that courier
     * once the approval is saved. "Company decides" orders keep the manual dispatch. When the
     * courier is switched off or the branch lacks stock, nothing is sent and staff are told,
     * so they can dispatch by hand or change the courier.
     */
    private function autoShip(User $actor, Order $locked): void
    {
        $provider = $locked->courier_choice;
        if (blank($provider) || DB::table('shipments')->where('order_id', $locked->id)->exists()) {
            return;
        }
        if (! app(CourierOptions::class)->isEnabled($provider)) {
            $this->autoShipSkipped($actor, $locked, (string) $provider, 'courier_off');

            return;
        }
        if (CourierOptions::isManual($provider)) {
            $this->event($locked, $actor, 'order.courier_auto_skipped', ['provider' => $provider, 'reason' => 'manual_courier']);
            $replace = ['number' => $locked->number, 'courier' => CourierOptions::label($provider)];
            Audit::notifyAdminsAndStaff(['orders', 'inventory'], __('courier_more.notice_manual_dispatch_title', $replace), __('courier_more.notice_manual_dispatch_body', $replace));

            return;
        }
        $branchQuery = CompanyBranch::query()->active()->where('is_stock_hub', true);
        $branch = ($locked->fulfillment_branch_id ? (clone $branchQuery)->whereKey($locked->fulfillment_branch_id)->first() : null)
            ?? $branchQuery->orderByRaw('COALESCE(is_head_office, false) DESC')->orderBy('id')->first();
        if (! $branch) {
            $this->autoShipSkipped($actor, $locked, (string) $provider, 'no_branch');

            return;
        }
        $lines = OrderLine::query()->where('order_id', $locked->id)->whereColumn('dispatched', '<', 'quantity')->orderBy('id')->get();
        if ($lines->isEmpty()) {
            return;
        }
        $products = Product::whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = [];
        foreach ($lines as $line) {
            $left = (int) $line->getAttribute('quantity') - (int) $line->getAttribute('dispatched');
            $product = $products->get((int) $line->getAttribute('product_id'));
            if (! $product instanceof Product || $this->stockLots->usableQuantity($this->branches->ensureBranchStock($branch, $product), $product) < $left) {
                $this->autoShipSkipped($actor, $locked, (string) $provider, 'stock');

                return;
            }
            $items[] = ['line_id' => (int) $line->getKey(), 'quantity' => $left];
        }
        $shipmentLines = $this->takeStock($actor, $locked, $branch, $items);
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $locked->id,
            'branch_id' => $branch->id,
            'courier' => CourierOptions::label($provider),
            'tracking' => '',
            'lines' => json_encode($shipmentLines, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'auto-ship:'.$locked->id),
            'courier_provider' => $provider,
            'courier_booking_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->stockLots->linkShipment($shipmentLines, $shipmentId);
        DB::afterCommit(fn () => BookShipmentCourier::dispatch($shipmentId));
        $remaining = $locked->lines()->whereColumn('dispatched', '<', 'quantity')->exists();
        $locked->update(['status' => $remaining ? 'partially_dispatched' : 'dispatched', 'fulfillment_branch_id' => $branch->id]);
        $this->event($locked, $actor, 'order.dispatched', [
            'shipment_id' => $shipmentId,
            'courier_gateway' => ['provider' => $provider, 'booking' => 'pending'],
            'branch_id' => $branch->id,
            'branch_code' => $branch->code,
            'automatic' => true,
        ]);
    }

    /**
     * A manual courier's parcel starts waiting for pickup, ready for staff to move on by hand.
     *
     * @return array{courier_status: string, courier_status_at: mixed, courier_timeline: string}
     */
    private function manualParcelStart(): array
    {
        return [
            'courier_status' => 'waiting_pickup',
            'courier_status_at' => now(),
            'courier_timeline' => json_encode([ShipmentTracking::timelineEntry('waiting_pickup', null, 'staff')], JSON_THROW_ON_ERROR),
        ];
    }

    private function autoShipSkipped(User $actor, Order $locked, string $provider, string $reason): void
    {
        $this->event($locked, $actor, 'order.courier_auto_skipped', ['provider' => $provider, 'reason' => $reason]);
        $replace = ['number' => $locked->number, 'courier' => CourierOptions::label($provider)];
        Audit::notifyAdminsAndStaff(
            ['orders', 'inventory'],
            __('courier.notice_auto_skipped_title', $replace),
            __('courier.notice_auto_skipped_body_'.$reason, $replace),
        );
    }

    /**
     * Takes the shipped quantities out of the branch stock and marks the order lines sent.
     *
     * @param  list<array{line_id: int, quantity: int}>  $items
     * @return list<array{line_id: int, name: string, quantity: int, lots: list<array{movement_id: int, lot_id: int, batch_number: ?string, expires_on: ?string, quantity: int}>}>
     */
    private function takeStock(User $actor, Order $locked, CompanyBranch $branch, array $items): array
    {
        $orderLines = $locked->lines()->whereIn('id', array_column($items, 'line_id'))->get()->keyBy('id');
        abort_unless($orderLines->count() === count($items), 404);
        $products = Product::whereIn('id', $orderLines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $shipmentLines = [];
        foreach ($items as $item) {
            $line = $orderLines[$item['line_id']];
            $product = $products[$line->product_id];
            $quantity = (int) $item['quantity'];
            $branchStock = $this->branches->ensureBranchStock($branch, $product);
            if ($quantity > $line->quantity - $line->dispatched || $quantity > $branchStock->quantity) {
                throw ValidationException::withMessages(['shipment' => __('portal.err_shipment_qty', ['product' => $product->name])]);
            }
            $previousStock = $product->stock;
            $takenLots = $this->stockLots->take($branchStock, $product, $quantity, [
                'kind' => StockLots::KindDispatch,
                'actor_id' => $actor->id,
                'order_id' => $locked->id,
                'order_line_id' => (int) $line->getKey(),
                'reference' => $locked->number,
            ], errorField: 'shipment');
            $companyTotal = (int) BranchProductStock::query()->where('product_id', $product->id)->sum('quantity');
            $product->forceFill(['stock' => $companyTotal])->save();
            $this->stockNotifications->recordChange($product, $previousStock, $previousStock > 0 && ! $product->is_stock_paused);
            $line->increment('dispatched', $quantity);
            DB::table('stock_movements')->insert([
                'product_id' => $product->id,
                'branch_id' => $branch->id,
                'quantity' => -$quantity,
                'kind' => 'dispatch',
                'reference' => (string) Str::uuid(),
                'reason' => $locked->number,
                'actor_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $shipmentLines[] = ['line_id' => $line->id, 'name' => $line->name, 'quantity' => $quantity, 'lots' => $takenLots];
        }

        return $shipmentLines;
    }

    /**
     * Records when the store team expects to send the items still waiting for stock
     * (back-order date), or clears it. The partner sees the date on the order and gets
     * a notice. Only open orders with items left to send can carry a date.
     */
    public function setSupplyDate(User $actor, Order $order, ?string $eta, ?string $note): void
    {
        abort_unless($actor->allows('inventory'), 403);
        $this->assertNotOwnOrder($actor, $order);
        DB::transaction(function () use ($actor, $order, $eta, $note): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, self::SupplyDateStatuses, true) || ! $locked->lines()->whereColumn('dispatched', '<', 'quantity')->exists()) {
                throw ValidationException::withMessages(['supply_eta' => __('portal.err_supply_eta_nothing_left')]);
            }
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $raw = $locked->getRawOriginal('supply_eta');
            $before = $raw === null ? null : CarbonImmutable::parse((string) $raw)->toDateString();
            if ($before === $eta && $locked->supply_note === $note) {
                return;
            }
            $locked->update(['supply_eta' => $eta, 'supply_note' => $eta === null ? null : $note]);
            $this->event($locked, $actor, 'order.supply_date_set', ['supply_eta' => $eta, 'previous_supply_eta' => $before, 'note' => $note]);
            if ($eta !== null) {
                $this->partnerNotice($locked->user_id, 'portal.notice_supply_eta_title', 'portal.notice_supply_eta_body', [
                    'number' => $locked->number,
                    'date' => CarbonImmutable::parse($eta)->format('d/m/Y'),
                ]);
            }
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
            app(PartnerEventSms::class)->send(
                $partner,
                $event,
                SmsText::make($template, $replace),
            );
        });
    }

    /**
     * Asks the payment gateway about a pending claim without holding any lock.
     *
     * @return array{claim: string, result: array<string, mixed>}|null
     */
    private function gatewayLookupBeforeLock(int $claimId): ?array
    {
        $claim = DB::table('payment_claims')->where('id', $claimId)->first(['channel', 'reference', 'amount', 'status']);
        if (! $claim || $claim->status !== 'pending') {
            return null;
        }

        return [
            'claim' => $this->gatewayLookupKey($claim),
            'result' => app(PaymentGatewayClient::class)->lookup((string) $claim->channel, (string) $claim->reference, (string) $claim->amount),
        ];
    }

    /** The claim details the gateway answer is valid for. */
    private function gatewayLookupKey(object $claim): string
    {
        return $claim->channel.'|'.$claim->reference.'|'.Money::cents((string) $claim->amount);
    }

    private function insertClaim(Order $order, array $data): int
    {
        $amount = Money::cents((string) $data['amount']);
        if ($amount <= 0 || $amount > Money::cents($order->outstanding())) {
            throw ValidationException::withMessages(['amount' => __('portal.err_claim_amount')]);
        }
        // Payments already sent and still waiting for a check count too, so a dealer cannot
        // send more in total than the order still needs.
        $waiting = Money::cents((string) DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')->sum('amount'));
        if ($waiting > 0 && $amount + $waiting > Money::cents($order->outstanding())) {
            throw ValidationException::withMessages(['amount' => __('portal.err_claim_over_waiting', [
                'waiting' => Money::grouped(Money::decimal($waiting)),
                'left' => Money::grouped(Money::decimal(max(0, Money::cents($order->outstanding()) - $waiting))),
            ])]);
        }

        $account = app(PaymentAccounts::class)->collection(isset($data['payment_account_id']) ? (int) $data['payment_account_id'] : null, $data['channel']);
        $reference = Str::upper(trim($data['reference']));
        if (DB::table('payment_claims')->where('channel', $data['channel'])->where('reference', $reference)->where('status', '<>', 'rejected')->exists()
            || DB::table('receipts')->where('channel', $data['channel'])->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => __('portal.err_payment_ref_used')]);
        }

        // Proof of payment (screenshot or PDF) — required by the request rules for new claims.
        $proof = ($data['proof'] ?? null) instanceof UploadedFile ? app(PaymentProof::class)->store($data['proof']) : null;
        DB::afterRollBack(fn () => app(PaymentProof::class)->discard($proof));

        try {
            return DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => $data['channel'],
                'payment_account_id' => $account['id'] ?? null, 'account_snapshot' => $account ? json_encode(['encrypted' => encrypt($account)], JSON_THROW_ON_ERROR) : null,
                'reference' => $reference, 'amount' => Money::decimal($amount), ...($proof ?? []), 'created_at' => now(), 'updated_at' => now()]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['reference' => __('portal.err_payment_ref_used')]);
            }
            throw $exception;
        }
    }

    /**
     * "Full payment required when ordering": while the owner keeps it on, an order is only
     * accepted with a payment for its whole total (channel and reference). Part payments are
     * refused. The proof screenshot is required by the order form rules.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPaidInFullWithOrder(array $data, int $totalCents): void
    {
        if (! app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled']) {
            return;
        }
        if (trim((string) ($data['reference'] ?? '')) === '' || trim((string) ($data['channel'] ?? '')) === '') {
            throw ValidationException::withMessages(['reference' => __('portal.err_order_full_payment_needed')]);
        }
        if (Money::cents((string) ($data['amount'] ?? '0')) !== $totalCents) {
            throw ValidationException::withMessages(['amount' => __('portal.err_order_full_payment_amount', ['amount' => Money::grouped(Money::decimal($totalCents))])]);
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
        // An attached payment screenshot counts by its content, so retrying with the same file is the same request.
        $comparable = array_map(
            fn (mixed $value): mixed => $value instanceof UploadedFile ? 'file:'.hash_file('sha256', $value->getRealPath()) : $value,
            $data,
        );

        return hash('sha256', json_encode($comparable, JSON_THROW_ON_ERROR));
    }

    /**
     * Maker-checker rule: nobody may approve, verify payment for, dispatch or
     * deliver an order they placed themselves (e.g. a dealer with a staff desk).
     */
    private function assertNotOwnOrder(User $actor, Order $order): void
    {
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
    }
}

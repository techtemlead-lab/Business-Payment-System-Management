<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLots;
use App\Domain\Security\OwnerExemption;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use App\Support\ReasonFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cancelling, returning and refunding orders: one way for every case, so goods, money,
 * commission and the record always move together and only once.
 *
 * - Cancel: units that were never sent are closed. Before approval only the whole order can be
 *   cancelled; after approval the rest of a back order can be closed ("close the rest").
 * - Return: delivered units come back to a branch's stock, and the commission on them is taken
 *   back in proportion.
 * - Refund: money only, for goods that already came back by courier.
 *
 * The partner asks (with a reason) or the desk starts it. Money never moves on one person's word
 * unless that person is the owner: anyone else's approval waits for a second person with the
 * refund right. Money paid from the partner's account goes back there first; the rest goes to
 * the account or is handed over in cash, as the approver chose. A refund can never return money
 * for goods the partner keeps.
 */
final class OrderReturns
{
    public const KindCancel = 'cancel';

    public const KindReturn = 'return';

    public const KindRefund = 'refund';

    public const Kinds = [self::KindCancel, self::KindReturn, self::KindRefund];

    public const StatusRequested = 'requested';

    public const StatusAwaitingSecond = 'awaiting_second';

    public const StatusDone = 'done';

    public const StatusRejected = 'rejected';

    public const StatusWithdrawn = 'withdrawn';

    public const OpenStatuses = [self::StatusRequested, self::StatusAwaitingSecond];

    public const MethodAccount = 'account';

    public const MethodCash = 'cash';

    /** The order status when every delivered unit came back and nothing else is left. */
    public const OrderReturned = 'returned';

    /** Before approval only the whole order may be cancelled. */
    private const WholeOrderOnly = ['submitted', 'held'];

    /** Orders whose unsent units can still be closed. */
    private const CancelStatuses = ['submitted', 'held', 'approved', 'partially_dispatched'];

    /** Orders whose delivered goods can come back. */
    private const ReturnStatuses = ['delivered', MixedOutcomeDecisions::PartlyDelivered];

    public const ActionRequested = 'order.return_requested';

    public const ActionFirstApproved = 'order.return_first_approved';

    public const ActionDone = 'order.return_done';

    public const ActionRejected = 'order.return_rejected';

    public const ActionWithdrawn = 'order.return_withdrawn';

    public const ActionPartnerCancelled = 'order.partner_cancelled';

    public function __construct(
        private readonly CommissionReversal $reversal,
        private readonly AccountOrderPayment $accountPayment,
        private readonly CommissionWallet $wallet,
        private readonly BranchOperations $branches,
        private readonly CommissionPosting $commissions,
    ) {}

    /** Staff who may look at, start, approve or turn down a request. */
    public static function canHandle(User $actor): bool
    {
        return $actor->status === 'active' && ($actor->allows('order_review') || OrderRefund::canRefund($actor));
    }

    /** Only the owner's own account carries money out alone (CR-10); anyone else needs a second person. */
    public static function actsAlone(User $actor): bool
    {
        return OwnerExemption::applies($actor);
    }

    /** CR-16: the open refund needs an owner as its second person, and the viewer is not one. */
    private function waitsForOwner(object $request, User $viewer): bool
    {
        return $this->secondMustBeOwner($request, User::find($request->first_approved_by)) && ! OwnerApprovalLimits::ownerDecides($viewer);
    }

    /**
     * CR-16: a refund at or above the owner's refund limit needs an owner in one of its two steps.
     * True when the first step was not an owner's, so the second person must be one.
     */
    private function secondMustBeOwner(object $request, ?User $firstApprover): bool
    {
        return app(OwnerApprovalLimits::class)->needsOwner(OwnerApprovalLimits::Refund, (string) ($request->refund_amount ?? '0'))
            && ! OwnerApprovalLimits::ownerDecides($firstApprover);
    }

    /**
     * The partner asks to cancel (before shipping) or return (after delivery). An unpaid order
     * that is not approved yet, with no payment waiting to be checked, is cancelled at once:
     * nothing has moved, so nobody needs to look at it.
     *
     * @param  list<array{line_id: int|string, quantity: int|string}>  $lines
     * @return array{id: int|null, cancelled_now: bool}
     */
    public function ask(User $partner, Order $order, string $kind, array $lines, string $reason): array
    {
        abort_unless((int) $order->user_id === (int) $partner->id && in_array($partner->role, ['dealer', 'sub_dealer'], true) && $partner->status === 'active', 404);
        if (! in_array($kind, [self::KindCancel, self::KindReturn], true)) {
            throw ValidationException::withMessages(['kind' => __('orders_stock.err_kind')]);
        }

        return DB::transaction(function () use ($partner, $order, $kind, $lines, $reason): array {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertNoOpenRequest($locked);
            $units = $this->checkedUnits($locked, $kind, $kind === self::KindCancel ? $this->allUnsent($locked) : $lines);
            if ($kind === self::KindCancel && $this->cancelsAtOnce($locked)) {
                $this->cancelUnpaidNow($partner, $locked, $reason);

                return ['id' => null, 'cancelled_now' => true];
            }
            $id = $this->insertRequest($locked, $partner, $kind, $units, $reason);
            $this->event($locked, $partner, self::ActionRequested, ['request_id' => $id, 'kind' => $kind, 'lines' => $this->linesForRecord($units), 'reason' => $reason]);
            Audit::notifyStaffFirst(
                ['orders', 'finance'],
                __('orders_stock.notice_staff_request_title', ['number' => $locked->number]),
                __('orders_stock.notice_staff_request_body', ['name' => $partner->name, 'number' => $locked->number, 'kind' => __('orders_stock.kind_'.$kind), 'reason' => $reason]),
            );

            return ['id' => $id, 'cancelled_now' => false];
        }, 3);
    }

    /**
     * The desk starts a cancel, return or refund itself and approves it in the same step.
     *
     * @param  list<array{line_id: int|string, quantity: int|string}>  $lines
     * @return array{id: int, done: bool}
     */
    public function start(User $actor, Order $order, string $kind, array $lines, string $reason, string $refund, string $method, ?int $branchId = null): array
    {
        $this->assertStaff($actor, $order);
        if (! in_array($kind, self::Kinds, true)) {
            throw ValidationException::withMessages(['kind' => __('orders_stock.err_kind')]);
        }

        // One transaction: a refused approval leaves no half-made request behind.
        return DB::transaction(function () use ($actor, $order, $kind, $lines, $reason, $refund, $method, $branchId): array {
            CommissionWallet::lock();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertNoOpenRequest($locked);
            $units = $this->checkedUnits($locked, $kind, $kind === self::KindRefund ? [] : $lines, byStaff: true);
            $id = $this->insertRequest($locked, $actor, $kind, $units, $reason);
            $this->event($locked, $actor, self::ActionRequested, ['request_id' => $id, 'kind' => $kind, 'lines' => $this->linesForRecord($units), 'reason' => $reason, 'by_staff' => true]);

            return ['id' => $id, 'done' => $this->approve($actor, $id, $refund, $method, $branchId, $reason)];
        }, 3);
    }

    /**
     * The first approval. The owner's own account carries it out at once; for anyone else it
     * waits for a second person. Returns true when it was carried out.
     */
    public function approve(User $actor, int $requestId, string $refund, string $method, ?int $branchId, string $note): bool
    {
        return DB::transaction(function () use ($actor, $requestId, $refund, $method, $branchId, $note): bool {
            CommissionWallet::lock();
            $request = $this->lockedRequest($requestId);
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $this->assertStaff($actor, $order);
            if ($request->status !== self::StatusRequested) {
                throw ValidationException::withMessages(['request' => __('orders_stock.err_not_open')]);
            }
            $cents = $this->refundCents($refund);
            if (! in_array($method, [self::MethodAccount, self::MethodCash], true)) {
                throw ValidationException::withMessages(['refund_method' => __('orders_stock.err_method')]);
            }
            if ($cents > 0) {
                abort_unless(OrderRefund::canRefund($actor), 403, __('orders_stock.err_refund_right'));
            }
            $branch = $request->kind === self::KindReturn ? $this->returnBranch($order, $branchId) : null;
            $units = $this->storedUnits($request);
            // Checked again now: something may have changed since the partner asked.
            $this->checkedUnits($order, $request->kind, $this->unitsAsLines($units), byStaff: true, allowEmpty: $request->kind === self::KindRefund);
            $this->assertRefundFits($order, $request->kind, $units, $cents);
            $this->assertMethodUsable($method, $order, $cents);
            DB::table('order_return_requests')->where('id', $request->id)->update([
                'refund_amount' => Money::decimal($cents), 'refund_method' => $method, 'branch_id' => $branch?->id,
                'first_approved_by' => $actor->id, 'first_approved_at' => now(), 'first_approval_note' => $note, 'updated_at' => now(),
            ]);
            $request = $this->lockedRequest($requestId);
            if (self::actsAlone($actor) || ($cents === 0 && $request->kind === self::KindCancel && ! $this->hasPostedCommission($order))) {
                $this->carryOut($actor, $order, $request, null);

                return true;
            }
            DB::table('order_return_requests')->where('id', $request->id)->update(['status' => self::StatusAwaitingSecond, 'updated_at' => now()]);
            $this->event($order, $actor, self::ActionFirstApproved, ['request_id' => $request->id, 'refund' => Money::decimal($cents), 'method' => $method, 'reason' => $note]);
            $title = __('orders_stock.notice_second_needed_title', ['number' => $order->number]);
            $body = __('orders_stock.notice_second_needed_body', ['name' => $actor->name, 'number' => $order->number, 'amount' => Money::grouped(Money::decimal($cents))]);
            // CR-16: a big refund that no owner has approved yet waits for an owner; the rest go to finance staff.
            if ($this->secondMustBeOwner($request, $actor)) {
                Audit::notifyOwners(['finance'], $title, $body, $actor->id);
            } else {
                Audit::notifyStaffFirst(['finance'], $title, $body, $actor->id);
            }

            return false;
        }, 3);
    }

    /** A second person with the refund right checks the first approval and carries it out. */
    public function confirm(User $actor, int $requestId, string $note): void
    {
        DB::transaction(function () use ($actor, $requestId, $note): void {
            CommissionWallet::lock();
            $request = $this->lockedRequest($requestId);
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $this->assertStaff($actor, $order);
            abort_unless(OrderRefund::canRefund($actor), 403, __('orders_stock.err_refund_right'));
            if ($request->status !== self::StatusAwaitingSecond) {
                throw ValidationException::withMessages(['request' => __('orders_stock.err_not_waiting')]);
            }
            if ((int) $request->first_approved_by === (int) $actor->id) {
                throw ValidationException::withMessages(['request' => __('orders_stock.err_same_person')]);
            }
            if ($this->secondMustBeOwner($request, User::find($request->first_approved_by))) {
                app(OwnerApprovalLimits::class)->refuseUnlessOwnerMayApprove($actor, OwnerApprovalLimits::Refund, (string) $request->refund_amount, 'request');
            }
            $units = $this->storedUnits($request);
            $this->checkedUnits($order, $request->kind, $this->unitsAsLines($units), byStaff: true, allowEmpty: $request->kind === self::KindRefund);
            $cents = Money::cents((string) $request->refund_amount);
            $this->assertRefundFits($order, $request->kind, $units, $cents);
            $this->assertMethodUsable((string) $request->refund_method, $order, $cents);
            $this->carryOut($actor, $order, $request, $note);
        }, 3);
    }

    /** Staff turn a request down, saying why; the partner is told. */
    public function reject(User $actor, int $requestId, string $reason): void
    {
        DB::transaction(function () use ($actor, $requestId, $reason): void {
            $request = $this->lockedRequest($requestId);
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $this->assertStaff($actor, $order);
            if (! in_array($request->status, self::OpenStatuses, true)) {
                throw ValidationException::withMessages(['request' => __('orders_stock.err_not_open')]);
            }
            DB::table('order_return_requests')->where('id', $request->id)->update(['status' => self::StatusRejected, 'decided_by' => $actor->id,
                'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
            $this->event($order, $actor, self::ActionRejected, ['request_id' => $request->id, 'kind' => $request->kind, 'reason' => $reason]);
            $this->tellPartner($order, 'orders_stock.notice_rejected_title', 'orders_stock.notice_rejected_body', ['kind' => 'orders_stock.kind_'.$request->kind, 'reason' => $reason]);
        }, 3);
    }

    /** The partner takes back a request nobody has acted on yet. */
    public function withdraw(User $partner, int $requestId): void
    {
        DB::transaction(function () use ($partner, $requestId): void {
            $request = $this->lockedRequest($requestId);
            abort_unless((int) $request->user_id === (int) $partner->id, 404);
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            if ($request->status !== self::StatusRequested) {
                throw ValidationException::withMessages(['request' => __('orders_stock.err_withdraw_late')]);
            }
            DB::table('order_return_requests')->where('id', $request->id)->update(['status' => self::StatusWithdrawn, 'decided_by' => $partner->id, 'decided_at' => now(), 'updated_at' => now()]);
            $this->event($order, $partner, self::ActionWithdrawn, ['request_id' => $request->id, 'kind' => $request->kind]);
        }, 3);
    }

    /**
     * What the order page shows: the open request, what can still be cancelled or returned,
     * the money that can still be given back, and the refunds already made.
     *
     * @return array<string, mixed>
     */
    public function pageData(Order $order, User $viewer): array
    {
        $order->loadMissing('lines');
        $isPartner = (int) $viewer->id === (int) $order->user_id;
        $staff = ! $isPartner && self::canHandle($viewer);
        $open = DB::table('order_return_requests as r')->leftJoin('users as first', 'first.id', '=', 'r.first_approved_by')
            ->where('r.order_id', $order->id)->whereIn('r.status', self::OpenStatuses)
            ->first(['r.id', 'r.kind', 'r.status', 'r.lines', 'r.reason', 'r.refund_amount', 'r.refund_method', 'r.first_approved_by', 'r.first_approval_note', 'r.created_at', 'first.name as first_approved_name']);
        $parcels = $this->parcelUnits($order);
        $lines = $order->lines->map(fn (Model $line): array => [
            'line_id' => (int) $line->getKey(),
            'name' => (string) $line->getAttribute('name'),
            'is_free' => (bool) $line->getAttribute('is_free'),
            'unit_price' => (string) $line->getAttribute('unit_price'),
            'can_cancel' => $this->unsentOf($line),
            'can_return' => $this->returnableOf($line, $parcels),
            'closed' => (int) $line->getAttribute('closed'),
            'returned' => (int) $line->getAttribute('returned'),
        ])->values()->all();
        $history = DB::table('order_return_requests as r')->leftJoin('users as decider', 'decider.id', '=', 'r.decided_by')
            ->where('r.order_id', $order->id)->whereNotIn('r.status', self::OpenStatuses)->orderByDesc('r.id')->limit(20)
            ->get(['r.id', 'r.kind', 'r.status', 'r.reason', 'r.refund_amount', 'r.decision_reason', 'r.decided_at', 'decider.name as decided_by_name']);
        $refunds = DB::table('order_refunds as f')->leftJoin('users as staff', 'staff.id', '=', 'f.refunded_by')->leftJoin('users as second', 'second.id', '=', 'f.approved_by')
            ->where('f.order_id', $order->id)->orderBy('f.created_at')
            ->get(['f.id', 'f.kind', 'f.amount', 'f.to_account', 'f.cash_to_account', 'f.reason', 'f.created_at', 'staff.name as refunded_by', 'second.name as approved_by']);

        return [
            'open' => $open === null ? null : [...(array) $open, 'lines' => json_decode((string) $open->lines, true),
                'can_confirm' => $staff && $open->status === self::StatusAwaitingSecond && OrderRefund::canRefund($viewer) && (int) $open->first_approved_by !== (int) $viewer->id
                    && ! $this->waitsForOwner($open, $viewer),
                'waits_for_owner' => $staff && $open->status === self::StatusAwaitingSecond && $this->waitsForOwner($open, $viewer),
                'can_approve' => $staff && $open->status === self::StatusRequested,
                'can_withdraw' => $isPartner && $open->status === self::StatusRequested],
            'lines' => $lines,
            // Money that can go back without any goods changing hands, and all money not yet given back.
            'refundable_now' => Money::decimal($this->refundableCents($order)),
            'paid_left' => Money::decimal($this->paidLeftCents($order)),
            'paid_from_account_left' => Money::decimal($this->accountLeftCents($order)),
            'refunds' => $refunds->map(fn (object $row): array => (array) $row)->all(),
            'history' => $history->map(fn (object $row): array => (array) $row)->all(),
            'partner_can_cancel' => $isPartner && $open === null && in_array($order->status, ['submitted', 'held', 'approved'], true)
                && ! DB::table('shipments')->where('order_id', $order->id)->exists() && collect($lines)->sum('can_cancel') > 0,
            'partner_can_return' => $isPartner && $open === null && in_array($order->status, self::ReturnStatuses, true) && collect($lines)->sum('can_return') > 0,
            'staff_can_start' => $staff && $open === null && ! $order->is_opening_balance
                && ($this->paidLeftCents($order) > 0 || collect($lines)->sum('can_cancel') > 0 || collect($lines)->sum('can_return') > 0),
            'can_refund' => $staff && OrderRefund::canRefund($viewer),
            'acts_alone' => self::actsAlone($viewer),
            'whole_order_only' => in_array($order->status, self::WholeOrderOnly, true),
            'branches' => $staff ? CompanyBranch::query()->active()->where('is_stock_hub', true)->orderBy('name')->get(['id', 'name', 'code'])->toArray() : [],
            'default_branch_id' => $order->fulfillment_branch_id,
        ];
    }

    /** Paisa that can still be given back on this order without paying for goods the partner keeps. */
    public function refundableCents(Order $order): int
    {
        $order->loadMissing('lines');

        return max(0, Money::cents((string) $order->paid) - $this->refundedCents($order) - $this->keptValueCents($order, []));
    }

    /**
     * Carries out an approved request: goods, money, commission and the record, in the caller's
     * transaction, with the money lock and the order lock held.
     */
    private function carryOut(User $actor, Order $order, object $request, ?string $secondNote): void
    {
        $units = $this->storedUnits($request);
        $cents = Money::cents((string) ($request->refund_amount ?? '0'));
        $method = (string) $request->refund_method;
        $reason = (string) ($request->first_approval_note ?: $request->reason);
        $firstApprover = $request->first_approved_by !== null ? (int) $request->first_approved_by : (int) $actor->id;
        $goodsCents = $this->chargedValueCents($order, $units);
        $order->loadMissing('lines');
        $lines = $order->lines->keyBy(fn (Model $line): int => (int) $line->getKey());

        // Goods.
        foreach ($units as $lineId => $quantity) {
            $line = $lines[$lineId];
            if ($request->kind === self::KindCancel) {
                $line->increment('closed', $quantity);
            } elseif ($request->kind === self::KindReturn) {
                $line->increment('returned', $quantity);
                $branch = CompanyBranch::query()->findOrFail((int) $request->branch_id);
                $this->branches->receiveOrderReturn($actor, $branch, [
                    'product_id' => (int) $line->getAttribute('product_id'),
                    'quantity' => $quantity,
                    'kind' => BranchOperations::KindProductIn,
                    'reason_code' => 'return',
                    'reason' => $order->number,
                    'reference' => 'order-return:'.$request->id.':'.$lineId,
                    'lot_source' => StockLots::SourceReturn,
                    'batch_number' => $line->getAttribute('batch_number'),
                    'expires_on' => $line->getRawOriginal('expires_on'),
                    'lot_context' => ['order_id' => $order->id, 'order_line_id' => $lineId],
                ]);
            }
        }

        // Commission already posted on goods that are cancelled or come back is taken back in proportion. The company funds may not go below zero.
        $reversed = 0;
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        if ($request->kind !== self::KindRefund && $batch !== null && ! $batch->no_commission && $goodsCents > 0) {
            $left = Money::cents((string) $batch->cash_basis) - Money::cents((string) $batch->refunded_amount);
            $take = min($goodsCents, $left);
            if ($take > 0) {
                $this->assertFundsStayPositive($batch, $take);
                $reversed = count($this->reversal->refund($order, $take, $reason, 'order-return:'.$request->id, $actor->id));
            }
        }

        // Money.
        $refundId = null;
        $toAccount = 0;
        $cashToAccount = 0;
        if ($cents > 0) {
            $refundId = (string) Str::uuid();
            $toAccount = min($cents, $this->accountLeftCents($order));
            $cashToAccount = $method === self::MethodAccount ? $cents - $toAccount : 0;
            $paidBefore = Money::cents((string) $order->paid) - $this->refundedCents($order);
            DB::table('order_refunds')->insert([
                'id' => $refundId, 'order_id' => $order->id, 'user_id' => $order->user_id, 'kind' => $request->kind,
                'amount' => Money::decimal($cents), 'paid_before' => Money::decimal($paidBefore), 'to_account' => Money::decimal($toAccount),
                'cash_to_account' => Money::decimal($cashToAccount), 'reason' => $reason, 'refunded_by' => $firstApprover,
                'approved_by' => $firstApprover === (int) $actor->id ? null : $actor->id, 'request_id' => $request->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($toAccount > 0) {
                $this->accountPayment->giveBack($order, $actor, $toAccount, $refundId);
            }
            if ($cashToAccount > 0) {
                $this->creditCashToAccount($order, $cashToAccount, $refundId);
            }
        }

        DB::table('order_return_requests')->where('id', $request->id)->update(['status' => self::StatusDone, 'decided_by' => $actor->id, 'decided_at' => now(),
            'decision_reason' => $secondNote, 'order_refund_id' => $refundId, 'updated_at' => now()]);
        if ($request->kind === self::KindCancel) {
            DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')->whereRaw('NOT EXISTS (SELECT 1 FROM order_lines l WHERE l.order_id = payment_claims.order_id AND l.dispatched + l.closed < l.quantity)')
                ->update(['status' => 'rejected', 'verified_by' => $actor->id, 'reason' => __('orders_stock.claim_closed_reason'), 'updated_at' => now()]);
        }
        $statusBefore = (string) $order->status;
        $this->settleStatus($actor, $order->refresh());
        $metadata = ['request_id' => $request->id, 'kind' => $request->kind, 'lines' => $this->linesForRecord($units), 'refund' => Money::decimal($cents),
            'to_account' => Money::decimal($toAccount), 'cash_to_account' => Money::decimal($cashToAccount), 'cash_by_hand' => Money::decimal($cents - $toAccount - $cashToAccount),
            'goods_value' => Money::decimal($goodsCents), 'commissions_reversed' => $reversed, 'refund_id' => $refundId, 'reason' => $reason,
            'first_approved_by' => $firstApprover, 'second_approved_by' => $firstApprover === (int) $actor->id ? null : $actor->id,
            'status_before' => $statusBefore, 'status_after' => $order->status, 'branch_id' => $request->branch_id];
        $this->event($order, $actor, $cents > 0 ? 'order.refunded' : self::ActionDone, $metadata);
        Audit::record($actor->id, self::ActionDone, $order->id, $metadata);
        if ($cents > 0) {
            // Kept for the money reports, which read refunds by this name.
            Audit::record($actor->id, 'order.refunded', $order->id, $metadata);
        }
        $locale = User::find($order->user_id)?->preferredLocale() ?? app()->getLocale();
        $this->tellPartner($order, 'orders_stock.notice_done_title', 'orders_stock.notice_done_body_'.$request->kind, [
            'amount' => LocalDigits::text(Money::grouped(Money::decimal($cents)), $locale),
            'cash' => LocalDigits::text(Money::grouped(Money::decimal($cents - $toAccount - $cashToAccount)), $locale),
            'account' => LocalDigits::text(Money::grouped(Money::decimal($toAccount + $cashToAccount)), $locale),
            'reason' => $reason,
        ]);
    }

    /**
     * The order's status after goods were closed or came back. Nothing changes while units are
     * still to send. With nothing left to send and a parcel on the road it waits for that parcel.
     * Once everything has finished the order is delivered in part (commission posts on the
     * delivered goods, once), returned, or cancelled.
     */
    private function settleStatus(User $actor, Order $order): void
    {
        $order->load('lines');
        $unsent = $order->lines->contains(fn (Model $line): bool => $this->unsentOf($line) > 0);
        if ($unsent) {
            return;
        }
        $parcels = $this->parcelUnits($order);
        if (array_sum($parcels['open']) > 0) {
            $order->update(['status' => 'dispatched']);

            return;
        }
        $kept = 0;
        $everDelivered = array_sum($parcels['delivered']) > 0;
        foreach ($order->lines as $line) {
            $kept += ($parcels['delivered'][(int) $line->getKey()] ?? 0) - (int) $line->getAttribute('returned');
        }
        if (! $everDelivered) {
            $order->update(['status' => 'cancelled']);

            return;
        }
        if ($kept <= 0) {
            $order->update(['status' => self::OrderReturned]);

            return;
        }
        if (in_array($order->status, self::ReturnStatuses, true)) {
            return;
        }
        // A back order whose rest was closed: delivered in part, commission on the delivered goods.
        $order->update(['status' => MixedOutcomeDecisions::PartlyDelivered]);
        try {
            $posted = $this->commissions->processDeliveredPart($order, $actor->id) !== [];
        } catch (ValidationException) {
            $posted = false;
        }
        if ($posted) {
            $this->event($order, $actor, MixedOutcomeDecisions::PostedAction, ['delivered_value' => DeliveredMoney::valueOf($order)]);
        }
    }

    private function cancelUnpaidNow(User $partner, Order $order, string $reason): void
    {
        foreach ($order->lines as $line) {
            $left = $this->unsentOf($line);
            if ($left > 0) {
                $line->increment('closed', $left);
            }
        }
        $order->update(['status' => 'cancelled', 'decision_reason' => $reason]);
        DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'verified_by' => $partner->id, 'reason' => __('orders_stock.claim_closed_reason'), 'updated_at' => now()]);
        $this->event($order, $partner, self::ActionPartnerCancelled, ['reason' => $reason]);
        Audit::record($partner->id, self::ActionPartnerCancelled, $order->id, ['reason' => $reason]);
        Audit::notifyAdminsAndStaff(['orders'], __('orders_stock.notice_staff_cancelled_title', ['number' => $order->number]),
            __('orders_stock.notice_staff_cancelled_body', ['name' => $partner->name, 'number' => $order->number, 'reason' => $reason]));
    }

    /** True for an order that is not approved, has no verified money and no payment waiting. */
    private function cancelsAtOnce(Order $order): bool
    {
        return in_array($order->status, self::WholeOrderOnly, true)
            && Money::cents((string) $order->paid) === 0
            && ! DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')->exists();
    }

    /**
     * Checks the units asked for against what the order allows and returns them by line.
     *
     * @param  array<int|string, mixed>  $lines
     * @return array<int, int> units by order line id
     */
    private function checkedUnits(Order $order, string $kind, array $lines, bool $byStaff = false, bool $allowEmpty = false): array
    {
        if ($order->is_opening_balance) {
            throw ValidationException::withMessages(['order' => __('orders_stock.err_opening_balance')]);
        }
        $order->load('lines');
        if ($kind === self::KindRefund) {
            return [];
        }
        $allowed = $kind === self::KindCancel ? self::CancelStatuses : self::ReturnStatuses;
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['kind' => __('orders_stock.err_status_'.$kind)]);
        }
        if (! $byStaff && $kind === self::KindCancel && DB::table('shipments')->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['kind' => __('orders_stock.err_partner_cancel_shipped')]);
        }
        $orderLines = $order->lines->keyBy(fn (Model $line): int => (int) $line->getKey());
        $parcels = $kind === self::KindReturn ? $this->parcelUnits($order) : null;
        $units = [];
        foreach ($lines as $row) {
            $lineId = (int) ($row['line_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($quantity === 0) {
                continue;
            }
            $line = $orderLines->get($lineId);
            if ($line === null || $quantity < 0) {
                throw ValidationException::withMessages(['lines' => __('orders_stock.err_line')]);
            }
            $max = $kind === self::KindCancel ? $this->unsentOf($line) : $this->returnableOf($line, $parcels ?? []);
            $units[$lineId] = ($units[$lineId] ?? 0) + $quantity;
            if ($units[$lineId] > $max) {
                throw ValidationException::withMessages(['lines' => __('orders_stock.err_too_many', ['product' => (string) $line->getAttribute('name'), 'max' => LocalDigits::text((string) $max)])]);
            }
        }
        if ($units === [] && ! $allowEmpty) {
            throw ValidationException::withMessages(['lines' => __('orders_stock.err_no_units')]);
        }
        if ($kind === self::KindCancel && in_array($order->status, self::WholeOrderOnly, true)) {
            foreach ($orderLines as $lineId => $line) {
                if (($units[$lineId] ?? 0) !== $this->unsentOf($line)) {
                    throw ValidationException::withMessages(['lines' => __('orders_stock.err_whole_order')]);
                }
            }
        }

        return $units;
    }

    /**
     * @param  array<int, int>  $units
     */
    private function assertRefundFits(Order $order, string $kind, array $units, int $cents): void
    {
        $order->load('lines');
        $paidLeft = Money::cents((string) $order->paid) - $this->refundedCents($order);
        $after = $kind === self::KindRefund ? [] : [$kind => $units];
        $max = max(0, $paidLeft - $this->keptValueCents($order, $after));
        if ($cents > $max) {
            throw ValidationException::withMessages(['refund_amount' => __('orders_stock.err_refund_too_much', ['max' => LocalDigits::text(Money::grouped(Money::decimal($max)))])]);
        }
        if ($kind === self::KindRefund && $cents <= 0) {
            throw ValidationException::withMessages(['refund_amount' => __('orders_stock.err_refund_zero')]);
        }
    }

    private function assertMethodUsable(string $method, Order $order, int $cents): void
    {
        if ($method !== self::MethodAccount || $cents <= $this->accountLeftCents($order)) {
            return;
        }
        try {
            CommissionWallet::requireActive();
        } catch (ValidationException) {
            throw ValidationException::withMessages(['refund_method' => __('orders_stock.err_account_off')]);
        }
    }

    /** For the commission-only refund API: the same fund check as a return. */
    public function assertCommissionRefundKeepsFundsPositive(Order $order, int $take): void
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        if ($batch !== null && $take > 0) {
            $this->assertFundsStayPositive($batch, $take);
        }
    }

    /** The company-share and owners' funds may not go below zero because commission came back (owner rule: funds never negative). */
    private function assertFundsStayPositive(object $batch, int $take): void
    {
        $cash = max(1, Money::cents((string) $batch->cash_basis));
        $balances = app(CompanyFunds::class)->balances();
        $drops = [
            'company_share' => intdiv(Money::cents((string) $batch->company_share) * $take + intdiv($cash, 2), $cash),
            'benefits' => intdiv(Money::cents((string) $batch->unallocated) * $take + intdiv($cash, 2), $cash),
        ];
        foreach ($drops as $fund => $drop) {
            if ($drop > 0 && ($balances[$fund]['balance'] ?? 0) - $drop < 0) {
                throw ValidationException::withMessages(['refund_amount' => __('orders_stock.err_fund_negative', ['fund' => __('orders_stock.fund_'.$fund)])]);
            }
        }
    }

    /** Cash the partner paid that goes into their account instead of being handed back. */
    private function creditCashToAccount(Order $order, int $cents, string $refundId): void
    {
        CommissionWallet::requireActive();
        $locale = User::find($order->user_id)?->preferredLocale() ?? app()->getLocale();
        $this->wallet->journal('order.refund_to_account.'.$refundId, __('orders_stock.jr_refund_to_account', ['number' => $order->number], $locale), [
            ['account' => 'order_refund_account_clearing', 'user_id' => null, 'debit' => $cents, 'credit' => 0],
            ['account' => 'wallet_liability', 'user_id' => $order->user_id, 'debit' => 0, 'credit' => $cents],
        ]);
        $this->wallet->credit((int) $order->user_id, $cents);
    }

    /**
     * Value of the goods the partner keeps or is still owed, after the given change.
     *
     * @param  array<string, array<int, int>>  $change  units being cancelled or returned now, by kind
     */
    private function keptValueCents(Order $order, array $change): int
    {
        $parcels = $this->parcelUnits($order);
        $kept = 0;
        foreach ($order->chargedLines() as $line) {
            $lineId = (int) $line->getKey();
            $quantity = (int) $line->getAttribute('quantity');
            $dispatched = (int) $line->getAttribute('dispatched');
            $closed = (int) $line->getAttribute('closed') + ($change[self::KindCancel][$lineId] ?? 0);
            $returned = (int) $line->getAttribute('returned') + ($change[self::KindReturn][$lineId] ?? 0);
            $units = ($quantity - $dispatched - $closed) + ($dispatched - ($parcels['came_back'][$lineId] ?? 0) - $returned);
            $kept += max(0, $units) * Money::cents((string) $line->getAttribute('unit_price'));
        }

        return $kept;
    }

    /** @param array<int, int> $units */
    private function chargedValueCents(Order $order, array $units): int
    {
        $order->loadMissing('lines');
        $value = 0;
        foreach ($order->chargedLines() as $line) {
            $value += ($units[(int) $line->getKey()] ?? 0) * Money::cents((string) $line->getAttribute('unit_price'));
        }

        return $value;
    }

    /** Paid money not given back yet. */
    public function paidLeftCents(Order $order): int
    {
        return max(0, Money::cents((string) $order->paid) - $this->refundedCents($order));
    }

    private function refundedCents(Order $order): int
    {
        return Money::cents((string) DB::table('order_refunds')->where('order_id', $order->id)->sum('amount'));
    }

    /** Money paid from the partner's account that has not gone back to it yet. */
    private function accountLeftCents(Order $order): int
    {
        $back = Money::cents((string) DB::table('order_refunds')->where('order_id', $order->id)->sum('to_account'));

        return max(0, $this->accountPayment->paidFromAccount($order) - $back);
    }

    private function hasPostedCommission(Order $order): bool
    {
        return DB::table('commission_batches')->where('order_id', $order->id)->exists();
    }

    private function unsentOf(Model $line): int
    {
        return max(0, (int) $line->getAttribute('quantity') - (int) $line->getAttribute('dispatched') - (int) $line->getAttribute('closed'));
    }

    /** @param array{delivered?: array<int, int>} $parcels */
    private function returnableOf(Model $line, array $parcels): int
    {
        return max(0, ($parcels['delivered'][(int) $line->getKey()] ?? 0) - (int) $line->getAttribute('returned'));
    }

    /**
     * Units by order line in delivered parcels, parcels on the road, and parcels the courier
     * brought back.
     *
     * @return array{delivered: array<int, int>, open: array<int, int>, came_back: array<int, int>}
     */
    private function parcelUnits(Order $order): array
    {
        $order->loadMissing('lines');
        $units = ['delivered' => [], 'open' => [], 'came_back' => []];
        foreach (DB::table('shipments')->where('order_id', $order->id)->get(['status', 'lines']) as $parcel) {
            $bucket = match ($parcel->status) {
                'delivered' => 'delivered',
                'returned' => 'came_back',
                default => 'open',
            };
            foreach (json_decode((string) $parcel->lines, true, 512, JSON_THROW_ON_ERROR) as $sent) {
                $units[$bucket][(int) $sent['line_id']] = ($units[$bucket][(int) $sent['line_id']] ?? 0) + (int) $sent['quantity'];
            }
        }
        if ($order->status === 'delivered') {
            // Older delivered orders may have goods sent without a parcel record: what was sent arrived.
            foreach ($order->lines as $line) {
                $lineId = (int) $line->getKey();
                $recorded = ($units['delivered'][$lineId] ?? 0) + ($units['open'][$lineId] ?? 0) + ($units['came_back'][$lineId] ?? 0);
                $missing = (int) $line->getAttribute('dispatched') - $recorded;
                if ($missing > 0) {
                    $units['delivered'][$lineId] = ($units['delivered'][$lineId] ?? 0) + $missing;
                }
            }
        }

        return $units;
    }

    /** @return list<array{line_id: int, quantity: int}> */
    private function allUnsent(Order $order): array
    {
        $order->loadMissing('lines');

        return $order->lines->map(fn (Model $line): array => ['line_id' => (int) $line->getKey(), 'quantity' => $this->unsentOf($line)])->values()->all();
    }

    private function returnBranch(Order $order, ?int $branchId): CompanyBranch
    {
        $id = $branchId ?: $order->fulfillment_branch_id
            ?: (int) CompanyBranch::query()->active()->where('is_stock_hub', true)->orderByRaw('COALESCE(is_head_office, false) DESC')->orderBy('id')->value('id');
        $branch = CompanyBranch::query()->whereKey($id)->first();
        if ($branch === null || ! $branch->is_active || ! $branch->is_stock_hub) {
            throw ValidationException::withMessages(['branch_id' => __('portal.err_branch_not_stock_hub')]);
        }

        return $branch;
    }

    private function assertStaff(User $actor, Order $order): void
    {
        abort_unless(self::canHandle($actor), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        abort_unless($this->branches->mayOpenOrder($actor, $order), 403);
    }

    private function assertNoOpenRequest(Order $order): void
    {
        if (DB::table('order_return_requests')->where('order_id', $order->id)->whereIn('status', self::OpenStatuses)->exists()) {
            throw ValidationException::withMessages(['request' => __('orders_stock.err_open_exists')]);
        }
    }

    private function refundCents(string $refund): int
    {
        $refund = trim($refund);
        if ($refund === '') {
            return 0;
        }
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $refund) !== 1) {
            throw ValidationException::withMessages(['refund_amount' => __('orders_stock.err_refund_format')]);
        }

        return Money::cents($refund);
    }

    private function lockedRequest(int $id): object
    {
        $request = DB::table('order_return_requests')->where('id', $id)->lockForUpdate()->first();
        abort_if($request === null, 404);

        return $request;
    }

    /** @return array<int, int> */
    private function storedUnits(object $request): array
    {
        $units = [];
        foreach (json_decode((string) $request->lines, true, 512, JSON_THROW_ON_ERROR) as $row) {
            $units[(int) $row['line_id']] = (int) $row['quantity'];
        }

        return $units;
    }

    /**
     * @param  array<int, int>  $units
     * @return list<array{line_id: int, quantity: int}>
     */
    private function unitsAsLines(array $units): array
    {
        return array_map(fn (int $lineId, int $quantity): array => ['line_id' => $lineId, 'quantity' => $quantity], array_keys($units), array_values($units));
    }

    /**
     * @param  array<int, int>  $units
     * @return list<array{line_id: int, quantity: int}>
     */
    private function linesForRecord(array $units): array
    {
        return $this->unitsAsLines($units);
    }

    /** @param array<int, int> $units */
    private function insertRequest(Order $order, User $by, string $kind, array $units, string $reason): int
    {
        return (int) DB::table('order_return_requests')->insertGetId([
            'order_id' => $order->id, 'user_id' => $order->user_id, 'kind' => $kind, 'status' => self::StatusRequested,
            'lines' => json_encode($this->linesForRecord($units), JSON_THROW_ON_ERROR), 'reason' => $reason, 'requested_by' => $by->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $replace values; a value naming a translation key is translated in the partner's language */
    private function tellPartner(Order $order, string $titleKey, string $bodyKey, array $replace): void
    {
        $locale = User::find($order->user_id)?->preferredLocale() ?? app()->getLocale();
        $values = ['number' => $order->number];
        foreach ($replace as $key => $value) {
            $values[$key] = match (true) {
                is_string($value) && str_starts_with($value, 'orders_stock.') => __($value, [], $locale),
                $key === 'reason' => ReasonFields::shown((string) $value, $locale),
                default => (string) $value,
            };
        }
        Audit::notice((int) $order->user_id, __($titleKey, $values, $locale), __($bodyKey, $values, $locale));
    }

    /** @param array<string, mixed> $metadata */
    private function event(Order $order, User $actor, string $action, array $metadata = []): void
    {
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}

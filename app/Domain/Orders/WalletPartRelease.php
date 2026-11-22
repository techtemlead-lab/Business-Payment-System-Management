<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionWallet;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Wallet + another method": the wallet part leaves the wallet when the order is placed. When the
 * rest never comes (the other payment is turned down, or was never sent) that money must not stay
 * stuck on an order nobody decides:
 *
 * - the partner may cancel such an order alone, at once, while it is not approved and nothing was
 *   sent; the wallet part goes back through the usual return path (refund row, journal, wallet,
 *   order history, audit, notice);
 * - after `madina.wallet_part_unpaid_days` days (default 3) with nothing waiting to be checked, the
 *   order is cancelled by itself the same way (`madina:release-unpaid-wallet-orders`, daily).
 *
 * Every step runs under the money lock and the order lock; a cancelled order is never touched
 * again, so a repeat does nothing.
 */
final class WalletPartRelease
{
    public const ActionCancelled = 'order.wallet_part_cancelled';

    public const ActionReminded = 'order.wallet_part_reminded';

    public function __construct(private readonly AccountOrderPayment $accountPayment) {}

    /** Days the rest of a wallet-part order may stay unpaid before the order is cancelled by itself. */
    public static function unpaidDays(): int
    {
        return max(1, (int) config('madina.wallet_part_unpaid_days', 3));
    }

    /**
     * True for an order not approved yet whose only verified money is a wallet part and whose rest
     * is still due, with nothing
     * sent, no commission posted, no refund yet, and no bank or mobile payment still waiting to be
     * checked (cash promised at the office is not money yet, so it does not stop the cancel).
     * Call with the order locked.
     */
    public function cancelsAtOnce(Order $order): bool
    {
        $paid = Money::cents((string) $order->paid);

        return in_array($order->status, ['submitted', 'held'], true)
            && ! $order->is_opening_balance
            && $paid > 0
            // Only while the rest is still due: an order paid in full keeps the normal cancel request.
            && Money::cents($order->outstanding()) > 0
            && $this->accountPayment->paidFromAccount($order) === $paid
            && ! DB::table('shipments')->where('order_id', $order->id)->exists()
            && ! DB::table('order_refunds')->where('order_id', $order->id)->exists()
            && ! DB::table('commission_batches')->where('order_id', $order->id)->exists()
            && ! DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')
                ->where('channel', '<>', OrderService::OfficeChannel)->exists();
    }

    /**
     * Cancels the order and puts the wallet part back. Runs inside the caller's transaction, with
     * the money lock and the order lock held, after {@see cancelsAtOnce()} said yes.
     */
    public function cancel(User $actor, Order $order, string $reason, bool $automatic = false): void
    {
        $order->loadMissing('lines');
        foreach ($order->lines as $line) {
            $left = (int) $line->getAttribute('quantity') - (int) $line->getAttribute('dispatched') - (int) $line->getAttribute('closed');
            if ($left > 0) {
                $line->increment('closed', $left);
            }
        }
        $order->update(['status' => 'cancelled', 'decision_reason' => $reason]);
        DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'verified_by' => $actor->id, 'reason' => __('orders_stock.claim_closed_reason'), 'updated_at' => now()]);
        $returned = $this->accountPayment->paidFromAccount($order);
        $this->accountPayment->returnForRejectedOrder($order, $actor, $reason);
        $metadata = ['reason' => $reason, 'returned_to_wallet' => Money::decimal($returned), 'automatic' => $automatic];
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => self::ActionCancelled,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record($actor->id, self::ActionCancelled, $order->id, $metadata);
        $partner = User::find($order->user_id);
        Audit::notifyAdminsAndStaff(['orders', 'finance'], __('order_payment.notice_staff_wallet_cancel_title', ['number' => $order->number]),
            __($automatic ? 'order_payment.notice_staff_wallet_cancel_auto_body' : 'order_payment.notice_staff_wallet_cancel_body', [
                'name' => (string) $partner?->name, 'number' => $order->number, 'amount' => LocalDigits::text(Money::grouped(Money::decimal($returned))),
            ]));
    }

    /**
     * The other payment of a wallet-part order was turned down: tell the partner what is still due,
     * by when, and that the wallet part comes back if the rest is not paid. Runs after the rejection
     * is saved; does nothing for any other order.
     */
    public function remindAfterRejection(Order $order): void
    {
        if (! in_array($order->status, ['submitted', 'held'], true) || $this->accountPayment->paidFromAccount($order) === 0 || Money::cents($order->outstanding()) === 0) {
            return;
        }
        $partner = User::find($order->user_id);
        $locale = $partner?->preferredLocale() ?? app()->getLocale();
        $replace = ['number' => $order->number, 'rest' => LocalDigits::text(Money::grouped($order->outstanding()), $locale),
            'wallet' => LocalDigits::text(Money::grouped(Money::decimal($this->accountPayment->paidFromAccount($order))), $locale),
            'days' => LocalDigits::text((string) self::unpaidDays(), $locale)];
        Audit::notice($order->user_id, __('order_payment.notice_wallet_rest_title', $replace, $locale), __('order_payment.notice_wallet_rest_body', $replace, $locale));
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => null, 'action' => self::ActionReminded,
            'metadata' => json_encode(['rest' => $order->outstanding(), 'days' => self::unpaidDays()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    /**
     * Wallet-part orders whose rest has been unpaid for the set number of days, with nothing waiting
     * to be checked, counted from the last turned-down payment or, when no other payment was ever
     * sent, from the order itself.
     *
     * @return list<string> order ids
     */
    public function overdue(CarbonImmutable $now, ?string $onlyOrderId = null): array
    {
        $cutoff = $now->subDays(self::unpaidDays());

        return DB::table('orders')
            ->when($onlyOrderId !== null, fn (Builder $query) => $query->where('orders.id', $onlyOrderId))
            ->whereIn('status', ['submitted', 'held'])
            ->where('is_opening_balance', false)
            ->whereRaw('orders.paid < orders.total - orders.due_credit')
            ->whereExists(fn (Builder $query) => $query->from('receipts')->whereColumn('receipts.order_id', 'orders.id')->where('receipts.channel', AccountOrderPayment::Channel))
            // A bank or mobile payment still waiting to be checked keeps the order; cash promised at the office does not.
            ->whereNotExists(fn (Builder $query) => $query->from('payment_claims')->whereColumn('payment_claims.order_id', 'orders.id')
                ->where('payment_claims.status', 'pending')->where('payment_claims.channel', '<>', OrderService::OfficeChannel))
            ->whereRaw("COALESCE((SELECT MAX(c.updated_at) FROM payment_claims c WHERE c.order_id = orders.id AND c.channel <> ? AND (c.status = 'rejected' OR (c.status = 'pending' AND c.channel = ?))), orders.created_at) <= ?",
                [AccountOrderPayment::Channel, OrderService::OfficeChannel, $cutoff->toDateTimeString()])
            ->orderBy('orders.created_at')
            ->pluck('orders.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Cancels one overdue order and returns its wallet part. Its own transaction; an order that
     * changed meanwhile (paid, approved, cancelled, a new payment waiting) is left alone.
     */
    public function expire(string $orderId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($orderId, $now): bool {
            CommissionWallet::lock();
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            if ($order === null || $this->overdue($now, $orderId) === [] || ! $this->cancelsAtOnce($order)) {
                return false;
            }
            $partner = User::findOrFail($order->user_id);
            $locale = $partner->preferredLocale();
            $reason = __('order_payment.wallet_auto_cancel_reason', ['days' => LocalDigits::text((string) self::unpaidDays(), $locale)], $locale);
            // Nobody pressed a button: the partner's own order is recorded as cancelled for them.
            $this->cancel($partner, $order, $reason, automatic: true);

            return true;
        }, 3);
    }
}

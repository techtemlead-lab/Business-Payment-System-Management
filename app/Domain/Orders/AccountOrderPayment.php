<?php

namespace App\Domain\Orders;

use App\Domain\Commissions\CommissionWallet;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "From My Account": an order paid straight from the money a partner can use now.
 *
 * Reads and spends `available` only. `held` and `commission_held` are never touched. Every
 * write happens inside the caller's transaction, after the same money lock and user row
 * lock a withdrawal takes, so two orders at the same moment run one after the other and the
 * second sees the lowered balance.
 */
final class AccountOrderPayment
{
    public const Channel = 'Account';

    public const PaidEvent = 'payment.account_paid';

    public const ReturnedEvent = 'payment.account_returned';

    public function __construct(private readonly CommissionWallet $wallet) {}

    /**
     * What the order form and the order page need to offer this method.
     *
     * @return array{account_available: string, account_usable: bool, account_block: null|'debt'|'off'}
     */
    public function options(User $partner): array
    {
        $block = $this->block($partner->id);

        return [
            'account_available' => Money::decimal(Money::cents((string) (DB::table('wallets')->where('user_id', $partner->id)->value('available') ?? '0.00'))),
            'account_usable' => $block === null,
            'account_block' => $block,
        ];
    }

    /**
     * Takes the whole amount still due on the order from the buyer's usable balance.
     * Must run inside a transaction. The order row is written here, so pass a locked or
     * just-created one.
     */
    public function pay(Order $order, User $buyer, ?User $submittedBy = null): void
    {
        CommissionWallet::lock();
        $this->assertMoneyMovementIsOn();
        if ($submittedBy !== null) {
            $this->refuse(__('order_payment.err_account_staff'));
        }
        if (CommissionWallet::hasDebt($buyer->id)) {
            $this->refuse(__('order_payment.err_account_debt'));
        }
        $wallet = DB::table('wallets')->where('user_id', $buyer->id)->lockForUpdate()->first();
        $available = $wallet ? Money::cents($wallet->available) : 0;
        $cents = Money::cents($order->outstanding());
        if ($wallet === null || $available < $cents) {
            $this->refuse(__('order_payment.err_account_short', [
                'balance' => $this->taka($available),
                'total' => $this->taka($cents),
                'short' => $this->taka($cents - $available),
            ]));
        }

        $amount = Money::decimal($cents);
        $reference = self::reference($order);
        $now = now();
        $claimId = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => self::Channel, 'reference' => $reference,
            'amount' => $amount, 'status' => 'verified', 'verified_by' => null, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claimId, 'channel' => self::Channel, 'reference' => $reference,
            'amount' => $amount, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => Money::decimal($available - $cents), 'updated_at' => $now]);
        $locale = $buyer->preferredLocale();
        $this->wallet->journal('order.account_pay.'.$order->id, __('order_payment.jr_account_pay', ['number' => $order->number], $locale), [
            ['account' => 'wallet_liability', 'user_id' => $buyer->id, 'debit' => $cents, 'credit' => 0],
            ['account' => 'wallet_order_clearing', 'user_id' => null, 'debit' => 0, 'credit' => $cents],
        ]);
        $order->update(['paid' => Money::decimal(Money::cents((string) $order->paid) + $cents)]);

        $this->record($order, $buyer, self::PaidEvent, ['claim_id' => $claimId, 'channel' => self::Channel, 'reference' => $reference,
            'amount' => $amount, 'order_paid_after' => $order->paid, 'order_total' => $order->total]);
        Audit::notice(
            $buyer->id,
            __('order_payment.notice_paid_title', [], $locale),
            __('order_payment.notice_paid_body', ['number' => $order->number, 'amount' => LocalDigits::text(Money::grouped($amount), $locale)], $locale),
        );
    }

    /** How much of this order was paid from the partner's account, in paisa. */
    public function paidFromAccount(Order $order): int
    {
        return Money::cents((string) DB::table('receipts')->where('order_id', $order->id)->where('channel', self::Channel)->sum('amount'));
    }

    /**
     * Staff rejected an order that was paid wholly from the account: the money goes back
     * at once, in the rejection's own transaction.
     */
    public function returnForRejectedOrder(Order $order, User $actor, string $reason): void
    {
        $cents = $this->paidFromAccount($order);
        $refundId = (string) Str::uuid();
        $now = now();
        DB::table('order_refunds')->insert(['id' => $refundId, 'order_id' => $order->id, 'user_id' => $order->user_id, 'amount' => Money::decimal($cents),
            'paid_before' => Money::decimal($cents), 'to_account' => Money::decimal($cents), 'reason' => $reason, 'refunded_by' => $actor->id,
            'created_at' => $now, 'updated_at' => $now]);
        $this->giveBack($order, $actor, $cents, $refundId);
    }

    /**
     * Puts money back in the partner's usable balance and records why. Anything they still
     * owe from a returned order is cleared first, as with any other money coming in.
     */
    public function giveBack(Order $order, User $actor, int $cents, string $refundId): void
    {
        $locale = User::find($order->user_id)?->preferredLocale() ?? app()->getLocale();
        $this->wallet->journal('order.account_return.'.$refundId, __('order_payment.jr_account_return', ['number' => $order->number], $locale), [
            ['account' => 'wallet_order_clearing', 'user_id' => null, 'debit' => $cents, 'credit' => 0],
            ['account' => 'wallet_liability', 'user_id' => $order->user_id, 'debit' => 0, 'credit' => $cents],
        ]);
        $this->wallet->credit($order->user_id, $cents);
        $this->record($order, $actor, self::ReturnedEvent, ['refund_id' => $refundId, 'amount' => Money::decimal($cents)]);
        Audit::notice(
            $order->user_id,
            __('order_payment.notice_returned_title', [], $locale),
            __('order_payment.notice_returned_body', ['number' => $order->number, 'amount' => LocalDigits::text(Money::grouped(Money::decimal($cents)), $locale)], $locale),
        );
    }

    public static function reference(Order $order): string
    {
        return 'ACC-'.$order->number;
    }

    /** Why this partner cannot pay from their account right now, or null when they can. */
    private function block(int $userId): ?string
    {
        try {
            CommissionWallet::requireActive();
        } catch (ValidationException) {
            return 'off';
        }

        return CommissionWallet::hasDebt($userId) ? 'debt' : null;
    }

    private function assertMoneyMovementIsOn(): void
    {
        try {
            CommissionWallet::requireActive();
        } catch (ValidationException) {
            $this->refuse(__('order_payment.err_account_off'));
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['channel' => $message]);
    }

    private function taka(int $cents): string
    {
        return LocalDigits::text(Money::grouped(Money::decimal($cents)));
    }

    /** @param array<string, mixed> $metadata */
    private function record(Order $order, User $actor, string $action, array $metadata): void
    {
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record($actor->id, $action, $order->id, $metadata);
    }
}

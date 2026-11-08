<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Collection;
use stdClass;

/**
 * What an order page should tell the partner about paying. This only decides the wording
 * on the screen; it never changes an amount.
 */
final class OrderPaymentPrompt
{
    /** Nobody is asked to pay for an order in one of these states. */
    private const NoPaymentStatuses = ['rejected', 'cancelled', 'delivered'];

    /**
     * @param  Collection<int, stdClass>  $claims  The order's payment claims, with amount and status.
     * @return array{ask_for_payment: bool, waiting_for_check: bool, waiting_amount: string}
     */
    public function for(Order $order, Collection $claims): array
    {
        $dueCents = Money::cents($order->outstanding());
        $waitingCents = (int) $claims->where('status', 'pending')->sum(fn (stdClass $claim): int => Money::cents((string) $claim->amount));
        $stillPayable = $dueCents > 0 && ! in_array($order->status, self::NoPaymentStatuses, true);
        $waitingForCheck = $stillPayable && $waitingCents >= $dueCents;

        return [
            'ask_for_payment' => $stillPayable && ! $waitingForCheck,
            'waiting_for_check' => $waitingForCheck,
            'waiting_amount' => Money::decimal($waitingCents),
        ];
    }
}

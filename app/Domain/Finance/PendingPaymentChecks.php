<?php

namespace App\Domain\Finance;

use App\Domain\Orders\AccountOrderPayment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The payments that still wait for an office person to check them. The dashboard task row, the
 * Payments page tab and the Money tasks badge all read this one query, so the number cannot differ
 * between screens. Payments taken from a partner's own balance are checked by the system and never
 * wait here, exactly like the Payments page.
 */
final class PendingPaymentChecks
{
    public static function query(): Builder
    {
        return DB::table('payment_claims')
            ->where('payment_claims.status', 'pending')
            ->where('payment_claims.channel', '<>', AccountOrderPayment::Channel);
    }
}

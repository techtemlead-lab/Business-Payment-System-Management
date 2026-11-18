<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two small helps on the order page (OS-11): desks see who else has the order open right now, so
 * two people do not work on it unaware, and the partner can say the parcel reached him, which tells
 * the store to confirm the delivery. The partner's word does not confirm it: commission still
 * posts only on the staff confirmation.
 */
final class OrderWatchers
{
    /** How long a visit counts as "has it open"; the order page refreshes itself every few seconds. */
    private const SeenSeconds = 120;

    public const ReceivedAction = 'order.partner_received';

    /**
     * Notes that this staff member has the order open and returns the other staff who have it open.
     *
     * @return list<string> names
     */
    public function seen(Order $order, User $viewer): array
    {
        if (! $viewer->staff() || (int) $viewer->id === (int) $order->user_id) {
            return [];
        }
        $key = 'order-viewers:'.$order->id;
        $now = now()->getTimestamp();
        /** @var array<int, array{name: string, at: int}> $viewers */
        $viewers = Cache::get($key, []);
        $viewers = array_filter($viewers, fn (array $seen): bool => $now - $seen['at'] <= self::SeenSeconds);
        $others = array_values(array_map(fn (array $seen): string => $seen['name'], array_filter($viewers, fn (int $id): bool => $id !== (int) $viewer->id, ARRAY_FILTER_USE_KEY)));
        $viewers[(int) $viewer->id] = ['name' => (string) $viewer->name, 'at' => $now];
        Cache::put($key, $viewers, self::SeenSeconds * 2);

        return $others;
    }

    /** The partner says a parcel on the road reached him; the store is asked to confirm it. */
    public function received(User $partner, Order $order): void
    {
        abort_unless((int) $order->user_id === (int) $partner->id && in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);
        if (! DB::table('shipments')->where('order_id', $order->id)->where('status', 'dispatched')->exists()) {
            throw ValidationException::withMessages(['shipment' => __('orders_stock.err_received_nothing')]);
        }
        if (DB::table('order_events')->where('order_id', $order->id)->where('action', self::ReceivedAction)->where('created_at', '>', now()->subHour())->exists()) {
            return;
        }
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $partner->id, 'action' => self::ReceivedAction, 'metadata' => '{}', 'created_at' => now()]);
        Audit::notifyAdminsAndStaff(['inventory', 'orders'], __('orders_stock.notice_received_title', ['number' => $order->number]),
            __('orders_stock.notice_received_body', ['name' => $partner->name, 'number' => $order->number]));
    }
}

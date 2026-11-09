<?php

namespace App\Domain\Orders;

use App\Domain\Integrations\CourierGatewayClient;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Where a booked parcel is with the courier: waiting for pickup, picked up, in transit,
 * and finally delivered or returned.
 *
 * The courier's own words (Pathao, Steadfast, RedX, Paperfly) are turned into these five
 * steps. A parcel only moves forward, never back, and stops at delivered or returned. Every
 * change goes on the shipment timeline, the order history and the audit log, and the dealer
 * gets a notice. "Delivered by the courier" does not close the order by itself: staff still
 * confirm the delivery, which is what releases the dealer's commission.
 */
final class ShipmentTracking
{
    public const Statuses = ['waiting_pickup', 'picked_up', 'in_transit', 'delivered', 'returned'];

    public const Finished = ['delivered', 'returned'];

    /** A parcel the courier was asked about less than this many minutes ago is not asked again when its order is opened. */
    public const RefreshAfterMinutes = 5;

    private const Order = ['waiting_pickup' => 0, 'picked_up' => 1, 'in_transit' => 2, 'delivered' => 3, 'returned' => 3];

    public function __construct(private readonly CourierGatewayClient $couriers) {}

    /**
     * Turns the courier's status text into one of the five steps, or null when it says nothing clear.
     */
    public static function normalize(?string $raw): ?string
    {
        $status = (string) preg_replace('/[\s\-]+/', '_', Str::lower(trim((string) $raw)));
        if ($status === '' || is_numeric($status)) {
            return null;
        }
        if (str_contains($status, 'return') || str_contains($status, 'cancel')) {
            return 'returned';
        }
        if (preg_match('/^(partial_)?deliver(ed|y)(_approval_pending)?$/', $status) === 1) {
            return 'delivered';
        }
        if (str_contains($status, 'picked')) {
            return 'picked_up';
        }
        foreach (['transit', 'hub', 'branch', 'sorting', 'delivery', 'ready', 'progress', 'hold', 'out_for', 'dispatched', 'shipped'] as $word) {
            if (str_contains($status, $word)) {
                return 'in_transit';
            }
        }
        foreach (['pickup', 'pending', 'requested', 'review', 'placed', 'created', 'booked', 'accepted', 'initiated'] as $word) {
            if (str_contains($status, $word)) {
                return 'waiting_pickup';
            }
        }

        return null;
    }

    /**
     * One line on the shipment's tracking timeline.
     *
     * @return array{status: string, raw: ?string, at: string, source: string}
     */
    public static function timelineEntry(string $status, ?string $raw, string $source): array
    {
        return ['status' => $status, 'raw' => $raw !== null ? Str::limit($raw, 100) : null, 'at' => now()->toIso8601String(), 'source' => $source];
    }

    /**
     * The columns that put a parcel on the "delivered" step when staff confirm its delivery, so
     * the courier's last word cannot be left showing beside a finished parcel.
     *
     * @return array<string, mixed>
     */
    public static function confirmedDelivered(object $shipment): array
    {
        if ($shipment->courier_status === 'delivered') {
            return [];
        }
        $timeline = json_decode((string) ($shipment->courier_timeline ?? '[]'), true) ?: [];
        $timeline[] = self::timelineEntry('delivered', null, 'staff');

        return ['courier_status' => 'delivered', 'courier_status_at' => now(), 'courier_timeline' => json_encode($timeline, JSON_THROW_ON_ERROR)];
    }

    /**
     * Whether the courier can still be asked about this parcel.
     */
    public static function isTrackable(?object $shipment): bool
    {
        return $shipment !== null
            && $shipment->courier_booking_status === 'booked'
            && $shipment->status === 'dispatched'
            && filled($shipment->courier_provider)
            && filled($shipment->tracking)
            && ! in_array($shipment->courier_status, self::Finished, true);
    }

    /**
     * Whether opening the parcel's order should ask the courier for news: the parcel is still
     * moving and the courier was last asked more than a few minutes ago.
     */
    public static function isDueForRefresh(?object $shipment): bool
    {
        if ($shipment === null || ! self::isTrackable($shipment)) {
            return false;
        }

        return $shipment->courier_tracked_at === null
            || Carbon::parse($shipment->courier_tracked_at)->addMinutes(self::RefreshAfterMinutes)->isPast();
    }

    /**
     * Asks the courier about a parcel only if it is still due. A job that waited behind others
     * in the queue may find the courier was already asked in the meantime.
     */
    public function refreshIfDue(int $shipmentId): ?string
    {
        return self::isDueForRefresh(DB::table('shipments')->where('id', $shipmentId)->first()) ? $this->refresh($shipmentId) : null;
    }

    /**
     * Asks the courier where the parcel is. Returns the new step when it moved forward.
     */
    public function refresh(int $shipmentId): ?string
    {
        $shipment = DB::table('shipments')->where('id', $shipmentId)->first();
        if (! self::isTrackable($shipment)) {
            return null;
        }
        // Pathao looks parcels up by consignment id, the others by tracking number.
        $reference = $shipment->courier_provider === 'pathao' && filled($shipment->courier_consignment_id)
            ? (string) $shipment->courier_consignment_id
            : (string) $shipment->tracking;
        // Paperfly looks parcels up by the merchant's own order reference, the invoice sent when booking.
        if ($shipment->courier_provider === 'paperfly') {
            $reference = Order::query()->whereKey($shipment->order_id)->value('number').'-'.$shipment->id;
        }
        $result = $this->couriers->track((string) $shipment->courier_provider, $reference);
        $answered = $result['success'] && filled($result['status']);
        DB::table('shipments')->where('id', $shipmentId)->update(['courier_tracked_at' => now(), ...($answered ? ['courier_answered_at' => now()] : [])]);
        if (! $answered) {
            return null;
        }

        return $this->apply($shipmentId, $result['step'] ?? self::normalize($result['status']), (string) $result['status'], null);
    }

    /**
     * Store staff: the courier has collected the parcel from the branch.
     */
    public function markPickedUp(User $actor, Order $order, int $shipmentId): void
    {
        abort_unless($actor->allows('inventory'), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        $shipment = DB::table('shipments')->where('id', $shipmentId)->where('order_id', $order->id)->first();
        abort_if($shipment === null, 404);
        // Before the booking succeeds there is nothing to collect, and the booking would reset the step.
        if ($shipment->courier_booking_status !== 'booked' || $shipment->status !== 'dispatched' || ! in_array($shipment->courier_status, [null, 'waiting_pickup'], true)) {
            throw ValidationException::withMessages(['shipment' => __('courier.err_pickup_not_waiting')]);
        }
        $this->apply($shipmentId, 'picked_up', null, $actor);
    }

    /**
     * Store staff, for a parcel no courier system reports on (a manual courier, or a parcel sent
     * with a typed tracking number): type the consignment number and move the parcel forward. A
     * step of "delivered" is still only the courier's word: staff confirm the delivery separately,
     * which is what releases commission.
     */
    public function updateByHand(User $actor, Order $order, int $shipmentId, ?string $status, ?string $tracking): void
    {
        abort_unless($actor->allows('inventory'), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        $tracking = trim((string) $tracking);
        if ($status === null && $tracking === '') {
            throw ValidationException::withMessages(['status' => __('courier_more.err_manual_nothing')]);
        }
        $shipment = DB::table('shipments')->where('id', $shipmentId)->where('order_id', $order->id)->first();
        abort_if($shipment === null, 404);
        if (! in_array($shipment->courier_booking_status, ['manual', null], true) || $shipment->status !== 'dispatched') {
            throw ValidationException::withMessages(['status' => __('courier_more.err_not_manual_parcel')]);
        }
        DB::transaction(function () use ($actor, $order, $shipment, $status, $tracking): void {
            if ($tracking !== '' && $tracking !== (string) $shipment->tracking) {
                DB::table('shipments')->where('id', $shipment->id)->update(['tracking' => $tracking, 'updated_at' => now()]);
                $metadata = ['shipment_id' => $shipment->id, 'from' => $shipment->tracking, 'to' => $tracking];
                DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => 'shipment.consignment_updated',
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                Audit::record($actor->id, 'shipment.consignment_updated', $order->id, $metadata);
            }
            if ($status !== null && $this->apply($shipment->id, $status, null, $actor) === null) {
                throw ValidationException::withMessages(['status' => __('courier_more.err_manual_status_backwards')]);
            }
        }, 3);
    }

    /**
     * Moves the parcel to a new step (forward only) and tells the dealer. Returns the step
     * applied, or null when nothing changed.
     */
    public function apply(int $shipmentId, ?string $status, ?string $raw, ?User $actor): ?string
    {
        if ($status === null || ! isset(self::Order[$status])) {
            if ($raw !== null) {
                DB::table('shipments')->where('id', $shipmentId)->update(['courier_status_raw' => Str::limit($raw, 115)]);
            }

            return null;
        }

        return DB::transaction(function () use ($shipmentId, $status, $raw, $actor): ?string {
            $shipment = DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            $current = $shipment?->courier_status;
            if (! $shipment || in_array($current, self::Finished, true) || ($current !== null && self::Order[$status] <= (self::Order[$current] ?? -1))) {
                if ($shipment && $raw !== null) {
                    DB::table('shipments')->where('id', $shipmentId)->update(['courier_status_raw' => Str::limit($raw, 115)]);
                }

                return null;
            }
            $timeline = json_decode((string) ($shipment->courier_timeline ?? '[]'), true) ?: [];
            $timeline[] = self::timelineEntry($status, $raw, $actor ? 'staff' : 'courier');
            DB::table('shipments')->where('id', $shipmentId)->update([
                'courier_status' => $status,
                'courier_status_raw' => $raw !== null ? Str::limit($raw, 115) : null,
                'courier_status_at' => now(),
                'courier_timeline' => json_encode($timeline, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $order = Order::query()->findOrFail($shipment->order_id);
            $metadata = ['shipment_id' => $shipmentId, 'from' => $current, 'to' => $status, 'courier_said' => $raw];
            DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actor?->id, 'action' => 'shipment.courier_status',
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            Audit::record($actor?->id, 'shipment.courier_status', $order->id, $metadata);
            $this->tell($order, $shipment, $status);

            return $status;
        }, 3);
    }

    private function tell(Order $order, object $shipment, string $status): void
    {
        $partner = User::query()->find($order->user_id);
        if ($partner) {
            $locale = $partner->preferredLocale();
            $replace = ['number' => $order->number, 'courier' => (string) $shipment->courier, 'tracking' => (string) $shipment->tracking,
                'status' => __('courier.status_'.$status, [], $locale)];
            Audit::notice($partner->id, __('courier.notice_status_title', $replace, $locale), __('courier.notice_status_body', $replace, $locale));
        }
        if (in_array($status, self::Finished, true)) {
            $replace = ['number' => $order->number, 'courier' => (string) $shipment->courier, 'status' => __('courier.status_'.$status)];
            Audit::notifyAdminsAndStaff(['orders', 'inventory'], __('courier.notice_staff_finished_title', $replace), __('courier.notice_staff_finished_body_'.$status, $replace));
        }
    }
}

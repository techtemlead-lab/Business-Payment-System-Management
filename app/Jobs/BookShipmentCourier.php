<?php

namespace App\Jobs;

use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Domain\Orders\ShipmentTracking;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Books a saved shipment with the courier company, once the dispatch (or the approval
 * that created it) is committed.
 *
 * The shipment row is claimed (pending -> booking) before the courier is called, so a
 * repeated or retried job never books the same parcel twice. A refused booking is marked
 * "failed": staff see it on the order and the deliveries board, and can try again or
 * switch to another courier. A booking that stops half way is never sent again by itself:
 * it is also marked "failed", with a warning to check with the courier first.
 */
class BookShipmentCourier implements ShouldQueue
{
    use Queueable;

    /** A claimed booking older than this has stopped: the job itself ends after 60 seconds. */
    public const StuckAfterMinutes = 15;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $shipmentId) {}

    /**
     * Execute the job.
     */
    public function handle(CourierGatewayClient $couriers): void
    {
        $shipment = $this->claim();
        if ($shipment === null) {
            return;
        }
        $order = Order::query()->findOrFail($shipment->order_id);
        $partner = User::query()->find($order->user_id);
        /** @var array<string, mixed> $profile */
        $profile = (array) ($partner?->getAttribute('profile') ?? []);
        $placeCode = trim((string) ($profile['place_code'] ?? $profile['area_code'] ?? ''));
        $branch = $shipment->branch_id ? CompanyBranch::query()->find($shipment->branch_id) : null;
        $created = $couriers->create((string) $shipment->courier_provider, [
            // One invoice per shipment, so the courier can also refuse a repeat booking.
            'invoice' => $order->number.'-'.$shipment->id,
            'recipient_name' => $partner?->name,
            'recipient_phone' => $partner?->phone,
            'recipient_address' => collect([
                is_string($profile['address'] ?? null) ? $profile['address'] : null,
                $partner?->union, $partner?->upazila, $partner?->district, $partner?->division,
                $placeCode !== '' ? __('courier.place_code_in_address', ['code' => $placeCode]) : null,
            ])->filter()->implode(', '),
            'amount' => $order->outstanding(),
            'note' => $order->number,
            'pickup_address' => $branch ? collect([$branch->name, $branch->address])->filter()->implode(', ') : null,
        ]);

        if (! $created['success']) {
            $message = ($created['outcome_unknown'] ?? false) ? __('courier.booking_outcome_unknown') : (string) $created['message'];
            DB::table('shipments')->where('id', $shipment->id)->update([
                'courier_booking_status' => 'failed',
                'courier_booking_error' => Str::limit($message, 250),
                'updated_at' => now(),
            ]);
            $this->reportFailure($order, $shipment, $message);

            return;
        }

        $tracking = $created['tracking'] ?: (string) $shipment->tracking;
        DB::table('shipments')->where('id', $shipment->id)->update([
            'tracking' => $tracking,
            'courier_booking_status' => 'booked',
            'courier_consignment_id' => $created['consignment_id'],
            'courier_booking_error' => null,
            // A booked parcel waits at the branch until the courier collects it.
            'courier_status' => 'waiting_pickup',
            'courier_status_at' => now(),
            'courier_timeline' => json_encode([ShipmentTracking::timelineEntry('waiting_pickup', null, 'booking')], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $this->event($order, (int) $shipment->actor_id, 'order.courier_booked', [
            'shipment_id' => $shipment->id,
            'provider' => $shipment->courier_provider,
            'consignment_id' => $created['consignment_id'],
        ]);
        if ($partner) {
            $this->tellPartner($partner, $order, (string) $shipment->courier, $tracking);
        }
    }

    /**
     * The job stopped before the booking finished (an error, a timeout, a crashed worker).
     * Nothing else would ever move it on, so it is handed to staff as a failed booking. A
     * parcel the courier already accepted is left alone.
     */
    public function failed(?Throwable $exception = null): void
    {
        $message = __('courier.booking_outcome_unknown');
        $stopped = DB::table('shipments')->where('id', $this->shipmentId)
            ->whereIn('courier_booking_status', ['pending', 'booking'])
            ->update(['courier_booking_status' => 'failed', 'courier_booking_error' => Str::limit($message, 250), 'updated_at' => now()]);
        if ($stopped === 0) {
            return;
        }
        $shipment = DB::table('shipments')->where('id', $this->shipmentId)->first();
        $this->reportFailure(Order::query()->findOrFail($shipment->order_id), $shipment, $message);
    }

    /**
     * Writes the failed booking to the order history and tells the staff.
     */
    private function reportFailure(Order $order, object $shipment, string $message): void
    {
        $this->event($order, (int) $shipment->actor_id, 'order.courier_booking_failed', [
            'shipment_id' => $shipment->id,
            'provider' => $shipment->courier_provider,
            'message' => $message,
        ]);
        Audit::notifyAdminsAndStaff(
            ['orders', 'inventory'],
            __('courier.notice_booking_failed_title', ['number' => $order->number]),
            __('courier.notice_booking_failed_body', ['number' => $order->number, 'courier' => (string) $shipment->courier, 'message' => $message]),
        );
    }

    /**
     * Takes the booking for this run, or returns null when it is not waiting to be booked.
     */
    private function claim(): ?object
    {
        return DB::transaction(function (): ?object {
            $shipment = DB::table('shipments')->where('id', $this->shipmentId)->lockForUpdate()->first();
            if (! $shipment || $shipment->courier_booking_status !== 'pending' || blank($shipment->courier_provider)) {
                return null;
            }
            DB::table('shipments')->where('id', $shipment->id)->update(['courier_booking_status' => 'booking', 'updated_at' => now()]);

            return $shipment;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(Order $order, int $actorId, string $action, array $metadata): void
    {
        DB::table('order_events')->insert(['order_id' => $order->id, 'actor_id' => $actorId, 'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        Audit::record($actorId, $action, $order->id, $metadata);
    }

    private function tellPartner(User $partner, Order $order, string $courier, string $tracking): void
    {
        $locale = $partner->preferredLocale();
        $replace = ['number' => $order->number, 'courier' => $courier, 'tracking' => $tracking];
        Audit::notice(
            $partner->id,
            __('portal.notice_shipment_dispatched_title', $replace, $locale),
            __('portal.notice_shipment_dispatched_body', $replace, $locale),
        );
        app(PartnerEventSms::class)->send($partner, 'order.dispatched', SmsText::make('sms.shipment_dispatched', $replace));
    }
}

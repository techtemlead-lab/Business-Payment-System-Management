<?php

namespace App\Jobs;

use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Notifications\PartnerEventSms;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Books a saved shipment with the courier company, once the dispatch is committed.
 *
 * The shipment row is claimed (pending -> booking) before the courier is called, so a
 * repeated or retried job never books the same parcel twice. A booking that stops half
 * way stays "booking" for staff to check with the courier instead of being sent again.
 */
class BookShipmentCourier implements ShouldQueue
{
    use Queueable;

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
        $created = $couriers->create((string) $shipment->courier_provider, [
            // One invoice per shipment, so the courier can also refuse a repeat booking.
            'invoice' => $order->number.'-'.$shipment->id,
            'recipient_name' => $partner?->name,
            'recipient_phone' => $partner?->phone,
            'recipient_address' => collect([$partner?->union, $partner?->upazila, $partner?->district, $partner?->division])->filter()->implode(', '),
            'amount' => $order->outstanding(),
            'note' => $order->number,
        ]);

        if (! $created['success']) {
            DB::table('shipments')->where('id', $shipment->id)->update([
                'courier_booking_status' => 'failed',
                'courier_booking_error' => Str::limit((string) $created['message'], 250),
                'updated_at' => now(),
            ]);
            $this->event($order, (int) $shipment->actor_id, 'order.courier_booking_failed', [
                'shipment_id' => $shipment->id,
                'provider' => $shipment->courier_provider,
                'message' => $created['message'],
            ]);

            return;
        }

        $tracking = $created['tracking'] ?: (string) $shipment->tracking;
        DB::table('shipments')->where('id', $shipment->id)->update([
            'tracking' => $tracking,
            'courier_booking_status' => 'booked',
            'courier_consignment_id' => $created['consignment_id'],
            'courier_booking_error' => null,
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
        app(PartnerEventSms::class)->send($partner, 'order.dispatched', __('sms.shipment_dispatched', $replace, $locale));
    }
}

<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\BranchOperations;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\SiteCustomization;
use Illuminate\Support\Facades\DB;

/**
 * The label a packer sticks on a parcel for the courier. Unlike the packing slip it shows the
 * receiver's full name, address and phone, so only staff who may dispatch can print it, and every
 * print goes on the activity log. Partners never reach it.
 */
final class ShippingLabels
{
    /** Orders being packed or already on their way. */
    public const PrintableStatuses = ['approved', 'partially_dispatched', 'dispatched'];

    public function __construct(private readonly BranchOperations $branches) {}

    /**
     * Where the parcel goes: the street address, then the place from smallest to largest.
     */
    public static function deliveryAddress(?User $partner): string
    {
        /** @var array<string, mixed> $profile */
        $profile = (array) ($partner?->getAttribute('profile') ?? []);
        $fromProfile = static fn (string $key): ?string => is_string($profile[$key] ?? null) ? $profile[$key] : null;
        $placeCode = trim((string) ($profile['place_code'] ?? $profile['area_code'] ?? ''));

        return collect([
            $fromProfile('address'),
            $fromProfile('union'),
            $fromProfile('upazila'),
            $partner?->district,
            $partner?->division,
            $placeCode !== '' ? __('courier.place_code_in_address', ['code' => $placeCode]) : null,
        ])->filter()->implode(', ');
    }

    /**
     * Prints the label for staff who may dispatch this order, and writes it on the activity log.
     *
     * @return array{order: Order, receiver: array{name: string, address: string, phone: string}, sender: array{name: string, address: string, phone: string}, courier: ?string, consignment: ?string, pieces: int}
     */
    public function printFor(User $actor, Order $order): array
    {
        $this->assertMayPrint($actor, $order);
        $order->loadMissing(['user', 'lines', 'fulfillmentBranch']);
        $shipment = DB::table('shipments')->where('order_id', $order->id)->orderByDesc('id')->first(['id', 'courier', 'tracking']);
        Audit::record($actor->id, 'shipment.label_printed', $order->id, [
            'order_number' => $order->number,
            'receiver_id' => $order->user_id,
            'shipment_id' => $shipment?->id,
        ]);
        $company = SiteCustomization::all();
        $consignment = trim((string) ($shipment->tracking ?? ''));
        $receiver = $order->user instanceof User ? $order->user : null;
        $branch = $order->fulfillmentBranch instanceof CompanyBranch ? $order->fulfillmentBranch : null;

        return [
            'order' => $order,
            'receiver' => [
                'name' => (string) $receiver?->name,
                'address' => self::deliveryAddress($receiver),
                'phone' => (string) $receiver?->phone,
            ],
            'sender' => [
                'name' => SiteCustomization::brandName(),
                'address' => (string) ($branch?->address ?: ($company['office_address'] ?? '')),
                'phone' => (string) ($branch?->phone ?: ($company['contact_phone'] ?? '')),
            ],
            'courier' => $shipment?->courier,
            'consignment' => $consignment !== '' ? $consignment : null,
            'pieces' => (int) $order->lines->sum('quantity'),
        ];
    }

    private function assertMayPrint(User $actor, Order $order): void
    {
        $this->branches->assertCompanyHandler($actor);
        abort_unless($actor->allows('inventory') && $actor->canAct('inventory', 'edit'), 403);
        abort_if((int) $actor->id === (int) $order->user_id, 403, __('portal.err_self_review_order'));
        abort_unless($this->branches->mayOpenOrder($actor, $order), 403);
        abort_unless(in_array($order->status, self::PrintableStatuses, true), 422, __('shipping_label.err_not_ready'));
    }
}

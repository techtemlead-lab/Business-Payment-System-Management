<?php

namespace App\Jobs;

use App\Domain\Orders\ShipmentTracking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the courier where one booked parcel is, in the background (for example when the
 * dealer opens the order page), so the page itself never waits for the courier.
 */
class RefreshShipmentTracking implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $shipmentId) {}

    /**
     * Execute the job.
     */
    public function handle(ShipmentTracking $tracking): void
    {
        $tracking->refreshIfDue($this->shipmentId);
    }
}

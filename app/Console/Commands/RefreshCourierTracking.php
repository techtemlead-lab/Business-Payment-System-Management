<?php

namespace App\Console\Commands;

use App\Domain\Orders\ShipmentTracking;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('couriers:refresh-tracking {--limit=200 : Most parcels to check in one run}')]
#[Description('Ask the couriers where every booked, not yet delivered parcel is, and update its status')]
class RefreshCourierTracking extends Command
{
    /** Parcels checked less than this many minutes ago are skipped. */
    public const FreshMinutes = 20;

    /**
     * Checks the parcels that waited longest first. A courier that does not answer is simply
     * asked again on the next run.
     */
    public function handle(ShipmentTracking $tracking): int
    {
        $ids = DB::table('shipments')
            ->where('courier_booking_status', 'booked')
            ->where('status', 'dispatched')
            ->whereNotNull('courier_provider')
            ->where('tracking', '!=', '')
            ->where(fn ($query) => $query->whereNull('courier_status')->orWhereNotIn('courier_status', ShipmentTracking::Finished))
            ->where(fn ($query) => $query->whereNull('courier_tracked_at')->orWhere('courier_tracked_at', '<', now()->subMinutes(self::FreshMinutes)))
            ->orderByRaw('courier_tracked_at ASC NULLS FIRST')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $moved = 0;
        foreach ($ids as $id) {
            if ($tracking->refresh((int) $id) !== null) {
                $moved++;
            }
        }
        $this->info("Checked {$ids->count()} parcels; {$moved} moved to a new step.");

        return self::SUCCESS;
    }
}

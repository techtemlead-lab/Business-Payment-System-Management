<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Intentionally seeds no place codes.
 *
 * Management, divisions, districts, upazilas, and unions are created only by
 * an administrator in the Place codes panel. Optional full Bangladesh registry:
 * `php artisan db:seed --class=BangladeshPlaceCodeSeeder`.
 */
class PlaceCodeSeeder extends Seeder
{
    public function run(): void
    {
        // Empty by product policy — no hardcoded geography rows.
    }
}

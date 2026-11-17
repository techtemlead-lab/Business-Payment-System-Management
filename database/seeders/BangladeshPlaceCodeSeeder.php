<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The full Bangladesh place list. Kept so `php artisan db:seed --class=BangladeshPlaceCodeSeeder`
 * still works; it now does exactly what PlaceCodeSeeder does (fill-only, see DefaultPlaceCatalog).
 */
class BangladeshPlaceCodeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlaceCodeSeeder::class);
    }
}

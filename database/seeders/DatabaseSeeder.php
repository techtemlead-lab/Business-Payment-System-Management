<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CommissionRuleSeeder::class,
            PlaceCodeSeeder::class,
            BangladeshPlaceCodeSeeder::class,
        ]);
        $this->command?->info('No default accounts are seeded. Use madina:admin to create an administrator, or madina:demo for a local synthetic preview.');
    }
}

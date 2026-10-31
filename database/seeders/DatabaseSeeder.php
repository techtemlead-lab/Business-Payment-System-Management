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
            // BangladeshPlaceCodeSeeder is opt-in only — run manually when a full
            // Bangladesh administrative registry is required. Default installs keep
            // blank management + division slots for admin fill-in via the panel.
        ]);
        $this->command?->info('No default accounts are seeded. Use madina:admin to create an administrator, or madina:demo for a local synthetic preview.');
        $this->command?->info('Optional geography: php artisan db:seed --class=BangladeshPlaceCodeSeeder');
    }
}

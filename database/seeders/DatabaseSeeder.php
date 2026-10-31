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
            // Place codes are never seeded by default — admins create Management,
            // divisions, and all lower levels in the Place codes panel.
            // Optional full Bangladesh registry:
            // php artisan db:seed --class=BangladeshPlaceCodeSeeder
        ]);
        $this->command?->info('No default accounts are seeded. Use madina:admin to create an administrator, or madina:demo for a local synthetic preview.');
        $this->command?->info('Place codes start empty. Admins create them in the Place codes panel, or optionally: php artisan db:seed --class=BangladeshPlaceCodeSeeder');
    }
}

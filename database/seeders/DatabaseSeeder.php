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
            // Starter training lessons, saved as drafts until staff publish them.
            TrainingItemSeeder::class,
            // The default Bangladesh place list with its default codes; admins can change any of it in the Place codes panel.
            PlaceCodeSeeder::class,
        ]);
        $this->command?->info('No default accounts are seeded. Use madina:admin to create an administrator, or madina:demo for a local synthetic preview.');
    }
}

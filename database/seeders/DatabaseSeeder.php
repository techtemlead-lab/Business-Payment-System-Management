<?php

namespace Database\Seeders;

use App\Models\RuleVersion;
use App\Services\FinancialCalculator;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! RuleVersion::where('status', 'reference')->exists()) {
            RuleVersion::create(['name' => 'Confirmed MRP model', 'status' => 'reference', 'definition' => FinancialCalculator::definition(), 'reason' => 'Confirmed master prompt. Simulation only; recipients and financial workflow remain undefined.']);
        }
    }
}

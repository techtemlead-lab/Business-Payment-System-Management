<?php

namespace Database\Seeders;

use App\Domain\Commissions\CommissionPolicy;
use App\Models\CommissionRuleParameter;
use Illuminate\Database\Seeder;

class CommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CommissionPolicy::defaults() as $key => $rule) {
            CommissionRuleParameter::firstOrCreate(['rule_key' => $key], $rule);
        }
    }
}

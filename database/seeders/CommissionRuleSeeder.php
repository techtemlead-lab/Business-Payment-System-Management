<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CommissionRuleParameter;

class CommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'rule_key' => 'area_dealer_pool',
                'category' => 'area',
                'name' => 'Area Dealer Commission Pool',
                'description' => '10% total split into 5 tiers (2% each): Management Reserve, Division, District, Upazila, and Union area dealers.',
                'percentage' => 10.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'management_share_pct' => 2.00,
                    'division_share_pct' => 2.00,
                    'district_share_pct' => 2.00,
                    'upazila_share_pct' => 2.00,
                    'union_share_pct' => 2.00,
                    'quotas' => [
                        'union_min_monthly' => 20000.00,
                        'upazila_min_monthly' => 40000.00,
                        'district_min_monthly' => 50000.00,
                        'division_min_monthly' => 50000.00,
                    ]
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'direct_referral',
                'category' => 'referral',
                'name' => 'Direct Referral Commission',
                'description' => '10% direct sponsor reward credited to the referring dealer on all frontline orders.',
                'percentage' => 10.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'dealer_monthly_salary',
                'category' => 'salary',
                'name' => 'Dealer Monthly Salary Pool',
                'description' => '2% pooled monthly across all cash sales and distributed to dealers meeting monthly qualification.',
                'percentage' => 2.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'min_monthly_volume' => 10000.00,
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'own_dealer_cashback',
                'category' => 'cashback',
                'name' => 'Own Dealer Purchase Cashback',
                'description' => '5% cashback credited to the dealer on their own purchase price (cash collected, not MRP).',
                'percentage' => 5.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'rank_royalty',
                'category' => 'rank',
                'name' => 'Leadership Rank Royalty Pool',
                'description' => '4% monthly recurring royalty shared equally across leadership ranks: MO (1%), AM (1%), SM (1%), ME (1%).',
                'percentage' => 4.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'mo_share_pct' => 1.00,
                    'am_share_pct' => 1.00,
                    'sm_share_pct' => 1.00,
                    'me_share_pct' => 1.00,
                    'requirements' => [
                        'mo_min_personal_purchase' => 250000.00,
                        'am_required_mos_in_3gens' => 10,
                        'sm_required_ams_in_3gens' => 10,
                        'me_required_sms_in_3gens' => 10,
                    ]
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'rank_incentive',
                'category' => 'incentive',
                'name' => 'One-Time Rank Achievement Incentive',
                'description' => '2% one-time reward unlocked upon achieving a leadership rank for the first time in a career.',
                'percentage' => 2.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'd2d_multitier',
                'category' => 'd2d',
                'name' => 'Dealer-to-Dealer (D2D) Multi-Tier Overrides',
                'description' => '3% total multi-tier overrides: Generation 2 sponsor gets 2%, Generation 3 sponsor gets 1%.',
                'percentage' => 3.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'gen2_pct' => 2.00,
                    'gen3_pct' => 1.00,
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'company_marketing',
                'category' => 'marketing',
                'name' => 'Company Marketing Reserve Fund',
                'description' => '2% retained in company reserve for national advertising campaigns, branding, and expos.',
                'percentage' => 2.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'car_bike_incentive',
                'category' => 'rewards',
                'name' => 'Car & Bike Asset Incentive Fund',
                'description' => '2% reserved for vehicle rewards. Bike (60 Lakh with 8 Lakh branch cap), Car (25 Crore with 2 Crore branch cap).',
                'percentage' => 2.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'bike_target_amount' => 6000000.00,
                    'bike_leg_cap_amount' => 800000.00,
                    'car_target_amount' => 250000000.00,
                    'car_leg_cap_amount' => 20000000.00,
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'commission_cooling_off',
                'category' => 'operations',
                'name' => 'Commission Holding Period / Cooling-Off',
                'description' => 'Cooling-off return window holding commissions in held balance before maturity release.',
                'percentage' => 0.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'cooling_off_days' => 0,
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'payout_tax_withholding',
                'category' => 'operations',
                'name' => 'Payout TDS Withholding Tax',
                'description' => 'Tax Deducted at Source (TDS) on dealer commission withdrawals (NBR Compliance).',
                'percentage' => 5.00,
                'amount_threshold' => 500.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'min_payout_amount' => 500.00,
                    'tds_pct' => 5.00,
                ],
                'is_active' => true,
                'version' => 1,
            ],
            [
                'rule_key' => 'rank_maintenance',
                'category' => 'rank',
                'name' => 'Rank Maintenance & Grace Period',
                'description' => 'Minimum monthly activity required to retain Paid-As leadership rank status.',
                'percentage' => 0.00,
                'amount_threshold' => 0.00,
                'cap_limit' => 0.00,
                'parameters' => [
                    'mo_min_monthly_volume' => 5000.00,
                    'am_min_monthly_volume' => 15000.00,
                    'sm_min_monthly_volume' => 30000.00,
                    'me_min_monthly_volume' => 50000.00,
                    'grace_period_days' => 30,
                ],
                'is_active' => true,
                'version' => 1,
            ],
        ];

        foreach ($rules as $r) {
            CommissionRuleParameter::updateOrCreate(
                ['rule_key' => $r['rule_key']],
                $r
            );
        }
    }
}

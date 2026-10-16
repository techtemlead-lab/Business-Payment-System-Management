<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use App\Models\User;

class DashboardManagement
{
    private const SLUG = 'dashboard_management';

    public static function defaults(): array
    {
        return [
            // Dealer Dashboard Controls
            'dealer_network_tree_enabled' => true,
            'dealer_ranks_enabled' => true,
            'dealer_rewards_enabled' => true,
            'dealer_orders_enabled' => true,
            'dealer_claims_enabled' => true,
            'dealer_catalog_enabled' => true,
            'dealer_announcement' => 'Welcome to your Madina Authorized Dealer Workspace. Order products and review network volume securely.',
            'dealer_announcement_type' => 'info',
            'dealer_min_order' => '0.00',
            'dealer_maintenance_mode' => false,

            // Sub-Dealer Dashboard Controls
            'subdealer_salary_enabled' => true,
            'subdealer_orders_enabled' => true,
            'subdealer_deliveries_enabled' => true,
            'subdealer_catalog_enabled' => true,
            'subdealer_announcement' => 'Welcome to your Sub-Dealer Portal. 40% margin orders and monthly salary tiers are active.',
            'subdealer_announcement_type' => 'info',
            'subdealer_min_order' => '0.00',
            'subdealer_maintenance_mode' => false,

            // Employee Dashboard Controls
            'employee_default_permissions' => ['orders', 'inventory'],
            'employee_announcement' => 'All actions on customer accounts, inventory, and orders are logged in the append-only company audit trail.',
            'employee_announcement_type' => 'info',

            // Company & Super Dashboard Controls
            'company_treasury_enabled' => true,
            'company_commissions_enabled' => true,
            'company_customization_enabled' => true,
            'company_audit_enabled' => true,
        ];
    }

    public static function get(): array
    {
        $row = DB::table('content_pages')->where('slug', self::SLUG)->first();
        if (!$row || empty($row->body)) {
            return self::defaults();
        }
        $data = json_decode($row->body, true);
        if (!is_array($data)) {
            return self::defaults();
        }
        return array_merge(self::defaults(), $data);
    }

    public static function save(array $settings): void
    {
        $clean = array_intersect_key($settings, self::defaults());
        DB::table('content_pages')->updateOrInsert(
            ['slug' => self::SLUG],
            [
                'title' => 'Dashboard Management Configuration',
                'body' => json_encode($clean),
                'published' => true,
                'updated_at' => now(),
            ]
        );
    }

    public static function reset(): void
    {
        DB::table('content_pages')->where('slug', self::SLUG)->delete();
    }

    public static function noticeForUser(?User $user): ?array
    {
        if (!$user) return null;
        $settings = self::get();

        if ($user->role === 'dealer') {
            return [
                'text' => $settings['dealer_announcement'] ?? '',
                'type' => $settings['dealer_announcement_type'] ?? 'info',
                'maintenance' => (bool)($settings['dealer_maintenance_mode'] ?? false),
            ];
        } elseif ($user->role === 'sub_dealer') {
            return [
                'text' => $settings['subdealer_announcement'] ?? '',
                'type' => $settings['subdealer_announcement_type'] ?? 'info',
                'maintenance' => (bool)($settings['subdealer_maintenance_mode'] ?? false),
            ];
        } elseif ($user->role === 'employee') {
            return [
                'text' => $settings['employee_announcement'] ?? '',
                'type' => $settings['employee_announcement_type'] ?? 'info',
                'maintenance' => false,
            ];
        }
        return null;
    }
}

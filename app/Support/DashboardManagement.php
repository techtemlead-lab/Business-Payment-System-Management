<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'dealer_announcement' => 'Welcome to your dealer workspace. Order products and review network volume here.',
            'dealer_announcement_type' => 'info',
            'dealer_min_order' => '0.00',
            'dealer_maintenance_mode' => false,

            // Sub-Dealer Dashboard Controls
            'subdealer_salary_enabled' => true,
            'subdealer_orders_enabled' => true,
            'subdealer_deliveries_enabled' => true,
            'subdealer_catalog_enabled' => true,
            'subdealer_announcement' => 'Welcome to your sub-dealer workspace. Product-priced orders and monthly flat 3% salary are active.',
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
        if (! $row || empty($row->body)) {
            return self::defaults();
        }
        $data = json_decode($row->body, true);
        if (! is_array($data)) {
            return self::defaults();
        }

        return array_merge(self::defaults(), $data);
    }

    public static function save(array $settings): void
    {
        $defaults = self::defaults();
        $clean = array_intersect_key($settings, $defaults);
        foreach (['dealer_network_tree_enabled', 'dealer_ranks_enabled', 'dealer_rewards_enabled', 'dealer_orders_enabled', 'dealer_claims_enabled', 'dealer_catalog_enabled', 'dealer_maintenance_mode', 'subdealer_salary_enabled', 'subdealer_orders_enabled', 'subdealer_deliveries_enabled', 'subdealer_catalog_enabled', 'subdealer_maintenance_mode', 'company_treasury_enabled', 'company_commissions_enabled', 'company_customization_enabled', 'company_audit_enabled'] as $flag) {
            if (array_key_exists($flag, $clean)) {
                $clean[$flag] = filter_var($clean[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['dealer_announcement_type', 'subdealer_announcement_type', 'employee_announcement_type'] as $typeKey) {
            if (array_key_exists($typeKey, $clean) && ! in_array($clean[$typeKey], ['info', 'warning', 'danger', 'success'], true)) {
                $clean[$typeKey] = $defaults[$typeKey];
            }
        }
        foreach (['dealer_announcement', 'subdealer_announcement', 'employee_announcement'] as $textKey) {
            if (array_key_exists($textKey, $clean)) {
                $clean[$textKey] = mb_substr(trim((string) $clean[$textKey]), 0, 500);
            }
        }
        foreach (['dealer_min_order', 'subdealer_min_order'] as $moneyKey) {
            if (! array_key_exists($moneyKey, $clean)) {
                continue;
            }
            $amount = (string) $clean[$moneyKey];
            if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount)) {
                throw ValidationException::withMessages([$moneyKey => 'Enter a valid minimum order amount.']);
            }
            $clean[$moneyKey] = Money::decimal(Money::cents($amount));
        }
        if (array_key_exists('employee_default_permissions', $clean)) {
            $known = array_unique(array_merge(...array_values(config('madina.roles'))));
            $clean['employee_default_permissions'] = array_values(array_intersect(
                array_map('strval', (array) $clean['employee_default_permissions']),
                $known,
            ));
        }

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

    /**
     * Enforce dashboard maintenance lockout and optional minimum order for partners.
     */
    public static function assertPartnerCanOrder(User $user, int $orderTotalCents): void
    {
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            return;
        }
        $settings = self::get();
        $maintenance = $user->role === 'dealer'
            ? (bool) ($settings['dealer_maintenance_mode'] ?? false)
            : (bool) ($settings['subdealer_maintenance_mode'] ?? false);
        if ($maintenance) {
            throw ValidationException::withMessages([
                'items' => $user->role === 'dealer'
                    ? 'Dealer ordering is temporarily paused for maintenance. Please try again later.'
                    : 'Sub-dealer ordering is temporarily paused for maintenance. Please try again later.',
            ]);
        }
        $ordersEnabled = $user->role === 'dealer'
            ? (bool) ($settings['dealer_orders_enabled'] ?? true)
            : (bool) ($settings['subdealer_orders_enabled'] ?? true);
        if (! $ordersEnabled) {
            throw ValidationException::withMessages(['items' => 'Ordering is currently disabled for your account type.']);
        }
        $minimum = $user->role === 'dealer'
            ? (string) ($settings['dealer_min_order'] ?? '0.00')
            : (string) ($settings['subdealer_min_order'] ?? '0.00');
        $minimumCents = Money::cents($minimum);
        if ($minimumCents > 0 && $orderTotalCents < $minimumCents) {
            throw ValidationException::withMessages([
                'items' => 'Minimum order amount is BDT '.Money::decimal($minimumCents).'.',
            ]);
        }
    }

    public static function reset(): void
    {
        DB::table('content_pages')->where('slug', self::SLUG)->delete();
    }

    public static function noticeForUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }
        $settings = self::get();

        if ($user->role === 'dealer') {
            return [
                'text' => $settings['dealer_announcement'] ?? '',
                'type' => $settings['dealer_announcement_type'] ?? 'info',
                'maintenance' => (bool) ($settings['dealer_maintenance_mode'] ?? false),
            ];
        } elseif ($user->role === 'sub_dealer') {
            return [
                'text' => $settings['subdealer_announcement'] ?? '',
                'type' => $settings['subdealer_announcement_type'] ?? 'info',
                'maintenance' => (bool) ($settings['subdealer_maintenance_mode'] ?? false),
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

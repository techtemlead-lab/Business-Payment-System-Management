<?php

return [
    'financial_activation' => env('MADINA_FINANCIAL_ACTIVATION', false),
    'business_timezone' => 'Asia/Dhaka',
    'withdrawal_minimum' => '200.00',
    'roles' => [
        'super_admin' => ['pricing', 'cost_read', 'accounts', 'catalog', 'inventory', 'orders', 'order_review', 'finance', 'content', 'reports', 'audit', 'identity_write', 'staff_admin', 'field_sales', 'sr_orders', 'sr_dues', 'sr_onboarding', 'payroll'],
        'md' => ['orders', 'finance', 'reports', 'audit'],
        'chairman' => ['orders', 'finance', 'reports', 'audit'],
        'sr' => ['field_sales', 'sr_orders', 'sr_dues', 'sr_onboarding'],
        'account_manager' => ['accounts', 'orders', 'order_review'],
        'inventory' => ['inventory', 'orders'],
        'finance' => ['cost_read', 'finance', 'orders', 'order_review', 'reports', 'payroll'],
        'editor' => ['content', 'catalog'],
        'support' => ['orders'],
    ],
];

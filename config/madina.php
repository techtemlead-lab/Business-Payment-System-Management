<?php

return [
    'financial_activation' => env('MADINA_FINANCIAL_ACTIVATION', false),
    'business_timezone' => 'Asia/Dhaka',
    'withdrawal_minimum' => '200.00',
    'roles' => [
        'super_admin' => ['pricing', 'cost_read', 'accounts', 'catalog', 'inventory', 'orders', 'order_review', 'finance', 'content', 'reports', 'audit', 'identity_write'],
        'account_manager' => ['accounts', 'orders', 'order_review'],
        'inventory' => ['inventory', 'orders'],
        'finance' => ['cost_read', 'finance', 'orders', 'order_review', 'reports'],
        'editor' => ['content', 'catalog'],
        'support' => ['orders'],
    ],
];

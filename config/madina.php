<?php

return [
    'app_domain' => env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'locales' => ['en', 'bn'],
    'default_locale' => env('APP_LOCALE', 'en'),
    'financial_activation' => env('MADINA_FINANCIAL_ACTIVATION', false),
    'preview_mode' => env('MADINA_PREVIEW_MODE', false),
    'auto_release_matured' => env('MADINA_AUTO_RELEASE_MATURED', false),
    'backups_enabled' => env('MADINA_BACKUPS_ENABLED', false),
    'backup_password' => env('MADINA_BACKUP_PASSWORD'),
    'backup_disk' => env('MADINA_BACKUP_DISK', 'backups'),
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'business_timezone' => 'Asia/Dhaka',
    'withdrawal_minimum' => '200.00',
    'bdris' => [
        'enabled' => env('MADINA_BDRIS_ENABLED', false),
        'base_url' => env('MADINA_BDRIS_BASE_URL', ''),
        'api_key' => env('MADINA_BDRIS_API_KEY', ''),
        'timeout' => (int) env('MADINA_BDRIS_TIMEOUT', 12),
    ],
    'sms' => [
        'driver' => env('MADINA_SMS_DRIVER', 'log'),
        'log_channel' => env('MADINA_SMS_LOG_CHANNEL', 'stack'),
        'sent' => [],
    ],
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

<?php

return [
    'app_domain' => env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'locales' => ['en', 'bn'],
    'default_locale' => env('APP_LOCALE', 'en'),
    'financial_activation' => env('MADINA_FINANCIAL_ACTIVATION', false),
    'preview_mode' => env('MADINA_PREVIEW_MODE', false),
    'auto_release_matured' => env('MADINA_AUTO_RELEASE_MATURED', false),
    'auto_release_quota_holds' => env('MADINA_AUTO_RELEASE_QUOTA_HOLDS', false),
    'backups_enabled' => env('MADINA_BACKUPS_ENABLED', false),
    'backup_password' => env('MADINA_BACKUP_PASSWORD'),
    'backup_disk' => env('MADINA_BACKUP_DISK', 'backups'),
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    // Comma/space-separated IPs or CIDRs for manage/sadmin. Empty = unrestricted (preview-safe).
    'admin_ip_allowlist' => env('MADINA_ADMIN_IP_ALLOWLIST', ''),
    // Staff/owner TOTP after optional post-login enrollment. Forced off while MADINA_PREVIEW_MODE=true.
    'totp' => [
        'enabled' => env('MADINA_TOTP_ENABLED', true),
    ],
    'privacy' => [
        // Days to retain closed support/enquiry records; 0 disables automated retention guidance.
        'closed_record_retention_days' => (int) env('MADINA_PRIVACY_RETENTION_DAYS', 365),
    ],

    'business_timezone' => 'Asia/Dhaka',
    'withdrawal_minimum' => '200.00',
    // Printable order invoice letterhead (matches partner invoice template layout).
    'invoice_manufacturer' => env('MADINA_INVOICE_MANUFACTURER', 'Holistic Nutracare'),
    'invoice_marketed_by' => env('MADINA_INVOICE_MARKETED_BY', 'Madina Natural Health Care'),
    'invoice_marketed_phones' => env('MADINA_INVOICE_MARKETED_PHONES', '01723-731877, 01730532966'),
    'company_name' => env('MADINA_COMPANY_NAME', 'Madina Natural Health Care'),
    'company_address' => env('MADINA_COMPANY_ADDRESS', 'Thana Road, Pirganj 5470, Rangpur, Bangladesh'),
    'company_phone' => env('MADINA_COMPANY_PHONE', '+8801730532966'),
    'company_email' => env('MADINA_COMPANY_EMAIL', ''),
    // Legacy env fallbacks. Prefer Service integrations in the admin panel; all remain disabled by default.
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
    // Partner (dealer/sub-dealer) password login OTP default. Owner can override in Security;
    // MADINA_PREVIEW_MODE forces OTP off until SMS is live.
    'login_otp' => [
        'enabled' => env('MADINA_LOGIN_OTP', true),
        'ttl' => (int) env('MADINA_LOGIN_OTP_TTL', 300),
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

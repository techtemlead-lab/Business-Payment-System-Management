<?php

return [
    'app_domain' => env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'locales' => ['en', 'bn'],
    'default_locale' => env('APP_LOCALE', 'en'),
    'financial_activation' => env('MADINA_FINANCIAL_ACTIVATION', false),
    'preview_mode' => env('MADINA_PREVIEW_MODE', false),
    // Removes product photo backgrounds in the office browser. The files are too large for git:
    // `php artisan madina:install-product-photo-model` puts them on each server.
    'product_photo_model' => [
        'repository' => 'wonfy/BiRefNet_lite-ONNX-webgpu',
        'revision' => 'c40eaa22fb1af9edcf75c7b79d25ed8a8e901140',
        'directory' => 'product-photo-model/cutout',
        'files' => [
            'config.json' => 'ea376925bd57077446df1e74ffdc0742c2368b7e912dd176fd2aa877af0a7ddc',
            'preprocessor_config.json' => 'f17fdced4118a9be40e3a32c5e456ef0d5234c01c7ff860889497423ec96e3da',
            'onnx/model_fp16.onnx' => '2290313b335cfdb867eefac47b46aa2b225793fc3deb4da8dbc86e71e9cff2d7',
        ],
    ],
    'auto_release_matured' => env('MADINA_AUTO_RELEASE_MATURED', false),
    'auto_release_quota_holds' => env('MADINA_AUTO_RELEASE_QUOTA_HOLDS', false),
    'backups_enabled' => env('MADINA_BACKUPS_ENABLED', false),
    'backup_password' => env('MADINA_BACKUP_PASSWORD'),
    'backup_disk' => env('MADINA_BACKUP_DISK', 'backups'),
    // A second disk on another machine (a mounted network or object-storage volume). Empty = no off-site copy.
    'backup_offsite_disk' => env('MADINA_BACKUP_OFFSITE_DISK', ''),
    // app:backup-prune deletes local backups older than this, but always keeps the newest few.
    'backup_retention_days' => (int) env('MADINA_BACKUP_RETENTION_DAYS', 14),
    'backup_keep_minimum' => (int) env('MADINA_BACKUP_KEEP_MINIMUM', 7),
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    // Prefer /public/build over Vite HMR. Stale public/hot points CSS/JS at :5173, which CSP
    // blocks on port-forwarded Cloud Agent browsers and blanks public pages.
    'force_built_assets' => (bool) env('MADINA_FORCE_BUILT_ASSETS', false),
    // Comma/space-separated IPs or CIDRs for manage/sadmin. Empty = unrestricted (preview-safe).
    'admin_ip_allowlist' => env('MADINA_ADMIN_IP_ALLOWLIST', ''),
    // Staff/owner TOTP (authenticator app). Forced off while MADINA_PREVIEW_MODE=true.
    'totp' => [
        'enabled' => env('MADINA_TOTP_ENABLED', true),
        // Owners (Super Admin, MD, Chairman) and finance staff must set up the app at their next
        // sign-in (owner decision Q14). Also off while MADINA_PREVIEW_MODE=true.
        'required' => env('MADINA_TOTP_REQUIRED', true),
    ],
    'privacy' => [
        // Days to retain closed support/enquiry records; 0 disables automated retention guidance.
        'closed_record_retention_days' => (int) env('MADINA_PRIVACY_RETENTION_DAYS', 365),
    ],
    // Partner training uploads: the disk they are kept on and the largest file staff may upload.
    // The web server's upload limit (infra/php.production.ini, nginx client_max_body_size) must allow the video size.
    'training' => [
        'disk' => env('MADINA_TRAINING_DISK', 'local'),
        'video_max_mb' => (int) env('MADINA_TRAINING_VIDEO_MAX_MB', 100),
        'pdf_max_mb' => (int) env('MADINA_TRAINING_PDF_MAX_MB', 20),
    ],

    // Prescription module. Prescribing stays off per partner until the owner verifies them.
    'prescriptions' => [
        'enabled' => (bool) env('MADINA_PRESCRIPTIONS', true),
    ],

    'business_timezone' => 'Asia/Dhaka',
    'withdrawal_minimum' => '200.00',
    // Owner decision: nobody orders without paying the whole order first. This is only the starting
    // value; the owner switches it on or off in Finance settings ("Full payment required when ordering").
    'full_payment_when_ordering' => env('MADINA_FULL_PAYMENT_WHEN_ORDERING', true),
    // Printable order invoice letterhead (matches partner invoice template layout).
    'invoice_manufacturer' => env('MADINA_INVOICE_MANUFACTURER', ''),
    'invoice_marketed_by' => env('MADINA_INVOICE_MARKETED_BY', ''),
    'invoice_marketed_phones' => env('MADINA_INVOICE_MARKETED_PHONES', ''),
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
    // Browser push services a subscription may point at (Chrome/Android/Samsung, Firefox,
    // Edge/Windows, Safari). Anything else is refused, so the server never posts to other hosts.
    'web_push_hosts' => ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'],
    'roles' => [
        'super_admin' => ['pricing', 'cost_read', 'accounts', 'catalog', 'inventory', 'orders', 'order_review', 'finance', 'content', 'reports', 'audit', 'identity_write', 'staff_admin', 'field_sales', 'sr_orders', 'sr_onboarding', 'payroll', 'partner_chat', 'prescriber_verify', 'support_desk'],
        'md' => ['orders', 'finance', 'reports', 'audit'],
        'chairman' => ['orders', 'finance', 'reports', 'audit'],
        'sr' => ['field_sales', 'sr_orders', 'sr_onboarding'],
        'account_manager' => ['accounts', 'orders', 'order_review'],
        'inventory' => ['inventory', 'orders'],
        'finance' => ['cost_read', 'finance', 'orders', 'order_review', 'reports', 'payroll'],
        'editor' => ['content', 'catalog'],
        'support' => ['orders', 'support_desk', 'partner_chat'],
    ],
];

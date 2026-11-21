<?php

return [
    'app_domain' => env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'locales' => ['en', 'bn'],
    'default_locale' => env('APP_LOCALE', 'en'),
    // The language for someone who has never chosen one (sign-in pages, the public site). Empty means
    // "follow the browser". Bangla by default: most dealers read Bangla only.
    // Send the translation words inside every page (tests, and any host that cannot serve /i18n files). Off: one cached file.
    'inline_translations' => env('MADINA_INLINE_TRANSLATIONS', false),
    'guest_locale' => env('MADINA_GUEST_LOCALE', 'bn'),
    // The partner (dealer and sub-dealer) product list page. On again since 2026-11-19 (it was off under CR-01); false hides it.
    'partner_catalog_enabled' => env('MADINA_PARTNER_CATALOG', true),
    // The partner-side join link, QR, share buttons and poster. Off: the client says partners never join anyone and the office sets the sponsor.
    'partner_join_link_enabled' => env('MADINA_PARTNER_JOIN_LINK', false),
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
        // For computers whose graphics cannot do the half-precision maths the file above needs.
        // 245 MB, so it is fetched only on request: add `--full-precision` to the install command.
        'full_precision_files' => [
            'onnx/model.onnx' => 'b69359ea66471e57f59099d2e84146091a0534d0d56aa2e33d6bc1ef5e92ccca',
        ],
    ],
    // Writes the Bangla boxes from the English ones in the office browser (CR-06): AI4Bharat IndicTrans2,
    // distilled 200M, English to Indian languages, INT8 ONNX export (MIT licence; about 310 MB). Text never
    // leaves the office computer. `php artisan madina:install-bangla-model` puts the files on each server; until
    // then the Bangla boxes are typed by hand as before. A new revision needs new checksums and a new
    // browser cache name in resources/js/shared/banglaFill/banglaModel.ts.
    'bangla_model' => [
        'repository' => 'hari31416/indictrans2-en-indic-dist-200M-ONNX-int8',
        'revision' => '1eaa43a3fb76f382f1b22c76e56f6ab4926ad68b',
        'directory' => 'bangla-model/en-bn',
        'files' => [
            'config.json' => '1429519f3b36f8bab631ed632b328a3422c19c4225c825dcde27c545746f475e',
            'generation_config.json' => '6c8b29ae72e680d5a2d9e849567f6f556d3f4e9bbd42434964a646f49b556972',
            'tokenizer_config.json' => '283f76ffdd7e553ec7f108ad8eeb0124052db4bdadd924b3c291c591c8dd6625',
            'tokenizer_meta.json' => '9f18574a040d815695f98ac53e7d8ca18ae38b90a339eb59301690fccfc62437',
            'tokenizer_src.json' => 'ac1930c411028ba3a7929f35bc7f62d2e5e837ca24150b661de4f4606469eeca',
            'tokenizer_tgt.json' => '51b05cd37636765e158146534d54452be558df32c6ab06ba4caeecdd0266a580',
            'encoder_model.onnx' => '87c3d6c6fbc48d9d7081927f3bd59915d5443a06cb63460e0d3705bae74de5a9',
            'encoder_model.onnx.data' => '71a4d119514411a3cb3f9d76d2d88d5c4eac7c8e907397d62d5b29e2468a0c16',
            'decoder_model.onnx' => '58c0fafb7ba0f343acba8643a386fab08143d49ef24472cefaecc87080f44e75',
            'decoder_with_past_model.onnx' => 'a2e9a87ff7563f5fc87fe8102188be37fb66771cf30970e649ca67be7f895497',
            'decoder_shared.onnx.data' => '902314746ac629ae2ff584709c03eecdb0b3f7be4fb70ce0ff1bfbe66f14e574',
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
    // Withheld tax or salary deductions owed longer than this show on Money tasks and the office home.
    'payable_stale_days' => (int) env('MADINA_PAYABLE_STALE_DAYS', 45),
    // A fund spend still waiting for its checker after this many days shows on Money tasks and the office home.
    'fund_spend_stale_days' => (int) env('MADINA_FUND_SPEND_STALE_DAYS', 7),
    // Run app:backup-restore-drill on the 2nd of every month at 05:00 Dhaka (needs CREATEDB for the app's DB user).
    'restore_drill_monthly' => (bool) env('MADINA_RESTORE_DRILL_MONTHLY', false),
    // Where serious problems are sent (OP-02). Off until a Telegram bot + chat or an email address is set.
    // Make a bot with @BotFather, add it to the owner's or IT group, and put the group's chat id here.
    'alerts' => [
        'telegram_bot_token' => env('MADINA_ALERT_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('MADINA_ALERT_TELEGRAM_CHAT_ID'),
        // Comma-separated; needs a real mail provider (MAIL_MAILER other than "log").
        'emails' => env('MADINA_ALERT_EMAILS'),
        'level' => env('MADINA_ALERT_LEVEL', 'critical'),
        // The same problem is sent again only after this many minutes.
        'repeat_minutes' => (int) env('MADINA_ALERT_REPEAT_MINUTES', 60),
    ],
    // Dead-man switch: ops:heartbeat pings this address every 5 minutes while the scheduler, queue, database
    // and disk are fine (e.g. a free healthchecks.io check). When the pings stop, that service alerts a person.
    'heartbeat_url' => env('MADINA_HEARTBEAT_URL', ''),
    // ops:heartbeat raises an alert when the disk holding uploads or backups is fuller than this.
    'disk_alert_percent' => (int) env('MADINA_DISK_ALERT_PERCENT', 85),
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    // After the permission check, let nginx send private product photos, videos and the photo model
    // (X-Accel-Redirect) so PHP workers are not held by slow downloads. Only for the production nginx image.
    'nginx_protected_files' => (bool) env('MADINA_NGINX_PROTECTED_FILES', false),
    // Factory default of the website editor's "Dealer network" page switch: off. The owner can turn the page on in the
    // website editor at any time; a new project can start with it on by setting MADINA_PUBLIC_DEALER_NETWORK=true.
    'public_dealer_network' => (bool) env('MADINA_PUBLIC_DEALER_NETWORK', false),
    // Factory default of the website editor's "Verify a dealer" page switch: off, turned on only by the owner in the website editor (client, 2026-11-20).
    'public_verify_dealer' => (bool) env('MADINA_PUBLIC_VERIFY_DEALER', false),
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

    // Partners must accept the agreement before using the portal unless the owner switched that off. Tests turn the default off.
    'agreement_required_default' => (bool) env('MADINA_AGREEMENT_REQUIRED_DEFAULT', true),
    // Owner decision: nobody orders without paying the whole order first. This is only the starting
    // value; the owner switches it on or off in Finance settings ("Full payment required when ordering").
    'full_payment_when_ordering' => env('MADINA_FULL_PAYMENT_WHEN_ORDERING', true),
    // "Wallet + another method": days the rest may stay unpaid (other payment turned down or never sent)
    // before the order is cancelled by itself and the wallet part goes back (madina:release-unpaid-wallet-orders).
    'wallet_part_unpaid_days' => (int) env('MADINA_WALLET_PART_UNPAID_DAYS', 3),
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
    // There is no SMS gateway yet, so new installs keep the code off (password only).
    'login_otp' => [
        'enabled' => env('MADINA_LOGIN_OTP', false),
        'ttl' => (int) env('MADINA_LOGIN_OTP_TTL', 300),
    ],
    // Picture on the sign-in form for everyone. Off until an owner turns it on: the letters are
    // hard to read for older partners. It switches on by itself for one internet address after
    // many wrong sign-ins from it (see SignInGuard).
    'login_captcha' => [
        'enabled' => env('MADINA_LOGIN_CAPTCHA', false),
    ],
    // Separate key for the readable copy of dealer and sub-dealer passwords (see PasswordVault).
    // Keep it out of the backup set: set the key directly, or point at a file outside .env.
    'password_vault' => [
        'key' => env('PASSWORD_VAULT_KEY'),
        'key_file' => env('PASSWORD_VAULT_KEY_FILE'),
    ],
    // An office sign-in (manage, sadmin) ends after this many hours however active it is (SA-10).
    'office_session_hours' => (int) env('MADINA_OFFICE_SESSION_HOURS', 12),
    // Owner reading a dealer's password: how long one password confirmation lasts, and a daily cap.
    'password_reveal' => [
        'confirm_minutes' => (int) env('MADINA_PASSWORD_REVEAL_CONFIRM_MINUTES', 5),
        'per_day' => (int) env('MADINA_PASSWORD_REVEAL_PER_DAY', 60),
    ],
    // Browser push services a subscription may point at (Chrome/Android/Samsung, Firefox,
    // Edge/Windows, Safari). Anything else is refused, so the server never posts to other hosts.
    'web_push_hosts' => ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'],
    'roles' => [
        'super_admin' => ['pricing', 'cost_read', 'accounts', 'catalog', 'inventory', 'orders', 'order_review', 'finance', 'content', 'reports', 'audit', 'identity_write', 'staff_admin', 'field_sales', 'sr_orders', 'sr_onboarding', 'payroll', 'partner_chat', 'prescriber_verify', 'support_desk'],
        // D11 (SA-07): the MD and Chairman see payroll only (view and download) until the Super Admin grants more.
        'md' => ['payroll'],
        'chairman' => ['payroll'],
        'sr' => ['field_sales', 'sr_orders', 'sr_onboarding'],
        'account_manager' => ['accounts', 'orders', 'order_review'],
        'inventory' => ['inventory', 'orders'],
        'finance' => ['cost_read', 'finance', 'orders', 'order_review', 'reports', 'payroll'],
        'editor' => ['content', 'catalog'],
        'support' => ['orders', 'support_desk', 'partner_chat'],
    ],
];

<?php

/*
 * Operations and server-health texts (backups, alerts, heartbeat, restore drill).
 * Go-live warnings are shown on the owner's readiness page and printed by `php artisan app:readiness`.
 */
return [
    'warn_alerts_not_configured' => 'Nobody is told when something serious fails (a backup, the month close, a payout run). Ask your technician to connect a Telegram group or an email address for alerts. For your IT person: MADINA_ALERT_TELEGRAM_BOT_TOKEN and MADINA_ALERT_TELEGRAM_CHAT_ID (or MADINA_ALERT_EMAILS), then php artisan ops:alert-test.',
    'warn_heartbeat_not_configured' => 'If the server or its timed jobs stop, nobody outside will notice. Ask your technician to add an outside heartbeat check. For your IT person: create a free check at healthchecks.io (5-minute period) and put its ping address in MADINA_HEARTBEAT_URL.',
    'warn_offsite_backup_not_configured' => 'Backups are kept only on this server. If the server is lost, the backups are lost with it. Ask your technician to keep a copy on another machine or cloud storage. For your IT person: infra/ops/host-backup.sh with rclone, or MADINA_BACKUP_OFFSITE_DISK.',
    'warn_restore_drill_overdue' => 'Nobody has tried bringing a backup back in the last 35 days, so nobody knows the backups really work. Ask your technician to run the monthly restore test. For your IT person: php artisan app:backup-restore-drill.',
];

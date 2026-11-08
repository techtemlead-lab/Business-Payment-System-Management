<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => Cache::put('operations:scheduler-heartbeat', now()->utc()->toIso8601String(), now()->addMinutes(5)))
    ->name('operations-heartbeat')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('finance:release-matured')->everyFifteenMinutes()->withoutOverlapping(60)->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled commission maturity release failed.'));

Schedule::command('finance:release-quota-holds')->everyFifteenMinutes()->withoutOverlapping(60)->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled area-quota hold release failed.'));

// Auto-approval (on by default): settles closed months and pays earned rank rewards
// for every item the owner left automatic. Car and bike rewards are never automatic.
Schedule::command('finance:auto-approve')->hourly()->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled automatic payout approval failed.'));

// Daily self-checks: the audit trail has not been edited, and the money books still balance
// (journals balanced, wallets equal the ledger). A failure is logged as critical for the owner.
Schedule::command('audit:verify-integrity')->dailyAt('03:00')->timezone('Asia/Dhaka')->withoutOverlapping(60)->onOneServer()
    ->onFailure(fn () => Log::critical('Daily audit-trail integrity check failed. Someone may have changed audit records.'));
Schedule::command('finance:readiness')->dailyAt('03:15')->timezone('Asia/Dhaka')->withoutOverlapping(60)->onOneServer()
    ->onFailure(fn () => Log::critical('Daily money reconciliation failed. The ledger and wallets no longer match.'));

if (config('madina.backups_enabled')) {
    Schedule::command('app:backup')->dailyAt('02:00')->timezone('Asia/Dhaka')->withoutOverlapping(120)->onOneServer()
        ->onFailure(fn () => Log::critical('Scheduled application backup failed. Review backup status before handover.'));
    // Old local backups are deleted so the disk does not fill up; the newest few are always kept.
    Schedule::command('app:backup-prune')->dailyAt('04:00')->timezone('Asia/Dhaka')->withoutOverlapping(30)->onOneServer()
        ->onFailure(fn () => Log::critical('Scheduled backup clean-up failed. Check free disk space on the backup volume.'));
}

// Birthday greetings for dealers and sub-dealers. Each SMS costs money, so this only
// sends when the owner turns on "Send birthday SMS"; a partner is greeted at most once a year.
Schedule::command('partners:birthday-sms')->dailyAt('09:00')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled birthday SMS run failed.'));

// Join-anniversary greeting for dealers and sub-dealers: an in-app notice only (no SMS, so no cost),
// at most once a year each.
Schedule::command('partners:anniversary-greetings')->dailyAt('09:05')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled join anniversary greeting failed.'));

// Orders sent more than a week ago but not marked delivered earn the dealer no commission.
// Each morning staff with the orders or inventory permission get one portal notice with the count. No SMS.
Schedule::command('orders:delivery-reminders')->dailyAt('10:00')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled overdue delivery reminder failed.'));

// Courier tracking: every 30 minutes ask the couriers where each booked, not yet delivered parcel is.
// Dealers get a notice when their parcel moves (picked up, in transit, delivered, returned).
Schedule::command('couriers:refresh-tracking')->everyThirtyMinutes()->withoutOverlapping(25)->onOneServer()
    ->onFailure(fn () => Log::warning('Scheduled courier tracking refresh failed.'));

// Support tickets that have waited longer than the owner's limit (24 hours unless changed) for an
// office reply. Each morning staff with the accounts permission get one portal notice with the count. No SMS.
Schedule::command('support:overdue-reminders')->dailyAt('09:30')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled overdue support ticket reminder failed.'));

// Prescribers: how many patients are due back today or past their follow-up date. One portal notice
// each morning, counts only. No SMS.
Schedule::command('prescriptions:follow-up-reminders')->dailyAt('08:30')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled prescription follow-up reminder failed.'));

// Partner alerts: direct partners who went quiet, salary or rank targets at risk, ranks nearly reached.
// Portal notice only (no SMS); each one at most once a month, or once a week for inactive partners.
Schedule::command('partners:progress-alerts')->dailyAt('09:15')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled partner alert run failed.'));

// Partners who share a phone, NID or payout number, or refer themselves: listed for the owner and finance. Flag only.
Schedule::command('partners:scan-duplicates')->dailyAt('03:30')->timezone('Asia/Dhaka')->withoutOverlapping(60)->onOneServer()
    ->onFailure(fn () => Log::warning('Scheduled duplicate account check failed.'));

// A partner's own customers due for a follow-up: one portal notice a day with counts only. No SMS.
Schedule::command('partners:customer-follow-up-reminders')->dailyAt('08:45')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled customer follow-up reminder failed.'));
// Inventory staff: batches newly inside the warning window or just expired. Portal notice only.
Schedule::command('stock:near-expiry-notices')->dailyAt('08:45')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled near-expiry stock notice failed.'));
// Branches that hold goods but have had no approved stock count for 30 days. Each morning staff
// with the inventory permission get one portal notice naming the branch codes. No SMS.
Schedule::command('stock:count-reminders')->dailyAt('09:45')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('Scheduled stock count reminder failed.'));

// Failed background jobs are kept for a week to investigate, then removed.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('04:15')->timezone('Asia/Dhaka')->withoutOverlapping(30)->onOneServer();

if (! in_array(config('queue.default'), ['sync', 'null'], true)) {
    Schedule::command('queue:monitor '.config('queue.default').':default,imports --max=1000')->everyFiveMinutes()->withoutOverlapping()->onOneServer()
        ->onFailure(fn () => Log::critical('Queue monitoring failed.'));
}

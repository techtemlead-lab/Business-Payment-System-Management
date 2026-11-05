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
}

if (! in_array(config('queue.default'), ['sync', 'null'], true)) {
    Schedule::command('queue:monitor '.config('queue.default').':default,imports --max=1000')->everyFiveMinutes()->withoutOverlapping()->onOneServer()
        ->onFailure(fn () => Log::critical('Queue monitoring failed.'));
}

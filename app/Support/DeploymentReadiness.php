<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DeploymentReadiness
{
    /** @return array{ready: bool, checks: array<string, bool>, preview_mode: bool, financial_posting: bool} */
    public function inspect(bool $strict = false): array
    {
        $checks = ['php_84' => PHP_VERSION_ID >= 80400, 'required_extensions' => true];
        foreach (['bcmath', 'gd', 'intl', 'mbstring', 'pdo_pgsql', 'zip'] as $extension) {
            $checks['required_extensions'] = $checks['required_extensions'] && extension_loaded($extension);
        }
        $checks['database'] = false;
        $checks['database_timezone_utc'] = false;
        $checks['migrations_current'] = false;
        $checks['financial_activation_verified'] = ! config('madina.financial_activation');
        try {
            DB::select('SELECT 1');
            $checks['database'] = true;
            $checks['database_timezone_utc'] = strtoupper(DB::selectOne('SHOW TIMEZONE')->TimeZone) === 'UTC';
            $migrator = app('migrator');
            $checks['migrations_current'] = $migrator->repositoryExists()
                && array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()) === [];
            if ($checks['migrations_current'] && config('madina.financial_activation')) {
                $checks['financial_activation_verified'] = DB::table('financial_verifications')->whereNotNull('verified_at')->exists();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
        $probe = 'readiness-'.Str::uuid();
        $checks['cache'] = false;
        $checks['private_storage'] = false;
        try {
            Cache::put($probe, 'ok', 30);
            $checks['cache'] = Cache::get($probe) === 'ok';
            Cache::forget($probe);
            $checks['private_storage'] = Storage::disk('local')->put('readiness/'.$probe, 'ok');
            Storage::disk('local')->delete('readiness/'.$probe);
        } catch (\Throwable $exception) {
            report($exception);
        }
        $checks['preview_posting_disabled'] = ! config('madina.preview_mode') || ! config('madina.financial_activation');
        if ($strict) {
            $checks['production_environment'] = app()->isProduction();
            $checks['worker_signals_supported'] = extension_loaded('pcntl');
            $checks['debug_disabled'] = config('app.debug') === false;
            $checks['application_key'] = is_string(config('app.key')) && strlen(config('app.key')) >= 32;
            $checks['https_url'] = str_starts_with((string) config('app.url'), 'https://');
            $checks['secure_sessions'] = (bool) config('session.secure') && (bool) config('session.encrypt');
            $checks['durable_queue'] = ! in_array(config('queue.default'), ['sync', 'null'], true);
            $checks['working_mail_configuration'] = ! in_array(config('mail.default'), ['log', 'array', 'null'], true)
                && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
                && ! str_ends_with((string) config('mail.from.address'), '@example.com')
                && (config('mail.default') !== 'smtp' || filled(config('mail.mailers.smtp.host')));
            $checks['scheduler_recent'] = Cache::has('operations:scheduler-heartbeat');
            $checks['queue_worker_recent'] = Cache::has('operations:queue-heartbeat');
            $checks['backups_configured'] = config('madina.backups_enabled') && strlen((string) config('madina.backup_password')) >= 32;
            $checks['recent_verified_backup'] = Cache::has('operations:verified-backup');
        }

        return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks,
            'preview_mode' => (bool) config('madina.preview_mode'), 'financial_posting' => (bool) config('madina.financial_activation')];
    }
}

<?php

namespace App\Support;

use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\IntegrationSettings;
use App\Models\PlaceCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DeploymentReadiness
{
    /**
     * @return array{
     *   ready: bool,
     *   checks: array<string, bool>,
     *   warnings: list<string>,
     *   preview_mode: bool,
     *   financial_posting: bool
     * }
     */
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

        $warnings = [];
        $smsDriver = (string) config('madina.sms.driver', 'log');
        $smsGatewayReady = $this->smsGatewayConfigured();
        if (in_array($smsDriver, ['log', 'array', 'null', ''], true) && ! $smsGatewayReady) {
            $warnings[] = 'sms_driver_logging_only';
        }
        if (! $strict) {
            $mailDriver = (string) config('mail.default');
            if (in_array($mailDriver, ['log', 'array', 'null'], true)) {
                $warnings[] = 'mail_driver_logging_only';
            } else {
                $fromAddress = (string) config('mail.from.address');
                if ($fromAddress === '' || str_ends_with(strtolower($fromAddress), '@example.com')) {
                    $warnings[] = 'mail_from_address_is_example_placeholder';
                }
                if ($mailDriver === 'smtp') {
                    $smtpHost = strtolower((string) config('mail.mailers.smtp.host'));
                    if ($smtpHost === '' || in_array($smtpHost, ['mail', 'localhost', '127.0.0.1', '::1', 'mailpit', 'mailhog', 'smtp'], true)) {
                        $warnings[] = 'mail_smtp_host_is_local_development';
                    }
                }
            }
        }
        if (config('madina.login_otp.enabled') && in_array($smsDriver, ['log', 'array', 'null', ''], true) && ! $smsGatewayReady) {
            $warnings[] = 'login_otp_requires_real_sms_before_go_live';
        }
        if ($checks['database'] && ! $this->placeCodeCatalogReady()) {
            $warnings[] = 'place_code_catalog_incomplete';
        }
        if ($checks['database'] && ! $this->paymentCollectionAccountsReady()) {
            $warnings[] = 'payment_collection_accounts_missing';
        }

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'warnings' => $warnings,
            'preview_mode' => (bool) config('madina.preview_mode'),
            'financial_posting' => (bool) config('madina.financial_activation'),
        ];
    }

    /**
     * Bangladesh onboarding needs the seeded administrative place registry
     * (8 divisions + 64 districts at minimum) before partner go-live.
     */
    private function placeCodeCatalogReady(): bool
    {
        try {
            $divisions = PlaceCode::query()->where('level', PlaceCode::LevelDivision)->count();
            $districts = PlaceCode::query()->where('level', PlaceCode::LevelDistrict)->count();

            return $divisions >= 8 && $districts >= 64;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Partner collections and payment claims need at least one active account
     * that accepts collections before financial go-live.
     */
    private function paymentCollectionAccountsReady(): bool
    {
        try {
            return DB::table('payment_accounts')
                ->where('is_active', true)
                ->where('collects_payments', true)
                ->exists();
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Partner SMS tries enabled Service Integrations providers before the log driver.
     * An enabled provider with credentials clears go-live SMS warnings.
     */
    private function smsGatewayConfigured(): bool
    {
        try {
            $settings = app(IntegrationSettings::class);
            foreach (array_keys(IntegrationCatalog::groups()['sms']['providers'] ?? []) as $provider) {
                $config = $settings->provider('sms', $provider);
                if (! ($config['enabled'] ?? false)) {
                    continue;
                }
                if (filled($config['base_url'] ?? null) && filled($config['api_key'] ?? null)) {
                    return true;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return false;
    }
}

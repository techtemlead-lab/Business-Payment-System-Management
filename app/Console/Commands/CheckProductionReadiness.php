<?php

namespace App\Console\Commands;

use App\Support\DeploymentReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:readiness {--strict : Require production configuration, workers and verified backups} {--json : Output machine-readable results} {--locale=en : Language of the explanations (en or bn)}')]
#[Description('Check deployment configuration and services without activating financial posting')]
class CheckProductionReadiness extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DeploymentReadiness $readiness): int
    {
        // The technician reads this in English; the owner's page shows the same list in their language.
        app()->setLocale(in_array($this->option('locale'), ['en', 'bn'], true) ? (string) $this->option('locale') : 'en');
        $result = $readiness->inspect((bool) $this->option('strict'));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Deployment check', 'Result'], collect($result['checks'])->map(fn (bool $passed, string $name): array => [str_replace('_', ' ', $name), $passed ? 'PASS' : 'REVIEW'])->all());
            $this->newLine();
            $this->line('Money switches: posting '.($result['financial_posting'] ? 'ON' : 'OFF (MADINA_FINANCIAL_ACTIVATION)')
                .' | release of matured commission '.(config('madina.auto_release_matured') ? 'ON' : 'OFF (MADINA_AUTO_RELEASE_MATURED)')
                .' | area quota hold release '.(config('madina.auto_release_quota_holds') ? 'ON' : 'OFF (MADINA_AUTO_RELEASE_QUOTA_HOLDS)')
                .($result['preview_mode'] ? ' | PREVIEW MODE' : ''));
            $warnings = $result['warnings'];
            if ($warnings !== []) {
                $this->newLine();
                $this->warn('Go-live warnings ('.count($warnings).'; they do not block readiness, but resolve each before real money):');
                foreach ($warnings as $number => $warning) {
                    $this->line(sprintf('%2d. [%s] %s', $number + 1, $warning, $result['explanations'][$warning] ?? str_replace('_', ' ', (string) $warning)));
                }
            }
            $details = $result['details'];
            $missingPartners = $details['partners_missing_place_codes'] ?? null;
            if (is_array($missingPartners) && $missingPartners !== []) {
                $this->newLine();
                $this->warn('Partners missing place codes (first '.count($missingPartners).'):');
                $this->table(
                    ['ID', 'Role', 'Dealer code', 'Name'],
                    collect($missingPartners)->map(fn (array $row): array => [
                        $row['id'],
                        $row['role'],
                        $row['dealer_code'] ?? '',
                        $row['name'],
                    ])->all(),
                );
            }
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\DeploymentReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:readiness {--strict : Require production configuration, workers and verified backups} {--json : Output machine-readable results}')]
#[Description('Check deployment configuration and services without activating financial posting')]
class CheckProductionReadiness extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DeploymentReadiness $readiness): int
    {
        $result = $readiness->inspect((bool) $this->option('strict'));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Deployment check', 'Result'], collect($result['checks'])->map(fn (bool $passed, string $name): array => [str_replace('_', ' ', $name), $passed ? 'PASS' : 'REVIEW'])->all());
            $warnings = $result['warnings'] ?? [];
            if ($warnings !== []) {
                $this->newLine();
                $this->warn('Go-live warnings (do not block readiness; resolve before production traffic):');
                foreach ($warnings as $warning) {
                    $this->line(' - '.str_replace('_', ' ', (string) $warning));
                }
            }
            $details = $result['details'] ?? [];
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

<?php

namespace App\Console\Commands;

use App\Domain\Finance\FinancialReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class FinanceReadiness extends Command
{
    protected $signature = 'finance:readiness {--backup : Save a private PostgreSQL backup before inspection} {--backup-only : Save the backup before pending migrations without inspecting new tables}';

    protected $description = 'Inspect financial reconciliation without posting or approving balances';

    public function handle(FinancialReadiness $readiness): int
    {
        if ($this->option('backup') || $this->option('backup-only')) {
            $connection = config('database.connections.'.config('database.default'));
            if ($connection['driver'] !== 'pgsql') {
                $this->error('PostgreSQL is required for a deployment backup.');

                return self::FAILURE;
            }
            Storage::disk('local')->makeDirectory('deployment-backups');
            $path = Storage::disk('local')->path('deployment-backups/'.now()->format('Ymd-His').'-'.Str::uuid().'.dump');
            $process = new Process(['pg_dump', '-h', $connection['host'], '-p', (string) $connection['port'], '-U', $connection['username'],
                '-d', $connection['database'], '--format=custom', '--file='.$path], null, ['PGPASSWORD' => $connection['password']]);
            $process->setTimeout(300)->mustRun();
            chmod($path, 0600);
            $this->info('Private backup: '.$path.'; SHA256 '.hash_file('sha256', $path));
            if ($this->option('backup-only')) {
                return self::SUCCESS;
            }
        }
        $snapshot = $readiness->inspect();
        $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $snapshot['internally_reconciled'] ? self::SUCCESS : self::FAILURE;
    }
}

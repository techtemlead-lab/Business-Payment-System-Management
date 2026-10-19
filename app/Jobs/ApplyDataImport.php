<?php

namespace App\Jobs;

use App\Domain\Finance\OpeningBalanceImport;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplyDataImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $batchId, public int $actorId, public string $reason)
    {
        $this->onQueue('imports');
    }

    /**
     * Execute the job.
     */
    public function handle(OpeningBalanceImport $imports): void
    {
        $imports->apply(User::findOrFail($this->actorId), $this->batchId, $this->reason);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('data_import_batches')->where('id', $this->batchId)->where('status', 'queued')
            ->update(['status' => 'failed', 'review_reason' => 'Import failed. No partial records were applied. Review current data and upload a new preview.', 'updated_at' => now()]);
        Audit::record(null, 'data_import.failed', $this->batchId, ['reviewer_id' => $this->actorId]);
    }
}

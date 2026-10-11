<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\RankDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModeManager
{
    public function change(string $mode, int $revision, string $reason): void
    {
        DB::transaction(function () use ($mode, $revision, $reason) {
            $config = BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            if ($revision !== $config->mode_revision) {
                throw ValidationException::withMessages(['mode' => 'Configuration changed. Refresh before trying again.']);
            }
            if ($config->mode === $mode) {
                return;
            }
            if (! in_array($mode, ['COMMISSION_BASED', 'RANK_BASED'], true)) {
                throw ValidationException::withMessages(['mode' => 'Invalid operating mode.']);
            }
            if ((int) LedgerEntry::whereIn('kind', ['reserved', 'released'])->sum('amount_minor') < 0) {
                throw ValidationException::withMessages(['mode' => 'Reconcile reserved ledger funds before switching modes.']);
            }
            if (Payout::whereIn('status', ['pending', 'processing', 'needs_reconciliation'])->exists()) {
                throw ValidationException::withMessages(['mode' => 'Resolve outstanding payouts and reconciliation before switching modes.']);
            }
            if ($mode === 'RANK_BASED' && ! RankDefinition::where('status', 'approved')->where('effect', 'status_only')->exists()) {
                throw ValidationException::withMessages(['mode' => 'Approve at least one status-only rank with explicit eligibility first. Financial rank rewards remain inactive.']);
            }
            $before = $config->mode;
            $config->update(['mode' => $mode, 'mode_revision' => $revision + 1]);
            AuditEvent::record('mode.changed', ['before' => $before, 'after' => $mode, 'reason' => $reason, 'revision' => $revision + 1]);
        }, 3);
    }
}

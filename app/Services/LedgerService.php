<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function balances(int $dealerId): array
    {
        $totals = LedgerEntry::where('dealer_id', $dealerId)->selectRaw("SUM(CASE WHEN kind IN ('earned', 'reversal') THEN amount_minor ELSE 0 END) AS earned, SUM(CASE WHEN kind IN ('reserved', 'released') THEN amount_minor ELSE 0 END) AS reserved, SUM(CASE WHEN kind = 'paid' THEN amount_minor ELSE 0 END) AS paid")->first();
        $earned = (int) $totals->earned;
        $reserved = -(int) $totals->reserved;
        $paid = -(int) $totals->paid;

        return ['earned_minor' => $earned, 'reserved_minor' => $reserved, 'paid_minor' => $paid, 'available_minor' => $earned - $reserved - $paid];
    }

    public function postCommission(): never
    {
        throw ValidationException::withMessages(['posting' => 'Financial posting is inactive: approved source workflow, eligibility and dealer distribution rules are required.']);
    }

    public function reverse(int $entryId, string $reference, string $reason): LedgerEntry
    {
        return DB::transaction(function () use ($entryId, $reference, $reason) {
            $entry = LedgerEntry::findOrFail($entryId);
            Dealer::whereKey($entry->dealer_id)->lockForUpdate()->firstOrFail();
            $existing = LedgerEntry::where('business_reference', $reference)->first();
            if ($existing) {
                if ($existing->reversal_of !== $entryId) {
                    throw ValidationException::withMessages(['reference' => 'Reference already belongs to another event.']);
                }

                return $existing;
            }
            if ($entry->kind !== 'earned' || LedgerEntry::where('reversal_of', $entryId)->exists()) {
                throw ValidationException::withMessages(['entry' => 'Only an unreversed earning can be reversed.']);
            }
            if ($this->balances($entry->dealer_id)['available_minor'] < $entry->amount_minor) {
                throw ValidationException::withMessages(['entry' => 'Funds are reserved or paid; reconcile before reversing.']);
            }
            $reversal = LedgerEntry::create(['dealer_id' => $entry->dealer_id, 'amount_minor' => -$entry->amount_minor, 'kind' => 'reversal', 'business_reference' => $reference, 'rule_version_id' => $entry->rule_version_id, 'reversal_of' => $entry->id, 'actor_id' => auth()->id(), 'reason' => $reason, 'created_at' => now()]);
            AuditEvent::record('ledger.reversed', ['entry_id' => $entryId, 'reversal_id' => $reversal->id, 'reason' => $reason], $entry->dealer_id);

            return $reversal;
        }, 3);
    }
}

<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\LedgerEntry;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    public function __construct(private LedgerService $ledger) {}

    public function request(int $dealerId, int $amount, string $key, ?string $note): WithdrawalRequest
    {
        return DB::transaction(function () use ($dealerId, $amount, $key, $note): WithdrawalRequest {
            BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            $dealer = Dealer::whereKey($dealerId)->lockForUpdate()->firstOrFail();
            $existing = WithdrawalRequest::where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->dealer_id !== $dealerId || $existing->amount_minor !== $amount) {
                    throw ValidationException::withMessages(['amount' => 'This submission reference belongs to a different request.']);
                }

                return $existing;
            }
            if ($dealer->status !== 'active' || $amount <= 0 || $amount > $this->ledger->balances($dealerId)['available_minor']) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount within your available earned balance. An active account is required.']);
            }
            $withdrawal = WithdrawalRequest::create(['dealer_id' => $dealerId, 'amount_minor' => $amount, 'idempotency_key' => $key, 'note' => $note, 'status' => 'pending']);
            LedgerEntry::create(['dealer_id' => $dealerId, 'amount_minor' => -$amount, 'kind' => 'reserved', 'business_reference' => 'withdrawal-reserve-'.$withdrawal->id, 'actor_id' => auth()->id(), 'reason' => 'Withdrawal requested for company review', 'created_at' => now()]);
            AuditEvent::record('withdrawal.requested', ['withdrawal_id' => $withdrawal->id, 'amount_minor' => $amount], $dealerId);

            return $withdrawal;
        }, 3);
    }

    public function review(int $id, string $status, string $reason, int $actorId, bool $isCompany): WithdrawalRequest
    {
        return DB::transaction(function () use ($id, $status, $reason, $actorId, $isCompany): WithdrawalRequest {
            $snapshot = WithdrawalRequest::findOrFail($id);
            BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            $dealer = Dealer::whereKey($snapshot->dealer_id)->lockForUpdate()->firstOrFail();
            $withdrawal = WithdrawalRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($isCompany || $dealer->user_id === $actorId, 404);
            abort_unless($isCompany ? in_array($status, ['approved', 'declined'], true) : $status === 'cancelled', 403);
            if ($withdrawal->status !== 'pending' && ! ($isCompany && $withdrawal->status === 'approved' && $status === 'declined')) {
                throw ValidationException::withMessages(['withdrawal' => 'This request cannot be changed from its current status.']);
            }
            if (in_array($status, ['declined', 'cancelled'], true)) {
                LedgerEntry::create(['dealer_id' => $dealer->id, 'amount_minor' => $withdrawal->amount_minor, 'kind' => 'released', 'business_reference' => 'withdrawal-release-'.$id, 'actor_id' => $actorId, 'reason' => $reason, 'created_at' => now()]);
            }
            $withdrawal->update(['status' => $status, 'review_note' => $reason, 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
            AuditEvent::record('withdrawal.'.$status, ['withdrawal_id' => $id, 'amount_minor' => $withdrawal->amount_minor, 'reason' => $reason], $dealer->id);

            return $withdrawal;
        }, 3);
    }
}

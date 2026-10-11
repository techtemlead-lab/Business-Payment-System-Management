<?php

namespace App\Services;

use App\Contracts\PayoutProvider;
use App\Models\Dealer;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    public function __construct(private LedgerService $ledger, private PayoutProvider $provider) {}

    public function request(int $dealerId, int $amount, string $key): Payout
    {
        return DB::transaction(function () use ($dealerId, $amount, $key) {
            $dealer = Dealer::whereKey($dealerId)->lockForUpdate()->firstOrFail();
            $existing = Payout::where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->dealer_id !== $dealerId || $existing->amount_minor !== $amount) {
                    throw ValidationException::withMessages(['payout' => 'Idempotency key conflicts with a different request.']);
                }

                return $existing;
            }
            if ($dealer->status !== 'active' || $amount <= 0 || $amount > $this->ledger->balances($dealerId)['available_minor']) {
                throw ValidationException::withMessages(['payout' => 'An active dealer account and sufficient available funds are required.']);
            }
            if (! $this->provider->available()) {
                throw ValidationException::withMessages(['payout' => 'Payouts are inactive until the company approves limits, beneficiary verification, and a disbursement provider.']);
            }
            // Provider unavailable is a deliberate fail-closed boundary; no funds are reserved.
            throw new \LogicException('Provider workflow must be implemented and verified before activation.');
        }, 3);
    }
}

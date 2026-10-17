<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceSettings
{
    /** @return array{amount: string, version: int} */
    public function withdrawalMinimum(): array
    {
        $row = DB::table('business_settings')->where('key', 'withdrawal_minimum')->first();

        return ['amount' => $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR)['amount'] : (string) config('madina.withdrawal_minimum'), 'version' => $row?->version ?? 0];
    }

    public function saveMinimum(User $actor, string $amount, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $amount, $version, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->canAct('finance', 'edit'), 403);
            $before = $this->withdrawalMinimum();
            $stored = DB::table('business_settings')->where('key', 'withdrawal_minimum')->first();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => 'The withdrawal setting changed. Reload before saving.']);
            }
            $cents = Money::cents($amount);
            if ($cents < 1 || $cents > 9999999999) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive withdrawal minimum of at most BDT 99,999,999.99.']);
            }
            $after = ['amount' => Money::decimal($cents)];
            DB::table('business_settings')->updateOrInsert(['key' => 'withdrawal_minimum'], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored?->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'withdrawal.minimum_changed', 'withdrawal_minimum', ['before' => $before, 'after' => $after, 'reason' => $reason]);
        }, 3);
    }
}

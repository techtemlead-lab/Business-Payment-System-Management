<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use App\Models\Dealer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function assign(int $dealerId, ?string $code, string $reason): void
    {
        DB::transaction(function () use ($dealerId, $code, $reason): void {
            BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            $dealer = Dealer::whereKey($dealerId)->lockForUpdate()->firstOrFail();
            $parent = $code ? Dealer::where('code', strtoupper(trim($code)))->first() : null;
            if ($code && ! $parent) {
                throw ValidationException::withMessages(['referrer_code' => 'No dealer matches this referral code.']);
            }
            $visited = [$dealerId => true];
            $cursor = $parent;
            while ($cursor) {
                if (isset($visited[$cursor->id])) {
                    throw ValidationException::withMessages(['referrer_code' => 'A dealer cannot refer themselves or create a circular referral chain.']);
                }
                $visited[$cursor->id] = true;
                $cursor = $cursor->referrer_id ? Dealer::findOrFail($cursor->referrer_id) : null;
            }
            if ($dealer->referrer_id === null && $parent === null) {
                return;
            }
            $before = $dealer->referrer_id;
            $dealer->forceFill(['referrer_id' => $parent?->id, 'referral_verified_by' => $parent ? auth()->id() : null, 'referral_verified_at' => $parent && auth()->id() ? now() : null])->save();
            AuditEvent::record($before === $parent?->id ? 'dealer.referrer_verified' : 'dealer.referrer_changed', ['before' => $before, 'after' => $parent?->id, 'referrer_code' => $parent?->code, 'verified_by' => $dealer->referral_verified_by, 'verified_at' => $dealer->referral_verified_at?->toIso8601String(), 'reason' => $reason], $dealerId);
        });
    }

    /** @return list<array{generation: int, dealer_id: int, code: string, status: string}> */
    public function ancestors(Dealer $dealer): array
    {
        $rows = [];
        $seen = [$dealer->id => true];
        for ($generation = 1; $generation <= 3 && $dealer->referrer_id; $generation++) {
            $dealer = Dealer::findOrFail($dealer->referrer_id);
            if (isset($seen[$dealer->id])) {
                throw new \LogicException('Circular referral chain detected.');
            }
            $seen[$dealer->id] = true;
            $rows[] = ['generation' => $generation, 'dealer_id' => $dealer->id, 'code' => $dealer->code, 'status' => $dealer->status];
        }

        return $rows;
    }
}

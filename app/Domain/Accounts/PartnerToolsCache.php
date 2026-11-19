<?php

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The partner dashboard's "next step" and alert cards are cached for a few minutes. When an order of a
 * partner changes state (paid, delivered, cancelled, returned) both cards must be worked out again for
 * that partner and their sponsor, or they disagree with the live targets card.
 */
final class PartnerToolsCache
{
    public static function forgetFor(int $userId): void
    {
        DB::afterCommit(function () use ($userId): void {
            $sponsorId = User::query()->whereKey($userId)->value('sponsor_id');
            foreach (array_filter([$userId, $sponsorId ? (int) $sponsorId : null]) as $id) {
                Cache::forget('partner-tools:next-steps:'.$id);
                Cache::forget('partner-tools:alerts:'.$id);
            }
        });
    }
}

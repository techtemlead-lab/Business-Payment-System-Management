<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Office can fix (lock) a partner's profile so the partner cannot change it any more.
 * The flag lives in the profile data, so no new column is needed; every place where a partner
 * edits their own profile asks isLocked() first.
 */
final class PartnerProfileLock
{
    public const Flag = 'profile_locked';

    public static function isLocked(User $partner): bool
    {
        return is_array($partner->profile) && ! empty($partner->profile[self::Flag]);
    }

    public function set(User $actor, User $partner, bool $locked): void
    {
        DB::transaction(function () use ($actor, $partner, $locked): void {
            $fresh = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $profile = is_array($fresh->profile) ? $fresh->profile : [];
            if ($locked === ! empty($profile[self::Flag])) {
                return;
            }
            if ($locked) {
                $profile[self::Flag] = true;
            } else {
                unset($profile[self::Flag]);
            }
            $fresh->forceFill(['profile' => $profile])->save();
            Audit::record($actor->id, $locked ? 'partner.profile_locked' : 'partner.profile_unlocked', (string) $partner->id, ['role' => $partner->role]);
        });
    }
}

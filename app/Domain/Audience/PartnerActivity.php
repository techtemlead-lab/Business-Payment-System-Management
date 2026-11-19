<?php

namespace App\Domain\Audience;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CR-17: remembers when each signed-in dealer and sub-dealer was last active, from which address and device.
 * One row per partner, overwritten in place; the throttle keeps it to about one write a minute per person.
 */
final class PartnerActivity
{
    /** Seconds between two writes for the same person. */
    public const WriteEvery = 60;

    /** A partner counts as online when active within this many minutes. */
    public const OnlineMinutes = 5;

    private const SessionKey = 'partner_activity_at';

    public static function tracks(User $user): bool
    {
        return in_array($user->role, ['dealer', 'sub_dealer'], true);
    }

    /** Called on every signed-in request; writes only when the last write for this session is a minute old. */
    public function touch(Request $request, User $user): void
    {
        if (! self::tracks($user)) {
            return;
        }
        $last = (int) $request->session()->get(self::SessionKey, 0);
        if ($last > 0 && now()->getTimestamp() - $last < self::WriteEvery) {
            return;
        }
        $this->write($request, $user, false);
        $request->session()->put(self::SessionKey, now()->getTimestamp());
    }

    /** Called once when a partner signs in. */
    public function signedIn(Request $request, User $user): void
    {
        if (! self::tracks($user)) {
            return;
        }
        $this->write($request, $user, true);
        $request->session()->put(self::SessionKey, now()->getTimestamp());
    }

    private function write(Request $request, User $user, bool $signIn): void
    {
        $agent = UserAgentParser::parse($request->userAgent());
        $values = [
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'device_kind' => $agent['device_kind'],
            'browser' => $agent['browser'],
            'os' => $agent['os'],
        ];
        if ($signIn) {
            $values['last_login_at'] = now();
        }
        DB::table('partner_activity')->upsert(['user_id' => $user->id, ...$values], ['user_id'], array_keys($values));
    }
}

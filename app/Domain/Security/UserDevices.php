<?php

namespace App\Domain\Security;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The places a person is signed in, read from the session table, and logging one of
 * them out. A raw session id works like a password, so the page only ever gets a keyed
 * handle for each session. This reads and removes session rows; the sign-in code is untouched.
 */
final class UserDevices
{
    /**
     * @return list<array{handle: string, label: string, ip_address: string|null, last_active_at: string, current: bool}>
     */
    public function forUser(User $user, string $currentSessionId): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->limit(50)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (\stdClass $session): array => [
                'handle' => $this->handle((string) $session->id),
                'label' => $this->label(is_string($session->user_agent) ? $session->user_agent : ''),
                'ip_address' => $session->ip_address,
                'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity)->toIso8601String(),
                'current' => hash_equals((string) $session->id, $currentSessionId),
            ])->all();
    }

    /**
     * Log out one other device. The session that asks can only end sessions of its own user,
     * and uses the normal log out button for itself.
     *
     * @return bool False when the handle matches no other session of this user.
     */
    public function logOut(User $user, string $handle, string $currentSessionId): bool
    {
        foreach (DB::table('sessions')->where('user_id', $user->id)->pluck('id') as $sessionId) {
            $sessionId = (string) $sessionId;
            if (hash_equals($this->handle($sessionId), $handle)) {
                if (hash_equals($sessionId, $currentSessionId)) {
                    return false;
                }
                DB::table('sessions')->where('id', $sessionId)->where('user_id', $user->id)->delete();
                Audit::record($user->id, 'security.device_logged_out', 'account');

                return true;
            }
        }

        return false;
    }

    private function handle(string $sessionId): string
    {
        return hash_hmac('sha256', 'device-session|'.$sessionId, (string) config('app.key'));
    }

    /** "Chrome on Windows": enough to recognise a device, with no version numbers. */
    private function label(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
        if ($browser === null && $system === null) {
            return (string) __('productivity.device_unknown');
        }
        if ($browser === null || $system === null) {
            return (string) ($browser ?? $system);
        }

        return (string) __('productivity.device_label', ['browser' => $browser, 'system' => $system]);
    }
}

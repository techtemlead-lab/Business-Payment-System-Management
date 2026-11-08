<?php

namespace App\Listeners;

use App\Domain\Security\UserDevices;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Leaves a staff member a portal notice when their account signs in on a browser and system
 * it has not used before. The very first sign-in only remembers the device.
 *
 * It listens for the sign-in event the framework sends after every check has passed, so no
 * login, 2FA or captcha code changes. A failure here is swallowed, so it can never stop a sign-in.
 */
class NoticeNewStaffDevice
{
    public function handle(Login $event): void
    {
        try {
            $user = $event->user;
            if ($user instanceof User && $user->staff()) {
                $this->remember($user, request()->userAgent() ?? '');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function remember(User $user, string $userAgent): void
    {
        $device = mb_substr(app(UserDevices::class)->label($userAgent), 0, 120);
        $alreadyKnown = DB::table('known_sign_in_devices')->where('user_id', $user->id)->where('device', $device)
            ->update(['last_seen_at' => now()]) > 0;
        if ($alreadyKnown) {
            return;
        }
        $isFirstDevice = ! DB::table('known_sign_in_devices')->where('user_id', $user->id)->exists();
        DB::table('known_sign_in_devices')->insertOrIgnore(['user_id' => $user->id, 'device' => $device, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        if ($isFirstDevice) {
            return;
        }
        $locale = $user->preferredLocale();
        Audit::notice(
            (int) $user->id,
            __('wave3_ops.new_device_title', [], $locale),
            __('wave3_ops.new_device_body', ['device' => $device], $locale),
        );
    }
}

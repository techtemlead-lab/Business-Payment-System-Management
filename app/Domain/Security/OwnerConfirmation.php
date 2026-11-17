<?php

namespace App\Domain\Security;

use App\Domain\Auth\LoginOtpChallenge;
use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * What the owner must do to confirm a sensitive change (changing their own contact details or
 * the sign-in rules). The owner picks one of three modes in the office settings:
 *
 *  - off:      nothing is asked.
 *  - password: the owner types their account password (the default).
 *  - sms:      a one-time code is sent to the owner's phone.
 *
 * The code mode never locks the owner out. When no code can be sent (the SMS service is not set
 * up, the owner has no valid phone, or the last send failed), the password is accepted instead
 * and the screen says so.
 */
final class OwnerConfirmation
{
    public const SettingKey = 'owner_confirmation';

    public const ModeOff = 'off';

    public const ModePassword = 'password';

    public const ModeSms = 'sms';

    public const Modes = [self::ModeOff, self::ModePassword, self::ModeSms];

    /** The login-code store is reused with this name in place of a portal. */
    private const CodeChannel = 'owner-confirm';

    /** How long the password stays accepted after a code could not be sent. */
    private const SendFailureMinutes = 15;

    public function __construct(private LoginOtpChallenge $codes) {}

    /** @return array{mode: string, version: int} */
    public function current(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $mode = (string) ($stored['mode'] ?? self::ModePassword);

        return ['mode' => in_array($mode, self::Modes, true) ? $mode : self::ModePassword, 'version' => (int) ($row->version ?? 0)];
    }

    /** True when a code can really reach this owner's phone right now. */
    public function smsWorks(User $owner): bool
    {
        return PhoneIdentity::normalize((string) $owner->phone) !== null && $this->codes->canDeliver();
    }

    /**
     * What this owner is asked for on a sensitive change right now.
     *
     * @return 'none'|'password'|'code'
     */
    public function method(User $owner): string
    {
        return $this->methodFor($owner, $this->current()['mode']);
    }

    /** @return 'none'|'password'|'code' */
    private function methodFor(User $owner, string $mode): string
    {
        return match ($mode) {
            self::ModeOff => 'none',
            self::ModeSms => $this->smsWorks($owner) && ! Cache::has($this->failureKey($owner)) ? 'code' : 'password',
            default => 'password',
        };
    }

    /** @return array{mode: string, version: int, method: string, sms_works: bool, has_phone: bool, code_fell_back: bool} */
    public function payload(User $owner): array
    {
        $current = $this->current();
        $method = $this->methodFor($owner, $current['mode']);

        return [
            ...$current,
            'method' => $method,
            'sms_works' => $this->smsWorks($owner),
            'has_phone' => PhoneIdentity::normalize((string) $owner->phone) !== null,
            'code_fell_back' => $current['mode'] === self::ModeSms && $method === 'password',
        ];
    }

    /**
     * Refuses the request unless the owner has given the confirmation the current mode needs.
     *
     * @throws ValidationException
     */
    public function require(Request $request, User $owner): void
    {
        match ($this->method($owner)) {
            'none' => null,
            'password' => $this->requirePassword($request, $owner),
            default => $this->requireCode($request, $owner),
        };
    }

    /**
     * Sends a fresh code to the owner's phone.
     *
     * @return array{sent: bool, reason: 'sent'|'cooldown'|'limit'|'failed'|'unavailable', retry_after: int, code: ?string}
     */
    public function sendCode(User $owner): array
    {
        if (! $this->smsWorks($owner)) {
            return ['sent' => false, 'reason' => 'unavailable', 'retry_after' => 0, 'code' => null];
        }
        $result = $this->codes->issue($owner, self::CodeChannel, 'owner.confirm', 'sms.owner_confirm_code');
        if ($result['reason'] === 'failed') {
            // No code reached the phone: accept the password for a while so the owner is never locked out.
            Cache::put($this->failureKey($owner), true, now()->addMinutes(self::SendFailureMinutes));
            Audit::record($owner->id, 'security.owner_code_not_sent', 'owner_confirmation');
        } elseif ($result['sent']) {
            Cache::forget($this->failureKey($owner));
        }

        return $result;
    }

    /**
     * Saves the mode. The code mode is refused while no code can be sent, unless the owner states
     * they know and want it anyway (the password then stands in until SMS works).
     *
     * @throws ValidationException
     */
    public function save(User $actor, string $mode, int $version, bool $acceptNoSms): void
    {
        DB::transaction(function () use ($actor, $mode, $version, $acceptNoSms): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && $fresh->owner(), 403);
            if (! in_array($mode, self::Modes, true)) {
                throw ValidationException::withMessages(['mode' => __('owner_security.err_mode')]);
            }
            $before = $this->current();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            if ($mode === self::ModeSms && ! $this->smsWorks($fresh) && ! $acceptNoSms) {
                throw ValidationException::withMessages(['accept_no_sms' => __('owner_security.err_sms_not_working')]);
            }
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode(['mode' => $mode], JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'security.owner_confirmation_changed', self::SettingKey, [
                'before' => ['mode' => $before['mode']],
                'after' => ['mode' => $mode],
                'sms_working' => $this->smsWorks($fresh),
            ]);
        }, 3);
    }

    private function requirePassword(Request $request, User $owner): void
    {
        $request->validate(['current_password' => 'required|string']);
        if (! Hash::check((string) $request->input('current_password'), (string) $owner->password)) {
            throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
        }
    }

    private function requireCode(Request $request, User $owner): void
    {
        $data = $request->validate(['confirm_code' => 'required|digits:6']);
        try {
            $this->codes->verify($owner, self::CodeChannel, $data['confirm_code']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['confirm_code' => $exception->errors()['code'] ?? [__('sms.err_otp_incorrect')]]);
        }
    }

    private function failureKey(User $owner): string
    {
        return 'owner-confirm-send-failed|'.$owner->id;
    }
}

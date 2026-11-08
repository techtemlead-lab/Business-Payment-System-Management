<?php

namespace App\Domain\Auth;

use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Second sign-in step for every account: a one-time code sent by SMS to the
 * mobile number on the account.
 *
 * The owner switch and trial (preview) mode are owned by SecuritySettings. When
 * the step is on, an account with no usable mobile number, or a server with no
 * way to send SMS, is stopped rather than let through — except the Super Admin,
 * who must still be able to sign in to fix exactly those two problems.
 *
 * Only a hash of the code is kept; the code itself is never logged.
 */
final class LoginOtpChallenge
{
    /** Wrong codes allowed for one code before the person has to sign in again. */
    public const AttemptsPerCode = 5;

    /** Wrong codes allowed per account per hour, across all codes, before the account is locked for that hour. */
    public const WrongCodesPerHour = 10;

    /** Codes sent per account per hour (first code plus resends), which also caps the SMS bill. */
    public const SendsPerHour = 5;

    public const ResendCooldownSeconds = 60;

    public function __construct(
        private PartnerEventSms $sms,
        private SecuritySettings $security,
        private SmsGatewayClient $gateway,
    ) {}

    public function enabled(): bool
    {
        return $this->security->partnerLoginOtpEnabled();
    }

    /**
     * True when a code can really reach a phone: a configured SMS provider, the
     * array driver used by tests, or the log driver on a developer machine (where
     * the code is shown on screen instead).
     */
    public function canDeliver(): bool
    {
        if ($this->security->previewMode()) {
            return false;
        }

        $driver = (string) config('madina.sms.driver', 'log');
        if ($driver === 'array' || $this->showsCodeOnScreen()) {
            return true;
        }

        return $this->gateway->isConfigured();
    }

    /** On a developer machine without an SMS provider, the code is shown on the code page. Never on a live server. */
    public function showsCodeOnScreen(): bool
    {
        return app()->environment('local')
            && (string) config('madina.sms.driver', 'log') === 'log'
            && ! $this->gateway->isConfigured();
    }

    /**
     * What the second step should do for this account.
     *
     * @return 'skip'|'send'|'no_phone'|'unavailable'
     */
    public function decide(User $user): string
    {
        if (! $this->enabled()) {
            return 'skip';
        }
        if (PhoneIdentity::normalize((string) $user->phone) === null) {
            return 'no_phone';
        }

        return $this->canDeliver() ? 'send' : 'unavailable';
    }

    public function requiredFor(User $user): bool
    {
        return $this->decide($user) === 'send';
    }

    /**
     * Send a fresh code, replacing any earlier one.
     *
     * @return array{sent: bool, reason: 'sent'|'cooldown'|'limit'|'failed', retry_after: int, code: ?string}
     */
    public function issue(User $user, string $portal): array
    {
        $key = $this->cacheKey($user->id, $portal);
        $previous = Cache::get($key);
        if (is_array($previous) && isset($previous['sent_at'])) {
            $wait = (int) $previous['sent_at'] + self::ResendCooldownSeconds - now()->getTimestamp();
            if ($wait > 0) {
                return ['sent' => false, 'reason' => 'cooldown', 'retry_after' => $wait, 'code' => null];
            }
        }
        $sendKey = 'login-otp-send|'.$user->id;
        if (RateLimiter::tooManyAttempts($sendKey, self::SendsPerHour)) {
            return ['sent' => false, 'reason' => 'limit', 'retry_after' => RateLimiter::availableIn($sendKey), 'code' => null];
        }
        if (! $this->canDeliver()) {
            return ['sent' => false, 'reason' => 'failed', 'retry_after' => 0, 'code' => null];
        }

        $code = (string) random_int(100000, 999999);
        $sent = $this->sms->send(
            $user,
            'login.otp',
            SmsText::make('sms.login_otp', [
                'code' => $code,
                'minutes' => (string) max(1, intdiv($this->ttl(), 60)),
            ]),
        );
        if (! $sent) {
            return ['sent' => false, 'reason' => 'failed', 'retry_after' => 0, 'code' => null];
        }
        RateLimiter::hit($sendKey, 3600);

        Cache::put($key, [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'sent_at' => now()->getTimestamp(),
        ], now()->addSeconds($this->ttl()));

        return ['sent' => true, 'reason' => 'sent', 'retry_after' => self::ResendCooldownSeconds, 'code' => $this->showsCodeOnScreen() ? $code : null];
    }

    /** Seconds until another code may be requested for this sign-in; 0 when allowed now. */
    public function resendWait(User $user, string $portal): int
    {
        $payload = Cache::get($this->cacheKey($user->id, $portal));
        if (! is_array($payload) || ! isset($payload['sent_at'])) {
            return 0;
        }

        return max(0, (int) $payload['sent_at'] + self::ResendCooldownSeconds - now()->getTimestamp());
    }

    /**
     * @throws ValidationException when the code is missing, expired, wrong, or the account is locked
     */
    public function verify(User $user, string $portal, string $code): void
    {
        $lockKey = 'login-otp-fail|'.$user->id;
        if (RateLimiter::tooManyAttempts($lockKey, self::WrongCodesPerHour)) {
            throw ValidationException::withMessages(['code' => __('sms.err_otp_locked', [
                'minutes' => (string) max(1, (int) ceil(RateLimiter::availableIn($lockKey) / 60)),
            ])]);
        }
        $key = $this->cacheKey($user->id, $portal);
        $payload = Cache::get($key);
        if (! is_array($payload) || ! isset($payload['hash'])) {
            throw ValidationException::withMessages(['code' => __('sms.err_otp_expired')]);
        }
        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= self::AttemptsPerCode) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => __('sms.err_otp_too_many')]);
        }
        if (! Hash::check(trim($code), (string) $payload['hash'])) {
            RateLimiter::hit($lockKey, 3600);
            $payload['attempts'] = $attempts + 1;
            Cache::put($key, $payload, now()->addSeconds(max(1, (int) $payload['sent_at'] + $this->ttl() - now()->getTimestamp())));
            throw ValidationException::withMessages(['code' => __('sms.err_otp_incorrect')]);
        }
        Cache::forget($key);
        RateLimiter::clear($lockKey);
    }

    public function forget(User $user, string $portal): void
    {
        Cache::forget($this->cacheKey($user->id, $portal));
    }

    public function ttl(): int
    {
        return max(60, (int) config('madina.login_otp.ttl', 300));
    }

    private function cacheKey(int $userId, string $portal): string
    {
        return 'login_otp:'.$portal.':'.$userId;
    }
}

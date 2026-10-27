<?php

namespace App\Domain\Auth;

use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SMS one-time code challenge for dealer / sub-dealer logins when a valid
 * Bangladesh mobile is on file. Staff portals stay password-only.
 *
 * Effective enablement is owned by SecuritySettings (owner toggle + preview force-off).
 * OTP is skipped when no deliverable SMS path exists so partners are never trapped.
 */
final class LoginOtpChallenge
{
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
     * True when login OTP can actually reach the partner (test array driver or
     * a configured SMS provider). Preview mode and missing gateways skip OTP.
     */
    public function canDeliver(): bool
    {
        if ($this->security->previewMode()) {
            return false;
        }

        $driver = (string) config('madina.sms.driver', 'log');
        if ($driver === 'array') {
            return true;
        }

        return $this->gateway->isConfigured();
    }

    public function requiredFor(User $user): bool
    {
        if (! $this->enabled() || ! $this->canDeliver()) {
            return false;
        }
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            return false;
        }

        return PhoneIdentity::normalize((string) $user->phone) !== null;
    }

    public function issue(User $user, string $portal): bool
    {
        if (! $this->canDeliver()) {
            return false;
        }

        $code = (string) random_int(100000, 999999);
        $locale = $this->localeFor($user);
        $sent = $this->sms->send(
            $user,
            'login.otp',
            __('sms.login_otp', [
                'code' => $code,
                'minutes' => (string) (int) (config('madina.login_otp.ttl', 300) / 60),
            ], $locale),
        );
        if (! $sent) {
            return false;
        }

        Cache::put($this->cacheKey($user->id, $portal), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addSeconds((int) config('madina.login_otp.ttl', 300)));

        return true;
    }

    public function verify(User $user, string $portal, string $code): void
    {
        $key = $this->cacheKey($user->id, $portal);
        $payload = Cache::get($key);
        if (! is_array($payload) || ! isset($payload['hash'])) {
            throw ValidationException::withMessages(['code' => __('sms.err_otp_expired')]);
        }
        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= 5) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => __('sms.err_otp_too_many')]);
        }
        if (! Hash::check(trim($code), (string) $payload['hash'])) {
            $payload['attempts'] = $attempts + 1;
            Cache::put($key, $payload, now()->addSeconds((int) config('madina.login_otp.ttl', 300)));
            throw ValidationException::withMessages(['code' => __('sms.err_otp_incorrect')]);
        }
        Cache::forget($key);
    }

    private function localeFor(User $user): string
    {
        $locale = is_string($user->locale ?? null) ? $user->locale : app()->getLocale();
        $supported = config('madina.locales', ['en', 'bn']);

        return in_array($locale, $supported, true) ? $locale : (string) config('app.fallback_locale', 'en');
    }

    private function cacheKey(int $userId, string $portal): string
    {
        return 'login_otp:'.$portal.':'.$userId;
    }
}

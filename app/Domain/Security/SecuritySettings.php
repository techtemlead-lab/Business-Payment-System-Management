<?php

namespace App\Domain\Security;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner-tunable security controls with preview-safe overrides.
 *
 * Preview mode (MADINA_PREVIEW_MODE) forces partner login OTP and staff TOTP
 * challenges off because SMS/SMTP providers are not live yet.
 */
final class SecuritySettings
{
    public const PartnerLoginOtpKey = 'partner_login_otp';

    /** @return array{enabled: bool, version: int, preview_forced_off: bool, config_default: bool} */
    public function partnerLoginOtp(): array
    {
        $configDefault = (bool) config('madina.login_otp.enabled', true);
        $row = DB::table('business_settings')->where('key', self::PartnerLoginOtpKey)->first();
        $stored = $row
            ? (bool) (json_decode($row->value, true, 512, JSON_THROW_ON_ERROR)['enabled'] ?? $configDefault)
            : $configDefault;
        $preview = $this->previewMode();

        return [
            'enabled' => $preview ? false : $stored,
            'version' => (int) ($row->version ?? 0),
            'preview_forced_off' => $preview,
            'config_default' => $configDefault,
            'stored_enabled' => $stored,
        ];
    }

    public function partnerLoginOtpEnabled(): bool
    {
        return $this->partnerLoginOtp()['enabled'];
    }

    public function totpChallengesEnabled(): bool
    {
        return ! $this->previewMode() && (bool) config('madina.totp.enabled', true);
    }

    public function previewMode(): bool
    {
        return (bool) config('madina.preview_mode', false);
    }

    /** @return list<string> */
    public function adminIpAllowlist(): array
    {
        $raw = trim((string) config('madina.admin_ip_allowlist', ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            preg_split('/[\s,]+/', $raw) ?: [],
        ), static fn (string $ip): bool => $ip !== ''));
    }

    public function adminIpRestricted(): bool
    {
        return $this->adminIpAllowlist() !== [];
    }

    public function ipAllowed(string $ip): bool
    {
        $allowlist = $this->adminIpAllowlist();
        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $allowed) {
            if ($this->ipMatches($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   preview_mode: bool,
     *   partner_login_otp: array{enabled: bool, version: int, preview_forced_off: bool, config_default: bool, stored_enabled: bool},
     *   totp: array{challenges_enabled: bool, confirmed: bool, preview_forced_off: bool},
     *   admin_ip_allowlist_active: bool,
     *   session_count: int
     * }
     */
    public function portalPayload(User $user): array
    {
        $otp = $this->partnerLoginOtp();

        return [
            'preview_mode' => $this->previewMode(),
            'partner_login_otp' => $otp,
            'totp' => [
                'challenges_enabled' => $this->totpChallengesEnabled(),
                'confirmed' => filled($user->totp_confirmed_at),
                'preview_forced_off' => $this->previewMode(),
            ],
            'admin_ip_allowlist_active' => $this->adminIpRestricted(),
            'session_count' => (int) DB::table('sessions')->where('user_id', $user->id)->count(),
        ];
    }

    public function savePartnerLoginOtp(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            abort_unless($actor->fresh()->owner(), 403);
            $before = $this->partnerLoginOtp();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::PartnerLoginOtpKey)->first();
            $after = ['enabled' => $enabled];
            DB::table('business_settings')->updateOrInsert(['key' => self::PartnerLoginOtpKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'security.partner_login_otp_changed', self::PartnerLoginOtpKey, [
                'before' => ['enabled' => $before['stored_enabled']],
                'after' => $after,
                'preview_forced_off' => $before['preview_forced_off'],
            ]);
        }, 3);
    }

    private function ipMatches(string $ip, string $allowed): bool
    {
        if (! str_contains($allowed, '/')) {
            return $ip === $allowed;
        }
        [$subnet, $mask] = explode('/', $allowed, 2);
        if (! is_numeric($mask)) {
            return false;
        }
        $maskBits = (int) $mask;
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bytes = intdiv($maskBits, 8);
        $bits = $maskBits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $maskByte = (~((1 << (8 - $bits)) - 1)) & 0xFF;

        return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
    }
}

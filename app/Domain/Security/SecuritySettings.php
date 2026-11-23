<?php

namespace App\Domain\Security;

use App\Domain\Staff\LeadershipAccess;
use App\Models\User;
use App\Support\Audit;
use App\Support\ReasonFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Owner-tunable security controls with preview-safe overrides.
 *
 * Preview mode (MADINA_PREVIEW_MODE) forces partner login OTP and staff TOTP
 * challenges off because SMS/SMTP providers are not live yet.
 */
final class SecuritySettings
{
    public const PartnerLoginOtpKey = 'partner_login_otp';

    public const PasswordResetNoticeKey = 'password_reset_owner_notice';

    public const OneAccountPerIdentityKey = 'one_account_per_identity';

    public const TeamPhonesKey = 'partner_team_phones';

    /** @var array<string, object>|null */
    private ?array $settingRows = null;

    /** @return array{enabled: bool, version: int, preview_forced_off: bool, config_default: bool} */
    public function partnerLoginOtp(): array
    {
        $configDefault = (bool) config('madina.login_otp.enabled', true);
        $row = $this->settingRow(self::PartnerLoginOtpKey);
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

    /**
     * Whether owners are told when a non-owner staff member resets a password. Default on.
     *
     * @return array{enabled: bool, version: int}
     */
    public function passwordResetNotice(): array
    {
        $row = $this->settingRow(self::PasswordResetNoticeKey);

        return [
            'enabled' => $row ? (bool) (json_decode($row->value, true, 512, JSON_THROW_ON_ERROR)['enabled'] ?? true) : true,
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function passwordResetNoticeEnabled(): bool
    {
        return $this->passwordResetNotice()['enabled'];
    }

    public function savePasswordResetNotice(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            abort_unless($actor->fresh()->owner(), 403);
            $this->settingRows = null;
            $before = $this->passwordResetNotice();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::PasswordResetNoticeKey)->first();
            $after = ['enabled' => $enabled];
            DB::table('business_settings')->updateOrInsert(['key' => self::PasswordResetNoticeKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'security.password_reset_notice_changed', self::PasswordResetNoticeKey, [
                'before' => ['enabled' => $before['enabled']],
                'after' => $after,
            ]);
            $this->settingRows = null;
        }, 3);
    }

    /**
     * Whether one person may hold only one account: the same mobile number, email, NID number or
     * birth-certificate number cannot open a second account. OFF by default (owner's word, 2026-11-17
     * and again 2026-11-22): several accounts may share them until an owner switches this on.
     *
     * @return array{enabled: bool, version: int}
     */
    public function oneAccountPerIdentity(): array
    {
        $row = $this->settingRow(self::OneAccountPerIdentityKey);

        return [
            'enabled' => $row ? (bool) (json_decode($row->value, true, 512, JSON_THROW_ON_ERROR)['enabled'] ?? false) : false,
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function oneAccountPerIdentityEnforced(): bool
    {
        return $this->oneAccountPerIdentity()['enabled'];
    }

    public function saveOneAccountPerIdentity(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            abort_unless($actor->fresh()->owner(), 403);
            $this->settingRows = null;
            $before = $this->oneAccountPerIdentity();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::OneAccountPerIdentityKey)->first();
            $after = ['enabled' => $enabled];
            DB::table('business_settings')->updateOrInsert(['key' => self::OneAccountPerIdentityKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'security.one_account_per_identity_changed', self::OneAccountPerIdentityKey, [
                'before' => ['enabled' => $before['enabled']],
                'after' => $after,
            ]);
            $this->settingRows = null;
        }, 3);
    }

    /**
     * Whether a dealer sees the full phone numbers of the members they referred directly (LC-07,
     * owner decision D13). On by default, as before; when off, every team member's number shows
     * only its last four digits. A partner's own number and their upline's number are not affected.
     *
     * @return array{enabled: bool, version: int}
     */
    public function teamPhones(): array
    {
        $row = $this->settingRow(self::TeamPhonesKey);

        return [
            'enabled' => $row ? (bool) (json_decode($row->value, true, 512, JSON_THROW_ON_ERROR)['enabled'] ?? true) : true,
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function teamPhonesVisible(): bool
    {
        return $this->teamPhones()['enabled'];
    }

    public function saveTeamPhones(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            abort_unless(LeadershipAccess::granted($actor->fresh(), 'owner_tools'), 403);
            $this->settingRows = null;
            $before = $this->teamPhones();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::TeamPhonesKey)->first();
            $after = ['enabled' => $enabled];
            DB::table('business_settings')->updateOrInsert(['key' => self::TeamPhonesKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'security.team_phones_changed', self::TeamPhonesKey, [
                'before' => ['enabled' => $before['enabled']],
                'after' => $after,
            ]);
            $this->settingRows = null;
        }, 3);
    }

    public function totpChallengesEnabled(): bool
    {
        return ! $this->previewMode() && (bool) config('madina.totp.enabled', true);
    }

    /**
     * True when owners and finance staff must have an authenticator app (owner decision Q14,
     * 2026-11-07). On by default; off in preview (trial) mode, when staff 2FA is switched off
     * (MADINA_TOTP_ENABLED=false), or when MADINA_TOTP_REQUIRED=false.
     */
    public function totpRequirementActive(): bool
    {
        return $this->totpChallengesEnabled() && (bool) config('madina.totp.required', true);
    }

    /**
     * True for the accounts that must use an authenticator app: the owners (Super Admin, MD,
     * Chairman) and office staff who can see finance. Dealers and sub-dealers never do.
     */
    public function totpRequiredByRole(User $user): bool
    {
        if ($user->status !== 'active' || ! $user->staff()) {
            return false;
        }

        return $user->owner() || $user->allows('finance');
    }

    /** True when this account must set up its authenticator app before it may use the portal. */
    public function totpSetupPending(User $user): bool
    {
        return $this->totpRequirementActive() && ! $user->hasTotpEnabled() && $this->totpRequiredByRole($user);
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
     *   partner_login_otp: array{enabled: bool, version: int, preview_forced_off: bool, config_default: bool},
     *   password_reset_notice: array{enabled: bool, version: int},
     *   one_account_per_identity: array{enabled: bool, version: int},
     *   team_phones: array{enabled: bool, version: int},
     *   leadership_access: array{md: list<string>, chairman: list<string>, version: int}|null,
     *   owner_confirmation: array{mode: string, version: int, method: string, sms_works: bool, has_phone: bool, code_fell_back: bool}|null,
     *   totp: array{challenges_enabled: bool, confirmed: bool, preview_forced_off: bool, required: bool},
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
            'password_reset_notice' => $this->passwordResetNotice(),
            'one_account_per_identity' => $this->oneAccountPerIdentity(),
            'team_phones' => $this->teamPhones(),
            // Only the Super Admin sets what the MD and Chairman may open (SA-07).
            'leadership_access' => $user->role === 'super_admin' && request()->is('login-rules') ? LeadershipAccess::current() : null,
            // Only the two pages that show it ask for it, so no other page pays for the extra query.
            'owner_confirmation' => $user->owner() && request()->is('login-rules', 'profile') ? app(OwnerConfirmation::class)->payload($user) : null,
            'totp' => [
                'challenges_enabled' => $this->totpChallengesEnabled(),
                'confirmed' => filled($user->totp_confirmed_at),
                'preview_forced_off' => $this->previewMode(),
                'required' => $this->totpRequirementActive() && $this->totpRequiredByRole($user),
            ],
            'admin_ip_allowlist_active' => $this->adminIpRestricted(),
            'session_count' => (int) DB::table('sessions')->where('user_id', $user->id)->count(),
        ];
    }

    public function savePartnerLoginOtp(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            abort_unless($actor->fresh()->owner(), 403);
            $this->settingRows = null;
            $before = $this->partnerLoginOtp();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $this->storePartnerLoginOtp($actor->id, $enabled, $before, 'owner_panel');
        }, 3);
    }

    /**
     * The reason box switches as saved, read in the same query as the security switches because
     * every portal page needs both. Null when no owner has changed one yet.
     */
    public function reasonFieldsRow(): ?object
    {
        return $this->settingRow(ReasonFields::SettingsKey);
    }

    /** The owner switches are read together, so a page pays for one query, not one per switch. */
    private function settingRow(string $key): ?object
    {
        $this->settingRows ??= DB::table('business_settings')
            ->whereIn('key', [self::PartnerLoginOtpKey, self::PasswordResetNoticeKey, self::OneAccountPerIdentityKey, self::TeamPhonesKey, ReasonFields::SettingsKey])
            ->get()
            ->keyBy('key')
            ->all();

        return $this->settingRows[$key] ?? null;
    }

    /**
     * Emergency switch run on the server (`php artisan madina:sms-login off`), for when SMS is
     * down and nobody — the Super Admin included — can get a code to reach the owner switch.
     */
    public function savePartnerLoginOtpFromServer(bool $enabled): void
    {
        DB::transaction(function () use ($enabled): void {
            $this->settingRows = null;
            $before = $this->partnerLoginOtp();
            // The settings row needs an account to name; the audit entry records that it came from the server.
            $ownerId = User::query()->where('role', 'super_admin')->orderBy('id')->value('id');
            if ($ownerId === null) {
                throw new RuntimeException('Create the Super Admin account first (php artisan madina:admin).');
            }
            $this->storePartnerLoginOtp(null, $enabled, $before, 'server_command', (int) $ownerId);
        }, 3);
    }

    /** @param array{enabled: bool, version: int, preview_forced_off: bool, config_default: bool, stored_enabled: bool} $before */
    private function storePartnerLoginOtp(?int $actorId, bool $enabled, array $before, string $source, ?int $recordedBy = null): void
    {
        $existing = DB::table('business_settings')->where('key', self::PartnerLoginOtpKey)->first();
        $after = ['enabled' => $enabled];
        DB::table('business_settings')->updateOrInsert(['key' => self::PartnerLoginOtpKey], [
            'value' => json_encode($after, JSON_THROW_ON_ERROR),
            'version' => $before['version'] + 1,
            'updated_by' => $recordedBy ?? $actorId,
            'created_at' => $existing?->created_at ?? now(),
            'updated_at' => now(),
        ]);
        Audit::record($actorId, 'security.partner_login_otp_changed', self::PartnerLoginOtpKey, [
            'before' => ['enabled' => $before['stored_enabled']],
            'after' => $after,
            'preview_forced_off' => $before['preview_forced_off'],
            'source' => $source,
        ]);
        $this->settingRows = null;
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

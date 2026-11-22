<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\PasswordVaultEncrypted;
use App\Casts\ProtectedProfile;
use App\Domain\Accounts\IdentityKeys;
use App\Domain\Staff\LeadershipAccess;
use App\Domain\Staff\PartnerStaffLink;
use App\Domain\Staff\StaffPermissions;
use App\Support\PortalHost;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Hidden(['password', 'remember_token', 'nid', 'nid_hash', 'birth_certificate_hash', 'tin', 'admin_password_cipher', 'totp_secret', 'totp_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference
{
    public const PasswordManagedByAdmin = 'admin';

    public const PasswordManagedByPartner = 'partner';

    /** The language a new account starts in; the person can change it at any time. */
    public const DefaultLocale = 'bn';

    /** Accounts whose password an owner may read back (see PasswordAccess). */
    public const RevealablePasswordRoles = ['dealer', 'sub_dealer'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * Only plain contact fields may be mass-assigned. Everything that grants rights or money
     * (role, status, profile with its permissions, linked staff role and permissions, branch,
     * sponsor, codes, password fields) is set on purpose with forceFill(), so a future
     * update($request->validated()) can never raise someone's access.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'locale',
        'division',
        'district',
    ];

    /**
     * Remembers when a saved account's phone number or password changed, because a withdrawal
     * waits a short while after either (see WithdrawalSecurityWait). A new account has no change yet.
     * A new password also cancels any password-reset link still waiting in the mail, so an old
     * link cannot undo the change.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            // The comparable keys of the encrypted NID and birth-certificate numbers (see IdentityKeys) follow the numbers.
            if (! $user->exists || $user->isDirty('nid')) {
                $user->nid_hash = IdentityKeys::key(IdentityKeys::Nid, $user->nid);
            }
            if (! $user->exists || $user->isDirty('profile')) {
                $profile = $user->getAttribute('profile');
                $user->birth_certificate_hash = IdentityKeys::key(IdentityKeys::BirthCertificate, is_array($profile) ? ($profile['birth_certificate_number'] ?? null) : null);
            }
            if ($user->exists && ($user->isDirty('password') || $user->isDirty('phone'))) {
                $user->credentials_changed_at = now();
            }
            // Choosing a password ends a "must change" state, unless the same save hands out a temporary one.
            if ($user->exists && $user->isDirty('password') && ! $user->isDirty('must_change_password')) {
                $user->must_change_password = false;
            }
        });
        static::saved(function (self $user): void {
            if ($user->wasChanged('password') && filled($user->email)) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
        });
    }

    protected $attributes = ['status' => 'active', 'role' => 'dealer', 'locale' => self::DefaultLocale];

    public function owner(): bool
    {
        return in_array($this->role, ['super_admin', 'md', 'chairman'], true);
    }

    public function preferredLocale(): string
    {
        $locale = is_string($this->locale ?? null) ? $this->locale : self::DefaultLocale;
        $supported = config('madina.locales', ['en', 'bn']);

        return in_array($locale, $supported, true) ? $locale : self::DefaultLocale;
    }

    public function loginPath(): string
    {
        return PortalHost::loginUrl($this);
    }

    public function portalHome(): string
    {
        return PortalHost::homeUrl($this);
    }

    public function staff(): bool
    {
        return $this->role === 'employee' || str_starts_with($this->role, 'custom_') || array_key_exists($this->role, config('madina.roles'));
    }

    public function hasLinkedStaffAccess(): bool
    {
        return in_array($this->role, ['dealer', 'sub_dealer'], true)
            && is_string($this->linked_staff_role)
            && $this->linked_staff_role !== '';
    }

    public function usingStaffWorkspace(): bool
    {
        if (! $this->hasLinkedStaffAccess()) {
            return false;
        }

        return (string) session('workspace_mode') === 'staff'
            && (int) session('workspace_user_id') === (int) $this->id;
    }

    public function allows(string $capability): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if ($this->role === 'super_admin') {
            return true;
        }

        return in_array($capability, $this->capabilities(), true);
    }

    public function canAct(string $module, string $action): bool
    {
        return in_array($action, StaffPermissions::Actions, true)
            && (app(StaffPermissions::class)->matrix($this)[$module][$action] ?? false);
    }

    public function scopeStaffAccounts(Builder $query): void
    {
        $query->where(fn ($staff) => $staff->whereIn('role', ['employee', ...array_keys(config('madina.roles'))])->orWhere('role', 'like', 'custom\\_%'));
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        if ($this->status !== 'active') {
            return [];
        }
        if ($this->usingStaffWorkspace()) {
            return app(PartnerStaffLink::class)->linkedCapabilities($this);
        }
        if (str_starts_with($this->role, 'custom_') || isset($this->profile['action_permissions']) || in_array($this->role, ['md', 'chairman'], true)) {
            return array_keys(array_filter(app(StaffPermissions::class)->matrix($this), fn (array $actions): bool => $actions['view'] ?? false));
        }
        if ($this->role === 'employee') {
            $known = array_unique(array_merge(...array_values(config('madina.roles'))));

            return array_values(array_intersect((array) ($this->profile['permissions'] ?? []), $known));
        }
        if ($this->role === 'sr' && isset($this->profile['permissions'])) {
            return array_values(array_intersect((array) $this->profile['permissions'], config('madina.roles.sr')));
        }

        return config('madina.roles.'.$this->role, []);
    }

    public function safe(): array
    {
        $profile = is_array($this->profile) ? $this->profile : [];

        return [
            ...$this->only(['id', 'name', 'email', 'phone', 'role', 'dealer_code', 'district', 'division', 'status', 'linked_staff_role', 'active_branch_id']),
            'shop_name' => is_string($profile['shop_name'] ?? null) ? $profile['shop_name'] : null,
            'photo_url' => is_string($profile['photo_url'] ?? null)
                ? $profile['photo_url']
                : (is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null),
            'has_linked_staff' => $this->hasLinkedStaffAccess(),
            'workspace_mode' => $this->usingStaffWorkspace() ? 'staff' : 'partner',
            'permissions' => $this->capabilities(),
            // Owner areas the MD or Chairman may open (SA-07); empty for staff and partners.
            'owner_areas' => LeadershipAccess::areasFor($this),
            'active_branch' => $this->activeBranch?->summary(),
        ];
    }

    public function activeBranch()
    {
        return $this->belongsTo(CompanyBranch::class, 'active_branch_id');
    }

    public function branchAssignments()
    {
        return $this->hasMany(BranchStaffAssignment::class);
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'sponsor_id');
    }

    public function rank()
    {
        return $this->hasOne(DealerRank::class);
    }

    public function areaAssignments()
    {
        return $this->hasMany(AreaDealerAssignment::class);
    }

    public function milestones()
    {
        return $this->hasMany(RewardMilestoneProgress::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Date of birth, parents names, village, post office and birth-certificate number are encrypted inside (LC-05).
            'profile' => ProtectedProfile::class,
            'nid' => 'encrypted',
            'tin' => 'encrypted',
            'admin_password_cipher' => PasswordVaultEncrypted::class,
            'totp_secret' => 'encrypted',
            'totp_recovery_codes' => 'encrypted:array',
            'totp_confirmed_at' => 'datetime',
            'directory_visible' => 'boolean',
            'closed_at' => 'datetime',
            'credentials_changed_at' => 'datetime',
            'linked_staff_permissions' => 'array',
            'staff_linked_at' => 'datetime',
            'can_prescribe' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function hasTotpEnabled(): bool
    {
        return filled($this->totp_confirmed_at) && filled($this->totp_secret);
    }

    public function isPasswordAdminManaged(): bool
    {
        return filled($this->admin_password_cipher);
    }

    public function adminVisiblePassword(): ?string
    {
        $plain = $this->admin_password_cipher;

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    public function storeAdminManagedPassword(string $plainPassword): void
    {
        $this->forceFill([
            'password' => $plainPassword,
            'password_managed_by' => self::PasswordManagedByAdmin,
            'admin_password_cipher' => $this->recoverablePasswordCopy($plainPassword),
        ])->save();
    }

    /**
     * The encrypted copy owners may reveal: dealer and sub-dealer passwords only (owner decision,
     * 2026-11-17). Staff, MD, Chairman and Super Admin passwords are never kept readable, so nobody
     * can sign in as another office member and pass a second-person check in their name.
     */
    public function recoverablePasswordCopy(string $plainPassword): ?string
    {
        return in_array($this->role, self::RevealablePasswordRoles, true) ? $plainPassword : null;
    }

    public function markPasswordSelfManaged(): void
    {
        $this->forceFill([
            'password_managed_by' => self::PasswordManagedByPartner,
            'admin_password_cipher' => null,
        ])->save();
    }
}

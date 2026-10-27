<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Hidden(['password', 'remember_token', 'nid', 'admin_password_cipher'])]
class User extends Authenticatable implements HasLocalePreference
{
    public const PasswordManagedByAdmin = 'admin';

    public const PasswordManagedByPartner = 'partner';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = [
        'id',
        'password',
        'remember_token',
        'email_verified_at',
        'role',
        'status',
        'sponsor_id',
        'nid',
        'dealer_code',
        'password_managed_by',
        'admin_password_cipher',
    ];

    protected $attributes = ['status' => 'active', 'role' => 'dealer'];

    public function owner(): bool
    {
        return in_array($this->role, ['super_admin', 'md', 'chairman'], true);
    }

    public function preferredLocale(): string
    {
        $locale = is_string($this->locale ?? null) ? $this->locale : (string) config('app.fallback_locale', 'en');
        $supported = config('madina.locales', ['en', 'bn']);

        return in_array($locale, $supported, true) ? $locale : (string) config('app.fallback_locale', 'en');
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
            ...$this->only(['id', 'name', 'email', 'role', 'dealer_code', 'district', 'division', 'status', 'linked_staff_role']),
            'shop_name' => is_string($profile['shop_name'] ?? null) ? $profile['shop_name'] : null,
            'photo_url' => is_string($profile['photo_url'] ?? null) ? $profile['photo_url'] : null,
            'has_linked_staff' => $this->hasLinkedStaffAccess(),
            'workspace_mode' => $this->usingStaffWorkspace() ? 'staff' : 'partner',
            'permissions' => $this->capabilities(),
        ];
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
            'profile' => 'array',
            'nid' => 'encrypted',
            'admin_password_cipher' => 'encrypted',
            'directory_visible' => 'boolean',
            'linked_staff_permissions' => 'array',
            'staff_linked_at' => 'datetime',
        ];
    }

    public function isPasswordAdminManaged(): bool
    {
        return $this->password_managed_by === self::PasswordManagedByAdmin
            && filled($this->admin_password_cipher);
    }

    public function adminVisiblePassword(): ?string
    {
        if (! $this->isPasswordAdminManaged()) {
            return null;
        }

        $plain = $this->admin_password_cipher;

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    public function storeAdminManagedPassword(string $plainPassword): void
    {
        $this->forceFill([
            'password' => $plainPassword,
            'password_managed_by' => self::PasswordManagedByAdmin,
            'admin_password_cipher' => $plainPassword,
        ])->save();
    }

    public function markPasswordSelfManaged(): void
    {
        $this->forceFill([
            'password_managed_by' => self::PasswordManagedByPartner,
            'admin_password_cipher' => null,
        ])->save();
    }
}

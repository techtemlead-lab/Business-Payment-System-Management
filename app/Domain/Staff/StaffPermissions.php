<?php

namespace App\Domain\Staff;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StaffPermissions
{
    public const Actions = ['view', 'create', 'edit', 'approve'];

    /** What the role form sends for a role that has no key yet; the key is made when it is saved. */
    public const NewRoleKey = 'custom_';

    /** Staff enter money records by default; approving and paying stay with the owner until granted in the matrix. */
    public const EnterOnlyModules = ['finance', 'payroll'];

    /** @return list<string> */
    public function modules(): array
    {
        return array_values(array_unique(array_merge(...array_values(config('madina.roles')))));
    }

    /** @return list<string> */
    public function roleKeys(): array
    {
        return DB::table('staff_roles')->orderBy('name')->pluck('key')->all();
    }

    /** @return array<string, string> */
    public function customRoleNames(): array
    {
        return DB::table('staff_roles')->pluck('name', 'key')->all();
    }

    /**
     * The permissions a built-in role starts with, before anyone ticks or unticks a box.
     * Role and status are guarded columns, so new User(['role' => ...]) would leave both empty.
     *
     * @return array<string, array<string, bool>>
     */
    public function presetMatrix(string $role): array
    {
        return $this->matrix((new User)->forceFill(['role' => $role, 'status' => 'active']));
    }

    /** @return array<string, array<string, bool>> */
    public function matrix(User $user): array
    {
        if ($user->status !== 'active') {
            return [];
        }
        if ($user->usingStaffWorkspace()) {
            $caps = app(PartnerStaffLink::class)->linkedCapabilities($user);
            $matrix = [];
            foreach ($this->modules() as $module) {
                $enabled = in_array($module, $caps, true);
                $matrix[$module] = array_fill_keys(self::Actions, $enabled);
            }

            return $matrix;
        }
        if (! $user->staff()) {
            return [];
        }
        if ($user->role === 'super_admin') {
            return array_fill_keys($this->modules(), array_fill_keys(self::Actions, true));
        }
        $matrix = [];
        if (str_starts_with($user->role, 'custom_')) {
            $saved = DB::table('staff_roles')->where('key', $user->role)->value('permissions');
            $matrix = $saved ? json_decode($saved, true, 512, JSON_THROW_ON_ERROR) : [];
        } else {
            $capabilities = $user->role === 'employee' ? (array) ($user->profile['permissions'] ?? []) : config('madina.roles.'.$user->role, []);
            if ($user->role === 'sr' && isset($user->profile['permissions'])) {
                $capabilities = array_intersect($capabilities, $user->profile['permissions']);
            }
            foreach (array_intersect($capabilities, $this->modules()) as $capability) {
                $matrix[$capability] = match (true) {
                    in_array($user->role, ['md', 'chairman'], true) => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false],
                    in_array($capability, self::EnterOnlyModules, true) => ['view' => true, 'create' => true, 'edit' => true, 'approve' => false],
                    default => array_fill_keys(self::Actions, true),
                };
            }
        }
        if (isset($user->profile['action_permissions'])) {
            $matrix = $user->profile['action_permissions'];
        }
        if ($user->role === 'sr') {
            $matrix = array_intersect_key($matrix, array_flip(config('madina.roles.sr')));
        }

        return array_intersect_key($matrix, array_flip($this->modules()));
    }

    /** @param array<string, mixed> $matrix @return array<string, array<string, bool>> */
    public function validateMatrix(array $matrix): array
    {
        if (array_diff(array_keys($matrix), $this->modules())) {
            throw ValidationException::withMessages(['permissions' => __('portal.err_perm_unknown_module')]);
        }
        $validated = [];
        foreach ($matrix as $module => $actions) {
            if (! is_array($actions) || array_diff(array_keys($actions), self::Actions)) {
                throw ValidationException::withMessages(['permissions' => __('portal.err_perm_actions')]);
            }
            $row = array_fill_keys(self::Actions, false);
            foreach ($actions as $action => $enabled) {
                if (! is_bool($enabled)) {
                    throw ValidationException::withMessages(['permissions' => __('portal.err_perm_bool')]);
                }
                $row[$action] = $enabled;
            }
            if (! $row['view'] && in_array(true, array_diff_key($row, ['view' => false]), true)) {
                throw ValidationException::withMessages(['permissions' => __('portal.err_perm_view_first')]);
            }
            $validated[$module] = $row;
        }

        return $validated;
    }

    /** @param array<string, mixed> $permissions */
    public function saveRole(User $actor, string $key, string $name, array $permissions, int $version, string $reason): string
    {
        $matrix = $this->validateMatrix($permissions);
        if ($key === self::NewRoleKey) {
            $key = 'custom_role_'.Str::lower(Str::random(12));
        }
        if (! preg_match('/^custom_[a-z][a-z0-9_]{0,49}$/D', $key)) {
            throw ValidationException::withMessages(['key' => __('portal.err_perm_role_key')]);
        }

        return DB::transaction(function () use ($actor, $key, $name, $matrix, $version, $reason): string {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->role === 'super_admin' && $actor->fresh()->status === 'active', 403);
            $before = DB::table('staff_roles')->where('key', $key)->first();
            if (($before?->version ?? 0) !== $version) {
                throw ValidationException::withMessages(['version' => __('portal.err_perm_role_stale')]);
            }
            DB::table('staff_roles')->updateOrInsert(['key' => $key], ['name' => $name, 'permissions' => json_encode($matrix, JSON_THROW_ON_ERROR),
                'version' => $version + 1, 'updated_by' => $actor->id, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()]);
            // People who hold the role follow it: a copy saved on the person when the role was given would keep the old permissions.
            $holders = User::query()->where('role', $key)->lockForUpdate()->get();
            foreach ($holders as $holder) {
                $profile = (array) ($holder->profile ?? []);
                unset($profile['action_permissions']);
                $holder->forceFill(['profile' => $profile])->save();
            }
            Audit::record($actor->id, 'staff.role_saved', $key, ['before' => $before, 'after' => ['name' => $name, 'permissions' => $matrix, 'people_updated' => $holders->pluck('id')->all()], 'reason' => $reason]);

            return $key;
        }, 3);
    }

    /** @param array<string, mixed> $permissions */
    public function assign(User $actor, int $userId, array $permissions, string $version, string $reason, ?string $role = null): void
    {
        $matrix = $this->validateMatrix($permissions);
        DB::transaction(function () use ($actor, $userId, $matrix, $version, $reason, $role): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->role === 'super_admin' && $actor->fresh()->status === 'active', 403);
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            abort_unless($user->staff() && $user->role !== 'super_admin', 403);
            if ($this->version($user) !== $version) {
                throw ValidationException::withMessages(['version' => __('portal.err_perm_staff_stale')]);
            }
            if (($user->role === 'sr' || $role === 'sr') && ($user->role === 'sr' && $role !== null && $role !== 'sr' || array_diff(array_keys($matrix), config('madina.roles.sr')))) {
                throw ValidationException::withMessages(['permissions' => __('portal.err_perm_sr_scope')]);
            }
            if ($role !== null && ! in_array($role, ['employee', ...array_keys(config('madina.roles')), ...$this->roleKeys()], true) || $role === 'super_admin') {
                throw ValidationException::withMessages(['role' => __('portal.err_perm_staff_role')]);
            }
            $before = ['role' => $user->role, 'permissions' => $this->matrix($user)];
            // The role is a guarded column, so a plain update() would silently keep the old one.
            $user->forceFill(['role' => $role ?? $user->role, 'profile' => [...($user->profile ?? []), 'action_permissions' => $matrix, 'access_revision' => Str::uuid()->toString()]])->save();
            Audit::record($actor->id, 'staff.actions_assigned', (string) $userId, ['before' => $before, 'after' => ['role' => $user->role, 'permissions' => $matrix], 'reason' => $reason]);
        }, 3);
    }

    public function version(User $user): string
    {
        return hash('sha256', json_encode([$user->role, $user->profile, $this->matrix($user)], JSON_THROW_ON_ERROR));
    }
}

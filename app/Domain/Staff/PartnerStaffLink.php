<?php

namespace App\Domain\Staff;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PartnerStaffLink
{
    public function __construct(private StaffPermissions $permissions) {}

    public function link(User $actor, User $partner, string $staffRole, array $permissionOverrides = []): User
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user' => __('portal.err_staff_link_partner_only')]);
        }
        $allowed = ['employee', 'sr', ...$this->permissions->roleKeys()];
        if (! in_array($staffRole, $allowed, true) || in_array($staffRole, ['super_admin', 'md', 'chairman', 'dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['linked_staff_role' => __('portal.err_perm_staff_role')]);
        }

        $overrides = array_values(array_unique(array_map('strval', $permissionOverrides)));
        $granted = $overrides !== [] ? $overrides : $this->capabilitiesForRole($staffRole);
        $this->assertActorMayGrant($actor, $granted);

        $partner->forceFill([
            'linked_staff_role' => $staffRole,
            'linked_staff_permissions' => $overrides !== [] ? $overrides : null,
            'staff_linked_at' => now(),
        ])->save();

        Audit::record($actor->id, 'account.staff_linked', (string) $partner->id, [
            'linked_staff_role' => $staffRole,
        ]);

        return $partner->fresh();
    }

    public function unlink(User $actor, User $partner): User
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user' => __('portal.err_staff_link_partner_only')]);
        }
        $partner->forceFill([
            'linked_staff_role' => null,
            'linked_staff_permissions' => null,
            'staff_linked_at' => null,
        ])->save();

        if (session('workspace_mode') === 'staff' && (int) session('workspace_user_id') === (int) $partner->id) {
            session()->forget(['workspace_mode', 'workspace_user_id']);
        }

        Audit::record($actor->id, 'account.staff_unlinked', (string) $partner->id, []);

        return $partner->fresh();
    }

    public function switchMode(User $user, string $mode): void
    {
        if (! in_array($mode, ['partner', 'staff'], true)) {
            throw ValidationException::withMessages(['mode' => __('portal.err_workspace_mode')]);
        }
        if ($mode === 'staff' && ! $user->hasLinkedStaffAccess()) {
            throw ValidationException::withMessages(['mode' => __('portal.err_workspace_mode_staff')]);
        }
        session([
            'workspace_mode' => $mode,
            'workspace_user_id' => $user->id,
        ]);
    }

    public function activeMode(User $user): string
    {
        if (! $user->hasLinkedStaffAccess()) {
            return 'partner';
        }
        $mode = (string) session('workspace_mode', 'partner');
        if ((int) session('workspace_user_id') !== (int) $user->id) {
            return 'partner';
        }

        return $mode === 'staff' ? 'staff' : 'partner';
    }

    /**
     * @return list<string>
     */
    public function linkedCapabilities(User $user): array
    {
        if (! $user->hasLinkedStaffAccess()) {
            return [];
        }
        if (is_array($user->linked_staff_permissions) && $user->linked_staff_permissions !== []) {
            $granted = array_values(array_unique(array_map('strval', $user->linked_staff_permissions)));
        } else {
            $granted = $this->capabilitiesForRole((string) $user->linked_staff_role);
        }

        $granted = array_values(array_intersect($granted, self::knownCapabilities()));

        return array_values(array_diff($granted, self::OwnerOnlyCapabilities));
    }

    /**
     * Capabilities that only the company owner may hold. A dealer or sub-dealer
     * with a linked staff desk must never approve money movement or manage staff.
     */
    public const OwnerOnlyCapabilities = ['finance', 'payroll', 'staff_admin', 'identity_write', 'audit'];

    /**
     * @return list<string>
     */
    public static function knownCapabilities(): array
    {
        return array_values((array) config('madina.roles.super_admin', []));
    }

    /**
     * @return list<string>
     */
    private function capabilitiesForRole(string $role): array
    {
        if (str_starts_with($role, 'custom_')) {
            $saved = DB::table('staff_roles')->where('key', $role)->value('permissions');
            $decoded = is_string($saved) ? json_decode($saved, true) : [];

            // A saved role is a matrix of modules and actions; a module counts when it can be viewed.
            return is_array($decoded) ? array_keys(array_filter($decoded, fn (mixed $actions): bool => is_array($actions) && ($actions['view'] ?? false) === true)) : [];
        }

        return array_values(config('madina.roles.'.$role, config('madina.roles.employee', [])));
    }

    /**
     * @param  list<string>  $granted
     */
    private function assertActorMayGrant(User $actor, array $granted): void
    {
        $unknown = array_diff($granted, self::knownCapabilities());
        $ownerOnly = array_intersect($granted, self::OwnerOnlyCapabilities);
        $beyondActor = $actor->role === 'super_admin' ? [] : array_diff($granted, $actor->capabilities());

        if ($unknown !== [] || $ownerOnly !== [] || $beyondActor !== []) {
            throw ValidationException::withMessages([
                'permissions' => __('portal.err_staff_link_capability', [
                    'items' => implode(', ', array_values(array_unique([...$unknown, ...$ownerOnly, ...$beyondActor]))),
                ]),
            ]);
        }
    }
}

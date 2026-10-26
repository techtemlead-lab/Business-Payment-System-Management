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

        $partner->forceFill([
            'linked_staff_role' => $staffRole,
            'linked_staff_permissions' => $permissionOverrides !== [] ? array_values($permissionOverrides) : null,
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
            return array_values(array_unique(array_map('strval', $user->linked_staff_permissions)));
        }
        $role = (string) $user->linked_staff_role;
        if (str_starts_with($role, 'custom_')) {
            $saved = DB::table('staff_roles')->where('key', $role)->value('permissions');
            $decoded = is_string($saved) ? json_decode($saved, true) : [];

            return is_array($decoded) ? array_values(array_unique(array_map('strval', $decoded))) : [];
        }

        return array_values(config('madina.roles.'.$role, config('madina.roles.employee', [])));
    }
}

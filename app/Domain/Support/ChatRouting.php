<?php

namespace App\Domain\Support;

use App\Domain\Geography\PlaceTree;
use App\Models\BranchStaffAssignment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who answers partner chat, and which partners each of them may see.
 *
 * The Support role always answers chat for the branches its staff are assigned to. The owner can
 * nominate other staff, or widen a Support person to the whole company: a nominee answers either
 * for their own branches (the branches they are assigned to) or for the whole company. A partner's chat
 * belongs to the partner's branch: the branch saved on the partner, or else the active
 * branch nearest the partner's place code. Owners always see every chat.
 */
final class ChatRouting
{
    public const SettingKey = 'chat_answerers';

    public const ScopeBranch = 'branch';

    public const ScopeCompany = 'company';

    public const Scopes = [self::ScopeBranch, self::ScopeCompany];

    /** Request attribute that holds the setting once read, so a page reads it only once. */
    private const MemoKey = 'madina.chat_answerers';

    /**
     * The owner's list of chat answerers.
     *
     * @return array{answerers: array<int, string>, version: int}
     */
    public function setting(): array
    {
        $request = request();
        $memo = $request->attributes->get(self::MemoKey);
        if (is_array($memo)) {
            return $memo;
        }
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $answerers = [];
        foreach ((array) ($stored['answerers'] ?? []) as $entry) {
            $userId = (int) ($entry['user_id'] ?? 0);
            $scope = (string) ($entry['scope'] ?? self::ScopeBranch);
            if ($userId > 0 && in_array($scope, self::Scopes, true)) {
                $answerers[$userId] = $scope;
            }
        }
        $setting = ['answerers' => $answerers, 'version' => (int) ($row->version ?? 0)];
        $request->attributes->set(self::MemoKey, $setting);

        return $setting;
    }

    /** The nominee's scope, or null when this staff member was not nominated. */
    public function scopeOf(User $user): ?string
    {
        return $this->setting()['answerers'][(int) $user->id] ?? null;
    }

    /** Owners and the Support role always; other active staff only when the owner nominated them. */
    public function canAnswer(User $user): bool
    {
        if ($user->status !== 'active') {
            return false;
        }
        if ($user->owner()) {
            return true;
        }

        return $user->role === 'support' || ($user->staff() && $this->scopeOf($user) !== null);
    }

    /**
     * The branches whose chats this answerer sees: null means every chat.
     *
     * @return list<int>|null
     */
    public function visibleBranchIds(User $viewer): ?array
    {
        if ($viewer->owner() || $this->scopeOf($viewer) === self::ScopeCompany) {
            return null;
        }

        return BranchStaffAssignment::query()
            ->where('user_id', $viewer->id)
            ->pluck('branch_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Limit a query that has the partner's users row (aliased $usersTable) to the chats the viewer may see.
     */
    public function constrainToViewer(QueryBuilder $query, User $viewer, string $usersTable = 'users'): void
    {
        if (! $this->canAnswer($viewer)) {
            $query->whereRaw('1 = 0');

            return;
        }
        $branchIds = $this->visibleBranchIds($viewer);
        if ($branchIds === null) {
            return;
        }
        if ($branchIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
        $query->whereRaw($this->partnerBranchSql($usersTable).' IN ('.$placeholders.')', $branchIds);
    }

    /** True when the viewer may read and answer this partner's chat. */
    public function canSeePartner(User $viewer, User $partner): bool
    {
        if (! $this->canAnswer($viewer)) {
            return false;
        }
        $branchIds = $this->visibleBranchIds($viewer);
        if ($branchIds === null) {
            return true;
        }
        $branchId = $this->partnerBranchId($partner);

        return $branchId !== null && in_array($branchId, $branchIds, true);
    }

    /** The branch a partner's chat belongs to, or null when no branch matches. */
    public function partnerBranchId(User $partner): ?int
    {
        $id = DB::table('users')->where('users.id', $partner->id)->selectRaw($this->partnerBranchSql('users').' AS branch_id')->value('branch_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * SQL for a partner's branch: the saved branch, or else the active branch on the partner's
     * place code or its nearest parent place ({@see PlaceTree::nearestActiveBranchIdSql()}).
     * Place names are never matched. NULL means the partner has no branch.
     */
    public function partnerBranchSql(string $usersTable = 'users'): string
    {
        return 'COALESCE('.$usersTable.'.active_branch_id, '.PlaceTree::nearestActiveBranchIdSql($usersTable).')';
    }

    /**
     * What the owner sees on the chat page: the nominees and the staff they may choose from.
     *
     * @return array{answerers: list<array<string, mixed>>, staff: list<array<string, mixed>>, version: int}
     */
    public function ownerPanel(): array
    {
        $setting = $this->setting();
        $staff = User::query()
            ->staffAccounts()
            ->where('status', 'active')
            ->whereNotIn('role', ['super_admin', 'md', 'chairman'])
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'role', 'phone']);
        $branches = BranchStaffAssignment::query()
            ->join('company_branches', 'company_branches.id', '=', 'branch_staff_assignments.branch_id')
            ->whereIn('branch_staff_assignments.user_id', $staff->pluck('id'))
            ->orderBy('company_branches.name')
            ->get(['branch_staff_assignments.user_id', 'company_branches.name'])
            ->groupBy('user_id')
            ->map(fn ($rows): array => $rows->pluck('name')->all());
        $rows = $staff->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'branches' => $branches->get($user->id, []),
            'scope' => $setting['answerers'][$user->id] ?? null,
        ])->values();

        return [
            'answerers' => $rows->filter(fn (array $row): bool => $row['scope'] !== null)->values()->all(),
            'staff' => $rows->all(),
            'version' => $setting['version'],
        ];
    }

    /**
     * Save the owner's list of chat answerers.
     *
     * @param  array<int, array{user_id?: mixed, scope?: mixed}>  $entries
     */
    public function save(User $actor, array $entries, int $version): void
    {
        abort_unless($actor->status === 'active' && $actor->owner(), 403);
        $clean = [];
        foreach ($entries as $entry) {
            $userId = (int) ($entry['user_id'] ?? 0);
            $scope = (string) ($entry['scope'] ?? '');
            if ($userId <= 0 || ! in_array($scope, self::Scopes, true)) {
                throw ValidationException::withMessages(['answerers' => __('portal.chat_route_err_invalid')]);
            }
            $clean[$userId] = $scope;
        }
        if ($clean !== []) {
            $valid = User::query()->staffAccounts()->where('status', 'active')->whereIn('id', array_keys($clean))->count();
            if ($valid !== count($clean)) {
                throw ValidationException::withMessages(['answerers' => __('portal.chat_route_err_invalid')]);
            }
        }
        DB::transaction(function () use ($actor, $clean, $version): void {
            $stored = DB::table('business_settings')->where('key', self::SettingKey)->lockForUpdate()->first();
            $current = (int) ($stored->version ?? 0);
            if ($version !== $current) {
                throw ValidationException::withMessages(['answerers' => __('portal.chat_route_err_stale')]);
            }
            $before = $stored ? (array) (json_decode((string) $stored->value, true, 512, JSON_THROW_ON_ERROR)['answerers'] ?? []) : [];
            $after = [];
            foreach ($clean as $userId => $scope) {
                $after[] = ['user_id' => $userId, 'scope' => $scope];
            }
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode(['answerers' => $after], JSON_THROW_ON_ERROR),
                'version' => $current + 1,
                'updated_by' => $actor->id,
                'created_at' => $stored->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'partner_chat.answerers_changed', self::SettingKey, ['before' => $before, 'after' => $after]);
        });
        request()->attributes->remove(self::MemoKey);
    }
}

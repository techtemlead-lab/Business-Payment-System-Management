<?php

namespace App\Domain\Staff;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the MD and the Chairman may open on the owner panel (SA-07, owner decision D11).
 *
 * By default they see payroll only (view and download). The Super Admin may grant each of them
 * more, one area at a time:
 *  - accounts:    the partner and staff lists, the partner tree, resetting passwords and reading
 *                 dealer and sub-dealer passwords (the owner decision of 2026-11-17);
 *  - treasury:    the Treasury pages and the company funds;
 *  - owner_tools: the other owner pages (login rules, visitors, reason boxes, courier areas, error
 *                 list, sales kit, schemes, contests, privacy requests, branches, branch results,
 *                 training, events, agreement, support desk).
 * The manual data clean-up (deleting records) stays with the Super Admin alone.
 */
final class LeadershipAccess
{
    public const SettingKey = 'leadership_access';

    public const Areas = ['accounts', 'treasury', 'owner_tools'];

    public const Roles = ['md', 'chairman'];

    /**
     * Read once per request or queued job (the class is bound as a scoped instance).
     *
     * @var array{md: list<string>, chairman: list<string>, version: int}|null
     */
    private ?array $cache = null;

    public static function granted(User $user, string $area): bool
    {
        if ($user->status !== 'active') {
            return false;
        }
        if ($user->role === 'super_admin') {
            return true;
        }
        if (! in_array($user->role, self::Roles, true)) {
            return false;
        }

        return in_array($area, self::current()[$user->role], true);
    }

    /** @return list<string> the areas this account opens as an owner (empty for staff and partners) */
    public static function areasFor(User $user): array
    {
        if ($user->role === 'super_admin') {
            return self::Areas;
        }

        return in_array($user->role, self::Roles, true) ? self::current()[$user->role] : [];
    }

    /** @return array{md: list<string>, chairman: list<string>, version: int} */
    public static function current(): array
    {
        $instance = app(self::class);
        if ($instance->cache !== null) {
            return $instance->cache;
        }
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $clean = fn (mixed $areas): array => array_values(array_intersect(self::Areas, is_array($areas) ? $areas : []));

        return $instance->cache = [
            'md' => $clean($stored['md'] ?? []),
            'chairman' => $clean($stored['chairman'] ?? []),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public static function forget(): void
    {
        app(self::class)->cache = null;
    }

    /**
     * @param  array<string, mixed>  $areasByRole  ['md' => [...], 'chairman' => [...]]
     */
    public static function save(User $actor, array $areasByRole, int $version): void
    {
        DB::transaction(function () use ($actor, $areasByRole, $version): void {
            abort_unless($actor->fresh()?->role === 'super_admin', 403);
            self::forget();
            $before = self::current();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $after = [];
            foreach (self::Roles as $role) {
                $areas = $areasByRole[$role] ?? [];
                $after[$role] = array_values(array_intersect(self::Areas, is_array($areas) ? $areas : []));
            }
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'security.leadership_access_changed', self::SettingKey, [
                'before' => ['md' => $before['md'], 'chairman' => $before['chairman']],
                'after' => $after,
            ]);
            self::forget();
        }, 3);
    }
}

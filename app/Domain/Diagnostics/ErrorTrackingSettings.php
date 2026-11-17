<?php

namespace App\Domain\Diagnostics;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's switch for the built-in error log and how many days a problem is kept after it
 * was last seen. Tracking is on, and keeps 90 days, until an owner changes it.
 */
final class ErrorTrackingSettings
{
    public const Key = 'error_tracking';

    public const DefaultRetentionDays = 90;

    public const MinRetentionDays = 7;

    public const MaxRetentionDays = 730;

    /** @return array{enabled: bool, retention_days: int, version: int} */
    public function current(): array
    {
        $row = DB::table('business_settings')->where('key', self::Key)->first(['value', 'version']);
        $value = $row === null ? [] : (array) json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR);

        return [
            'enabled' => (bool) ($value['enabled'] ?? true),
            'retention_days' => $this->clampDays((int) ($value['retention_days'] ?? self::DefaultRetentionDays)),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function enabled(): bool
    {
        return $this->current()['enabled'];
    }

    public function retentionDays(): int
    {
        return $this->current()['retention_days'];
    }

    /** `$version` is the one the owner's page was showing, so two owners do not silently undo each other. */
    public function save(User $actor, bool $enabled, int $retentionDays, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $retentionDays, $version): void {
            abort_unless($actor->fresh()?->owner(), 403);
            $before = $this->current();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::Key)->first(['created_at']);
            $after = ['enabled' => $enabled, 'retention_days' => $this->clampDays($retentionDays)];
            DB::table('business_settings')->updateOrInsert(['key' => self::Key], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'errors.tracking_changed', self::Key, [
                'before' => ['enabled' => $before['enabled'], 'retention_days' => $before['retention_days']],
                'after' => $after,
            ]);
        }, 3);
    }

    private function clampDays(int $days): int
    {
        return max(self::MinRetentionDays, min(self::MaxRetentionDays, $days));
    }
}

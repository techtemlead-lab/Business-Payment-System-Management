<?php

namespace App\Domain\Audience;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's switch for counting visits to the public website. Counting is on until an owner
 * turns it off; while it is off no visit is recorded and no visitor cookie is given out.
 */
final class SiteVisitSettings
{
    public const CountingKey = 'site_visit_counting';

    /** @return array{enabled: bool, version: int} */
    public function counting(): array
    {
        $row = DB::table('business_settings')->where('key', self::CountingKey)->first(['value', 'version']);

        return [
            'enabled' => $row === null || (bool) (json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR)['enabled'] ?? true),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function countingEnabled(): bool
    {
        return $this->counting()['enabled'];
    }

    /**
     * Turn counting on or off. `$version` is the one the owner's page was showing, so two
     * owners changing the switch at once do not silently undo each other.
     */
    public function save(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            abort_unless($actor->fresh()?->owner(), 403);
            $before = $this->counting();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::CountingKey)->first(['created_at']);
            $after = ['enabled' => $enabled];
            DB::table('business_settings')->updateOrInsert(['key' => self::CountingKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'website.visit_counting_changed', self::CountingKey, [
                'before' => ['enabled' => $before['enabled']],
                'after' => $after,
            ]);
        }, 3);
    }
}

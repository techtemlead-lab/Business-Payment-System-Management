<?php

namespace App\Domain\Clinic;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's switch for medicines that are not company products. Only the owner's own
 * account (Super Admin) can change it, not MD or Chairman. It is off until the owner turns it on: while it is off every prescriber, dealers and sub-dealers included, can only
 * prescribe from the company's product list. While it is on a medicine may also be typed by
 * hand; such a medicine never touches stock, the clinic sale or any commission.
 */
final class PrescriptionSettings
{
    public const OtherMedicinesKey = 'prescription_other_medicines';

    /** @return array{enabled: bool, version: int} */
    public function otherMedicines(): array
    {
        $row = DB::table('business_settings')->where('key', self::OtherMedicinesKey)->first(['value', 'version']);

        return [
            'enabled' => $row !== null && (bool) (json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR)['enabled'] ?? false),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function otherMedicinesAllowed(): bool
    {
        return $this->otherMedicines()['enabled'];
    }

    /**
     * Turn hand-typed medicines on or off. `$version` is the one the owner's page was showing,
     * so two owners changing the switch at once do not silently undo each other.
     */
    public function save(User $actor, bool $enabled, int $version): void
    {
        DB::transaction(function () use ($actor, $enabled, $version): void {
            // The owner's own account only (owner decision 2026-11-13): not MD or Chairman.
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && PrescriptionAccess::isOwnerDoctor($fresh), 403);
            $before = $this->otherMedicines();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $existing = DB::table('business_settings')->where('key', self::OtherMedicinesKey)->first(['created_at']);
            $after = ['enabled' => $enabled];
            DB::table('business_settings')->updateOrInsert(['key' => self::OtherMedicinesKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'prescription.other_medicines_changed', self::OtherMedicinesKey, [
                'before' => ['enabled' => $before['enabled']],
                'after' => $after,
            ]);
        }, 3);
    }
}

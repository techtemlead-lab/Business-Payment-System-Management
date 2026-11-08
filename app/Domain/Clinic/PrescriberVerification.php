<?php

namespace App\Domain\Clinic;

use App\Models\PrescriberRegistration;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Turns prescribing on and off for one dealer or sub-dealer after the owner checked their
 * registration papers offline. Every change is written to the audit log.
 */
final class PrescriberVerification
{
    /**
     * @return array{enabled: bool, registration_type: string|null, registration_number: string|null, note: string|null, enabled_by: string|null, enabled_at: string|null, disabled_by: string|null, disabled_at: string|null, disable_reason: string|null}
     */
    public function status(User $partner): array
    {
        $registration = PrescriberRegistration::query()->where('user_id', $partner->id)->first();
        $names = $registration
            ? User::query()->whereIn('id', array_filter([$registration->enabled_by, $registration->disabled_by]))->pluck('name', 'id')
            : collect();

        return [
            'enabled' => (bool) ($registration?->enabled && $partner->can_prescribe),
            'registration_type' => $registration?->registration_type,
            'registration_number' => $registration?->registration_number,
            'note' => $registration?->note,
            'enabled_by' => $registration?->enabled_by ? $names->get($registration->enabled_by) : null,
            'enabled_at' => $registration?->enabled_at?->toIso8601String(),
            'disabled_by' => $registration?->disabled_by ? $names->get($registration->disabled_by) : null,
            'disabled_at' => $registration?->disabled_at?->toIso8601String(),
            'disable_reason' => $registration?->disable_reason,
        ];
    }

    /**
     * @param  array{registration_type: string, registration_number: string, note?: string|null}  $data
     */
    public function enable(User $actor, User $partner, array $data): PrescriberRegistration
    {
        $this->authorize($actor, $partner);

        return DB::transaction(function () use ($actor, $partner, $data): PrescriberRegistration {
            $locked = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $before = PrescriberRegistration::query()->where('user_id', $locked->id)->first();
            $registration = PrescriberRegistration::query()->updateOrCreate(['user_id' => $locked->id], [
                'enabled' => true,
                'registration_type' => $data['registration_type'],
                'registration_number' => trim($data['registration_number']),
                'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
                'enabled_by' => $actor->id,
                'enabled_at' => now(),
                'disabled_by' => null,
                'disabled_at' => null,
                'disable_reason' => null,
            ]);
            $locked->forceFill(['can_prescribe' => true])->save();
            Audit::record($actor->id, 'prescriber.enabled', (string) $locked->id, [
                'registration_type' => $registration->registration_type,
                'registration_number' => $registration->registration_number,
                'note' => $registration->note,
                'was_enabled' => (bool) $before?->enabled,
            ]);

            return $registration;
        });
    }

    public function disable(User $actor, User $partner, ?string $reason): void
    {
        $this->authorize($actor, $partner);

        DB::transaction(function () use ($actor, $partner, $reason): void {
            $locked = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            PrescriberRegistration::query()->where('user_id', $locked->id)->update([
                'enabled' => false,
                'disabled_by' => $actor->id,
                'disabled_at' => now(),
                'disable_reason' => filled($reason) ? trim((string) $reason) : null,
                'updated_at' => now(),
            ]);
            $locked->forceFill(['can_prescribe' => false])->save();
            Audit::record($actor->id, 'prescriber.disabled', (string) $locked->id, ['reason' => $reason]);
        });
    }

    /**
     * The owner-doctor's own registration, printed on clinic prescriptions.
     *
     * @param  array{registration_type: string, registration_number: string, note?: string|null}  $data
     */
    public function saveOwn(User $owner, array $data): PrescriberRegistration
    {
        abort_unless(PrescriptionAccess::isOwnerDoctor($owner), 403);
        $registration = PrescriberRegistration::query()->updateOrCreate(['user_id' => $owner->id], [
            'enabled' => true,
            'registration_type' => $data['registration_type'],
            'registration_number' => trim($data['registration_number']),
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
            'enabled_by' => $owner->id,
            'enabled_at' => now(),
        ]);
        Audit::record($owner->id, 'prescriber.own_registration_saved', (string) $owner->id, [
            'registration_type' => $registration->registration_type,
            'registration_number' => $registration->registration_number,
        ]);

        return $registration;
    }

    private function authorize(User $actor, User $partner): void
    {
        abort_unless(PrescriptionAccess::canVerify($actor), 403);
        abort_unless(PrescriptionAccess::isPartner($partner), 422, __('prescription.err_partner_only'));
    }
}

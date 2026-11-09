<?php

namespace App\Domain\Clinic;

use App\Domain\Notifications\PartnerEventEmail;
use App\Models\PrescriberRegistration;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Turns prescribing on and off for one account after the owner checked their registration
 * papers offline: a dealer or sub-dealer (typed medicines), or an office account (MD, Chairman
 * or any staff), who then prescribes and dispenses from company stock. Only the owners switch
 * company stock on or off for an office account. Every change is written to the audit log.
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
        abort_if((int) $actor->id === (int) $partner->id, 403, __('prescription.err_self_grant'));

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
            Audit::record($actor->id, PrescriptionAccess::isPartner($locked) ? 'prescriber.enabled' : 'prescriber.company_stock_granted', (string) $locked->id, [
                'registration_type' => $registration->registration_type,
                'registration_number' => $registration->registration_number,
                'note' => $registration->note,
                'was_enabled' => (bool) $before?->enabled,
            ]);
            if (! $before?->enabled) {
                app(PartnerEventEmail::class)->sendTranslated($locked, 'prescriber.enabled', 'event_emails.prescriber_enabled_title', 'event_emails.prescriber_enabled_body');
            }

            return $registration;
        });
    }

    public function disable(User $actor, User $partner, ?string $reason): void
    {
        $this->authorize($actor, $partner);

        DB::transaction(function () use ($actor, $partner, $reason): void {
            $locked = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $wasEnabled = (bool) $locked->can_prescribe;
            PrescriberRegistration::query()->where('user_id', $locked->id)->update([
                'enabled' => false,
                'disabled_by' => $actor->id,
                'disabled_at' => now(),
                'disable_reason' => filled($reason) ? trim((string) $reason) : null,
                'updated_at' => now(),
            ]);
            $locked->forceFill(['can_prescribe' => false])->save();
            Audit::record($actor->id, PrescriptionAccess::isPartner($locked) ? 'prescriber.disabled' : 'prescriber.company_stock_removed', (string) $locked->id, ['reason' => $reason]);
            if ($wasEnabled) {
                app(PartnerEventEmail::class)->sendTranslated($locked, 'prescriber.disabled', 'event_emails.prescriber_disabled_title', 'event_emails.prescriber_disabled_body');
            }
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
        abort_if($partner->role === 'super_admin', 422, __('prescription.err_owner_always_on'));
        abort_unless(PrescriptionAccess::isPartner($partner) || PrescriptionAccess::isOfficeAccount($partner), 422, __('prescription.err_partner_only'));
        // Staff with the verify permission handle partners; only the owners touch company stock.
        abort_if(PrescriptionAccess::isOfficeAccount($partner) && ! PrescriptionAccess::canManageCompanyStock($actor), 403, __('prescription.err_company_stock_owner_only'));
    }
}

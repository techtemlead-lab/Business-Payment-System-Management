<?php

namespace App\Domain\Clinic;

use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientAccessLog;
use App\Models\User;

/**
 * Who opened which patient record, and who tried and was refused. Only the patient's own
 * prescriber can read it. It is separate from the audit log, which the owner reads.
 */
final class PatientAccessLog
{
    public const Opened = 'opened';

    public const Printed = 'printed';

    public const Repeated = 'repeated';

    public const FileDownloaded = 'file_downloaded';

    public const Blocked = 'blocked';

    /** Rows on the prescriber's whole list. */
    public const ListLimit = 100;

    /** Rows on one patient's page. */
    public const PatientLimit = 20;

    public function record(User $viewer, PrescriptionPatient $patient, string $action): void
    {
        PrescriptionPatientAccessLog::query()->create([
            'patient_id' => $patient->id,
            'prescriber_id' => $patient->prescriber_id,
            'viewer_id' => $viewer->id,
            'action' => $action,
        ]);
    }

    /**
     * The newest entries on this prescriber's own patients, or on one of them.
     *
     * @return list<array{id: int, patient: string|null, patient_id: int, viewer: string|null, is_self: bool, action: string, at: string}>
     */
    public function forPrescriber(User $prescriber, ?PrescriptionPatient $patient = null): array
    {
        return PrescriptionPatientAccessLog::query()
            ->where('prescriber_id', $prescriber->id)
            ->when($patient !== null, fn ($query) => $query->where('patient_id', $patient?->id))
            ->with(['patient:id,name', 'viewer:id,name'])
            ->orderByDesc('id')
            ->limit($patient === null ? self::ListLimit : self::PatientLimit)
            ->get()
            ->map(fn (PrescriptionPatientAccessLog $entry): array => [
                'id' => $entry->id,
                'patient' => $entry->patient?->name,
                'patient_id' => $entry->patient_id,
                'viewer' => $entry->viewer?->name,
                'is_self' => (int) $entry->viewer_id === (int) $prescriber->id,
                'action' => $entry->action,
                'at' => $entry->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

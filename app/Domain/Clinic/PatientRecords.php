<?php

namespace App\Domain\Clinic;

use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * One patient's clinical record: their history page and the "repeat last prescription" draft.
 * A record is opened only by the prescriber who owns the patient; anyone else, the owner
 * included, is told it doesn't exist.
 */
final class PatientRecords
{
    /** Prescriptions shown on one patient's history page. */
    public const HistoryLimit = 100;

    public function __construct(private PrescriptionBook $book, private PatientAccessLog $accessLog) {}

    /** Opens a record for reading. Both the opening and a refused try are written to the access log. */
    public function open(User $viewer, PrescriptionPatient $patient, string $action): void
    {
        if ((int) $patient->prescriber_id !== (int) $viewer->id) {
            $this->accessLog->record($viewer, $patient, PatientAccessLog::Blocked);
        }
        $this->assertOwn($viewer, $patient);
        $this->accessLog->record($viewer, $patient, $action);
    }

    /** The print page's check, written to the access log like any other opening. */
    public function openForPrint(User $viewer, Prescription $prescription): void
    {
        $patient = $prescription->patient;
        if ($patient !== null && (int) $prescription->prescriber_id !== (int) $viewer->id) {
            $this->accessLog->record($viewer, $patient, PatientAccessLog::Blocked);
        }
        $this->book->assertVisible($viewer, $prescription);
        if ($patient !== null) {
            $this->accessLog->record($viewer, $patient, PatientAccessLog::Printed);
        }
    }

    /**
     * Ownership is checked first, so a person outside the record gets the same 404 whether
     * or not the patient exists. A prescriber whose switch was turned off gets a 403.
     */
    public function assertOwn(User $viewer, PrescriptionPatient $patient): void
    {
        abort_unless(PrescriptionAccess::enabled(), 404);
        abort_unless((int) $patient->prescriber_id === (int) $viewer->id, 404);
        abort_unless(PrescriptionAccess::canPrescribe($viewer), 403);
    }

    /**
     * @return array{patient: array<string, mixed>, prescriptions: list<array<string, mixed>>, access_log: list<array<string, mixed>>}
     */
    public function page(User $viewer, PrescriptionPatient $patient): array
    {
        $this->open($viewer, $patient, PatientAccessLog::Opened);
        $prescriptions = $this->newestFirst($viewer, $patient)
            ->with('lines')
            ->limit(self::HistoryLimit)
            ->get();

        return [
            'patient' => $this->book->patientRow($patient->loadMissing('placeCode:id,code,name'), $viewer),
            'prescriptions' => $prescriptions->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'number' => $prescription->number,
                'kind' => $prescription->kind,
                'status' => $prescription->status,
                'prescribed_on' => $prescription->prescribed_on->toDateString(),
                'follow_up_on' => $prescription->follow_up_on?->toDateString(),
                'complaints' => $prescription->complaints,
                'diagnosis' => $prescription->diagnosis,
                'advice' => $prescription->advice,
                'total' => $prescription->total,
                'lines' => $prescription->lines->map(fn (PrescriptionLine $line): array => [
                    'medicine_name' => $line->medicine_name,
                    'dose' => $line->dose,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'instructions' => $line->instructions,
                    'dose_summary' => DoseSchedule::summary($line, app()->getLocale()),
                    'quantity' => $line->quantity,
                ])->all(),
            ])->values()->all(),
            'access_log' => $this->accessLog->forPrescriber($viewer, $patient),
        ];
    }

    /**
     * The newest prescription as a draft for the new-prescription form. Nothing is saved until
     * the form is, and PrescriptionBook checks and prices it again then. Complaints are left
     * out on purpose: they belong to today's visit.
     *
     * @return array{patient: array{id: int, name: string, age: int|null, allergies: string|null}, draft: array{number: string, prescribed_on: string, diagnosis: string|null, advice: string|null, lines: list<array<string, mixed>>}|null}
     */
    public function repeatDraft(User $viewer, PrescriptionPatient $patient): array
    {
        $this->open($viewer, $patient, PatientAccessLog::Repeated);
        $last = $this->newestFirst($viewer, $patient)->with('lines')->first();

        return [
            'patient' => ['id' => $patient->id, 'name' => $patient->name, 'age' => $patient->age, 'allergies' => $patient->allergies],
            'draft' => $last === null ? null : [
                'number' => $last->number,
                'prescribed_on' => $last->prescribed_on->toDateString(),
                'diagnosis' => $last->diagnosis,
                'advice' => $last->advice,
                'lines' => $last->lines->map(fn (PrescriptionLine $line): array => $line->toDraft())->values()->all(),
            ],
        ];
    }

    /** @return Builder<Prescription> */
    private function newestFirst(User $viewer, PrescriptionPatient $patient): Builder
    {
        return Prescription::query()
            ->where('prescriber_id', $viewer->id)
            ->where('patient_id', $patient->id)
            ->orderByDesc('prescribed_on')
            ->orderByDesc('id');
    }
}

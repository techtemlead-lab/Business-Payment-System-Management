<?php

namespace App\Domain\Clinic;

use App\Models\PrescriptionAppointment;
use App\Models\PrescriptionPatient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simple appointments and a token queue for a prescriber's day. Tokens start at 1 each day
 * for each prescriber. Only that prescriber sees their queue, the owner included.
 */
final class ClinicQueue
{
    /** Rows shown for one day. */
    public const ListLimit = 200;

    /** @var array<string, list<string>> */
    private const NextSteps = [
        PrescriptionAppointment::StatusWaiting => [PrescriptionAppointment::StatusServing, PrescriptionAppointment::StatusCancelled],
        PrescriptionAppointment::StatusServing => [PrescriptionAppointment::StatusDone, PrescriptionAppointment::StatusCancelled],
    ];

    public function today(): string
    {
        return CarbonImmutable::now((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString();
    }

    /** The caller has already checked that the patient belongs to this prescriber. */
    public function book(User $prescriber, PrescriptionPatient $patient, string $date, ?string $time, ?string $note): PrescriptionAppointment
    {
        return DB::transaction(function () use ($prescriber, $patient, $date, $time, $note): PrescriptionAppointment {
            // One booking at a time per prescriber, so two desks can't take the same token.
            User::query()->whereKey($prescriber->id)->lockForUpdate()->firstOrFail(['id']);
            $alreadyBooked = PrescriptionAppointment::query()
                ->where('patient_id', $patient->id)
                ->whereDate('appointment_on', $date)
                ->whereIn('status', [PrescriptionAppointment::StatusWaiting, PrescriptionAppointment::StatusServing])
                ->exists();
            if ($alreadyBooked) {
                throw ValidationException::withMessages(['patient_id' => __('clinic_records.err_queue_already_booked')]);
            }
            $lastToken = (int) PrescriptionAppointment::query()->where('prescriber_id', $prescriber->id)->whereDate('appointment_on', $date)->max('token');

            return PrescriptionAppointment::query()->create([
                'prescriber_id' => $prescriber->id,
                'patient_id' => $patient->id,
                'appointment_on' => $date,
                'appointment_time' => $time,
                'token' => $lastToken + 1,
                'status' => PrescriptionAppointment::StatusWaiting,
                'note' => filled($note) ? trim((string) $note) : null,
            ]);
        });
    }

    /** Moves a token along. Calling a new patient finishes whoever was being seen. */
    public function changeStatus(User $prescriber, PrescriptionAppointment $appointment, string $status): void
    {
        abort_unless(PrescriptionAccess::enabled(), 404);
        abort_unless((int) $appointment->prescriber_id === (int) $prescriber->id, 404);
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);

        DB::transaction(function () use ($appointment, $status): void {
            $locked = PrescriptionAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($status, self::NextSteps[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('clinic_records.err_queue_status')]);
            }
            if ($status === PrescriptionAppointment::StatusServing) {
                PrescriptionAppointment::query()
                    ->where('prescriber_id', $locked->prescriber_id)
                    ->whereDate('appointment_on', $locked->appointment_on->toDateString())
                    ->where('status', PrescriptionAppointment::StatusServing)
                    ->update(['status' => PrescriptionAppointment::StatusDone]);
            }
            $locked->update(['status' => $status]);
        });
    }

    /**
     * One day's queue in token order. An unreadable or impossible date shows today.
     *
     * @return array{date: string, today: string, rows: list<array{id: int, token: int, patient: string|null, patient_id: int, time: string|null, status: string, note: string|null}>}
     */
    public function forDay(User $prescriber, ?string $requestedDate): array
    {
        $today = $this->today();
        $date = $requestedDate !== null && checkdate((int) substr($requestedDate, 5, 2), (int) substr($requestedDate, 8, 2), (int) substr($requestedDate, 0, 4))
            ? $requestedDate
            : $today;

        return [
            'date' => $date,
            'today' => $today,
            'rows' => PrescriptionAppointment::query()
                ->where('prescriber_id', $prescriber->id)
                ->whereDate('appointment_on', $date)
                ->with('patient:id,name')
                ->orderBy('token')
                ->limit(self::ListLimit)
                ->get()
                ->map(fn (PrescriptionAppointment $appointment): array => [
                    'id' => $appointment->id,
                    'token' => $appointment->token,
                    'patient' => $appointment->patient?->name,
                    'patient_id' => $appointment->patient_id,
                    'time' => $appointment->appointment_time === null ? null : substr($appointment->appointment_time, 0, 5),
                    'status' => $appointment->status,
                    'note' => $appointment->note,
                ])
                ->values()
                ->all(),
        ];
    }
}

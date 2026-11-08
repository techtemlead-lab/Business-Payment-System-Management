<?php

namespace App\Domain\Clinic;

use App\Models\ConsultationRequest;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientConsent;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Requests visitors send to one listed prescriber. Only that prescriber can read them: the
 * audit log and portal notice carry no name, phone or problem, so the owner never sees them.
 */
final class ConsultationRequests
{
    /** Requests one phone number may send to one prescriber in a day. */
    public const PhoneDailyLimit = 3;

    private const SecondsInDay = 86400;

    public function __construct(private PrescriptionBook $book) {}

    /**
     * @param  array{name: string, phone: string, problem: string, preferred_time?: string|null}  $data
     */
    public function submit(User $prescriber, array $data, string $consentStatement): ConsultationRequest
    {
        $phone = (string) preg_replace('/[^\d+]/', '', $data['phone']);
        $limiterKey = 'consultation-phone:'.$prescriber->id.':'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($limiterKey, self::PhoneDailyLimit)) {
            throw ValidationException::withMessages(['phone' => __('prescriber_public.err_too_many')]);
        }
        RateLimiter::hit($limiterKey, self::SecondsInDay);

        $request = DB::transaction(function () use ($prescriber, $data, $phone, $consentStatement): ConsultationRequest {
            $created = ConsultationRequest::query()->create([
                'prescriber_id' => $prescriber->id,
                'name' => trim($data['name']),
                'phone' => $phone,
                'problem' => trim($data['problem']),
                'preferred_time' => filled($data['preferred_time'] ?? null) ? trim((string) $data['preferred_time']) : null,
                'consent_statement' => $consentStatement,
                'consented_at' => now(),
            ]);
            Audit::record(null, 'consultation.requested', (string) $created->id, []);

            return $created;
        });
        Audit::notice(
            $prescriber->id,
            __('prescriber_public.notice_new_title', [], $prescriber->locale),
            __('prescriber_public.notice_new_body', [], $prescriber->locale),
        );

        return $request;
    }

    /** Turns a new request into one of the prescriber's own patients, with its consent record. */
    public function accept(User $prescriber, ConsultationRequest $consultation): PrescriptionPatient
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);

        return DB::transaction(function () use ($prescriber, $consultation): PrescriptionPatient {
            $locked = $this->lockOwnNewRequest($prescriber, $consultation);
            $patient = $this->book->savePatient($prescriber, null, [
                'name' => $locked->name,
                'phone' => $locked->phone,
                'notes' => $locked->problem,
            ], [
                'source' => PrescriptionPatientConsent::SourcePublicRequest,
                'statement' => $locked->consent_statement,
                'consultation_request_id' => $locked->id,
            ]);
            $locked->forceFill(['status' => ConsultationRequest::StatusAccepted, 'patient_id' => $patient->id, 'handled_at' => now()])->save();
            Audit::record($prescriber->id, 'consultation.accepted', (string) $locked->id, []);

            return $patient;
        });
    }

    public function decline(User $prescriber, ConsultationRequest $consultation): void
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);

        DB::transaction(function () use ($prescriber, $consultation): void {
            $locked = $this->lockOwnNewRequest($prescriber, $consultation);
            $locked->forceFill(['status' => ConsultationRequest::StatusDeclined, 'handled_at' => now()])->save();
            Audit::record($prescriber->id, 'consultation.declined', (string) $locked->id, []);
        });
    }

    /**
     * A request row for its own prescriber's screen, with the full phone so they can call back.
     *
     * @return array{id: int, name: string, phone: string, problem: string, preferred_time: string|null, status: string, created_at: string|null}
     */
    public function row(ConsultationRequest $consultation): array
    {
        return [
            'id' => $consultation->id,
            'name' => $consultation->name,
            'phone' => $consultation->phone,
            'problem' => $consultation->problem,
            'preferred_time' => $consultation->preferred_time,
            'status' => $consultation->status,
            'created_at' => $consultation->created_at?->toIso8601String(),
        ];
    }

    private function lockOwnNewRequest(User $prescriber, ConsultationRequest $consultation): ConsultationRequest
    {
        $locked = ConsultationRequest::query()->whereKey($consultation->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $locked->prescriber_id === (int) $prescriber->id, 404);
        if ($locked->status !== ConsultationRequest::StatusNew) {
            throw ValidationException::withMessages(['consultation' => __('prescriber_public.err_already_handled')]);
        }

        return $locked;
    }
}

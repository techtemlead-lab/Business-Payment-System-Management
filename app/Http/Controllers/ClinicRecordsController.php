<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PatientAttachments;
use App\Domain\Clinic\PatientRecords;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A prescriber's clinical records on their own patients. Every action checks that the patient
 * belongs to the signed-in prescriber; anyone else gets a 404.
 */
class ClinicRecordsController extends Controller
{
    public function __construct(private PatientRecords $records, private PatientAttachments $attachments) {}

    /** One patient's history page. */
    public function patient(Request $request, PrescriptionPatient $patient): Response
    {
        $record = $this->records->page($request->user(), $patient);

        return Inertia::render('PatientRecord', ['record' => [
            ...$record,
            'attachments' => $this->attachments->listFor($patient),
            'attachment_kinds' => PatientAttachments::Kinds,
        ]]);
    }

    /** The newest prescription as a draft for the new-prescription form. */
    public function repeatDraft(Request $request, PrescriptionPatient $patient): JsonResponse
    {
        return response()->json($this->records->repeatDraft($request->user(), $patient));
    }

    public function storeAttachment(Request $request, PrescriptionPatient $patient): RedirectResponse
    {
        // Before validation, so someone else's upload gets the same 404 whatever they send.
        $this->records->assertOwn($request->user(), $patient);
        $data = $request->validate([
            'kind' => ['required', Rule::in(PatientAttachments::Kinds)],
            'file' => 'required|file|mimes:'.PatientAttachments::AllowedTypes.'|max:'.PatientAttachments::MaxKilobytes,
        ]);
        $this->attachments->store($request->user(), $patient, $data['kind'], $request->file('file'));

        return back()->with('success', __('clinic_records.flash_file_saved'));
    }

    public function downloadAttachment(Request $request, PrescriptionPatient $patient, PrescriptionPatientAttachment $attachment): StreamedResponse
    {
        return $this->attachments->download($request->user(), $patient, $attachment);
    }

    public function destroyAttachment(Request $request, PrescriptionPatient $patient, PrescriptionPatientAttachment $attachment): RedirectResponse
    {
        $this->attachments->delete($request->user(), $patient, $attachment);

        return back()->with('success', __('clinic_records.flash_file_deleted'));
    }
}

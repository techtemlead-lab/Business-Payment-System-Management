<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PatientRecords;
use App\Models\PrescriptionPatient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A prescriber's clinical records on their own patients. Every action checks that the patient
 * belongs to the signed-in prescriber; anyone else gets a 404.
 */
class ClinicRecordsController extends Controller
{
    public function __construct(private PatientRecords $records) {}

    /** One patient's history page. */
    public function patient(Request $request, PrescriptionPatient $patient): Response
    {
        return Inertia::render('PatientRecord', ['record' => $this->records->page($request->user(), $patient)]);
    }

    /** The newest prescription as a draft for the new-prescription form. */
    public function repeatDraft(Request $request, PrescriptionPatient $patient): JsonResponse
    {
        return response()->json($this->records->repeatDraft($request->user(), $patient));
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\ConsultationRequests;
use App\Domain\Clinic\PrescriberDirectory;
use App\Domain\Clinic\PrescriptionAccess;
use App\Models\ConsultationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A prescriber's own public-listing choice and the consultation requests sent to them. Every
 * action is limited to the signed-in prescriber's own records.
 */
class PrescriberPublicToolsController extends Controller
{
    public function saveListing(Request $request, PrescriberDirectory $directory): RedirectResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $directory->saveListing($request->user(), $request->validate([
            'listed' => 'required|boolean',
            'chamber_hours' => 'nullable|string|max:200',
        ]));

        return back()->with('success', __('prescriber_public.flash_listing_saved'));
    }

    public function accept(Request $request, ConsultationRequest $consultation, ConsultationRequests $requests): RedirectResponse
    {
        $patient = $requests->accept($request->user(), $consultation);

        return back()->with('success', __('prescriber_public.flash_accepted', ['name' => $patient->name]));
    }

    public function decline(Request $request, ConsultationRequest $consultation, ConsultationRequests $requests): RedirectResponse
    {
        $requests->decline($request->user(), $consultation);

        return back()->with('success', __('prescriber_public.flash_declined'));
    }
}

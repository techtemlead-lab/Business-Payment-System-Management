<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\ConsultationRequests;
use App\Domain\Clinic\PrescriberDirectory;
use App\Domain\Clinic\PrescriptionAccess;
use App\Support\SiteChrome;
use App\Support\SiteCustomization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The public prescriber directory and its consultation request form.
 */
class PublicPrescriberController extends Controller
{
    public function index(Request $request, PrescriberDirectory $directory): View
    {
        $this->assertPageIsOn();

        return view('reference.prescribers', $directory->page($request->only(PrescriberDirectory::FilterLevels)))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }

    public function consult(Request $request, int $listing, PrescriberDirectory $directory, ConsultationRequests $requests): RedirectResponse
    {
        $this->assertPageIsOn();
        $prescriber = $directory->findListed($listing);
        abort_if($prescriber === null, 404);
        $phone = $request->input('phone');
        if (is_string($phone)) {
            $request->merge(['phone' => strtr($phone, PrescriberDirectory::BanglaDigits)]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[\d\s-]{6,20}$/'],
            'problem' => 'required|string|min:5|max:2000',
            'preferred_time' => 'nullable|string|max:120',
            'consent' => 'accepted',
            'website' => 'nullable|max:0',
        ]);
        $requests->submit(
            $prescriber,
            $data,
            SiteChrome::text('prescribers_consent_line', SiteCustomization::all()['prescribers_consent_line'] ?? null),
        );

        return back()->with('success', __('prescriber_public.flash_sent'));
    }

    private function assertPageIsOn(): void
    {
        abort_unless(PrescriptionAccess::enabled() && SiteCustomization::isPageEnabled('prescribers'), 404);
    }
}

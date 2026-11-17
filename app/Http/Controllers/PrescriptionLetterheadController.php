<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionLetterhead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A prescriber's own letterhead (header, footer, logo). Everyone reads and writes only their
 * own: the account is the one signed in, never an id from the request.
 */
class PrescriptionLetterheadController extends Controller
{
    public function save(Request $request, PrescriptionLetterhead $letterhead): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && PrescriptionAccess::canPrescribe($user), 403);
        $data = $request->validate(PrescriptionLetterhead::rules());
        $letterhead->save($user, $data, $request->file('logo'), (bool) ($data['remove_logo'] ?? false));

        return back()->with('success', __('prescription_letterhead.flash_saved'));
    }

    /** The signed-in prescriber's own uploaded logo, for the settings preview. */
    public function logo(Request $request, PrescriptionLetterhead $letterhead): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user !== null && PrescriptionAccess::canPrescribe($user), 403);
        $path = $letterhead->ownLogoPath($user);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}

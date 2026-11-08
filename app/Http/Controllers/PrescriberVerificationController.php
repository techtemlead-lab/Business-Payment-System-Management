<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PrescriberVerification;
use App\Domain\Clinic\PrescriptionAccess;
use App\Models\PrescriberRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The per-partner prescribing switch on the owner's accounts page. Owners and staff with
 * the "prescriber_verify" permission turn it on after checking the registration offline.
 */
class PrescriberVerificationController extends Controller
{
    public function __construct(private PrescriberVerification $verification) {}

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless(PrescriptionAccess::canVerify($request->user()), 403);
        abort_unless(PrescriptionAccess::isPartner($user), 404);

        return response()->json([
            'can_edit' => true,
            'types' => PrescriberRegistration::Types,
            ...$this->verification->status($user),
        ]);
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'registration_type' => ['required', Rule::in(PrescriberRegistration::Types)],
            'registration_number' => 'required|string|min:2|max:60',
            'note' => 'nullable|string|max:1000',
        ]);
        $this->verification->enable($request->user(), $user, $data);

        return back()->with('success', __('prescription.flash_enabled', ['name' => $user->name]));
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $this->verification->disable($request->user(), $user, $data['reason'] ?? null);

        return back()->with('success', __('prescription.flash_disabled', ['name' => $user->name]));
    }
}

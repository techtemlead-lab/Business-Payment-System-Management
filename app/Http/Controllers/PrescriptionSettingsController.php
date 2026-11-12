<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owner's switch for medicines that are not company products. EnforceStaffActions lets
 * the Super Admin through and this controller checks again.
 */
class PrescriptionSettingsController extends Controller
{
    public function otherMedicines(Request $request, PrescriptionSettings $settings): RedirectResponse
    {
        $owner = $request->user();
        // The owner's own account only; MD and Chairman are refused like any other account.
        abort_unless($owner !== null && PrescriptionAccess::isOwnerDoctor($owner), 403);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
        ]);
        $enabled = $data['enabled'] === '1';
        $settings->save($owner, $enabled, (int) $data['version']);

        return back()->with('success', __($enabled ? 'prescription.flash_other_medicines_on' : 'prescription.flash_other_medicines_off'));
    }
}

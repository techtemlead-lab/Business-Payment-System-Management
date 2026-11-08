<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionTemplates;
use App\Models\Prescription;
use App\Models\PrescriptionTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A prescriber's own working tools on the prescriptions page. Every action is limited to
 * the signed-in prescriber's own records.
 */
class PrescriptionToolsController extends Controller
{
    public function __construct(private PrescriptionTemplates $templates) {}

    public function storeTemplate(Request $request): RedirectResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:80',
            'prescription_id' => 'required|integer',
        ]);
        $template = $this->templates->saveFromPrescription(
            $request->user(),
            Prescription::query()->findOrFail($data['prescription_id']),
            $data['name'],
        );

        return back()->with('success', __('prescription.flash_template_saved', ['name' => $template->name]));
    }

    public function destroyTemplate(Request $request, PrescriptionTemplate $template): RedirectResponse
    {
        $this->templates->delete($request->user(), $template);

        return back()->with('success', __('prescription.flash_template_deleted'));
    }
}

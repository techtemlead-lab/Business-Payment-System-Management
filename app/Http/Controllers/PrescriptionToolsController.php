<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionDietTemplates;
use App\Domain\Clinic\PrescriptionFollowUps;
use App\Domain\Clinic\PrescriptionLists;
use App\Domain\Clinic\PrescriptionTemplates;
use App\Models\Prescription;
use App\Models\PrescriptionDietTemplate;
use App\Models\PrescriptionTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A prescriber's own working tools on the prescriptions page. Every action is limited to
 * the signed-in prescriber's own records.
 */
class PrescriptionToolsController extends Controller
{
    public function __construct(
        private PrescriptionTemplates $templates,
        private PrescriptionFollowUps $followUps,
        private PrescriptionDietTemplates $dietTemplates,
    ) {}

    public function storeDietTemplate(Request $request): RedirectResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $template = $this->dietTemplates->save($request->user(), null, $request->validate($this->dietTemplateRules()));

        return back()->with('success', __('prescription.flash_diet_template_saved', ['name' => $template->name]));
    }

    public function updateDietTemplate(Request $request, PrescriptionDietTemplate $dietTemplate): RedirectResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        // Ownership before validation, so another prescriber's template looks like one that doesn't exist.
        abort_unless((int) $dietTemplate->prescriber_id === (int) $request->user()->id, 404);
        $template = $this->dietTemplates->save($request->user(), $dietTemplate, $request->validate($this->dietTemplateRules()));

        return back()->with('success', __('prescription.flash_diet_template_saved', ['name' => $template->name]));
    }

    public function destroyDietTemplate(Request $request, PrescriptionDietTemplate $dietTemplate): RedirectResponse
    {
        $this->dietTemplates->delete($request->user(), $dietTemplate);

        return back()->with('success', __('prescription.flash_diet_template_deleted'));
    }

    /** @return array<string, string> */
    private function dietTemplateRules(): array
    {
        return [
            'name' => 'required|string|min:2|max:80',
            ...PrescriptionLists::rules('foods_allowed'),
            ...PrescriptionLists::rules('foods_avoided'),
        ];
    }

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

    public function markFollowUpDone(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->followUps->markDone($request->user(), $prescription);

        return back()->with('success', __('prescription.flash_follow_up_done'));
    }
}

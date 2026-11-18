<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\PartnerAge;
use App\Domain\Audience\PublicPartnerPlaces;
use App\Support\Audit;
use App\Support\SiteCustomization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an applicant sees after applying: the place lists on the form, and a status page found with the
 * application number and the phone number. The applicant can read the office's reason and, when the
 * office sent the application back, correct it and send it again.
 */
class PartnerApplicationStatusController extends Controller
{
    public function places(Request $request, PublicPartnerPlaces $places): JsonResponse
    {
        abort_unless(SiteCustomization::isPageEnabled('partnership'), 404);
        $data = $request->validate(['level' => 'required|string|max:20', 'parent_id' => 'nullable|integer']);

        return response()->json(['data' => $places->children($data['level'], isset($data['parent_id']) ? (int) $data['parent_id'] : null)]);
    }

    public function form(): View
    {
        abort_unless(SiteCustomization::isPageEnabled('partnership'), 404);

        return view('reference.partnership-status', ['application' => null, 'lookupFailed' => false]);
    }

    public function lookup(Request $request, AccountPhoneRules $phones): View
    {
        abort_unless(SiteCustomization::isPageEnabled('partnership'), 404);
        $application = $this->find($request, $phones);

        return view('reference.partnership-status', ['application' => $application, 'lookupFailed' => $application === null]);
    }

    public function resubmit(Request $request, AccountPhoneRules $phones): View
    {
        abort_unless(SiteCustomization::isPageEnabled('partnership'), 404);
        $application = $this->find($request, $phones);
        abort_if($application === null || $application->status !== 'correction', 404);

        if (! $request->filled('date_of_birth') && $request->filled('dob_year')) {
            $request->merge(['date_of_birth' => sprintf('%04d-%02d-%02d', (int) $request->input('dob_year'), (int) $request->input('dob_month'), (int) $request->input('dob_day'))]);
        }
        $fields = $request->validate([
            'name' => 'required|string|max:150',
            'nid' => ['nullable', 'regex:/^\d{10}$|^\d{13}$|^\d{17}$/'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', PartnerAge::rule()],
            'message' => 'nullable|string|max:2000',
        ]);
        $payload = $application->payload;
        $payload = [...$payload, 'name' => $fields['name'], 'nid' => $fields['nid'] ?? null, 'date_of_birth' => $fields['date_of_birth'], 'message' => $fields['message'] ?? ($payload['message'] ?? null)];
        DB::transaction(function () use ($application, $payload): void {
            DB::table('dealer_applications')->where('id', $application->id)->where('status', 'correction')->update([
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'updated_at' => now(),
            ]);
            Audit::record(null, 'dealer_application.resubmitted', (string) $application->id, ['number' => $application->application_number]);
            Audit::notifyAdminsAndStaff(
                ['accounts'],
                fn (string $locale): string => __('joining.notice_resubmitted_title', [], $locale),
                fn (string $locale): string => __('joining.notice_resubmitted_body', ['name' => (string) $payload['name'], 'number' => (string) $application->application_number], $locale),
            );
        }, 3);
        $application = $this->find($request, $phones);

        return view('reference.partnership-status', ['application' => $application, 'lookupFailed' => false, 'resubmitted' => true]);
    }

    /**
     * The application with this number and this phone, or null. Both must match, so a number alone never opens it.
     */
    private function find(Request $request, AccountPhoneRules $phones): ?object
    {
        $data = $request->validate(['number' => 'required|string|max:30', 'phone' => 'required|string|max:30']);
        try {
            $phone = $phones->normalize($data['phone']);
        } catch (ValidationException) {
            return null;
        }
        $row = DB::table('dealer_applications')
            ->where('application_number', strtoupper(trim($data['number'])))
            ->first(['id', 'application_number', 'status', 'reason', 'correction_note', 'data', 'created_at', 'updated_at']);
        if (! $row) {
            return null;
        }
        $payload = json_decode((string) $row->data, true);
        $payload = is_array($payload) ? $payload : [];
        if (($payload['phone'] ?? null) !== $phone) {
            return null;
        }
        $row->payload = $payload;
        $row->number_entered = strtoupper(trim($data['number']));
        $row->phone_entered = $data['phone'];

        return $row;
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Clinic\PrescriberVerification;
use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionBook;
use App\Models\PlaceCode;
use App\Models\PrescriberRegistration;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Support\PhoneIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Prescriber actions: patients, prescriptions, the printable page, and for company-stock
 * prescribers the clinic dispense.
 */
class PrescriptionController extends Controller
{
    public function __construct(private PrescriptionBook $book, private ClinicDispensing $dispensing) {}

    public function storePatient(Request $request): RedirectResponse
    {
        $this->book->savePatient($request->user(), null, $request->validate($this->patientRules()));

        return back()->with('success', __('prescription.flash_patient_saved'));
    }

    public function updatePatient(Request $request, PrescriptionPatient $patient): RedirectResponse
    {
        $this->book->savePatient($request->user(), $patient, $request->validate($this->patientRules()));

        return back()->with('success', __('prescription.flash_patient_saved'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $clinic = PrescriptionAccess::usesCompanyStock($request->user());
        $data = $request->validate([
            ClinicDispensing::ConfirmBelowFloorField => 'nullable|boolean',
            'patient_id' => 'required|integer',
            'prescribed_on' => 'required|date_format:Y-m-d',
            'complaints' => 'nullable|string|max:3000',
            'diagnosis' => 'nullable|string|max:3000',
            'advice' => 'nullable|string|max:3000',
            'follow_up_on' => 'nullable|date_format:Y-m-d|after_or_equal:prescribed_on',
            'lines' => 'required|array|min:1|max:'.PrescriptionBook::MaxLines,
            'lines.*.product_id' => $clinic ? 'required|integer' : 'prohibited',
            'lines.*.medicine_name' => $clinic ? 'nullable|string|max:160' : 'required|string|max:160',
            'lines.*.dose' => 'nullable|string|max:80',
            'lines.*.frequency' => 'nullable|string|max:80',
            'lines.*.duration' => 'nullable|string|max:80',
            'lines.*.instructions' => 'nullable|string|max:300',
            'lines.*.unit_price' => 'required|numeric|decimal:0,2|min:0|max:9999999',
            'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'lines.*.discount' => 'nullable|numeric|decimal:0,2|min:0|max:9999999',
        ]);
        $prescription = $this->book->create($request->user(), $data);

        return back()->with('success', __('prescription.flash_saved', ['number' => $prescription->number]));
    }

    public function dispense(Request $request, Prescription $prescription): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|integer|exists:company_branches,id',
            'payment_method' => ['nullable', Rule::in(Prescription::PaymentMethods)],
            'payment_reference' => 'nullable|string|max:80',
            ClinicDispensing::ConfirmBelowFloorField => 'nullable|boolean',
        ]);
        $this->book->assertVisible($request->user(), $prescription);
        $this->dispensing->dispense(
            $request->user(),
            $prescription,
            (int) $data['branch_id'],
            $data['payment_method'] ?? Prescription::PaymentCash,
            $data['payment_reference'] ?? null,
            (bool) ($data[ClinicDispensing::ConfirmBelowFloorField] ?? false),
        );

        return back()->with('success', __('prescription.flash_dispensed', ['number' => $prescription->number]));
    }

    /** The prescriber's own patients for the picker on the new-prescription form. */
    public function patientOptions(Request $request): JsonResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $data = $request->validate(['q' => 'nullable|string|max:80']);

        return response()->json(['data' => $this->book->patientOptions($request->user(), (string) ($data['q'] ?? ''))]);
    }

    public function registration(Request $request, PrescriberVerification $verification): RedirectResponse
    {
        $data = $request->validate([
            'registration_type' => ['required', Rule::in(PrescriberRegistration::Types)],
            'registration_number' => 'required|string|min:2|max:60',
            'note' => 'nullable|string|max:1000',
        ]);
        $verification->saveOwn($request->user(), $data);

        return back()->with('success', __('prescription.flash_setting_saved'));
    }

    /** Place codes for the patient form; partners can't use the office place-code tools. */
    public function places(Request $request): JsonResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $data = $request->validate([
            'parent_id' => 'nullable|integer',
            'level' => 'required|in:'.implode(',', PlaceCode::Levels),
            'q' => 'nullable|string|max:80',
        ]);
        $places = PlaceCode::query()
            ->where('level', $data['level'])
            ->where('is_active', true)
            ->whereNotNull('code')
            ->when(isset($data['parent_id']), fn ($builder) => $builder->where('parent_id', $data['parent_id']))
            ->when(($data['q'] ?? '') !== '', function ($builder) use ($data): void {
                $term = '%'.mb_strtolower(trim((string) $data['q'])).'%';
                $builder->where(fn ($inner) => $inner->whereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]));
            })
            ->orderBy('sort_order')
            ->orderBy('code')
            ->limit(100)
            ->get(['id', 'code', 'name', 'level', 'parent_id', 'is_active'])
            ->filter(fn (PlaceCode $place): bool => $place->isReadyForAssignment())
            ->values()
            ->map(fn (PlaceCode $place): array => [
                'id' => $place->id,
                'code' => PlaceCode::displayLabel($place->code),
                'name' => PlaceCode::displayLabel($place->name),
                'level' => $place->level,
            ]);

        return response()->json(['data' => $places]);
    }

    /** The printable prescription in Bangla (?lang=bn) or English (?lang=en). */
    public function print(Request $request, Prescription $prescription): Response
    {
        $this->book->assertVisible($request->user(), $prescription);
        $lang = in_array($request->query('lang'), ['bn', 'en'], true) ? (string) $request->query('lang') : app()->getLocale();
        $prescription->load(['lines', 'patient.placeCode', 'prescriber:id,name,phone']);
        $registration = PrescriberRegistration::query()->where('user_id', $prescription->prescriber_id)->first();

        return response()->view('prescriptions.print', [
            'prescription' => $prescription,
            'registration' => $registration,
            'lang' => $lang,
            'company' => (string) __('portal.brand_company_bar', [], $lang),
            'phone' => PhoneIdentity::maskedForPrint($prescription->patient?->phone),
            'csp_nonce' => (string) $request->attributes->get('csp_nonce', ''),
        ]);
    }

    /** @return array<string, mixed> */
    private function patientRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'age' => 'nullable|integer|min:0|max:130',
            'sex' => ['nullable', Rule::in(PrescriptionPatient::Sexes)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[\d\s-]{6,20}$/'],
            'place_code_id' => 'nullable|integer|exists:place_codes,id',
            'notes' => 'nullable|string|max:2000',
            'allergies' => 'nullable|string|max:1000',
        ];
    }
}

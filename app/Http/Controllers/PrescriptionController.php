<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Clinic\DoseSchedule;
use App\Domain\Clinic\PatientRecords;
use App\Domain\Clinic\PrescriberVerification;
use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionBook;
use App\Domain\Clinic\PrescriptionLists;
use App\Domain\Clinic\Vitals;
use App\Models\PlaceCode;
use App\Models\PrescriberRegistration;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Support\PhoneIdentity;
use App\Support\ReasonFields;
use App\Support\TransactionReference;
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
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $this->book->savePatient($request->user(), null, $request->validate([...$this->patientRules(), 'patient_consent' => 'accepted']));

        return back()->with('success', __('prescription.flash_patient_saved'));
    }

    public function updatePatient(Request $request, PrescriptionPatient $patient): RedirectResponse
    {
        $this->book->savePatient($request->user(), $patient, $request->validate([...$this->patientRules(), 'patient_consent' => 'nullable|boolean']));

        return back()->with('success', __('prescription.flash_patient_saved'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PrescriptionAccess::canPrescribe($request->user()), 403);
        $data = $request->validate([
            ClinicDispensing::ConfirmBelowFloorField => 'nullable|boolean',
            // Either one of the prescriber's own patients, or a new patient saved by the same request.
            'patient_id' => 'required_without:new_patient|nullable|integer|prohibits:new_patient',
            'new_patient' => 'nullable|array',
            // Only when a new patient is sent: "accepted" would otherwise refuse every old patient.
            ...($request->filled('new_patient') ? $this->newPatientRules() : []),
            'prescribed_on' => 'required|date_format:Y-m-d',
            'complaints' => 'nullable|string|max:3000',
            'diagnosis' => 'nullable|string|max:3000',
            'advice' => 'nullable|string|max:3000',
            'follow_up_on' => 'nullable|date_format:Y-m-d|after_or_equal:prescribed_on',
            ...Vitals::rules(),
            ...PrescriptionLists::rules('investigations'),
            ...PrescriptionLists::rules('foods_allowed'),
            ...PrescriptionLists::rules('foods_avoided'),
            // A prescription may hold tests, foods or advice only; PrescriptionBook refuses an empty one.
            'lines' => 'nullable|array|max:'.PrescriptionBook::MaxLines,
            // A line is a company product, or a medicine typed by hand while the owners allow that;
            // PrescriptionBook decides which, and refuses a hand-typed one when the switch is off.
            'lines.*.product_id' => 'nullable|integer',
            'lines.*.medicine_name' => 'nullable|string|max:160',
            'lines.*.strength' => 'nullable|string|max:80',
            'lines.*.dose' => 'nullable|string|max:80',
            'lines.*.frequency' => 'nullable|string|max:80',
            'lines.*.duration' => 'nullable|string|max:80',
            'lines.*.instructions' => 'nullable|string|max:300',
            'lines.*.dose_schedule' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! DoseSchedule::isValid((string) $value)) {
                    $fail(__('clinic_records.err_dose_schedule'));
                }
            }],
            'lines.*.food_timing' => ['nullable', Rule::in(DoseSchedule::FoodTimings)],
            'lines.*.duration_count' => 'nullable|integer|min:1|max:365|required_with:lines.*.duration_unit',
            'lines.*.duration_unit' => ['nullable', Rule::in(DoseSchedule::DurationUnits), 'required_with:lines.*.duration_count'],
            'lines.*.unit_price' => 'required|numeric|decimal:0,2|min:0|max:9999999',
            'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'lines.*.discount' => 'nullable|numeric|decimal:0,2|min:0|max:9999999',
        ]);
        $prescription = $this->book->create($request->user(), $data);

        return back()->with('success', __('prescription.flash_saved', ['number' => $prescription->number]));
    }

    public function dispense(Request $request, Prescription $prescription): RedirectResponse
    {
        TransactionReference::tidy($request, 'payment_reference');
        $data = $request->validate([
            'branch_id' => 'required|integer|exists:company_branches,id',
            'payment_method' => ['nullable', Rule::in(Prescription::PaymentMethods)],
            'payment_reference' => 'nullable|string|'.TransactionReference::MinRule.'|max:80',
            ClinicDispensing::ConfirmBelowFloorField => 'nullable|boolean',
        ], TransactionReference::messages('payment_reference'));
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
            'note' => ReasonFields::rule('clinic.registration_note', 1000),
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
    public function print(Request $request, Prescription $prescription, PatientRecords $records): Response
    {
        $records->openForPrint($request->user(), $prescription);
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

    /**
     * The patient rules for a patient added on the prescription form itself. The consent tick
     * is as compulsory there as on the patient form.
     *
     * @return array<string, mixed>
     */
    private function newPatientRules(): array
    {
        $rules = ['new_patient.patient_consent' => 'accepted'];
        foreach ($this->patientRules() as $field => $rule) {
            $rules['new_patient.'.$field] = $rule;
        }

        return $rules;
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
            'notes' => ReasonFields::rule('clinic.patient_notes', 2000),
            'allergies' => 'nullable|string|max:1000',
        ];
    }
}

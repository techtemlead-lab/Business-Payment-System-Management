<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionBook;
use App\Domain\Inventory\BranchOperations;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\PrescriberRegistration;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * The prescriptions page. Every prescriber sees only their own patients and prescriptions,
 * the owner included. Partners type their medicines; company-stock prescribers pick company
 * products and dispense from a branch. The owner also reads the clinic report, which holds
 * totals only and no patient details.
 */
final class PrescriptionsSection implements WorkspaceSection
{
    /** Rows shown on each page of a list. */
    private const ListLimit = 100;

    public function __construct(private PrescriptionBook $book, private ClinicDispensing $dispensing, private BranchOperations $branches) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'prescriptions') {
            return [];
        }
        $user = $context->user;
        abort_unless(PrescriptionAccess::canPrescribe($user), 403);
        $clinic = PrescriptionAccess::usesCompanyStock($user);
        $owner = PrescriptionAccess::isOwnerDoctor($user);
        $search = trim((string) $context->request->query('q', ''));

        $patientPage = PrescriptionPatient::query()
            ->visibleTo($user)
            ->with(['placeCode:id,code,name'])
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->orderByDesc('id')
            ->paginate(self::ListLimit, ['*'], 'patients_page')
            ->withQueryString()
            ->appends(['tab' => 'patients']);
        $patients = collect($patientPage->items())
            ->map(fn (PrescriptionPatient $patient): array => $this->book->patientRow($patient, $user))
            ->values()
            ->all();

        $prescriptionPage = Prescription::query()
            ->where('prescriber_id', $user->id)
            ->with(['patient:id,name', 'lines'])
            ->orderByDesc('id')
            ->paginate(self::ListLimit, ['*'], 'rx_page')
            ->withQueryString()
            ->appends(['tab' => 'prescriptions']);
        $prescriptions = collect($prescriptionPage->items())
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'number' => $prescription->number,
                'kind' => $prescription->kind,
                'own' => true,
                'patient' => $prescription->patient?->name,
                'prescribed_on' => $prescription->prescribed_on->toDateString(),
                'follow_up_on' => $prescription->follow_up_on?->toDateString(),
                'diagnosis' => $prescription->diagnosis,
                'subtotal' => $prescription->subtotal,
                'discount_total' => $prescription->discount_total,
                'total' => $prescription->total,
                'status' => $prescription->status,
                'manufacturer_share' => $prescription->manufacturer_share,
                'clinic_fund' => $prescription->clinic_fund,
                'payment_method' => $prescription->payment_method,
                'payment_reference' => $prescription->payment_reference,
                'lines' => $prescription->lines->map(fn ($line): array => [
                    'medicine_name' => $line->medicine_name,
                    'dose' => $line->dose,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'quantity' => $line->quantity,
                    'line_total' => $line->line_total,
                ])->all(),
            ])
            ->values()
            ->all();

        $registration = PrescriberRegistration::query()->where('user_id', $user->id)->first(['registration_type', 'registration_number']);
        $payload = [
            'mode' => $clinic ? 'clinic' : 'partner',
            'is_owner' => $owner,
            'patients' => $patients,
            'patients_page' => Arr::except($patientPage->toArray(), 'data'),
            'own_patients' => $this->book->patientOptions($user),
            'prescriptions' => $prescriptions,
            'prescriptions_page' => Arr::except($prescriptionPage->toArray(), 'data'),
            'sexes' => PrescriptionPatient::Sexes,
            'registration' => $registration ? ['type' => $registration->registration_type, 'number' => $registration->registration_number] : null,
            'registration_types' => PrescriberRegistration::Types,
            'max_lines' => PrescriptionBook::MaxLines,
        ];

        if ($clinic) {
            $payload['products'] = Product::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'mrp'])
                ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'mrp' => $product->mrp])
                ->all();
            // Only branches this person can really take stock out of (BranchOperations::resolveWritableBranch).
            $payload['branches'] = CompanyBranch::query()->active()->where('is_stock_hub', true)
                ->when(! $this->branches->canManageAllBranches($user), fn ($query) => $query->whereIn('id', BranchStaffAssignment::query()->where('user_id', $user->id)->select('branch_id')))
                ->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (CompanyBranch $branch): array => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->all();
            $payload['payment_methods'] = Prescription::PaymentMethods;
            $payload['manufacturer_percent'] = ClinicDispensing::ManufacturerMrpPercent;
        }

        if ($owner) {
            $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
            $from = SectionDates::optionalDate($context->request->query('from'));
            $to = SectionDates::optionalDate($context->request->query('to'));
            $fromDate = $from ? CarbonImmutable::createFromFormat('Y-m-d', $from, $tz) : CarbonImmutable::now($tz)->startOfMonth();
            $toDate = $to ? CarbonImmutable::createFromFormat('Y-m-d', $to, $tz) : CarbonImmutable::now($tz);
            $payload['report'] = $this->dispensing->report($fromDate, $toDate);
        }

        return ['prescriptions' => $payload];
    }
}

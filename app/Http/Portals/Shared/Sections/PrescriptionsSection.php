<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionBook;
use App\Models\CompanyBranch;
use App\Models\PrescriberRegistration;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * The prescriptions page. Partners see their own patients and typed prescriptions. The
 * owner-doctor also sees every partner's patients, picks company products, dispenses from a
 * branch, and reads the clinic report.
 */
final class PrescriptionsSection implements WorkspaceSection
{
    /** Rows shown in each list on the page. */
    private const ListLimit = 100;

    public function __construct(private PrescriptionBook $book, private ClinicDispensing $dispensing) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'prescriptions') {
            return [];
        }
        $user = $context->user;
        abort_unless(PrescriptionAccess::canPrescribe($user), 403);
        $clinic = PrescriptionAccess::isOwnerDoctor($user);
        $seesAll = PrescriptionAccess::seesAllPatients($user);
        $search = trim((string) $context->request->query('q', ''));

        $patients = PrescriptionPatient::query()
            ->visibleTo($user, $seesAll)
            ->with(['placeCode:id,code,name', ...($seesAll ? ['prescriber:id,name'] : [])])
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->orderByDesc('id')
            ->limit(self::ListLimit)
            ->get()
            ->map(fn (PrescriptionPatient $patient): array => $this->book->patientRow($patient, $user))
            ->values()
            ->all();

        $prescriptions = Prescription::query()
            ->when(! $seesAll, fn ($query) => $query->where('prescriber_id', $user->id))
            ->with(['patient:id,name', 'lines', ...($seesAll ? ['prescriber:id,name'] : [])])
            ->orderByDesc('id')
            ->limit(self::ListLimit)
            ->get()
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'number' => $prescription->number,
                'kind' => $prescription->kind,
                'own' => (int) $prescription->prescriber_id === (int) $user->id,
                'prescriber' => $seesAll ? $prescription->prescriber?->name : null,
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
            'patients' => $patients,
            'prescriptions' => $prescriptions,
            'sexes' => PrescriptionPatient::Sexes,
            'registration' => $registration ? ['type' => $registration->registration_type, 'number' => $registration->registration_number] : null,
            'registration_types' => PrescriberRegistration::Types,
            'max_lines' => PrescriptionBook::MaxLines,
        ];

        if ($clinic) {
            $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
            $from = SectionDates::optionalDate($context->request->query('from'));
            $to = SectionDates::optionalDate($context->request->query('to'));
            $fromDate = $from ? CarbonImmutable::createFromFormat('Y-m-d', $from, $tz) : CarbonImmutable::now($tz)->startOfMonth();
            $toDate = $to ? CarbonImmutable::createFromFormat('Y-m-d', $to, $tz) : CarbonImmutable::now($tz);
            $payload['products'] = Product::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'mrp'])
                ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'mrp' => $product->mrp])
                ->all();
            $payload['branches'] = CompanyBranch::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (CompanyBranch $branch): array => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->all();
            $payload['price_floor'] = $this->dispensing->priceFloor()['mode'];
            $payload['manufacturer_percent'] = ClinicDispensing::ManufacturerMrpPercent;
            $payload['report'] = $this->dispensing->report($fromDate, $toDate);
        }

        return ['prescriptions' => $payload];
    }
}

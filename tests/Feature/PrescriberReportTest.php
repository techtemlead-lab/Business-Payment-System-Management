<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrescriberReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-15 06:00:00', 'UTC'));
    }

    private function prescriber(): User
    {
        return User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
    }

    private function written(PrescriptionPatient $patient, string $date, string $total, string $discount = '0.00'): void
    {
        Prescription::factory()->create([
            'patient_id' => $patient->id, 'prescribed_on' => $date,
            'subtotal' => number_format((float) $total + (float) $discount, 2, '.', ''), 'discount_total' => $discount, 'total' => $total,
        ]);
    }

    public function test_a_dealer_sees_their_own_prescriptions_patients_and_typed_totals_for_the_period(): void
    {
        $dealer = $this->prescriber();
        $patientA = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $patientB = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $this->written($patientA, '2026-11-03', '100.00', '10.00');
        $this->written($patientA, '2026-11-03', '50.50');
        $this->written($patientB, '2026-11-10', '200.00');
        $this->written($patientB, '2026-10-20', '999.00');

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions?from=2026-11-01&to=2026-11-15')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.report.grouped_by', 'day')
                ->where('data.prescription_tools.report.prescriptions', 3)
                ->where('data.prescription_tools.report.patients', 2)
                ->where('data.prescription_tools.report.billed', '350.50')
                ->where('data.prescription_tools.report.discount', '10.00')
                ->where('data.prescription_tools.report.clinic', null)
                ->has('data.prescription_tools.report.rows', 2)
                ->where('data.prescription_tools.report.rows.0.period', '2026-11-03')
                ->where('data.prescription_tools.report.rows.0.prescriptions', 2)
                ->where('data.prescription_tools.report.rows.0.patients', 1)
                ->where('data.prescription_tools.report.rows.0.billed', '150.50')
                ->missing('data.prescription_tools.report.rows.0.manufacturer_share'));
    }

    public function test_the_report_defaults_to_this_month_and_groups_a_long_period_by_month(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $this->written($patient, '2026-11-02', '10.00');
        $this->written($patient, '2026-09-02', '20.00');
        $this->written($patient, '2026-09-25', '30.00');

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.report.from', '2026-11-01')
                ->where('data.prescription_tools.report.to', '2026-11-15')
                ->where('data.prescription_tools.report.billed', '10.00'));

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions?from=2026-01-01&to=2026-11-15')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.report.grouped_by', 'month')
                ->has('data.prescription_tools.report.rows', 2)
                ->where('data.prescription_tools.report.rows.0.period', '2026-09')
                ->where('data.prescription_tools.report.rows.0.billed', '50.00')
                ->where('data.prescription_tools.report.rows.1.period', '2026-11'));
    }

    public function test_a_dealers_report_never_includes_another_prescriber(): void
    {
        $first = $this->prescriber();
        $second = $this->prescriber();
        $this->written(PrescriptionPatient::factory()->create(['prescriber_id' => $first->id]), '2026-11-03', '100.00');

        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.report.prescriptions', 0)
                ->where('data.prescription_tools.report.billed', '0.00')
                ->has('data.prescription_tools.report.rows', 0));
    }

    public function test_the_owner_doctors_tools_never_carry_another_prescribers_patient(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Dealer Private Patient', 'phone' => '01799887766']);
        Prescription::factory()->create(['patient_id' => $patient->id, 'prescribed_on' => '2026-11-03', 'follow_up_on' => '2026-11-10', 'diagnosis' => 'Private diagnosis', 'total' => '100.00']);
        PrescriptionTemplate::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Dealer private template']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions?from=2026-11-01&to=2026-11-15')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.report.prescriptions', 0)
                ->where('data.prescription_tools.report.patients', 0)
                ->where('data.prescription_tools.follow_ups.overdue', 0)
                ->has('data.prescription_tools.follow_ups.rows', 0)
                ->has('data.prescription_tools.templates', 0)
                ->where('data.prescription_tools', function ($tools): bool {
                    $payload = (string) json_encode($tools);

                    return ! str_contains($payload, 'Dealer Private Patient')
                        && ! str_contains($payload, '01799887766')
                        && ! str_contains($payload, 'Private diagnosis')
                        && ! str_contains($payload, 'Dealer private template');
                }));
    }

    public function test_the_owner_doctor_sees_dispensed_sales_manufacturer_share_and_clinic_fund(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-report-in-'.$product->id, 'branch_id' => $branch->id,
        ]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        foreach (['150', '200'] as $price) {
            $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
                'patient_id' => $patient->id, 'prescribed_on' => '2026-11-15',
                'lines' => [['product_id' => $product->id, 'unit_price' => $price, 'quantity' => 3]],
            ])->assertSessionHasNoErrors();
        }
        // Only the first one is dispensed: 450 sold; 30% of the 600 MRP is 180; the clinic keeps 270.
        $dispensed = Prescription::query()->orderBy('id')->firstOrFail();
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$dispensed->id.'/dispense', ['branch_id' => $branch->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions?from=2026-11-01&to=2026-11-15')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.report.prescriptions', 2)
                ->where('data.prescription_tools.report.patients', 1)
                ->where('data.prescription_tools.report.clinic.sales', '450.00')
                ->where('data.prescription_tools.report.clinic.manufacturer_share', '180.00')
                ->where('data.prescription_tools.report.clinic.clinic_fund', '270.00')
                ->has('data.prescription_tools.report.rows', 1)
                ->where('data.prescription_tools.report.rows.0.sales', '450.00')
                ->where('data.prescription_tools.report.rows.0.clinic_fund', '270.00'));
    }

    public function test_the_report_stays_behind_the_partner_switch_and_the_feature_flag(): void
    {
        $disabled = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($disabled)->onPortal('dealer')->get('/prescriptions')->assertForbidden();

        $enabled = $this->prescriber();
        config(['madina.prescriptions.enabled' => false]);
        $this->actingAs($enabled)->onPortal('dealer')->get('/prescriptions')->assertForbidden();
    }
}

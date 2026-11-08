<?php

namespace Tests\Feature;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrescriptionModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function enable(User $actor, User $partner, string $portal = 'sadmin'): void
    {
        $this->actingAs($actor)->onPortal($portal)
            ->post('/commands/accounts/'.$partner->id.'/prescriber', [
                'registration_type' => 'bmdc',
                'registration_number' => 'A-12345',
                'note' => 'Papers checked at the office',
            ])->assertRedirect();
    }

    public function test_a_partner_cannot_use_prescriptions_until_enabled_and_enabling_is_audited_and_reversible(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/patients', ['name' => 'Rahim'])->assertForbidden();
        $this->assertSame(0, PrescriptionPatient::query()->count());

        // A partner can't switch it on for themselves.
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/accounts/'.$dealer->id.'/prescriber', ['registration_type' => 'bmdc', 'registration_number' => 'X-1'])
            ->assertForbidden();

        $this->enable($owner, $dealer);
        $this->assertTrue($dealer->fresh()->can_prescribe);
        $this->assertDatabaseHas('prescriber_registrations', [
            'user_id' => $dealer->id, 'enabled' => true, 'registration_type' => 'bmdc',
            'registration_number' => 'A-12345', 'note' => 'Papers checked at the office', 'enabled_by' => $owner->id,
        ]);
        $audit = DB::table('audit_events')->where('action', 'prescriber.enabled')->where('subject', (string) $dealer->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $this->assertSame('A-12345', json_decode($audit->metadata, true)['registration_number']);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/prescriptions')->assertOk();
        $this->actingAs($owner)->onPortal('sadmin')->getJson('/commands/accounts/'.$dealer->id.'/prescriber')
            ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('enabled_by', $owner->name);

        $this->actingAs($owner)->onPortal('sadmin')
            ->delete('/commands/accounts/'.$dealer->id.'/prescriber', ['reason' => 'Licence expired'])->assertRedirect();
        $this->assertFalse($dealer->fresh()->can_prescribe);
        $this->assertDatabaseHas('prescriber_registrations', ['user_id' => $dealer->id, 'enabled' => false, 'disabled_by' => $owner->id]);
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescriber.disabled')->where('subject', (string) $dealer->id)->exists());
        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/prescriptions')->assertForbidden();
    }

    public function test_only_owners_and_staff_with_the_verify_permission_can_enable_prescribing(): void
    {
        $subDealer = User::factory()->subDealer()->create(['status' => 'active']);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->onPortal('manage')
            ->post('/commands/accounts/'.$subDealer->id.'/prescriber', ['registration_type' => 'unani', 'registration_number' => 'U-77'])
            ->assertForbidden();

        $verifier = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts', 'prescriber_verify']]]);
        $this->enable($verifier, $subDealer, 'manage');
        $this->assertTrue($subDealer->fresh()->can_prescribe);

        $md = User::factory()->staff('md')->create();
        $this->actingAs($md)->onPortal('sadmin')
            ->delete('/commands/accounts/'.$subDealer->id.'/prescriber')->assertRedirect();
        $this->assertFalse($subDealer->fresh()->can_prescribe);
    }

    public function test_patients_are_private_to_their_own_prescriber(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $first = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $second = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $first->id, 'name' => 'Karim Private', 'phone' => '01799887766']);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions')->assertOk()->assertDontSee('Karim Private');
        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print')->assertNotFound();
        $this->actingAs($second)->onPortal('dealer')
            ->patch('/commands/prescriptions/patients/'.$patient->id, ['name' => 'Changed'])->assertNotFound();
        $this->actingAs($second)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08',
            'lines' => [['medicine_name' => 'Paracetamol', 'unit_price' => 2, 'quantity' => 10]],
        ])->assertNotFound();
        $this->assertSame('Karim Private', $patient->fresh()->name);

        $this->actingAs($first)->onPortal('dealer')->get('/prescriptions')->assertOk()->assertSee('Karim Private')->assertSee('01799887766');
        // Not even the owner can read another prescriber's patient.
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertDontSee('Karim Private')
            ->assertDontSee('01799887766')
            ->assertInertia(fn (Assert $page) => $page->where('data.prescriptions.patients', []));
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions/'.$prescription->id.'/print')->assertNotFound();
    }

    public function test_a_dealer_prescription_totals_discounts_and_never_touches_stock(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $product = Product::factory()->create(['stock' => 20]);
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/patients', ['name' => 'Rahima', 'age' => 34, 'sex' => 'female', 'phone' => '01811111111'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $patient = PrescriptionPatient::query()->where('prescriber_id', $dealer->id)->firstOrFail();
        $movementsBefore = DB::table('stock_movements')->count();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-08',
            'complaints' => 'Fever',
            'diagnosis' => 'Viral fever',
            'advice' => 'Rest',
            'follow_up_on' => '2026-11-15',
            'lines' => [
                ['medicine_name' => 'Napa 500', 'dose' => '1 tab', 'frequency' => '3 times', 'duration' => '5 days', 'unit_price' => '2.50', 'quantity' => 15, 'discount' => '2.50'],
                ['medicine_name' => 'Herbal syrup', 'unit_price' => '120', 'quantity' => 1],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->firstOrFail();
        $this->assertSame(Prescription::KindPartner, $prescription->kind);
        $this->assertSame('157.50', $prescription->subtotal);
        $this->assertSame('2.50', $prescription->discount_total);
        $this->assertSame('155.00', $prescription->total);
        $this->assertSame(['35.00', '120.00'], $prescription->lines->pluck('line_total')->all());
        $this->assertNull($prescription->lines->first()->product_id);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertFalse(DB::table('journal_entries')->where('event_key', 'like', 'clinic.%')->exists());

        // A partner may not link a line to a company product.
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08',
            'lines' => [['product_id' => $product->id, 'medicine_name' => 'X', 'unit_price' => 1, 'quantity' => 1]],
        ])->assertSessionHasErrors('lines.0.product_id');
        // The discount can't be bigger than the line.
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08',
            'lines' => [['medicine_name' => 'X', 'unit_price' => 1, 'quantity' => 1, 'discount' => 5]],
        ])->assertSessionHasErrors('lines.0.discount');
    }

    /** @return array{0: User, 1: CompanyBranch, 2: Product, 3: PrescriptionPatient} */
    private function clinic(): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-test-in-'.$product->id, 'branch_id' => $branch->id,
        ]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);

        return [$owner, $branch, $product, $patient];
    }

    public function test_owner_dispense_takes_stock_and_splits_manufacturer_and_clinic_funds_with_balanced_journals(): void
    {
        [$owner, $branch, $product, $patient] = $this->clinic();

        // Owner price 150 each × 3 = 450; MRP 200 × 3 = 600; 30% = 180 to the manufacturer, 270 to the clinic.
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08', 'diagnosis' => 'Gastritis',
            'lines' => [['product_id' => $product->id, 'dose' => '1 spoon', 'unit_price' => '150', 'quantity' => 3]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('prescriber_id', $owner->id)->firstOrFail();
        $this->assertSame(Prescription::KindClinic, $prescription->kind);
        $this->assertSame($product->name, $prescription->lines->first()->medicine_name);
        $this->assertSame(10, $product->fresh()->stock, 'Writing a prescription does not move stock.');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $prescription->refresh();
        $this->assertSame(Prescription::StatusDispensed, $prescription->status);
        $this->assertSame('180.00', $prescription->manufacturer_share);
        $this->assertSame('270.00', $prescription->clinic_fund);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(7, (int) BranchProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity'));

        $entry = DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->first();
        $this->assertNotNull($entry);
        $lines = DB::table('journal_lines')->where('entry_id', $entry->id)->get()->keyBy('account');
        $this->assertSame('450.00', (string) $lines[ClinicDispensing::ClinicCashAccount]->debit);
        $this->assertSame('180.00', (string) $lines[ClinicDispensing::ManufacturerAccount]->credit);
        $this->assertSame('270.00', (string) $lines[ClinicDispensing::ClinicFundAccount]->credit);
        $this->assertSame(
            (float) DB::table('journal_lines')->where('entry_id', $entry->id)->sum('debit'),
            (float) DB::table('journal_lines')->where('entry_id', $entry->id)->sum('credit'),
        );
        $this->assertFalse(DB::table('commission_allocations')->exists(), 'No dealer commission on clinic sales.');
        $this->assertSame(0, DB::table('orders')->count(), 'Clinic sales do not go through dealer orders.');

        // A second dispense is refused and stock stays put.
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])
            ->assertSessionHasErrors('prescription');
        $this->assertSame(7, $product->fresh()->stock);

        $report = app(ClinicDispensing::class)->report(now('Asia/Dhaka')->toImmutable()->startOfMonth(), now('Asia/Dhaka')->toImmutable());
        $this->assertSame(1, $report['count']);
        $this->assertSame('450.00', $report['sales']);
        $this->assertSame('180.00', $report['manufacturer_share']);
        $this->assertSame('270.00', $report['clinic_fund']);
    }

    public function test_the_print_page_renders_in_bangla_and_english_with_the_registration_number(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'name' => 'Dr Synthetic Dealer']);
        $this->enable($owner, $dealer);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Print Patient', 'sex' => 'male', 'age' => 40]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'diagnosis' => 'Cold']);
        $prescription->lines()->create(['medicine_name' => 'Tulsi syrup', 'unit_price' => '100.00', 'quantity' => 1, 'discount' => '0.00', 'line_total' => '100.00', 'position' => 0]);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=bn')
            ->assertOk()
            ->assertSee('প্রেসক্রিপশন')
            ->assertSee('রেজি. নং')
            ->assertSee('A-12345')
            ->assertSee('Dr Synthetic Dealer')
            ->assertSee('Tulsi syrup')
            ->assertSee('পুরুষ');

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')
            ->assertOk()
            ->assertSee('Prescription')
            ->assertSee('Reg. no.')
            ->assertSee('BMDC (allopathic)')
            ->assertSee('A-12345')
            ->assertSee('Male');
    }

    public function test_the_feature_flag_turns_the_module_off(): void
    {
        config(['madina.prescriptions.enabled' => false]);
        $owner = User::factory()->staff('super_admin')->create();
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')->assertForbidden();
    }
}

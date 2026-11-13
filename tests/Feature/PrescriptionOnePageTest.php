<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientConsent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One page for a prescription: the patient is picked or added in the same save, and a
 * prescription carries tests, foods to eat, foods to avoid and advice beside its medicines.
 */
class PrescriptionOnePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // These tests type medicines by hand; PrescriptionOtherMedicinesTest covers the switch being off.
        $this->allowOtherMedicines();
    }

    private function prescriber(): User
    {
        return User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
    }

    /** @return array<string, mixed> */
    private function newPatient(): array
    {
        return ['name' => 'Rahima Khatun', 'age' => 34, 'sex' => 'female', 'phone' => '01811111111', 'allergies' => 'Penicillin', 'patient_consent' => true];
    }

    /** @return list<array<string, mixed>> */
    private function oneMedicine(): array
    {
        return [['medicine_name' => 'Napa 500', 'unit_price' => '2.50', 'quantity' => 10]];
    }

    public function test_a_new_patient_and_the_prescription_are_saved_by_one_request_with_consent_on_file(): void
    {
        $dealer = $this->prescriber();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => $this->newPatient(),
            'prescribed_on' => '2026-11-13',
            'lines' => $this->oneMedicine(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $patient = PrescriptionPatient::query()->where('prescriber_id', $dealer->id)->sole();
        $this->assertSame('Rahima Khatun', $patient->name);
        $this->assertSame(34, $patient->age);
        $this->assertSame('01811111111', $patient->phone);
        $this->assertSame('Penicillin', $patient->allergies);
        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $this->assertSame($patient->id, $prescription->patient_id);
        $this->assertSame('25.00', $prescription->total);
        $consent = PrescriptionPatientConsent::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(PrescriptionPatientConsent::SourcePrescriber, $consent->source);
        $this->assertSame($dealer->id, $consent->prescriber_id);
        foreach (['prescription.patient_created', 'prescription.patient_consent_recorded'] as $action) {
            $this->assertTrue(DB::table('audit_events')->where('action', $action)->where('subject', (string) $patient->id)->where('actor_id', $dealer->id)->exists(), $action);
        }
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescription.created')->where('subject', (string) $prescription->id)->exists());
    }

    public function test_a_new_patient_without_the_consent_tick_is_rejected_and_nothing_is_saved(): void
    {
        $dealer = $this->prescriber();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => [...$this->newPatient(), 'patient_consent' => false],
            'prescribed_on' => '2026-11-13',
            'lines' => $this->oneMedicine(),
        ])->assertSessionHasErrors('new_patient.patient_consent');

        $this->assertSame(0, PrescriptionPatient::query()->count());
        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_new_patient_needs_a_name(): void
    {
        $dealer = $this->prescriber();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => [...$this->newPatient(), 'name' => ''],
            'prescribed_on' => '2026-11-13',
            'lines' => $this->oneMedicine(),
        ])->assertSessionHasErrors('new_patient.name');

        $this->assertSame(0, PrescriptionPatient::query()->count());
    }

    public function test_a_prescription_that_fails_leaves_no_half_saved_patient_behind(): void
    {
        $dealer = $this->prescriber();

        // The discount is bigger than the line, which PrescriptionBook refuses after the patient step.
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => $this->newPatient(),
            'prescribed_on' => '2026-11-13',
            'lines' => [['medicine_name' => 'Napa 500', 'unit_price' => 1, 'quantity' => 1, 'discount' => 5]],
        ])->assertSessionHasErrors('lines.0.discount');

        $this->assertSame(0, PrescriptionPatient::query()->count());
        $this->assertSame(0, PrescriptionPatientConsent::query()->count());
        $this->assertSame(0, Prescription::query()->count());
        $this->assertFalse(DB::table('audit_events')->where('action', 'prescription.patient_created')->exists());
    }

    public function test_an_old_and_a_new_patient_sent_together_are_rejected(): void
    {
        $dealer = $this->prescriber();
        $existing = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $existing->id,
            'new_patient' => $this->newPatient(),
            'prescribed_on' => '2026-11-13',
            'lines' => $this->oneMedicine(),
        ])->assertSessionHasErrors('patient_id');

        $this->assertSame(1, PrescriptionPatient::query()->count());
        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_prescription_needs_an_old_or_a_new_patient(): void
    {
        $dealer = $this->prescriber();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'prescribed_on' => '2026-11-13',
            'lines' => $this->oneMedicine(),
        ])->assertSessionHasErrors('patient_id');

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_partner_who_is_not_switched_on_cannot_add_a_patient_through_a_prescription(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => false]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => $this->newPatient(),
            'prescribed_on' => '2026-11-13',
            'lines' => $this->oneMedicine(),
        ])->assertForbidden();

        $this->assertSame(0, PrescriptionPatient::query()->count());
    }

    public function test_tests_foods_and_advice_are_saved_tidied_and_locked_in_the_database(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'advice' => 'Walk for thirty minutes every morning',
            'investigations' => ['  CBC  ', '', 'Chest X-ray', 'cbc'],
            'foods_allowed' => ['Papaya', 'Bottle gourd'],
            'foods_avoided' => ['Beef', 'Fried food'],
            'lines' => $this->oneMedicine(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        // Blank rows are dropped and a repeat in another letter case is kept once.
        $this->assertSame(['CBC', 'Chest X-ray'], $prescription->investigations);
        $this->assertSame(['Papaya', 'Bottle gourd'], $prescription->foods_allowed);
        $this->assertSame(['Beef', 'Fried food'], $prescription->foods_avoided);

        $raw = DB::table('prescriptions')->where('id', $prescription->id)->first();
        foreach (['investigations' => 'Chest X-ray', 'foods_allowed' => 'Papaya', 'foods_avoided' => 'Beef'] as $column => $plain) {
            $this->assertNotNull($raw->{$column});
            $this->assertStringNotContainsString($plain, (string) $raw->{$column});
        }
    }

    public function test_a_prescription_with_only_tests_is_saved_without_any_medicine(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'investigations' => ['CBC'],
            'lines' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $this->assertSame(0, $prescription->lines()->count());
        $this->assertSame('0.00', $prescription->total);
        $this->assertSame(['CBC'], $prescription->investigations);
    }

    public function test_a_prescription_with_nothing_written_is_rejected(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'complaints' => 'Fever',
            'investigations' => ['   '],
            'lines' => [],
        ])->assertSessionHasErrors(['lines' => __('prescription.err_nothing_written')]);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_list_longer_than_thirty_rows_or_a_row_longer_than_160_letters_is_rejected(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $base = ['patient_id' => $patient->id, 'prescribed_on' => '2026-11-13', 'lines' => $this->oneMedicine()];

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions', [...$base, 'investigations' => array_map(fn (int $n): string => 'Test '.$n, range(1, 31))])
            ->assertSessionHasErrors('investigations');
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions', [...$base, 'foods_avoided' => [str_repeat('a', 161)]])
            ->assertSessionHasErrors('foods_avoided.0');

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_the_print_page_shows_tests_foods_and_advice_in_both_languages(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $prescription = Prescription::factory()->create([
            'patient_id' => $patient->id,
            'advice' => 'Walk every morning',
            'investigations' => ['CBC', 'Chest X-ray'],
            'foods_allowed' => ['Papaya'],
            'foods_avoided' => ['Beef'],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertSeeInOrder(['Tests to do', 'CBC', 'Chest X-ray'])
            ->assertSeeInOrder(['Foods to eat', 'Papaya'])
            ->assertSeeInOrder(['Foods to avoid', 'Beef'])
            ->assertSee('Walk every morning');
        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=bn')->assertOk()
            ->assertSeeInOrder(['যে টেস্ট করাবেন', 'CBC'])
            ->assertSeeInOrder(['যা খাবেন', 'Papaya'])
            ->assertSeeInOrder(['যা খাবেন না', 'Beef']);
    }

    public function test_the_print_page_of_a_tests_only_prescription_has_no_empty_medicine_list_or_money_total(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'investigations' => ['CBC']]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertSee('CBC')
            ->assertDontSee('℞')
            ->assertDontSee('Subtotal');
    }

    public function test_text_typed_into_a_list_is_escaped_on_the_print_page(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'foods_avoided' => ['<script>alert(1)</script>']]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<script>alert(1)</script>');
    }

    public function test_the_patient_history_shows_the_lists_and_a_repeat_carries_foods_but_not_tests(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        Prescription::factory()->create([
            'patient_id' => $patient->id,
            'investigations' => ['CBC'],
            'foods_allowed' => ['Papaya'],
            'foods_avoided' => ['Beef'],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.prescriptions.0.investigations', ['CBC'])
                ->where('record.prescriptions.0.foods_allowed', ['Papaya'])
                ->where('record.prescriptions.0.foods_avoided', ['Beef']));
        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')->assertOk()
            ->assertJsonPath('draft.foods_allowed', ['Papaya'])
            ->assertJsonPath('draft.foods_avoided', ['Beef'])
            ->assertJsonMissingPath('draft.investigations');
    }

    public function test_the_prescriptions_page_lists_each_prescription_with_its_tests_and_foods(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        Prescription::factory()->create(['patient_id' => $patient->id, 'investigations' => ['CBC'], 'foods_avoided' => ['Beef']]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.prescriptions.0.investigations', ['CBC'])
                ->where('data.prescriptions.prescriptions.0.foods_allowed', [])
                ->where('data.prescriptions.prescriptions.0.foods_avoided', ['Beef']));
    }

    public function test_another_prescriber_cannot_write_for_someone_elses_patient_even_with_lists_only(): void
    {
        $owner = $this->prescriber();
        $other = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);

        $this->actingAs($other)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'investigations' => ['CBC'],
        ])->assertNotFound();

        $this->assertSame(0, Prescription::query()->count());
    }

    /** @return array{0: User, 1: CompanyBranch, 2: Product} */
    private function officePrescriberWithStock(): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-one-page-in-'.$product->id, 'branch_id' => $branch->id,
        ]);

        return [$owner, $branch, $product];
    }

    public function test_an_office_account_switched_on_for_company_stock_adds_a_patient_and_prescribes_a_product_in_one_save(): void
    {
        [, , $product] = $this->officePrescriberWithStock();
        $staff = User::factory()->staff('employee')->create(['can_prescribe' => true]);

        $this->actingAs($staff)->onPortal('manage')->post('/commands/prescriptions', [
            'new_patient' => $this->newPatient(),
            'prescribed_on' => '2026-11-13',
            'investigations' => ['CBC'],
            'lines' => [['product_id' => $product->id, 'unit_price' => '200', 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $staff->id)->sole();
        $this->assertSame(Prescription::KindClinic, $prescription->kind);
        $this->assertSame($product->id, $prescription->lines->sole()->product_id);
        $this->assertSame($staff->id, PrescriptionPatient::query()->sole()->prescriber_id);
    }

    public function test_a_clinic_prescription_without_medicine_cannot_be_dispensed(): void
    {
        [$owner, $branch] = $this->officePrescriberWithStock();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        $this->enableFinancialPosting();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'investigations' => ['CBC'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('prescriber_id', $owner->id)->sole();
        $movementsBefore = DB::table('stock_movements')->count();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])
            ->assertSessionHasErrors(['prescription' => __('prescription.err_nothing_to_dispense')]);

        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertFalse(DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->exists());
    }

    public function test_a_prescription_without_medicine_cannot_become_a_medicine_template(): void
    {
        $dealer = $this->prescriber();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'investigations' => ['CBC']]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/templates', ['name' => 'Tests only', 'prescription_id' => $prescription->id])
            ->assertSessionHasErrors(['name' => __('prescription.err_template_no_medicine')]);

        $this->assertSame(0, DB::table('prescription_templates')->count());
    }
}

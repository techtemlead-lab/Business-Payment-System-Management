<?php

namespace Tests\Feature;

use App\Domain\Clinic\PrescriptionSettings;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The owner's switch for medicines that are not company products. Off (the start): every
 * prescriber, dealers included, picks from the company's product list only. On: a medicine
 * may also be typed by hand; it never touches stock, the clinic sale or its funds.
 */
class PrescriptionOtherMedicinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function dealerWithPatient(): array
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);

        return [$dealer, PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id])];
    }

    /** @return array{0: User, 1: CompanyBranch, 2: Product, 3: PrescriptionPatient} */
    private function clinic(): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $product = Product::factory()->create(['name' => 'Madina Syrup', 'mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-other-in-'.$product->id, 'branch_id' => $branch->id,
        ]);

        return [$owner, $branch, $product, PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id])];
    }

    public function test_the_switch_starts_off_and_the_page_says_so(): void
    {
        [$dealer] = $this->dealerWithPatient();
        Product::factory()->create(['name' => 'Madina Syrup', 'sku' => 'MS-1', 'mrp' => '200.00', 'active' => true]);
        Product::factory()->create(['name' => 'Hidden Product', 'active' => false]);

        $this->assertFalse(app(PrescriptionSettings::class)->otherMedicinesAllowed());
        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.other_medicines_allowed', false)
                // A dealer now chooses from the company's active products.
                ->has('data.prescriptions.products', 1)
                ->where('data.prescriptions.products.0.name', 'Madina Syrup')
                ->where('data.prescriptions.products.0.sku', 'MS-1')
                // Only owners get the switch itself.
                ->missing('data.prescriptions.other_medicines'));
    }

    /**
     * The empty medicine row the writing form sends before a product is chosen: price 0, quantity 1.
     *
     * @return array<string, mixed>
     */
    private function blankMedicineRow(): array
    {
        return [
            'product_id' => null,
            'dose' => '',
            'frequency' => '',
            'duration' => '',
            'instructions' => '',
            'dose_schedule' => null,
            'food_timing' => null,
            'duration_count' => null,
            'duration_unit' => null,
            'unit_price' => '0',
            'quantity' => 1,
            'discount' => '0',
        ];
    }

    public function test_the_forms_empty_medicine_row_does_not_block_a_chosen_product(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();
        $product = Product::factory()->create(['name' => 'Madina Syrup', 'mrp' => '200.00']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [
                $this->blankMedicineRow(),
                ['product_id' => $product->id, 'unit_price' => '180.00', 'quantity' => 2],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $line = $prescription->lines->sole();
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame(0, $line->position);
        $this->assertSame('360.00', $prescription->total);
    }

    public function test_the_forms_empty_medicine_row_does_not_block_advice_and_tests(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'advice' => 'Rest and drink water',
            'investigations' => ['CBC'],
            'lines' => [$this->blankMedicineRow()],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $this->assertSame(0, $prescription->lines()->count());
        $this->assertSame('Rest and drink water', $prescription->advice);
        $this->assertSame(['CBC'], $prescription->investigations);
        $this->assertSame('0.00', $prescription->total);
    }

    public function test_an_empty_medicine_row_with_nothing_else_written_is_rejected(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [$this->blankMedicineRow()],
        ])->assertSessionHasErrors(['lines' => __('prescription.err_nothing_written')]);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_medicine_row_with_a_dose_but_no_product_is_still_refused(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'advice' => 'Rest and drink water',
            'lines' => [[...$this->blankMedicineRow(), 'dose' => '1+0+1']],
        ])->assertSessionHasErrors(['lines.0.product_id' => __('prescription.err_product_required')]);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_hand_typed_medicine_is_refused_while_the_switch_is_off(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [['medicine_name' => 'Napa 500', 'unit_price' => '2.50', 'quantity' => 10]],
        ])->assertSessionHasErrors(['lines.0.medicine_name' => __('prescription.err_other_medicine_off')]);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_dealer_prescribes_a_company_product_at_their_own_price_without_touching_stock(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();
        $product = Product::factory()->create(['name' => 'Madina Syrup', 'mrp' => '200.00', 'stock' => 20]);
        $movementsBefore = DB::table('stock_movements')->count();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [['product_id' => $product->id, 'medicine_name' => 'Something else', 'unit_price' => '180', 'quantity' => 2, 'discount' => '10']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $line = $prescription->lines->sole();
        $this->assertSame(Prescription::KindPartner, $prescription->kind);
        $this->assertSame($product->id, $line->product_id);
        // The name always comes from the product, whatever was sent beside it.
        $this->assertSame('Madina Syrup', $line->medicine_name);
        $this->assertSame('350.00', $prescription->total);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertFalse(DB::table('journal_entries')->where('event_key', 'like', 'clinic.%')->exists());
    }

    public function test_a_product_that_is_not_on_sale_cannot_be_prescribed(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();
        $retired = Product::factory()->create(['active' => false]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [['product_id' => $retired->id, 'unit_price' => '10', 'quantity' => 1]],
        ])->assertSessionHasErrors(['lines.0.product_id' => __('prescription.err_product_required')]);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_an_owner_turns_the_switch_on_and_off_and_each_change_is_audited(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/owner/prescription-other-medicines', ['enabled' => '1', 'version' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();

        $settings = app(PrescriptionSettings::class);
        $this->assertSame(['enabled' => true, 'version' => 1], $settings->otherMedicines());
        $audit = DB::table('audit_events')->where('action', 'prescription.other_medicines_changed')->sole();
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame(['enabled' => false], $metadata['before']);
        $this->assertSame(['enabled' => true], $metadata['after']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.other_medicines_allowed', true)
                ->where('data.prescriptions.other_medicines', ['enabled' => true, 'version' => 1]));

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/owner/prescription-other-medicines', ['enabled' => '0', 'version' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['enabled' => false, 'version' => 2], $settings->otherMedicines());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'prescription.other_medicines_changed')->count());
    }

    public function test_a_change_made_from_a_stale_page_is_refused(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->allowOtherMedicines($owner);

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/owner/prescription-other-medicines', ['enabled' => '0', 'version' => 0])
            ->assertSessionHasErrors(['version' => __('portal.err_security_setting_stale')]);

        $this->assertTrue(app(PrescriptionSettings::class)->otherMedicinesAllowed());
    }

    public function test_only_the_super_admin_may_change_the_switch_and_md_chairman_staff_and_partners_are_refused(): void
    {
        $refused = [
            [User::factory()->staff('md')->create(['can_prescribe' => true]), 'sadmin'],
            [User::factory()->staff('chairman')->create(['can_prescribe' => true]), 'sadmin'],
            [User::factory()->staff('employee')->create(['can_prescribe' => true]), 'manage'],
            [User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]), 'dealer'],
        ];
        $payload = ['enabled' => '1', 'version' => 0];

        foreach ($refused as [$user, $portal]) {
            $this->actingAs($user)->onPortal($portal)->post('/commands/owner/prescription-other-medicines', $payload)->assertForbidden();
        }
        $this->assertFalse(app(PrescriptionSettings::class)->otherMedicinesAllowed());
        $this->assertFalse(DB::table('audit_events')->where('action', 'prescription.other_medicines_changed')->exists());

        // MD and Chairman who prescribe from company stock see the state, not the switch.
        $this->actingAs($refused[0][0])->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.other_medicines_allowed', false)
                ->missing('data.prescriptions.other_medicines'));

        $this->actingAs(User::factory()->staff('super_admin')->create())->onPortal('sadmin')
            ->post('/commands/owner/prescription-other-medicines', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(PrescriptionSettings::class)->otherMedicinesAllowed());
    }

    public function test_md_or_chairman_cannot_change_the_switch_even_through_the_settings_class(): void
    {
        $this->expectException(HttpException::class);

        app(PrescriptionSettings::class)->save(User::factory()->staff('chairman')->create(), true, 0);
    }

    public function test_with_the_switch_on_a_dealer_types_a_medicine_with_its_strength_and_price(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();
        $this->allowOtherMedicines();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [['medicine_name' => 'Napa', 'strength' => '500 mg tablet', 'unit_price' => '2.50', 'quantity' => 10]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $line = $prescription->lines->sole();
        $this->assertNull($line->product_id);
        $this->assertSame('Napa', $line->medicine_name);
        $this->assertSame('500 mg tablet', $line->strength);
        // A dealer's own bill, as before: it never reaches company accounts.
        $this->assertSame('25.00', $prescription->total);
        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertSeeInOrder(['Napa', '500 mg tablet']);
    }

    public function test_a_hand_typed_medicine_needs_a_name(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();
        $this->allowOtherMedicines();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [['medicine_name' => '  ', 'unit_price' => '2.50', 'quantity' => 10]],
        ])->assertSessionHasErrors(['lines.0.medicine_name' => __('prescription.err_medicine_required')]);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_in_the_clinic_a_hand_typed_medicine_has_no_price_and_is_left_out_of_the_sale_and_the_stock(): void
    {
        [$owner, $branch, $product, $patient] = $this->clinic();
        $this->allowOtherMedicines($owner);
        $this->enableFinancialPosting();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [
                ['product_id' => $product->id, 'unit_price' => '200', 'quantity' => 2],
                // A price sent for a hand-typed medicine is ignored in the clinic.
                ['medicine_name' => 'Other Company Tablet', 'strength' => '10 mg', 'unit_price' => '999', 'quantity' => 5, 'discount' => '1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->where('prescriber_id', $owner->id)->sole();
        $typed = $prescription->lines->firstWhere('product_id', null);
        $this->assertSame('0.00', $typed->unit_price);
        $this->assertSame('0.00', $typed->line_total);
        $this->assertNull($typed->mrp);
        $this->assertSame('400.00', $prescription->total);
        $this->assertSame('400.00', $prescription->mrp_total);

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $prescription->refresh();
        $this->assertSame(Prescription::StatusDispensed, $prescription->status);
        // Only the company product left the branch, and only it was sold.
        $this->assertSame(8, (int) BranchProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, DB::table('stock_movements')->where('reference', 'like', 'clinic-'.$prescription->number.'-%')->count());
        $this->assertSame('120.00', $prescription->manufacturer_share);
        $this->assertSame('280.00', $prescription->clinic_fund);
    }

    public function test_a_clinic_prescription_of_hand_typed_medicines_only_cannot_be_dispensed(): void
    {
        [$owner, $branch, , $patient] = $this->clinic();
        $this->allowOtherMedicines($owner);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-13',
            'lines' => [['medicine_name' => 'Other Company Tablet', 'unit_price' => '0', 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('prescriber_id', $owner->id)->sole();
        $movementsBefore = DB::table('stock_movements')->count();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])
            ->assertSessionHasErrors(['prescription' => __('prescription.err_nothing_to_dispense')]);

        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    public function test_turning_the_switch_off_keeps_old_prescriptions_readable_and_refuses_new_hand_typed_medicines(): void
    {
        [$dealer, $patient] = $this->dealerWithPatient();
        $owner = User::factory()->staff('super_admin')->create();
        $this->allowOtherMedicines($owner);
        $line = ['medicine_name' => 'Napa 500', 'unit_price' => '2.50', 'quantity' => 10];
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions', ['patient_id' => $patient->id, 'prescribed_on' => '2026-11-13', 'lines' => [$line]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $old = Prescription::query()->where('prescriber_id', $dealer->id)->sole();

        app(PrescriptionSettings::class)->save($owner, false, 1);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$old->id.'/print?lang=en')->assertOk()->assertSee('Napa 500');
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions', ['patient_id' => $patient->id, 'prescribed_on' => '2026-11-13', 'lines' => [$line]])
            ->assertSessionHasErrors('lines.0.medicine_name');
        $this->assertSame(1, Prescription::query()->count());
    }
}

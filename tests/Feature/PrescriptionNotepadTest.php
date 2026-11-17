<?php

namespace Tests\Feature;

use App\Domain\Clinic\DoseSchedule;
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

/**
 * The notepad-style writing screen: every medicine is free text, the dose is a morning-noon-night
 * pattern (a list choice, spinner numbers, or loose text), the patient is new or old, and the
 * owner-doctor may link a typed medicine to a company product only when dispensing.
 */
class PrescriptionNotepadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function dealer(bool $canPrescribe = true): User
    {
        return User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => $canPrescribe]);
    }

    /** @param array<string, mixed> $line */
    private function line(array $line = []): array
    {
        return ['medicine_name' => 'Tab. Relanab 750 mg', ...$line];
    }

    /** @param array<string, mixed> $extra */
    private function write(User $user, string $portal, array $lines, array $extra = [])
    {
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $user->id]);

        return $this->actingAs($user)->onPortal($portal)->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-17',
            'lines' => $lines,
            ...$extra,
        ]);
    }

    public function test_a_dose_chosen_from_the_pattern_list_is_saved_with_meal_timing_and_a_quick_duration(): void
    {
        $dealer = $this->dealer();

        $this->write($dealer, 'dealer', [$this->line(['dose_schedule' => '1+0+1', 'food_timing' => 'after', 'duration_count' => 1, 'duration_unit' => 'month'])])
            ->assertRedirect()->assertSessionHasNoErrors();

        $line = Prescription::query()->sole()->lines->sole();
        $this->assertSame('Tab. Relanab 750 mg', $line->medicine_name);
        $this->assertNull($line->product_id);
        $this->assertSame('1+0+1', $line->dose_schedule);
        $this->assertSame('after', $line->food_timing);
        $this->assertSame(1, $line->duration_count);
        $this->assertSame('month', $line->duration_unit);
    }

    public function test_spinner_numbers_above_five_and_zero_are_saved(): void
    {
        $dealer = $this->dealer();

        $this->write($dealer, 'dealer', [
            $this->line(['dose_schedule' => '6+0+2', 'food_timing' => 'before', 'duration_count' => 15, 'duration_unit' => 'day']),
            $this->line(['medicine_name' => 'Cap. Vonocab 20 mg', 'dose_schedule' => '0+0+1', 'duration_count' => 3, 'duration_unit' => 'week']),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lines = Prescription::query()->sole()->lines;
        $this->assertSame(['6+0+2', '0+0+1'], $lines->pluck('dose_schedule')->all());
        $this->assertSame([15, 3], $lines->pluck('duration_count')->all());
    }

    public function test_a_loose_text_dose_with_no_pattern_is_saved(): void
    {
        $dealer = $this->dealer();

        $this->write($dealer, 'dealer', [$this->line(['dose' => '1 spoon when needed'])])
            ->assertRedirect()->assertSessionHasNoErrors();

        $line = Prescription::query()->sole()->lines->sole();
        $this->assertNull($line->dose_schedule);
        $this->assertSame('1 spoon when needed', $line->dose);
    }

    public function test_a_wrong_dose_pattern_is_refused(): void
    {
        $dealer = $this->dealer();

        foreach (['0+0+0', '1+1', '1+0+1+0', '21+0+0', '-1+0+1', 'a+0+1', '1,5+0+1'] as $bad) {
            $this->write($dealer, 'dealer', [$this->line(['dose_schedule' => $bad])])->assertSessionHasErrors('lines.0.dose_schedule');
        }
        $this->assertSame(0, Prescription::query()->count());
        $this->assertTrue(DoseSchedule::isValid('20+0+0'));
        $this->assertTrue(DoseSchedule::isValid('½+0+1'), 'Older fractions on saved prescriptions stay valid.');
    }

    public function test_a_duration_needs_both_a_number_and_a_unit_in_range(): void
    {
        $dealer = $this->dealer();

        $this->write($dealer, 'dealer', [$this->line(['duration_count' => 5])])->assertSessionHasErrors('lines.0.duration_unit');
        $this->write($dealer, 'dealer', [$this->line(['duration_unit' => 'day'])])->assertSessionHasErrors('lines.0.duration_count');
        $this->write($dealer, 'dealer', [$this->line(['duration_count' => 0, 'duration_unit' => 'day'])])->assertSessionHasErrors('lines.0.duration_count');
        $this->write($dealer, 'dealer', [$this->line(['duration_count' => 366, 'duration_unit' => 'day'])])->assertSessionHasErrors('lines.0.duration_count');
        $this->write($dealer, 'dealer', [$this->line(['duration_count' => 2, 'duration_unit' => 'year'])])->assertSessionHasErrors('lines.0.duration_unit');
        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_meal_timing_other_than_before_or_after_is_refused(): void
    {
        $this->write($this->dealer(), 'dealer', [$this->line(['food_timing' => 'during'])])->assertSessionHasErrors('lines.0.food_timing');
    }

    public function test_the_medicine_needs_no_price_quantity_or_company_product(): void
    {
        $dealer = $this->dealer();

        $this->write($dealer, 'dealer', [$this->line()])->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->sole();
        $this->assertSame('0.00', $prescription->total);
        $this->assertSame(1, $prescription->lines->sole()->quantity);
    }

    public function test_the_prescription_keeps_the_examination_and_the_visit_type(): void
    {
        $dealer = $this->dealer();

        $this->write($dealer, 'dealer', [$this->line()], ['visit_type' => 'old', 'examination' => 'SLR Rt full, Lt 40', 'complaints' => 'Pain on hip'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $prescription = Prescription::query()->sole();
        $this->assertSame('old', $prescription->visit_type);
        $this->assertSame('SLR Rt full, Lt 40', $prescription->examination);
        $this->assertNotSame('SLR Rt full, Lt 40', DB::table('prescriptions')->value('examination'), 'The examination is stored encrypted.');
    }

    public function test_a_visit_type_other_than_new_or_old_is_refused(): void
    {
        $this->write($this->dealer(), 'dealer', [$this->line()], ['visit_type' => 'maybe'])->assertSessionHasErrors('visit_type');
    }

    public function test_a_new_patient_gets_a_free_text_address_and_an_automatic_numbered_id(): void
    {
        $dealer = $this->dealer();
        $send = fn (string $name) => $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => ['name' => $name, 'age' => 30, 'sex' => 'male', 'address_text' => 'Shothibari, Rangpur', 'patient_consent' => true],
            'visit_type' => 'new',
            'prescribed_on' => '2026-11-17',
            'lines' => [$this->line()],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $send('Mr. Shaon');
        $send('Mrs. Rina');

        $patients = PrescriptionPatient::query()->orderBy('id')->get();
        $prefix = now((string) config('madina.business_timezone', 'Asia/Dhaka'))->format('dmy');
        $this->assertSame([$prefix.'01', $prefix.'02'], $patients->pluck('patient_code')->all());
        $this->assertSame('Shothibari, Rangpur', $patients[0]->address_text);
        $this->assertNotSame('Shothibari, Rangpur', DB::table('prescription_patients')->orderBy('id')->value('address_text'), 'The address is stored encrypted.');
        $prescription = Prescription::query()->orderBy('id')->first();
        $this->assertSame('new', $prescription->visit_type);
        $this->assertSame('Shothibari, Rangpur', $prescription->patient_address);
        $this->assertSame($prefix.'01', $prescription->patient_code);
    }

    public function test_the_patient_search_returns_the_old_patients_details_for_the_info_bar(): void
    {
        $dealer = $this->dealer();
        PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Karim Uddin', 'age' => 41, 'sex' => 'male', 'address_text' => 'Dhaka', 'patient_code' => '17112601']);
        PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Selina']);

        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/prescriptions/patients/options?q=karim')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.address', 'Dhaka')
            ->assertJsonPath('data.0.code', '17112601')
            ->assertJsonPath('data.0.sex', 'male')
            ->assertJsonPath('data.0.age', 41);
    }

    public function test_the_search_and_repeat_never_reach_another_prescribers_patient(): void
    {
        $dealer = $this->dealer();
        $other = $this->dealer();
        $theirs = PrescriptionPatient::factory()->create(['prescriber_id' => $other->id, 'name' => 'Karim Uddin']);

        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/prescriptions/patients/options?q=karim')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$theirs->id.'/repeat')->assertNotFound();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $theirs->id, 'prescribed_on' => '2026-11-17', 'lines' => [$this->line()],
        ])->assertNotFound();
    }

    public function test_repeat_copies_the_last_prescriptions_notepad_lines(): void
    {
        $dealer = $this->dealer();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Karim Uddin', 'address_text' => 'Dhaka']);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-17', 'diagnosis' => 'Radiculopathy', 'advice' => "No bending\nUse a high commode",
            'lines' => [$this->line(['dose_schedule' => '1+0+1', 'food_timing' => 'after', 'duration_count' => 1, 'duration_unit' => 'month'])],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')
            ->assertOk()
            ->assertJsonPath('patient.address', 'Dhaka')
            ->assertJsonPath('draft.diagnosis', 'Radiculopathy')
            ->assertJsonPath('draft.advice', "No bending\nUse a high commode")
            ->assertJsonPath('draft.lines.0.medicine_name', 'Tab. Relanab 750 mg')
            ->assertJsonPath('draft.lines.0.dose_schedule', '1+0+1')
            ->assertJsonPath('draft.lines.0.duration_unit', 'month');
    }

    public function test_a_partner_who_is_not_enabled_cannot_write(): void
    {
        $dealer = $this->dealer(false);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'new_patient' => ['name' => 'Mr. Shaon', 'patient_consent' => true], 'prescribed_on' => '2026-11-17', 'lines' => [$this->line()],
        ])->assertForbidden();
        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_another_prescribers_prescription_cannot_be_printed_not_even_by_the_owner(): void
    {
        $dealer = $this->dealer();
        $this->write($dealer, 'dealer', [$this->line()])->assertRedirect();
        $prescription = Prescription::query()->sole();
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertNotFound();
    }

    public function test_the_page_gives_the_notepad_its_date_next_id_and_letterhead_slot(): void
    {
        $dealer = $this->dealer();

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->where('data.prescriptions.doctor_name', $dealer->name)
                ->has('data.prescriptions.today')
                ->has('data.prescriptions.next_patient_code')
                ->has('data.prescriptions.letterhead'),
        );
    }

    /** @return array{0: User, 1: CompanyBranch, 2: Product} */
    private function clinic(): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $product = Product::factory()->create(['name' => 'Madina Syrup', 'mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-notepad-in-'.$product->id, 'branch_id' => $branch->id,
        ]);

        return [$owner, $branch, $product];
    }

    public function test_the_owner_links_a_typed_medicine_to_a_company_product_only_when_dispensing(): void
    {
        [$owner, $branch, $product] = $this->clinic();
        $this->enableFinancialPosting();
        $this->write($owner, 'sadmin', [$this->line(['medicine_name' => 'Syp. Madina'])])->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->sole();
        $line = $prescription->lines->sole();
        $this->assertNull($line->product_id);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/'.$prescription->id.'/dispense', [
            'branch_id' => $branch->id,
            'links' => [(string) $line->id => ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '200', 'discount' => '0']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prescription->refresh();
        $line->refresh();
        $this->assertSame(Prescription::StatusDispensed, $prescription->status);
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('Syp. Madina', $line->medicine_name, 'The name the doctor wrote stays.');
        $this->assertSame('400.00', $prescription->total);
        $this->assertSame(8, (int) BranchProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('120.00', $prescription->manufacturer_share);
        $this->assertSame('280.00', $prescription->clinic_fund);
    }

    public function test_an_unlinked_typed_medicine_is_never_taken_from_stock(): void
    {
        [$owner, $branch, $product] = $this->clinic();
        $this->write($owner, 'sadmin', [$this->line()])->assertRedirect();
        $prescription = Prescription::query()->sole();
        $movements = DB::table('stock_movements')->count();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])
            ->assertSessionHasErrors(['prescription' => __('prescription.err_nothing_to_dispense')]);

        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame(10, (int) BranchProductStock::query()->where('product_id', $product->id)->value('quantity'));
    }

    public function test_a_link_to_a_line_of_another_prescription_or_a_retired_product_is_refused(): void
    {
        [$owner, $branch, $product] = $this->clinic();
        $this->write($owner, 'sadmin', [$this->line()])->assertRedirect();
        $this->write($owner, 'sadmin', [$this->line()])->assertRedirect();
        [$first, $second] = Prescription::query()->orderBy('id')->get()->all();
        $foreign = $second->lines->sole();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/'.$first->id.'/dispense', [
            'branch_id' => $branch->id,
            'links' => [(string) $foreign->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '200']],
        ])->assertSessionHasErrors(['links' => __('prescription_notepad.err_link_line')]);

        $product->update(['active' => false]);
        $own = $first->lines->sole();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/'.$first->id.'/dispense', [
            'branch_id' => $branch->id,
            'links' => [(string) $own->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '200']],
        ])->assertSessionHasErrors(['links' => __('prescription_notepad.err_link_product')]);

        $this->assertNull($own->fresh()->product_id);
        $this->assertSame(Prescription::StatusIssued, $first->fresh()->status);
    }

    public function test_a_dealer_cannot_dispense_or_link_company_stock(): void
    {
        [, $branch, $product] = $this->clinic();
        $dealer = $this->dealer();
        $this->write($dealer, 'dealer', [$this->line()])->assertRedirect();
        $prescription = Prescription::query()->where('prescriber_id', $dealer->id)->sole();
        $line = $prescription->lines->sole();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/'.$prescription->id.'/dispense', [
            'branch_id' => $branch->id,
            'links' => [(string) $line->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '200']],
        ])->assertForbidden();

        $this->assertNull($line->fresh()->product_id);
        $this->assertSame(10, (int) BranchProductStock::query()->where('product_id', $product->id)->value('quantity'));
    }
}

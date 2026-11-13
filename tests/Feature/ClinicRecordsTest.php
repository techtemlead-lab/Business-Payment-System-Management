<?php

namespace Tests\Feature;

use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Clinical records kept by a prescriber: every record stays with that prescriber alone,
 * the owner included.
 */
class ClinicRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $prescriber;

    private User $otherPrescriber;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->prescriber = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $this->otherPrescriber = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    /** @param  array<string, mixed>  $attributes */
    private function patient(array $attributes = []): PrescriptionPatient
    {
        return PrescriptionPatient::factory()->create(['prescriber_id' => $this->prescriber->id, ...$attributes]);
    }

    public function test_allergies_are_saved_encrypted_and_reach_the_prescription_form_of_their_own_prescriber_only(): void
    {
        $this->actingAs($this->prescriber)->onPortal('dealer')
            ->post('/commands/prescriptions/patients', ['name' => 'Rahima', 'allergies' => 'Penicillin', 'patient_consent' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $patient = PrescriptionPatient::query()->where('prescriber_id', $this->prescriber->id)->firstOrFail();

        $this->assertSame('Penicillin', $patient->allergies);
        $stored = (string) DB::table('prescription_patients')->where('id', $patient->id)->value('allergies');
        $this->assertStringNotContainsString('Penicillin', $stored);
        $this->assertSame('Penicillin', Crypt::decryptString($stored));

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescriptions.own_patients.0.allergies', 'Penicillin'));
        $this->actingAs($this->prescriber)->onPortal('dealer')->getJson('/commands/prescriptions/patients/options?q=Rahima')
            ->assertOk()->assertJsonPath('data.0.allergies', 'Penicillin');

        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions')->assertOk()->assertDontSee('Penicillin');
            $this->actingAs($viewer)->onPortal($portal)->getJson('/commands/prescriptions/patients/options?q=Rahima')
                ->assertOk()->assertJsonPath('data', []);
        }
    }

    /**
     * Sends the same request as another prescriber and as the owner: both must get a 404.
     *
     * @param  callable(static): TestResponse<Response>  $request
     */
    private function assertHiddenFromOthers(callable $request): void
    {
        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $request($this->actingAs($viewer)->onPortal($portal))->assertNotFound();
        }
    }

    public function test_the_history_page_lists_only_this_patients_prescriptions_for_their_own_prescriber(): void
    {
        $patient = $this->patient(['name' => 'History Patient', 'allergies' => 'Penicillin']);
        $mine = Prescription::factory()->create(['patient_id' => $patient->id, 'prescribed_on' => '2026-11-01', 'diagnosis' => 'Fever']);
        $elsewhere = Prescription::factory()->create(['patient_id' => $this->patient(['name' => 'Someone Else'])->id, 'diagnosis' => 'Other illness']);

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PatientRecord')
                ->where('record.patient.name', 'History Patient')
                ->where('record.patient.allergies', 'Penicillin')
                ->has('record.prescriptions', 1)
                ->where('record.prescriptions.0.number', $mine->number)
                ->where('record.prescriptions.0.diagnosis', 'Fever'));
        $this->assertNotSame($mine->id, $elsewhere->id);

        $this->assertHiddenFromOthers(fn (self $test) => $test->get('/prescriptions/patients/'.$patient->id));
    }

    public function test_a_prescriber_whose_prescribing_is_off_cannot_open_the_record_and_a_stranger_gets_a_404(): void
    {
        $patient = $this->patient();
        $this->prescriber->forceFill(['can_prescribe' => false])->save();
        $this->actingAs($this->prescriber->fresh())->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertForbidden();

        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->onPortal('manage')->get('/prescriptions/patients/'.$patient->id)->assertNotFound();
    }

    public function test_repeat_last_returns_the_newest_prescription_as_a_draft_only_to_its_prescriber(): void
    {
        $patient = $this->patient(['allergies' => 'Sulfa']);
        Prescription::factory()->create(['patient_id' => $patient->id, 'prescribed_on' => '2026-10-01', 'advice' => 'Old advice']);
        $newest = Prescription::factory()->create(['patient_id' => $patient->id, 'prescribed_on' => '2026-11-01', 'advice' => 'Drink water', 'diagnosis' => 'Cough', 'complaints' => 'Today only']);
        $newest->lines()->create(['medicine_name' => 'Syrup A', 'dose' => '5ml', 'unit_price' => '40.00', 'quantity' => 2, 'discount' => '0.00', 'line_total' => '80.00', 'position' => 0]);
        $countBefore = Prescription::query()->count();

        $this->actingAs($this->prescriber)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')->assertOk()
            ->assertJsonPath('patient.allergies', 'Sulfa')
            ->assertJsonPath('draft.number', $newest->number)
            ->assertJsonPath('draft.advice', 'Drink water')
            ->assertJsonPath('draft.diagnosis', 'Cough')
            ->assertJsonMissingPath('draft.complaints')
            ->assertJsonPath('draft.lines.0.medicine_name', 'Syrup A')
            ->assertJsonPath('draft.lines.0.quantity', 2);
        $this->assertSame($countBefore, Prescription::query()->count());

        $this->assertHiddenFromOthers(fn (self $test) => $test->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat'));
    }

    public function test_repeat_last_says_so_when_the_patient_has_no_prescription_yet(): void
    {
        $patient = $this->patient();

        $this->actingAs($this->prescriber)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')
            ->assertOk()->assertJsonPath('draft', null);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function writePrescription(PrescriptionPatient $patient, array $line = [], array $extra = []): TestResponse
    {
        // The line is typed by hand, which needs the owners' switch.
        $this->allowOtherMedicines();

        return $this->actingAs($this->prescriber)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-08',
            'lines' => [['medicine_name' => 'Napa', 'unit_price' => 2, 'quantity' => 10, ...$line]],
            ...$extra,
        ]);
    }

    public function test_structured_dosing_is_saved_and_printed_clearly_in_bangla_and_english(): void
    {
        $patient = $this->patient();
        $this->writePrescription($patient, ['dose_schedule' => '1+0+1', 'food_timing' => 'after', 'duration_count' => 7, 'duration_unit' => 'day', 'dose' => '500 mg', 'instructions' => 'Shake well'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->with('lines')->where('patient_id', $patient->id)->firstOrFail();
        $line = $prescription->lines->first();
        $this->assertSame('1+0+1', $line->dose_schedule);
        $this->assertSame('after', $line->food_timing);
        $this->assertSame(7, $line->duration_count);
        $this->assertSame('day', $line->duration_unit);
        $this->assertSame('500 mg', $line->dose);

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=bn')->assertOk()
            ->assertSee('১+০+১ (সকালে ১ · রাতে ১) · খাবারের পরে · ৭ দিন', false)
            ->assertDontSee('দুপুরে', false)
            ->assertSee('500 mg')
            ->assertSee('Shake well');
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertSee('1+0+1 (morning 1 · night 1) · after food · 7 days', false);
    }

    public function test_free_text_dosing_still_works_on_its_own_and_prints_nothing_extra(): void
    {
        $patient = $this->patient();
        $this->writePrescription($patient, ['dose' => '1 spoon', 'frequency' => 'When needed', 'duration' => 'Until better'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('patient_id', $patient->id)->firstOrFail();

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=bn')->assertOk()
            ->assertSee('1 spoon')->assertSee('When needed')->assertSee('Until better')->assertDontSee('class="dosing"', false);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badDosing(): array
    {
        return [
            'two parts only' => [['dose_schedule' => '1+0'], 'lines.0.dose_schedule'],
            'all zero' => [['dose_schedule' => '0+0+0'], 'lines.0.dose_schedule'],
            'made-up amount' => [['dose_schedule' => '9+0+1'], 'lines.0.dose_schedule'],
            'unknown food timing' => [['food_timing' => 'during'], 'lines.0.food_timing'],
            'length without a unit' => [['duration_count' => 7], 'lines.0.duration_unit'],
            'unit without a length' => [['duration_unit' => 'day'], 'lines.0.duration_count'],
        ];
    }

    /** @param  array<string, mixed>  $line */
    #[DataProvider('badDosing')]
    public function test_bad_structured_dosing_is_refused(array $line, string $errorKey): void
    {
        $this->writePrescription($this->patient(), $line)->assertSessionHasErrors($errorKey);
        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_repeat_and_templates_carry_the_structured_dosing(): void
    {
        $patient = $this->patient();
        $this->writePrescription($patient, ['dose_schedule' => '½+0+½', 'food_timing' => 'before', 'duration_count' => 2, 'duration_unit' => 'week'])
            ->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('patient_id', $patient->id)->firstOrFail();

        $this->actingAs($this->prescriber)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')->assertOk()
            ->assertJsonPath('draft.lines.0.dose_schedule', '½+0+½')
            ->assertJsonPath('draft.lines.0.food_timing', 'before')
            ->assertJsonPath('draft.lines.0.duration_count', 2)
            ->assertJsonPath('draft.lines.0.duration_unit', 'week');

        $this->actingAs($this->prescriber)->onPortal('dealer')
            ->post('/commands/prescriptions/templates', ['name' => 'Cough set', 'prescription_id' => $prescription->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.templates.0.lines.0.dose_schedule', '½+0+½'));
    }

    public function test_vitals_are_saved_encrypted_shown_on_the_history_and_printed_in_bangla(): void
    {
        $patient = $this->patient();
        $this->writePrescription($patient, [], ['vitals' => ['bp_systolic' => 120, 'bp_diastolic' => 80, 'weight_kg' => '65.5', 'pulse' => 72, 'temperature_f' => '98.6']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('patient_id', $patient->id)->firstOrFail();

        $this->assertSame(['bp_systolic' => '120', 'bp_diastolic' => '80', 'weight_kg' => '65.5', 'pulse' => '72', 'temperature_f' => '98.6'], $prescription->vitals);
        $stored = (string) DB::table('prescriptions')->where('id', $prescription->id)->value('vitals');
        $this->assertStringNotContainsString('120', $stored);
        $this->assertStringNotContainsString('bp_systolic', $stored);

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=bn')->assertOk()
            ->assertSee('রক্তচাপ: ১২০/৮০ mmHg', false)
            ->assertSee('ওজন: ৬৫.৫ কেজি', false)
            ->assertSee('পালস: ৭২ /মিনিট', false)
            ->assertSee('তাপমাত্রা: ৯৮.৬ °F', false);
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('record.prescriptions.0.vitals.0.value', '120/80 mmHg'));

        $this->assertHiddenFromOthers(fn (self $test) => $test->get('/prescriptions/'.$prescription->id.'/print?lang=en'));
        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions')->assertOk()->assertDontSee('120/80');
        }
    }

    public function test_a_visit_without_vitals_stores_none_and_prints_no_heading(): void
    {
        $patient = $this->patient();
        $this->writePrescription($patient, [], ['vitals' => ['bp_systolic' => null, 'pulse' => null]])->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('patient_id', $patient->id)->firstOrFail();

        $this->assertNull(DB::table('prescriptions')->where('id', $prescription->id)->value('vitals'));
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertDontSee('Vitals at this visit');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badVitals(): array
    {
        return [
            'upper without lower' => [['bp_systolic' => 120], 'vitals.bp_diastolic'],
            'lower without upper' => [['bp_diastolic' => 80], 'vitals.bp_systolic'],
            'lower above upper' => [['bp_systolic' => 80, 'bp_diastolic' => 120], 'vitals.bp_diastolic'],
            'impossible temperature' => [['temperature_f' => 200], 'vitals.temperature_f'],
            'impossible pulse' => [['pulse' => 900], 'vitals.pulse'],
            'text for weight' => [['weight_kg' => 'heavy'], 'vitals.weight_kg'],
        ];
    }

    /** @param  array<string, mixed>  $vitals */
    #[DataProvider('badVitals')]
    public function test_impossible_vitals_are_refused(array $vitals, string $errorKey): void
    {
        $this->writePrescription($this->patient(), [], ['vitals' => $vitals])->assertSessionHasErrors($errorKey);
        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_patient_save_that_does_not_mention_allergies_keeps_them_and_an_empty_value_clears_them(): void
    {
        $patient = $this->patient(['name' => 'Karim', 'allergies' => 'Sulfa']);

        $this->actingAs($this->prescriber)->onPortal('dealer')
            ->patch('/commands/prescriptions/patients/'.$patient->id, ['name' => 'Karim Uddin'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Sulfa', $patient->fresh()->allergies);

        $this->actingAs($this->prescriber)->onPortal('dealer')
            ->patch('/commands/prescriptions/patients/'.$patient->id, ['name' => 'Karim Uddin', 'allergies' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($patient->fresh()->allergies);
    }
}

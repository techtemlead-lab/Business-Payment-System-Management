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
            ->post('/commands/prescriptions/patients', ['name' => 'Rahima', 'allergies' => 'Penicillin'])
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

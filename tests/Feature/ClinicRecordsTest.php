<?php

namespace Tests\Feature;

use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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

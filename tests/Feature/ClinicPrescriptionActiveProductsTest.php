<?php

namespace Tests\Feature;

use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clinic form only offers products that are on sale. The server has to hold the same line,
 * so a product taken off sale cannot be written on a clinic prescription by a direct request.
 */
class ClinicPrescriptionActiveProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_product_taken_off_sale_cannot_go_on_a_clinic_prescription(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        $archived = Product::factory()->create(['mrp' => '200.00', 'active' => false]);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-08',
            'lines' => [['product_id' => $archived->id, 'unit_price' => '150', 'quantity' => 1]],
        ])->assertSessionHasErrors('lines.0.product_id');

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_a_product_on_sale_still_goes_on_a_clinic_prescription(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        $onSale = Product::factory()->create(['mrp' => '200.00', 'active' => true]);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id,
            'prescribed_on' => '2026-11-08',
            'lines' => [['product_id' => $onSale->id, 'unit_price' => '150', 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Prescription::query()->count());
    }
}

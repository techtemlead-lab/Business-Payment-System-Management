<?php

namespace Tests\Feature;

use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrescriptionTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function prescriber(): User
    {
        return User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
    }

    private function prescriptionFor(User $prescriber): Prescription
    {
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $prescriber->id]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'advice' => 'Rest for a week']);
        $prescription->lines()->create([
            'medicine_name' => 'Napa 500', 'dose' => '1 tab', 'frequency' => '3 times', 'duration' => '5 days', 'instructions' => 'After food',
            'unit_price' => '2.50', 'quantity' => 15, 'discount' => '2.50', 'line_total' => '35.00', 'position' => 0,
        ]);

        return $prescription;
    }

    public function test_a_prescriber_saves_a_prescription_as_a_template_and_starts_a_new_one_from_it(): void
    {
        $dealer = $this->prescriber();
        $prescription = $this->prescriptionFor($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/templates', [
            'name' => 'Fever, adult',
            'prescription_id' => $prescription->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = PrescriptionTemplate::query()->where('prescriber_id', $dealer->id)->firstOrFail();
        $this->assertSame('Fever, adult', $template->name);
        $this->assertSame('Rest for a week', $template->advice);
        $this->assertSame([[
            'product_id' => null, 'medicine_name' => 'Napa 500', 'strength' => null, 'dose' => '1 tab', 'frequency' => '3 times', 'duration' => '5 days',
            'instructions' => 'After food', 'dose_schedule' => null, 'food_timing' => null, 'duration_count' => null, 'duration_unit' => null,
            'unit_price' => '2.50', 'quantity' => 15, 'discount' => '2.50',
        ]], $template->lines);
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescription.template_saved')->where('subject', (string) $template->id)->exists());

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.prescription_tools.templates', 1)
                ->where('data.prescription_tools.templates.0.name', 'Fever, adult')
                ->where('data.prescription_tools.templates.0.lines.0.medicine_name', 'Napa 500'));

        // The form sends the template's lines through the normal prescription save; they are
        // typed by hand, which needs the owners' switch.
        $this->allowOtherMedicines();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions', [
            'patient_id' => $prescription->patient_id,
            'prescribed_on' => '2026-11-08',
            'advice' => $template->advice,
            'lines' => array_map(fn (array $line): array => collect($line)->except('product_id')->all(), $template->lines),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $created = Prescription::query()->whereKeyNot($prescription->id)->firstOrFail();
        $this->assertSame('35.00', $created->total);
        $this->assertSame('Rest for a week', $created->advice);
        $this->assertSame('Napa 500', $created->lines->first()->medicine_name);
    }

    public function test_a_template_keeps_the_owner_doctors_product_and_saving_moves_no_stock(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 5]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08',
            'lines' => [['product_id' => $product->id, 'dose' => '1 spoon', 'unit_price' => '150', 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();
        $movements = DB::table('stock_movements')->count();
        $journals = DB::table('journal_entries')->count();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/templates', ['name' => 'Gastric', 'prescription_id' => $prescription->id])
            ->assertSessionHasNoErrors();

        $line = PrescriptionTemplate::query()->firstOrFail()->lines[0];
        $this->assertSame($product->id, $line['product_id']);
        $this->assertSame($product->name, $line['medicine_name']);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame($journals, DB::table('journal_entries')->count());
    }

    public function test_one_prescriber_cannot_see_use_or_delete_anothers_templates(): void
    {
        $first = $this->prescriber();
        $second = $this->prescriber();
        $owner = User::factory()->staff('super_admin')->create();
        $prescription = $this->prescriptionFor($first);
        $template = PrescriptionTemplate::factory()->create(['prescriber_id' => $first->id, 'name' => 'First private template']);

        // Not on another prescriber's page, nor on the owner-doctor's.
        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertDontSee('First private template')
            ->assertInertia(fn (Assert $page) => $page->has('data.prescription_tools.templates', 0));
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertDontSee('First private template')
            ->assertInertia(fn (Assert $page) => $page->has('data.prescription_tools.templates', 0));

        // Can't delete it, and can't copy someone else's prescription into a template.
        $this->actingAs($second)->onPortal('dealer')->delete('/commands/prescriptions/templates/'.$template->id)->assertNotFound();
        $this->assertNotNull($template->fresh());
        $this->actingAs($second)->onPortal('dealer')->post('/commands/prescriptions/templates', ['name' => 'Stolen', 'prescription_id' => $prescription->id])->assertNotFound();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/templates', ['name' => 'Stolen', 'prescription_id' => $prescription->id])->assertNotFound();
        $this->assertSame(0, PrescriptionTemplate::query()->where('name', 'Stolen')->count());

        $this->actingAs($first)->onPortal('dealer')->delete('/commands/prescriptions/templates/'.$template->id)->assertRedirect();
        $this->assertNull($template->fresh());
    }

    public function test_a_name_can_be_used_once_per_prescriber(): void
    {
        $first = $this->prescriber();
        $second = $this->prescriber();
        PrescriptionTemplate::factory()->create(['prescriber_id' => $first->id, 'name' => 'Fever']);

        $this->actingAs($first)->onPortal('dealer')->post('/commands/prescriptions/templates', ['name' => 'Fever', 'prescription_id' => $this->prescriptionFor($first)->id])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, PrescriptionTemplate::query()->where('prescriber_id', $first->id)->count());

        $this->actingAs($second)->onPortal('dealer')->post('/commands/prescriptions/templates', ['name' => 'Fever', 'prescription_id' => $this->prescriptionFor($second)->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionTemplate::query()->where('prescriber_id', $second->id)->count());
    }

    public function test_templates_need_the_partner_switch_and_the_feature_flag(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $prescription = $this->prescriptionFor($dealer);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/templates', ['name' => 'Nope', 'prescription_id' => $prescription->id])->assertForbidden();

        $enabled = $this->prescriber();
        $template = PrescriptionTemplate::factory()->create(['prescriber_id' => $enabled->id]);
        $own = $this->prescriptionFor($enabled);
        config(['madina.prescriptions.enabled' => false]);
        $this->actingAs($enabled)->onPortal('dealer')->post('/commands/prescriptions/templates', ['name' => 'Nope', 'prescription_id' => $own->id])->assertForbidden();
        $this->actingAs($enabled)->onPortal('dealer')->delete('/commands/prescriptions/templates/'.$template->id)->assertForbidden();
        $this->assertNotNull($template->fresh());
        $this->assertSame(1, PrescriptionTemplate::query()->count());
    }
}

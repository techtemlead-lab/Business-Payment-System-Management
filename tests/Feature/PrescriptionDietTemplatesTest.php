<?php

namespace Tests\Feature;

use App\Domain\Clinic\PrescriptionDietTemplates;
use App\Models\PrescriptionDietTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Named lists of foods to eat and to avoid that a prescriber keeps and adds to a prescription. */
class PrescriptionDietTemplatesTest extends TestCase
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

    public function test_a_prescriber_keeps_a_food_template_and_sees_it_on_the_prescriptions_page(): void
    {
        $dealer = $this->prescriber();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/diet-templates', [
            'name' => 'Diabetes diet',
            'foods_allowed' => [' Bitter gourd ', 'Oats', ''],
            'foods_avoided' => ['Sugar', 'sugar', 'White rice'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = PrescriptionDietTemplate::query()->where('prescriber_id', $dealer->id)->sole();
        $this->assertSame('Diabetes diet', $template->name);
        $this->assertSame(['Bitter gourd', 'Oats'], $template->foods_allowed);
        $this->assertSame(['Sugar', 'White rice'], $template->foods_avoided);
        $audit = DB::table('audit_events')->where('action', 'prescription.diet_template_saved')->where('subject', (string) $template->id)->sole();
        $this->assertSame($dealer->id, (int) $audit->actor_id);
        $this->assertFalse(json_decode((string) $audit->metadata, true)['updated']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.diet_templates', [[
                'id' => $template->id,
                'name' => 'Diabetes diet',
                'foods_allowed' => ['Bitter gourd', 'Oats'],
                'foods_avoided' => ['Sugar', 'White rice'],
            ]]));
    }

    public function test_the_food_lists_are_locked_in_the_database(): void
    {
        $template = PrescriptionDietTemplate::factory()->create(['foods_allowed' => ['Papaya'], 'foods_avoided' => ['Beef']]);

        $raw = DB::table('prescription_diet_templates')->where('id', $template->id)->first();
        $this->assertStringNotContainsString('Papaya', (string) $raw->foods_allowed);
        $this->assertStringNotContainsString('Beef', (string) $raw->foods_avoided);
    }

    public function test_a_prescriber_changes_and_deletes_their_own_food_template(): void
    {
        $dealer = $this->prescriber();
        $template = PrescriptionDietTemplate::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Old name']);

        $this->actingAs($dealer)->onPortal('dealer')->patch('/commands/prescriptions/diet-templates/'.$template->id, [
            'name' => 'Kidney diet',
            'foods_allowed' => ['Apple'],
            'foods_avoided' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $template->refresh();
        $this->assertSame('Kidney diet', $template->name);
        $this->assertSame(['Apple'], $template->foods_allowed);
        $this->assertNull($template->foods_avoided);
        $this->assertTrue(json_decode((string) DB::table('audit_events')->where('action', 'prescription.diet_template_saved')->where('subject', (string) $template->id)->value('metadata'), true)['updated']);

        $this->actingAs($dealer)->onPortal('dealer')->delete('/commands/prescriptions/diet-templates/'.$template->id)->assertRedirect();
        $this->assertSame(0, PrescriptionDietTemplate::query()->count());
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescription.diet_template_deleted')->where('subject', (string) $template->id)->where('actor_id', $dealer->id)->exists());
    }

    public function test_one_prescriber_cannot_see_change_or_delete_anothers_food_template(): void
    {
        $first = $this->prescriber();
        $second = $this->prescriber();
        $owner = User::factory()->staff('super_admin')->create();
        $template = PrescriptionDietTemplate::factory()->create(['prescriber_id' => $first->id, 'name' => 'Private diet', 'foods_allowed' => ['Papaya']]);

        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.diet_templates', []));
        // Not even the owner-doctor reads another prescriber's templates.
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.diet_templates', []));
        $this->actingAs($second)->onPortal('dealer')
            ->patch('/commands/prescriptions/diet-templates/'.$template->id, ['name' => 'Taken over', 'foods_allowed' => ['X']])->assertNotFound();
        $this->actingAs($second)->onPortal('dealer')
            ->delete('/commands/prescriptions/diet-templates/'.$template->id)->assertNotFound();

        $this->assertSame('Private diet', $template->fresh()->name);
        $this->assertSame(['Papaya'], $template->fresh()->foods_allowed);
    }

    public function test_a_name_can_be_used_once_per_prescriber_and_again_by_another(): void
    {
        $first = $this->prescriber();
        $second = $this->prescriber();
        PrescriptionDietTemplate::factory()->create(['prescriber_id' => $first->id, 'name' => 'Diabetes diet']);
        $kept = PrescriptionDietTemplate::factory()->create(['prescriber_id' => $first->id, 'name' => 'Kidney diet']);
        $payload = ['name' => 'Diabetes diet', 'foods_allowed' => ['Oats']];

        $this->actingAs($first)->onPortal('dealer')->post('/commands/prescriptions/diet-templates', $payload)
            ->assertSessionHasErrors(['name' => __('prescription.err_template_name_taken')]);
        $this->actingAs($first)->onPortal('dealer')->patch('/commands/prescriptions/diet-templates/'.$kept->id, $payload)
            ->assertSessionHasErrors(['name' => __('prescription.err_template_name_taken')]);
        $this->actingAs($second)->onPortal('dealer')->post('/commands/prescriptions/diet-templates', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, PrescriptionDietTemplate::query()->where('prescriber_id', $first->id)->count());
        $this->assertSame('Kidney diet', $kept->fresh()->name);
        $this->assertSame(1, PrescriptionDietTemplate::query()->where('prescriber_id', $second->id)->count());
    }

    public function test_a_food_template_needs_a_name_and_at_least_one_food(): void
    {
        $dealer = $this->prescriber();

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/diet-templates', ['name' => '', 'foods_allowed' => ['Oats']])
            ->assertSessionHasErrors('name');
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/diet-templates', ['name' => 'Empty diet', 'foods_allowed' => ['  '], 'foods_avoided' => []])
            ->assertSessionHasErrors(['foods_allowed' => __('prescription.err_diet_template_empty')]);

        $this->assertSame(0, PrescriptionDietTemplate::query()->count());
    }

    public function test_a_prescriber_cannot_keep_more_food_templates_than_the_limit(): void
    {
        $dealer = $this->prescriber();
        PrescriptionDietTemplate::factory()->count(PrescriptionDietTemplates::MaxTemplates)->create(['prescriber_id' => $dealer->id]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/diet-templates', ['name' => 'One too many', 'foods_allowed' => ['Oats']])
            ->assertSessionHasErrors(['name' => __('prescription.err_template_limit', ['max' => 100])]);

        $this->assertSame(100, PrescriptionDietTemplate::query()->where('prescriber_id', $dealer->id)->count());
    }

    public function test_food_templates_need_the_partner_switch_and_the_feature_flag(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => false]);
        $payload = ['name' => 'Diabetes diet', 'foods_allowed' => ['Oats']];

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/diet-templates', $payload)->assertForbidden();

        $dealer->forceFill(['can_prescribe' => true])->save();
        config(['madina.prescriptions.enabled' => false]);
        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/prescriptions/diet-templates', $payload)->assertForbidden();

        $this->assertSame(0, PrescriptionDietTemplate::query()->count());
    }

    public function test_an_office_account_switched_on_for_company_stock_keeps_food_templates(): void
    {
        $staff = User::factory()->staff('employee')->create(['can_prescribe' => true]);

        $this->actingAs($staff)->onPortal('manage')
            ->post('/commands/prescriptions/diet-templates', ['name' => 'Diabetes diet', 'foods_avoided' => ['Sugar']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['Sugar'], PrescriptionDietTemplate::query()->where('prescriber_id', $staff->id)->sole()->foods_avoided);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The owner's final decisions on prescriptions: the below-30% rule, who may use company stock,
 * payment details on a dispense, patient privacy and the paged lists.
 */
class PrescriptionOwnerDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CompanyBranch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($this->owner, [
            'product_id' => $this->product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-decisions-in', 'branch_id' => $this->branch->id,
        ]);
    }

    /** @return TestResponse<Response> */
    private function grant(User $actor, User $account, string $portal = 'sadmin'): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)->post('/commands/accounts/'.$account->id.'/prescriber', [
            'registration_type' => 'bmdc', 'registration_number' => 'A-777', 'note' => 'Papers seen',
        ]);
    }

    /** @return TestResponse<Response> */
    private function remove(User $actor, User $account, string $portal = 'sadmin'): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)->delete('/commands/accounts/'.$account->id.'/prescriber', ['reason' => 'Left the clinic']);
    }

    private function grantedMd(): User
    {
        $md = User::factory()->staff('md')->create();
        $this->grant($this->owner, $md)->assertRedirect()->assertSessionHasNoErrors();

        return $md->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function write(User $prescriber, string $unitPrice = '150', string $portal = 'sadmin', array $extra = []): TestResponse
    {
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $prescriber->id]);

        return $this->actingAs($prescriber)->onPortal($portal)->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08',
            'lines' => [['product_id' => $this->product->id, 'unit_price' => $unitPrice, 'quantity' => 1]],
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function dispense(User $actor, Prescription $prescription, string $portal = 'sadmin', array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $this->branch->id, ...$extra]);
    }

    /** A clinic prescription already on the books for $prescriber, priced $linePrice against a 200 MRP. */
    private function issued(User $prescriber, string $linePrice): Prescription
    {
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $prescriber->id]);
        $prescription = Prescription::factory()->create([
            'patient_id' => $patient->id, 'prescriber_id' => $prescriber->id, 'kind' => Prescription::KindClinic, 'status' => Prescription::StatusIssued, 'total' => $linePrice,
        ]);
        $prescription->lines()->create([
            'product_id' => $this->product->id, 'medicine_name' => 'Below Floor Syrup', 'unit_price' => $linePrice, 'quantity' => 1,
            'discount' => '0.00', 'line_total' => $linePrice, 'mrp' => '200.00', 'position' => 0,
        ]);

        return $prescription;
    }

    public function test_a_non_owner_below_thirty_percent_is_blocked_even_with_a_forged_confirm(): void
    {
        $md = $this->grantedMd();

        $this->write($md, '50', 'sadmin', ['confirm_below_floor' => true])->assertSessionHasErrors('lines');
        $this->assertSame(0, Prescription::query()->count());

        $prescription = $this->issued($md, '50.00');
        $this->dispense($md, $prescription, 'sadmin', ['confirm_below_floor' => true])
            ->assertSessionHasErrors('prices')
            ->assertSessionDoesntHaveErrors('confirm_below_floor');

        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertFalse(DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->exists());
        $this->assertFalse(DB::table('audit_events')->where('action', ClinicDispensing::BelowFloorOverrideAction)->exists());
    }

    public function test_the_owner_must_confirm_a_sale_below_thirty_percent_and_the_override_is_logged(): void
    {
        $this->write($this->owner, '50')->assertSessionHasErrors('confirm_below_floor');
        $this->assertSame(0, Prescription::query()->count());
        $this->assertStringContainsString('loss', session('errors')->first('confirm_below_floor'));

        $this->write($this->owner, '50', 'sadmin', ['confirm_below_floor' => true])->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();
        $this->assertSame('prescribed', json_decode((string) DB::table('audit_events')->where('action', ClinicDispensing::BelowFloorOverrideAction)->value('metadata'), true)['stage']);

        $this->dispense($this->owner, $prescription)->assertSessionHasErrors('confirm_below_floor');
        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);

        $this->dispense($this->owner, $prescription, 'sadmin', ['confirm_below_floor' => true])->assertSessionHasNoErrors();
        $this->assertSame(Prescription::StatusDispensed, $prescription->fresh()->status);
        $this->assertSame(9, $this->product->fresh()->stock);

        $audit = DB::table('audit_events')->where('action', ClinicDispensing::BelowFloorOverrideAction)->orderByDesc('id')->first();
        $this->assertSame($this->owner->id, (int) $audit->actor_id);
        $this->assertSame((string) $prescription->id, $audit->subject);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame('dispensed', $metadata['stage']);
        $this->assertSame('-10.00', $metadata['clinic_fund']);
    }

    public function test_a_price_at_thirty_percent_needs_no_confirmation(): void
    {
        $this->write($this->owner, '60')->assertSessionHasNoErrors();
        $this->dispense($this->owner, Prescription::query()->firstOrFail())->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('audit_events')->where('action', ClinicDispensing::BelowFloorOverrideAction)->exists());
    }

    public function test_the_fund_split_below_thirty_percent_balances(): void
    {
        $this->write($this->owner, '50', 'sadmin', ['confirm_below_floor' => true])->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();
        $this->dispense($this->owner, $prescription, 'sadmin', ['confirm_below_floor' => true])->assertSessionHasNoErrors();

        $prescription->refresh();
        $this->assertSame('60.00', $prescription->manufacturer_share);
        $this->assertSame('-10.00', $prescription->clinic_fund);

        $entryId = DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->value('id');
        $lines = DB::table('journal_lines')->where('entry_id', $entryId)->get()->keyBy('account');
        $this->assertSame('50.00', (string) $lines[ClinicDispensing::ClinicCashAccount]->debit);
        $this->assertSame('60.00', (string) $lines[ClinicDispensing::ManufacturerAccount]->credit);
        $this->assertSame('10.00', (string) $lines[ClinicDispensing::ClinicFundAccount]->debit);
        $this->assertSame(
            (float) DB::table('journal_lines')->where('entry_id', $entryId)->sum('debit'),
            (float) DB::table('journal_lines')->where('entry_id', $entryId)->sum('credit'),
        );
    }

    public function test_the_old_block_or_warn_setting_is_gone(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/price-floor', ['mode' => 'warn'])
            ->assertNotFound();
    }

    public function test_only_an_authorised_person_can_grant_or_remove_company_stock_prescribing(): void
    {
        $account = User::factory()->staff('employee')->create();
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $finance = User::factory()->staff('finance')->create();

        $this->grant($finance, $account, 'manage')->assertForbidden();
        $this->grant($dealer, $account, 'dealer')->assertForbidden();
        $this->assertFalse($account->fresh()->can_prescribe);

        $verifier = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts', 'prescriber_verify']]]);
        $authorised = [
            [$this->owner, 'sadmin'],
            [User::factory()->staff('md')->create(), 'sadmin'],
            [User::factory()->staff('chairman')->create(), 'sadmin'],
            [$verifier, 'manage'],
        ];
        foreach ($authorised as [$actor, $portal]) {
            $this->grant($actor, $account, $portal)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertTrue($account->fresh()->can_prescribe);
            $this->assertDatabaseHas('prescriber_registrations', [
                'user_id' => $account->id, 'enabled' => true, 'registration_type' => 'bmdc', 'registration_number' => 'A-777', 'enabled_by' => $actor->id,
            ]);
            $this->assertNotNull(DB::table('prescriber_registrations')->where('user_id', $account->id)->value('enabled_at'));
            $this->assertTrue(DB::table('audit_events')->where('action', 'prescriber.company_stock_granted')->where('subject', (string) $account->id)->where('actor_id', $actor->id)->exists());

            $this->grant($finance, $account, 'manage')->assertForbidden();
            $this->remove($finance, $account, 'manage')->assertForbidden();
            $this->assertTrue($account->fresh()->can_prescribe);

            $this->remove($actor, $account, $portal)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertFalse($account->fresh()->can_prescribe);
            $this->assertTrue(DB::table('audit_events')->where('action', 'prescriber.company_stock_removed')->where('subject', (string) $account->id)->where('actor_id', $actor->id)->exists());
        }
    }

    public function test_nobody_turns_it_on_for_themselves_and_the_owner_cannot_be_switched_off(): void
    {
        $verifier = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts', 'prescriber_verify']]]);
        $md = User::factory()->staff('md')->create();

        $this->grant($verifier, $verifier, 'manage')->assertForbidden();
        $this->grant($md, $md)->assertForbidden();
        $this->assertFalse($verifier->fresh()->can_prescribe);
        $this->assertFalse($md->fresh()->can_prescribe);

        $this->grant($md, $this->owner)->assertStatus(422);
        $this->remove($md, $this->owner)->assertStatus(422);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions')->assertOk();
    }

    public function test_only_owners_and_staff_with_the_permission_can_enable_a_partner(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->grant(User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts']]]), $dealer, 'manage')->assertForbidden();
        $this->assertFalse($dealer->fresh()->can_prescribe);

        $this->grant(User::factory()->staff('chairman')->create(), $dealer)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($dealer->fresh()->can_prescribe);
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescriber.enabled')->where('subject', (string) $dealer->id)->exists());
    }

    public function test_a_granted_md_can_prescribe_and_dispense_and_an_ungranted_one_cannot(): void
    {
        $ungranted = User::factory()->staff('md')->create();
        $this->actingAs($ungranted)->onPortal('sadmin')->get('/prescriptions')->assertForbidden();
        $this->write($ungranted)->assertForbidden();
        $this->dispense($ungranted, $this->issued($ungranted, '150.00'))->assertForbidden();

        $md = $this->grantedMd();
        $this->actingAs($md)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.mode', 'clinic')
                ->where('data.prescriptions.is_owner', false)
                ->missing('data.prescriptions.report'));
        $this->write($md)->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('prescriber_id', $md->id)->firstOrFail();
        $this->assertSame(Prescription::KindClinic, $prescription->kind);
        $this->assertSame($this->product->id, $prescription->lines->first()->product_id);

        $this->dispense($md, $prescription)->assertRedirect()->assertSessionHasNoErrors();
        $prescription->refresh();
        $this->assertSame(Prescription::StatusDispensed, $prescription->status);
        $this->assertSame($md->id, $prescription->dispensed_by);
        $this->assertSame(9, $this->product->fresh()->stock);

        $this->remove($this->owner, $md)->assertRedirect();
        $this->actingAs($md->fresh())->onPortal('sadmin')->get('/prescriptions')->assertForbidden();
    }

    public function test_a_granted_staff_member_dispenses_only_from_a_branch_they_are_assigned_to(): void
    {
        $staff = User::factory()->staff('employee')->create(['profile' => ['permissions' => []]]);
        $this->grant($this->owner, $staff)->assertRedirect()->assertSessionHasNoErrors();
        $staff->refresh();

        $this->actingAs($staff)->onPortal('manage')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescriptions.branches', []));
        $this->write($staff, '150', 'manage')->assertRedirect()->assertSessionHasNoErrors();
        $prescription = Prescription::query()->where('prescriber_id', $staff->id)->firstOrFail();

        $this->dispense($staff, $prescription, 'manage')->assertForbidden();
        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);

        BranchStaffAssignment::query()->create(['branch_id' => $this->branch->id, 'user_id' => $staff->id]);
        $this->actingAs($staff)->onPortal('manage')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescriptions.branches.0.id', $this->branch->id));
        $this->dispense($staff, $prescription, 'manage')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Prescription::StatusDispensed, $prescription->fresh()->status);
    }

    public function test_the_dispense_branch_list_offers_only_active_stock_hubs(): void
    {
        $hub = CompanyBranch::factory()->create();
        $inactive = CompanyBranch::factory()->inactive()->create();
        $deliveryOnly = CompanyBranch::factory()->create(['is_stock_hub' => false]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(function (Assert $page) use ($hub, $inactive, $deliveryOnly): void {
                $ids = collect($page->toArray()['props']['data']['prescriptions']['branches'])->pluck('id');
                $this->assertTrue($ids->contains($hub->id));
                $this->assertFalse($ids->contains($inactive->id));
                $this->assertFalse($ids->contains($deliveryOnly->id));
            });
    }

    public function test_the_payment_method_is_saved_and_shown_on_the_print_page_and_the_report(): void
    {
        $this->write($this->owner)->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();

        $this->dispense($this->owner, $prescription, 'sadmin', ['payment_method' => 'crypto'])->assertSessionHasErrors('payment_method');
        $this->dispense($this->owner, $prescription, 'sadmin', ['payment_method' => 'bkash', 'payment_reference' => ' TRX-991 '])->assertSessionHasNoErrors();

        $prescription->refresh();
        $this->assertSame('bkash', $prescription->payment_method);
        $this->assertSame('TRX-991', $prescription->payment_reference);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions/'.$prescription->id.'/print?lang=en')
            ->assertOk()->assertSee('bKash')->assertSee('TRX-991');

        $this->write($this->owner)->assertSessionHasNoErrors();
        $this->dispense($this->owner, Prescription::query()->orderByDesc('id')->firstOrFail())->assertSessionHasNoErrors();
        $this->assertSame('cash', Prescription::query()->orderByDesc('id')->firstOrFail()->payment_method, 'Cash stays the default.');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.prescriptions.1.payment_method', 'bkash')
                ->where('data.prescriptions.prescriptions.1.payment_reference', 'TRX-991')
                ->has('data.prescriptions.report.by_method', 2));

        $entryId = DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->value('id');
        $this->assertSame(
            (float) DB::table('journal_lines')->where('entry_id', $entryId)->sum('debit'),
            (float) DB::table('journal_lines')->where('entry_id', $entryId)->sum('credit'),
        );
    }

    public function test_the_owner_cannot_reach_a_partners_patient_or_prescription(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Hidden Patient', 'phone' => '01712341234', 'notes' => 'Private note']);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'prescriber_id' => $dealer->id, 'diagnosis' => 'Private diagnosis']);

        foreach ([$this->owner, $this->grantedMd()] as $viewer) {
            $response = $this->actingAs($viewer)->onPortal('sadmin')->get('/prescriptions?q=Hidden')->assertOk();
            $response->assertDontSee('Hidden Patient')->assertDontSee('01712341234')->assertDontSee('Private note')->assertDontSee('Private diagnosis');
            $response->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.patients', [])
                ->where('data.prescriptions.own_patients', [])
                ->where('data.prescriptions.prescriptions', []));

            $this->actingAs($viewer)->onPortal('sadmin')->get('/prescriptions/'.$prescription->id.'/print')->assertNotFound();
            $this->actingAs($viewer)->onPortal('sadmin')->patch('/commands/prescriptions/patients/'.$patient->id, ['name' => 'Changed'])->assertNotFound();
            $this->dispense($viewer, $prescription)->assertNotFound();
            $this->actingAs($viewer)->onPortal('sadmin')->getJson('/commands/prescriptions/patients/options?q=Hidden')->assertOk()->assertExactJson(['data' => []]);
        }
        $this->assertSame('Hidden Patient', $patient->fresh()->name);
    }

    public function test_a_prescriber_sees_their_own_patient_with_the_full_phone_and_the_print_page_is_masked(): void
    {
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $this->owner->id, 'name' => 'My Patient', 'phone' => '01712341234', 'notes' => 'My note']);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'prescriber_id' => $this->owner->id]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.patients.0.name', 'My Patient')
                ->where('data.prescriptions.patients.0.phone', '01712341234')
                ->where('data.prescriptions.patients.0.notes', 'My note')
                ->where('data.prescriptions.prescriptions.0.id', $prescription->id));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions/'.$prescription->id.'/print?lang=en')
            ->assertOk()
            ->assertSee('017****1234')
            ->assertDontSee('01712341234');
    }

    public function test_older_records_can_be_paged_and_the_picker_finds_old_own_patients(): void
    {
        $oldest = PrescriptionPatient::factory()->create(['prescriber_id' => $this->owner->id, 'name' => 'Oldest Rahima']);
        Prescription::factory()->create(['patient_id' => $oldest->id, 'prescriber_id' => $this->owner->id, 'number' => 'RX-OLDEST']);
        PrescriptionPatient::factory()->count(100)->create(['prescriber_id' => $this->owner->id]);
        $newest = PrescriptionPatient::query()->orderByDesc('id')->firstOrFail();
        Prescription::factory()->count(100)->create(['patient_id' => $newest->id, 'prescriber_id' => $this->owner->id]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.prescriptions.patients', 100)
                ->where('data.prescriptions.patients_page.last_page', 2)
                ->where('data.prescriptions.prescriptions_page.last_page', 2)
                ->has('data.prescriptions.own_patients', 30));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions?patients_page=2&rx_page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescriptions.patients.0.id', $oldest->id)
                ->where('data.prescriptions.prescriptions.0.number', 'RX-OLDEST'));

        $this->actingAs($this->owner)->onPortal('sadmin')->getJson('/commands/prescriptions/patients/options?q=Oldest')
            ->assertOk()->assertExactJson(['data' => [['id' => $oldest->id, 'name' => 'Oldest Rahima', 'age' => $oldest->age]]]);
    }
}

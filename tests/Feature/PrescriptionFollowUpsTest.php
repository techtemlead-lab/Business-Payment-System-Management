<?php

namespace Tests\Feature;

use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrescriptionFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 01:00 on 8 November in Dhaka, still 7 November in UTC: "today" must be the Dhaka date.
        $this->travelTo(Carbon::parse('2026-11-07 19:00:00', 'UTC'));
    }

    private function prescriber(array $attributes = []): User
    {
        return User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true, ...$attributes]);
    }

    private function followUp(User $prescriber, ?string $date, array $patient = [], array $prescription = []): Prescription
    {
        $record = PrescriptionPatient::factory()->create(['prescriber_id' => $prescriber->id, ...$patient]);

        return Prescription::factory()->create(['patient_id' => $record->id, 'prescribed_on' => '2026-11-01', 'follow_up_on' => $date, ...$prescription]);
    }

    public function test_the_section_home_lists_follow_ups_due_today_and_overdue(): void
    {
        $dealer = $this->prescriber();
        $this->followUp($dealer, '2026-11-05', ['name' => 'Late Patient', 'phone' => '01712345678']);
        $this->followUp($dealer, '2026-11-08', ['name' => 'Today Patient']);
        $this->followUp($dealer, '2026-11-09', ['name' => 'Tomorrow Patient']);
        $this->followUp($dealer, null, ['name' => 'No Date Patient']);
        $this->followUp($dealer, '2026-11-04', ['name' => 'Closed Patient'], ['follow_up_done_at' => now()]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.follow_ups.due_today', 1)
                ->where('data.prescription_tools.follow_ups.overdue', 1)
                ->has('data.prescription_tools.follow_ups.rows', 2)
                ->where('data.prescription_tools.follow_ups.rows.0.patient', 'Late Patient')
                ->where('data.prescription_tools.follow_ups.rows.0.phone', '01712345678')
                ->where('data.prescription_tools.follow_ups.rows.0.days_late', 3)
                ->where('data.prescription_tools.follow_ups.rows.1.patient', 'Today Patient')
                ->where('data.prescription_tools.follow_ups.rows.1.days_late', 0));
    }

    public function test_a_newer_prescription_for_the_patient_closes_the_old_follow_up(): void
    {
        $dealer = $this->prescriber();
        $old = $this->followUp($dealer, '2026-11-05', ['name' => 'Returned Patient']);
        Prescription::factory()->create(['patient_id' => $old->patient_id, 'prescribed_on' => '2026-11-06', 'follow_up_on' => '2026-11-20']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.follow_ups.overdue', 0)
                ->has('data.prescription_tools.follow_ups.rows', 0));
    }

    public function test_marking_a_follow_up_done_removes_it_from_the_list(): void
    {
        $dealer = $this->prescriber();
        $prescription = $this->followUp($dealer, '2026-11-05');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/'.$prescription->id.'/follow-up-done')->assertRedirect();

        $this->assertNotNull($prescription->fresh()->follow_up_done_at);
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescription.follow_up_done')->where('subject', (string) $prescription->id)->exists());
        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.prescription_tools.follow_ups.rows', 0));
    }

    public function test_one_prescriber_cannot_see_or_close_anothers_follow_ups(): void
    {
        $first = $this->prescriber();
        $second = $this->prescriber();
        $owner = User::factory()->staff('super_admin')->create();
        $prescription = $this->followUp($first, '2026-11-05', ['name' => 'First Private Patient']);

        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertDontSee('First Private Patient')
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.follow_ups.overdue', 0)
                ->has('data.prescription_tools.follow_ups.rows', 0));
        $this->actingAs($second)->onPortal('dealer')->post('/commands/prescriptions/'.$prescription->id.'/follow-up-done')->assertNotFound();
        $this->assertNull($prescription->fresh()->follow_up_done_at);

        // The owner-doctor sees every patient, but this list is only their own work.
        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.follow_ups.overdue', 0)
                ->has('data.prescription_tools.follow_ups.rows', 0));
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/'.$prescription->id.'/follow-up-done')->assertNotFound();
        $this->assertNull($prescription->fresh()->follow_up_done_at);
    }

    public function test_the_scheduled_command_posts_one_notice_with_counts_and_sends_no_sms(): void
    {
        $dealer = $this->prescriber(['locale' => 'en']);
        $other = $this->prescriber();
        $quiet = $this->prescriber();
        $this->followUp($dealer, '2026-11-05', ['name' => 'Hidden Name One']);
        $this->followUp($dealer, '2026-11-04', ['name' => 'Hidden Name Two']);
        $this->followUp($dealer, '2026-11-08', ['name' => 'Hidden Name Three']);
        $this->followUp($other, '2026-11-08');
        $this->followUp($quiet, '2026-11-20');

        $this->artisan('prescriptions:follow-up-reminders')->assertSuccessful();
        // A second run on the same day adds nothing.
        $this->artisan('prescriptions:follow-up-reminders')->assertSuccessful();

        $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $other->id)->count());
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $quiet->id)->count());
        $body = (string) DB::table('account_notices')->where('user_id', $dealer->id)->value('body');
        $this->assertStringContainsString('1', $body);
        $this->assertStringContainsString('2', $body);
        $this->assertStringNotContainsString('Hidden Name', $body);
        $this->assertSame([], (array) config('madina.sms.sent', []));
        $this->assertSame(0, DB::table('sms_logs')->count());
    }

    public function test_no_notice_when_the_module_or_the_partner_is_switched_off(): void
    {
        $disabled = $this->prescriber(['can_prescribe' => false]);
        $enabled = $this->prescriber();
        $this->followUp($disabled, '2026-11-05');
        $this->followUp($enabled, '2026-11-05');

        config(['madina.prescriptions.enabled' => false]);
        $this->artisan('prescriptions:follow-up-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('account_notices')->count());

        config(['madina.prescriptions.enabled' => true]);
        $this->artisan('prescriptions:follow-up-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $disabled->id)->count());
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $enabled->id)->count());
    }

    public function test_marking_done_needs_the_partner_switch_and_the_feature_flag(): void
    {
        $disabled = $this->prescriber(['can_prescribe' => false]);
        $prescription = $this->followUp($disabled, '2026-11-05');
        $this->actingAs($disabled)->onPortal('dealer')->post('/commands/prescriptions/'.$prescription->id.'/follow-up-done')->assertForbidden();

        $enabled = $this->prescriber();
        $own = $this->followUp($enabled, '2026-11-05');
        config(['madina.prescriptions.enabled' => false]);
        $this->actingAs($enabled)->onPortal('dealer')->post('/commands/prescriptions/'.$own->id.'/follow-up-done')->assertForbidden();
        $this->assertNull($own->fresh()->follow_up_done_at);
    }
}

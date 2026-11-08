<?php

namespace Tests\Feature;

use App\Models\PrescriptionAppointment;
use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A prescriber's token queue: numbered per day, per prescriber, and private to them.
 */
class ClinicQueueTest extends TestCase
{
    use RefreshDatabase;

    private const Today = '2026-11-08';

    private const Tomorrow = '2026-11-09';

    private User $prescriber;

    private User $otherPrescriber;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-08 06:00:00');
        $this->prescriber = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $this->otherPrescriber = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function patient(?User $prescriber = null): PrescriptionPatient
    {
        return PrescriptionPatient::factory()->create(['prescriber_id' => ($prescriber ?? $this->prescriber)->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function book(PrescriptionPatient $patient, array $extra = [], ?User $actor = null, string $portal = 'dealer'): TestResponse
    {
        return $this->actingAs($actor ?? $this->prescriber)->onPortal($portal)->post('/commands/prescriptions/appointments', [
            'patient_id' => $patient->id, 'appointment_on' => self::Today, ...$extra,
        ]);
    }

    /** @return TestResponse<Response> */
    private function setStatus(PrescriptionAppointment $appointment, string $status, ?User $actor = null, string $portal = 'dealer'): TestResponse
    {
        return $this->actingAs($actor ?? $this->prescriber)->onPortal($portal)
            ->post('/commands/prescriptions/appointments/'.$appointment->id.'/status', ['status' => $status]);
    }

    private function booked(?PrescriptionPatient $patient = null): PrescriptionAppointment
    {
        $this->book($patient ?? $this->patient())->assertRedirect()->assertSessionHasNoErrors();

        return PrescriptionAppointment::query()->latest('id')->firstOrFail();
    }

    public function test_tokens_count_up_for_each_day_and_each_prescriber(): void
    {
        $this->assertSame(1, $this->booked()->token);
        $this->assertSame(2, $this->booked()->token);

        $this->book($this->patient(), ['appointment_on' => self::Tomorrow])->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionAppointment::query()->latest('id')->firstOrFail()->token);

        $this->book($this->patient($this->otherPrescriber), [], $this->otherPrescriber)->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionAppointment::query()->latest('id')->firstOrFail()->token);
    }

    public function test_the_visit_reason_is_encrypted_and_the_queue_shows_on_the_prescribers_page(): void
    {
        $patient = $this->patient();
        $this->book($patient, ['note' => 'Back pain', 'appointment_time' => '10:30'])->assertSessionHasNoErrors();
        $appointment = PrescriptionAppointment::query()->firstOrFail();

        $this->assertStringNotContainsString('Back pain', (string) DB::table('prescription_appointments')->where('id', $appointment->id)->value('note'));
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.prescription_tools.queue.date', self::Today)
                ->where('data.prescription_tools.queue.rows.0.token', 1)
                ->where('data.prescription_tools.queue.rows.0.patient', $patient->name)
                ->where('data.prescription_tools.queue.rows.0.time', '10:30')
                ->where('data.prescription_tools.queue.rows.0.note', 'Back pain'));
    }

    public function test_another_day_can_be_opened_and_an_impossible_date_falls_back_to_today(): void
    {
        $this->book($this->patient(), ['appointment_on' => self::Tomorrow])->assertSessionHasNoErrors();

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions?queue_date='.self::Tomorrow)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.prescription_tools.queue.rows', 1));
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions?queue_date=2026-99-99')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.queue.date', self::Today));
    }

    public function test_a_patient_with_an_open_token_cannot_take_another_that_day_but_can_after_cancelling(): void
    {
        $patient = $this->patient();
        $appointment = $this->booked($patient);

        $this->book($patient)->assertSessionHasErrors('patient_id');
        $this->assertSame(1, PrescriptionAppointment::query()->count());

        $this->setStatus($appointment, PrescriptionAppointment::StatusCancelled)->assertSessionHasNoErrors();
        $this->book($patient)->assertSessionHasNoErrors();
        $this->assertSame(2, PrescriptionAppointment::query()->latest('id')->firstOrFail()->token);
    }

    public function test_a_past_date_and_a_bad_time_are_refused(): void
    {
        $this->book($this->patient(), ['appointment_on' => '2026-11-07'])->assertSessionHasErrors('appointment_on');
        $this->book($this->patient(), ['appointment_time' => '25:00'])->assertSessionHasErrors('appointment_time');
        $this->assertSame(0, PrescriptionAppointment::query()->count());
    }

    public function test_calling_the_next_patient_finishes_the_one_being_seen(): void
    {
        $first = $this->booked();
        $second = $this->booked();

        $this->setStatus($first, PrescriptionAppointment::StatusServing)->assertSessionHasNoErrors();
        $this->assertSame(PrescriptionAppointment::StatusServing, $first->fresh()->status);

        $this->setStatus($second, PrescriptionAppointment::StatusServing)->assertSessionHasNoErrors();
        $this->assertSame(PrescriptionAppointment::StatusDone, $first->fresh()->status);
        $this->assertSame(PrescriptionAppointment::StatusServing, $second->fresh()->status);
    }

    public function test_a_finished_token_cannot_be_reopened_and_unknown_statuses_are_refused(): void
    {
        $appointment = $this->booked();
        $this->setStatus($appointment, 'nonsense')->assertSessionHasErrors('status');
        $this->setStatus($appointment, PrescriptionAppointment::StatusDone)->assertSessionHasErrors('status');
        $this->setStatus($appointment, PrescriptionAppointment::StatusServing)->assertSessionHasNoErrors();
        $this->setStatus($appointment, PrescriptionAppointment::StatusDone)->assertSessionHasNoErrors();

        $this->setStatus($appointment, PrescriptionAppointment::StatusServing)->assertSessionHasErrors('status');
        $this->assertSame(PrescriptionAppointment::StatusDone, $appointment->fresh()->status);
    }

    public function test_another_prescriber_and_the_owner_get_a_404_and_see_nothing(): void
    {
        $patient = $this->patient();
        $appointment = $this->booked($patient);

        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $this->book($patient, [], $viewer, $portal)->assertNotFound();
            // The owner of the patient is checked first, so bad details tell them nothing either.
            $this->book($patient, ['appointment_on' => 'not-a-date'], $viewer, $portal)->assertNotFound();
            $this->setStatus($appointment, PrescriptionAppointment::StatusServing, $viewer, $portal)->assertNotFound();
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions')->assertOk()
                ->assertDontSee($patient->name)
                ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.queue.rows', []));
        }

        $this->assertSame(1, PrescriptionAppointment::query()->count());
        $this->assertSame(PrescriptionAppointment::StatusWaiting, $appointment->fresh()->status);
    }

    public function test_a_prescriber_whose_switch_is_off_cannot_change_their_queue(): void
    {
        $appointment = $this->booked();
        $this->prescriber->forceFill(['can_prescribe' => false])->save();

        $this->setStatus($appointment, PrescriptionAppointment::StatusServing, $this->prescriber->fresh())->assertForbidden();
        $this->assertSame(PrescriptionAppointment::StatusWaiting, $appointment->fresh()->status);
    }
}

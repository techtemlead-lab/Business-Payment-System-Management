<?php

namespace Tests\Feature;

use App\Models\ConsultationRequest;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientAccessLog;
use App\Models\PrescriptionTemplate;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sensitive patient fields are unreadable in the database, existing rows are moved over
 * safely, and each prescriber alone sees who opened their patients' records.
 */
class ClinicEncryptionAndAccessLogTest extends TestCase
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

    private function raw(string $table, int $id, string $column): mixed
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    private function encryptionMigration(): Migration
    {
        return require database_path('migrations/2026_11_08_105952_encrypt_sensitive_patient_fields.php');
    }

    public function test_sensitive_fields_are_unreadable_in_the_database_and_readable_on_screen(): void
    {
        $patient = $this->patient(['name' => 'Karim', 'phone' => '01799887766', 'notes' => 'Private note']);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'complaints' => 'Chest pain', 'diagnosis' => 'Angina', 'advice' => 'Rest now']);
        $template = PrescriptionTemplate::query()->create(['prescriber_id' => $this->prescriber->id, 'name' => 'Set', 'advice' => 'Template advice', 'lines' => []]);
        $request = ConsultationRequest::query()->create([
            'prescriber_id' => $this->prescriber->id, 'name' => 'Visitor', 'phone' => '01811223344', 'problem' => 'Knee pain',
            'consent_statement' => 'I agree', 'consented_at' => now(),
        ]);

        $checks = [
            ['prescription_patients', $patient->id, 'phone', '01799887766'],
            ['prescription_patients', $patient->id, 'notes', 'Private note'],
            ['prescriptions', $prescription->id, 'complaints', 'Chest pain'],
            ['prescriptions', $prescription->id, 'diagnosis', 'Angina'],
            ['prescriptions', $prescription->id, 'advice', 'Rest now'],
            ['prescription_templates', $template->id, 'advice', 'Template advice'],
            ['consultation_requests', $request->id, 'phone', '01811223344'],
            ['consultation_requests', $request->id, 'problem', 'Knee pain'],
        ];
        foreach ($checks as [$table, $id, $column, $plain]) {
            $stored = (string) $this->raw($table, $id, $column);
            $this->assertStringNotContainsString($plain, $stored, "$table.$column");
            $this->assertSame($plain, Crypt::decryptString($stored), "$table.$column");
        }

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.patient.phone', '01799887766')
                ->where('record.patient.notes', 'Private note')
                ->where('record.prescriptions.0.diagnosis', 'Angina'));
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()
            ->assertSee('Angina')->assertSee('017****7766')->assertDontSee('01799887766');
    }

    public function test_search_by_name_still_works_with_the_other_fields_encrypted(): void
    {
        $this->patient(['name' => 'Rahima Begum', 'phone' => '01700000001']);
        $this->patient(['name' => 'Someone Else', 'phone' => '01700000002']);

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions?q=rahima')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.prescriptions.patients', 1)
                ->where('data.prescriptions.patients.0.name', 'Rahima Begum')
                ->where('data.prescriptions.patients.0.phone', '01700000001'));
        $this->actingAs($this->prescriber)->onPortal('dealer')->getJson('/commands/prescriptions/patients/options?q=begum')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rahima Begum');
    }

    public function test_the_migration_encrypts_existing_rows_once_and_can_be_undone(): void
    {
        $stamp = '2026-01-02 03:04:05';
        $common = ['prescriber_id' => $this->prescriber->id, 'created_at' => $stamp, 'updated_at' => $stamp];
        $old = DB::table('prescription_patients')->insertGetId(['name' => 'Old', 'phone' => '01711111111', 'notes' => 'Old note', ...$common]);
        $empty = DB::table('prescription_patients')->insertGetId(['name' => 'Empty', 'phone' => null, 'notes' => null, ...$common]);
        $already = DB::table('prescription_patients')->insertGetId(['name' => 'Already', 'phone' => Crypt::encryptString('01722222222'), ...$common]);
        $prescription = DB::table('prescriptions')->insertGetId([
            'number' => 'RX-OLD-1', 'kind' => 'partner', 'patient_id' => $old, 'prescribed_on' => '2026-01-02',
            'complaints' => 'Old complaint', 'diagnosis' => 'Old diagnosis', 'advice' => 'Old advice', ...$common,
        ]);
        $template = DB::table('prescription_templates')->insertGetId(['name' => 'Old set', 'advice' => 'Old template advice', 'lines' => '[]', ...$common]);
        $request = DB::table('consultation_requests')->insertGetId([
            'name' => 'Old visitor', 'phone' => '01733333333', 'problem' => 'Old problem', 'consent_statement' => 'Yes', 'consented_at' => $stamp, ...$common,
        ]);
        $plain = [
            ['prescription_patients', $old, 'phone', '01711111111'],
            ['prescription_patients', $old, 'notes', 'Old note'],
            ['prescriptions', $prescription, 'complaints', 'Old complaint'],
            ['prescriptions', $prescription, 'diagnosis', 'Old diagnosis'],
            ['prescriptions', $prescription, 'advice', 'Old advice'],
            ['prescription_templates', $template, 'advice', 'Old template advice'],
            ['consultation_requests', $request, 'phone', '01733333333'],
            ['consultation_requests', $request, 'problem', 'Old problem'],
        ];

        $migration = $this->encryptionMigration();
        $migration->up();
        // Running it again must not encrypt anything twice.
        $migration->up();

        foreach ($plain as [$table, $id, $column, $value]) {
            $stored = (string) $this->raw($table, $id, $column);
            $this->assertNotSame($value, $stored, "$table.$column");
            $this->assertSame($value, Crypt::decryptString($stored), "$table.$column");
        }
        $this->assertSame('01711111111', PrescriptionPatient::query()->findOrFail($old)->phone);
        $this->assertSame('Old diagnosis', Prescription::query()->findOrFail($prescription)->diagnosis);
        $this->assertNull($this->raw('prescription_patients', $empty, 'phone'));
        $this->assertNull($this->raw('prescription_patients', $empty, 'notes'));
        $this->assertSame('01722222222', Crypt::decryptString((string) $this->raw('prescription_patients', $already, 'phone')));
        $this->assertSame($stamp, (string) $this->raw('prescription_patients', $old, 'updated_at'));

        $migration->down();

        foreach ($plain as [$table, $id, $column, $value]) {
            $this->assertSame($value, $this->raw($table, $id, $column), "$table.$column");
        }
        $this->assertSame('01722222222', $this->raw('prescription_patients', $already, 'phone'));
        $length = DB::table('information_schema.columns')->where('table_name', 'prescription_patients')->where('column_name', 'phone')->value('character_maximum_length');
        $this->assertSame(20, (int) $length);
    }

    public function test_openings_are_logged_and_refused_tries_are_logged_too(): void
    {
        $patient = $this->patient(['name' => 'Logged Patient']);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
        $auditBefore = DB::table('audit_events')->count();

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertOk();
        $this->actingAs($this->prescriber)->onPortal('dealer')->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')->assertOk();
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print')->assertOk();

        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions/patients/'.$patient->id)->assertNotFound();
            $this->actingAs($viewer)->onPortal($portal)->getJson('/commands/prescriptions/patients/'.$patient->id.'/repeat')->assertNotFound();
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions/'.$prescription->id.'/print')->assertNotFound();
        }

        $own = PrescriptionPatientAccessLog::query()->where('viewer_id', $this->prescriber->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['opened', 'repeated', 'printed'], $own);
        foreach ([$this->otherPrescriber, $this->owner] as $viewer) {
            $this->assertSame(['blocked', 'blocked', 'blocked'], PrescriptionPatientAccessLog::query()->where('viewer_id', $viewer->id)->pluck('action')->all());
        }
        // The owner reads the audit log, so nothing about these openings may go there.
        $this->assertSame($auditBefore, DB::table('audit_events')->count());
    }

    public function test_the_access_log_is_visible_only_to_the_patients_own_prescriber(): void
    {
        $patient = $this->patient(['name' => 'Secret Log Patient']);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/prescriptions/patients/'.$patient->id)->assertNotFound();
        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/patients/'.$patient->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.access_log.0.action', 'opened')
                ->where('record.access_log.0.is_self', true)
                ->where('record.access_log.1.action', 'blocked')
                ->where('record.access_log.1.viewer', $this->owner->name));

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.access_log.0.patient', 'Secret Log Patient'));

        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions')->assertOk()
                ->assertDontSee('Secret Log Patient')
                ->assertInertia(fn (Assert $page) => $page->where('data.prescription_tools.access_log', []));
        }
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Clinic\PatientAttachments;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientAccessLog;
use App\Models\PrescriptionPatientAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Files on a patient stay on the private disk and reach only that patient's prescriber.
 */
class ClinicAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $prescriber;

    private User $otherPrescriber;

    private User $owner;

    private PrescriptionPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->prescriber = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $this->otherPrescriber = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->patient = PrescriptionPatient::factory()->create(['prescriber_id' => $this->prescriber->id]);
    }

    /** @return TestResponse<Response> */
    private function upload(UploadedFile $file, ?PrescriptionPatient $patient = null, ?User $actor = null, string $portal = 'dealer'): TestResponse
    {
        return $this->actingAs($actor ?? $this->prescriber)->onPortal($portal)->post(
            '/commands/prescriptions/patients/'.($patient ?? $this->patient)->id.'/attachments',
            ['kind' => 'lab_report', 'file' => $file],
        );
    }

    private function stored(string $name = 'report.pdf'): PrescriptionPatientAttachment
    {
        $this->upload(UploadedFile::fake()->create($name, 100, 'application/pdf'))->assertRedirect()->assertSessionHasNoErrors();

        return PrescriptionPatientAttachment::query()->latest('id')->firstOrFail();
    }

    private function url(PrescriptionPatientAttachment $attachment, ?PrescriptionPatient $patient = null): string
    {
        return '/commands/prescriptions/patients/'.($patient ?? $this->patient)->id.'/attachments/'.$attachment->id;
    }

    public function test_a_file_goes_to_the_private_disk_and_its_name_is_encrypted(): void
    {
        $attachment = $this->stored('Rahima blood test.pdf');

        Storage::disk('local')->assertExists($attachment->disk_path);
        $this->assertStringStartsWith('clinic-attachments/'.$this->prescriber->id.'/'.$this->patient->id.'/', $attachment->disk_path);
        $this->assertStringEndsWith('.pdf', $attachment->disk_path);
        $this->assertStringNotContainsString('Rahima', $attachment->disk_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('Rahima blood test.pdf', $attachment->original_name);
        $this->assertStringNotContainsString('Rahima', (string) DB::table('prescription_patient_attachments')->where('id', $attachment->id)->value('original_name'));

        $this->actingAs($this->prescriber)->onPortal('dealer')->get('/prescriptions/patients/'.$this->patient->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.attachments.0.name', 'Rahima blood test.pdf')
                ->where('record.attachments.0.kind', 'lab_report')
                ->missing('record.attachments.0.disk_path'));
    }

    public function test_the_prescriber_downloads_with_safe_headers_and_it_is_logged(): void
    {
        $attachment = $this->stored();

        $response = $this->actingAs($this->prescriber)->onPortal('dealer')->get($this->url($attachment))->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('report.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue(PrescriptionPatientAccessLog::query()->where('viewer_id', $this->prescriber->id)->where('action', 'file_downloaded')->exists());
    }

    public function test_a_file_with_a_bangla_name_can_be_downloaded(): void
    {
        $attachment = $this->stored('রক্ত পরীক্ষা.pdf');

        $response = $this->actingAs($this->prescriber)->onPortal('dealer')->get($this->url($attachment))->assertOk();
        $this->assertStringContainsString('attachment-'.$attachment->id.'.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''", (string) $response->headers->get('Content-Disposition'));
    }

    public function test_another_prescriber_and_the_owner_get_a_404_on_every_file_route(): void
    {
        $attachment = $this->stored();
        $this->otherPrescriber->refresh();

        foreach ([[$this->otherPrescriber, 'dealer'], [$this->owner, 'sadmin']] as [$viewer, $portal]) {
            $this->actingAs($viewer)->onPortal($portal)->get($this->url($attachment))->assertNotFound();
            $this->actingAs($viewer)->onPortal($portal)->delete($this->url($attachment))->assertNotFound();
            $this->upload(UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf'), actor: $viewer, portal: $portal)->assertNotFound();
            // Ownership comes before the file checks, so a bad file tells them nothing either.
            $this->upload(UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload'), actor: $viewer, portal: $portal)->assertNotFound();
            $this->actingAs($viewer)->onPortal($portal)->get('/prescriptions/patients/'.$this->patient->id)->assertNotFound();
        }

        Storage::disk('local')->assertExists($attachment->disk_path);
        $this->assertSame(1, PrescriptionPatientAttachment::query()->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
    }

    public function test_a_prescriber_cannot_reach_someone_elses_file_through_their_own_patient(): void
    {
        $attachment = $this->stored();
        $theirPatient = PrescriptionPatient::factory()->create(['prescriber_id' => $this->otherPrescriber->id]);

        $this->actingAs($this->otherPrescriber)->onPortal('dealer')->get($this->url($attachment, $theirPatient))->assertNotFound();
        $this->actingAs($this->otherPrescriber)->onPortal('dealer')->delete($this->url($attachment, $theirPatient))->assertNotFound();
        $this->assertTrue(PrescriptionPatientAttachment::query()->whereKey($attachment->id)->exists());
    }

    public function test_a_prescriber_whose_switch_is_off_cannot_download(): void
    {
        $attachment = $this->stored();
        $this->prescriber->forceFill(['can_prescribe' => false])->save();

        $this->actingAs($this->prescriber->fresh())->onPortal('dealer')->get($this->url($attachment))->assertForbidden();
    }

    public function test_the_prescriber_can_delete_their_own_file(): void
    {
        $attachment = $this->stored();

        $this->actingAs($this->prescriber)->onPortal('dealer')->delete($this->url($attachment))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, PrescriptionPatientAttachment::query()->count());
        Storage::disk('local')->assertMissing($attachment->disk_path);
    }

    public function test_only_allowed_types_and_sizes_are_accepted(): void
    {
        $this->upload(UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->create('page.svg', 10, 'image/svg+xml'))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->create('big.pdf', PatientAttachments::MaxKilobytes + 1, 'application/pdf'))->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_web_page_renamed_to_pdf_is_refused_because_the_contents_are_checked(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'clinic-attachment-');
        file_put_contents($path, '<html><body><script>alert(1)</script></body></html>');

        $this->upload(new UploadedFile($path, 'report.pdf', 'application/pdf', null, true))->assertSessionHasErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles());
        unlink($path);
    }

    public function test_a_patient_cannot_hold_more_than_the_limit(): void
    {
        foreach (range(1, PatientAttachments::MaxPerPatient) as $number) {
            PrescriptionPatientAttachment::query()->create([
                'patient_id' => $this->patient->id, 'prescriber_id' => $this->prescriber->id, 'kind' => 'other',
                'disk_path' => 'clinic-attachments/x/'.$number.'.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1,
            ]);
        }

        $this->upload(UploadedFile::fake()->create('one-more.pdf', 10, 'application/pdf'))->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

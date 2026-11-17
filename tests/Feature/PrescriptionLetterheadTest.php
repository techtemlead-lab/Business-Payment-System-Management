<?php

namespace Tests\Feature;

use App\Domain\Clinic\PrescriptionLetterhead;
use App\Models\Prescription;
use App\Models\PrescriptionLetterhead as LetterheadRow;
use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrescriptionLetterheadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function dealer(string $name = 'Dr Synthetic Dealer'): User
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'name' => $name]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/prescriber', [
            'registration_type' => 'bmdc', 'registration_number' => 'A-12345', 'note' => 'Papers checked',
        ])->assertRedirect();

        return $dealer->fresh();
    }

    /** @param array<string, mixed> $extra */
    private function save(User $user, array $extra = [])
    {
        return $this->actingAs($user)->onPortal('dealer')->post('/commands/prescriptions/letterhead', $extra);
    }

    private function prescriptionFor(User $dealer, int $lines = 1): Prescription
    {
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Print Patient', 'sex' => 'male', 'age' => 30]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'prescriber_id' => $dealer->id, 'diagnosis' => 'Radiculopathy', 'advice' => "Rest well\nDrink water", 'investigations' => ['X-Ray L/S Spine']]);
        for ($i = 0; $i < $lines; $i++) {
            $prescription->lines()->create([
                'medicine_name' => 'Medicine '.$i, 'strength' => '500 mg', 'unit_price' => '10.00', 'quantity' => 1, 'discount' => '0.00',
                'line_total' => '10.00', 'position' => $i, 'dose_schedule' => '1+0+1', 'food_timing' => 'after', 'duration_count' => 7, 'duration_unit' => 'day',
            ]);
        }

        return $prescription;
    }

    public function test_defaults_fill_every_blank_field_and_the_company_logo_is_used(): void
    {
        $dealer = $this->dealer();
        $letterhead = app(PrescriptionLetterhead::class)->forPrescriber($dealer);

        $this->assertSame('Dr Synthetic Dealer', $letterhead['doctor_name_en']);
        $this->assertStringContainsString('A-12345', $letterhead['registration_bn']);
        $this->assertNotSame('', $letterhead['footer_note_bn']);
        $this->assertStringNotContainsString('/prescriptions/letterhead/logo', (string) $letterhead['logo_url']);
    }

    public function test_the_owner_doctor_default_carries_the_company_name(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['name' => 'Owner Doctor']);
        $letterhead = app(PrescriptionLetterhead::class)->forPrescriber($owner);

        $this->assertSame('Madina Natural Health Care', $letterhead['chamber_en']);
        $this->assertSame('মদিনা ন্যাচারাল হেলথ কেয়ার', $letterhead['chamber_bn']);
    }

    public function test_a_prescriber_saves_text_in_both_languages_and_blank_means_default(): void
    {
        $dealer = $this->dealer();
        $this->save($dealer, [
            'doctor_name_bn' => 'ডাঃ রূপায়ণ দাশ', 'doctor_name_en' => 'DR. RUPAYAN DAS',
            'degrees_en' => 'MBBS, BCS (Health), D-ortho', 'visiting_hours_bn' => 'বিকাল ৩টা-রাত ৯ টা', 'footer_note_en' => '  ',
        ])->assertRedirect();

        $letterhead = app(PrescriptionLetterhead::class)->forPrescriber($dealer);
        $this->assertSame('ডাঃ রূপায়ণ দাশ', $letterhead['doctor_name_bn']);
        $this->assertSame('DR. RUPAYAN DAS', $letterhead['doctor_name_en']);
        $this->assertSame('বিকাল ৩টা-রাত ৯ টা', $letterhead['visiting_hours_bn']);
        // Blank spaces fall back to the standard note.
        $this->assertSame((string) __('prescription_letterhead.default_footer_note', [], 'en'), $letterhead['footer_note_en']);
        $this->assertTrue(DB::table('audit_events')->where('action', 'prescription.letterhead_saved')->exists());
    }

    public function test_too_long_text_and_bad_logos_are_refused(): void
    {
        $dealer = $this->dealer();
        $this->save($dealer, ['doctor_name_en' => str_repeat('a', 121)])->assertSessionHasErrors('doctor_name_en');
        $this->save($dealer, ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->save($dealer, ['logo' => UploadedFile::fake()->image('big.png')->size(3000)])->assertSessionHasErrors('logo');
        $this->assertSame(0, LetterheadRow::query()->count());
    }

    public function test_an_own_logo_replaces_the_company_logo_and_can_be_removed(): void
    {
        $dealer = $this->dealer();
        $this->save($dealer, ['logo' => UploadedFile::fake()->image('logo.png', 200, 100)])->assertRedirect();
        $first = (string) LetterheadRow::query()->where('user_id', $dealer->id)->value('logo_path');
        Storage::disk('local')->assertExists($first);
        $this->assertStringContainsString('/prescriptions/letterhead/logo', (string) app(PrescriptionLetterhead::class)->forPrescriber($dealer)['logo_url']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/letterhead/logo')->assertOk();

        // A new logo deletes the old file.
        $this->save($dealer, ['logo' => UploadedFile::fake()->image('second.jpg', 100, 100)])->assertRedirect();
        Storage::disk('local')->assertMissing($first);

        $this->save($dealer, ['remove_logo' => '1'])->assertRedirect();
        $this->assertNull(LetterheadRow::query()->where('user_id', $dealer->id)->value('logo_path'));
        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/letterhead/logo')->assertNotFound();
    }

    public function test_a_prescriber_only_ever_reads_and_writes_their_own_letterhead(): void
    {
        $first = $this->dealer('First Doctor');
        $second = $this->dealer('Second Doctor');
        $this->save($first, ['doctor_name_en' => 'FIRST OWN NAME', 'logo' => UploadedFile::fake()->image('a.png')])->assertRedirect();
        // A user id sent in the request is ignored: the second doctor's save touches only their own row.
        $this->save($second, ['doctor_name_en' => 'SECOND OWN NAME', 'user_id' => $first->id])->assertRedirect();

        $this->assertSame('FIRST OWN NAME', LetterheadRow::query()->where('user_id', $first->id)->value('doctor_name_en'));
        $this->assertSame('SECOND OWN NAME', LetterheadRow::query()->where('user_id', $second->id)->value('doctor_name_en'));
        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions/letterhead/logo')->assertNotFound();
    }

    public function test_someone_who_may_not_prescribe_cannot_save_or_read(): void
    {
        $plain = User::factory()->dealer()->create(['status' => 'active']);
        $this->save($plain, ['doctor_name_en' => 'Nope'])->assertForbidden();
        $this->actingAs($plain)->onPortal('dealer')->get('/prescriptions/letterhead/logo')->assertForbidden();
        $this->assertSame(0, LetterheadRow::query()->count());
    }

    public function test_the_print_page_shows_the_letterhead_blocks_the_bar_the_body_and_the_footer(): void
    {
        $dealer = $this->dealer();
        $this->save($dealer, [
            'doctor_name_bn' => 'ডাঃ রূপায়ণ দাশ', 'doctor_name_en' => 'DR. RUPAYAN DAS',
            'footer_phone_bn' => 'সিরিয়ালের জন্য ০১৫৩৩-৮৮১৭০৬', 'visiting_hours_en' => 'Visiting 3pm to 9pm',
        ])->assertRedirect();
        $prescription = $this->prescriptionFor($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=bn')
            ->assertOk()
            ->assertSee('ডাঃ রূপায়ণ দাশ')
            ->assertSee('DR. RUPAYAN DAS')
            ->assertSee('সিরিয়ালের জন্য ০১৫৩৩-৮৮১৭০৬')
            ->assertSee('Visiting 3pm to 9pm')
            ->assertSee('Print Patient')
            ->assertSee('Medicine 0')
            ->assertSee('উপদেশ')
            ->assertSee('Rest well')
            ->assertSee('১+০+১', false)
            ->assertSee('data:image/', false)
            ->assertSee('print.pdf');
    }

    public function test_the_print_page_uses_the_own_logo_when_set(): void
    {
        $dealer = $this->dealer();
        $this->save($dealer, ['logo' => UploadedFile::fake()->image('logo.png', 120, 60)])->assertRedirect();
        $prescription = $this->prescriptionFor($dealer);
        $html = $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print?lang=en')->assertOk()->getContent();

        $own = base64_encode((string) file_get_contents(Storage::disk('local')->path((string) LetterheadRow::query()->value('logo_path'))));
        $this->assertStringContainsString($own, $html);
    }

    public function test_the_pdf_renders_in_both_languages_with_and_without_own_logo_and_with_long_content(): void
    {
        $dealer = $this->dealer();
        $short = $this->prescriptionFor($dealer, 2);
        $long = $this->prescriptionFor($dealer, 60);

        foreach (['bn', 'en'] as $lang) {
            $response = $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$short->id.'/print.pdf?lang='.$lang)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        $this->save($dealer, ['logo' => UploadedFile::fake()->image('logo.png', 120, 60)])->assertRedirect();
        $withLogo = $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$short->id.'/print.pdf?lang=bn')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $withLogo);

        $longPdf = $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$long->id.'/print.pdf?lang=bn')->assertOk()->getContent();
        // Sixty medicines need more than one page.
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page[^s]/', $longPdf));
    }

    public function test_another_prescribers_prescription_cannot_be_printed_or_downloaded(): void
    {
        $first = $this->dealer('First Doctor');
        $second = $this->dealer('Second Doctor');
        $prescription = $this->prescriptionFor($first);

        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print')->assertNotFound();
        $this->actingAs($second)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print.pdf')->assertNotFound();
    }
}

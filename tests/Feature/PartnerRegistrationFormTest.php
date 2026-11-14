<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerRegistrationForm;
use App\Domain\Content\PublicTrustContent;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerRegistrationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private int $partnerCount = 0;

    private function partner(string $role = 'dealer'): User
    {
        $this->partnerCount++;
        $factory = User::factory();
        $factory = $role === 'sub_dealer' ? $factory->subDealer() : $factory->dealer();

        return $factory->create([
            'name' => 'Md. Rafiqul Islam',
            'phone' => $this->partnerCount === 1 ? '01712345678' : '0171234567'.$this->partnerCount,
            'nid' => '19851234567890123',
            'profile' => [
                'name_bn' => 'মোঃ রফিকুল ইসলাম',
                'father_name' => 'Late Abdur Rahman',
                'mother_name' => 'Rahima Begum',
                'identity_type' => 'nid',
                'date_of_birth' => '1985-03-03',
                'shop_name' => 'Madina Care Point',
                'upazila' => 'Kaliakair',
            ],
        ]);
    }

    /**
     * @param  list<array{title: string, rows: list<array{label: string, value: string}>}>  $sections
     */
    private function flatValues(array $sections): string
    {
        return implode('|', array_merge(...array_map(fn (array $section): array => array_column($section['rows'], 'value'), $sections)));
    }

    public function test_the_form_holds_what_was_entered_and_no_password(): void
    {
        $partner = $this->partner();

        $values = $this->flatValues(app(PartnerRegistrationForm::class)->sections($partner, 'en', true));

        foreach (['Md. Rafiqul Islam', 'মোঃ রফিকুল ইসলাম', 'Late Abdur Rahman', 'Rahima Begum', '19851234567890123', '01712345678', 'Madina Care Point', 'Kaliakair', '03 Mar 1985'] as $expected) {
            $this->assertStringContainsString($expected, $values);
        }
        $this->assertStringNotContainsString((string) $partner->password, $values);
    }

    public function test_the_identity_number_is_masked_without_permission_to_write_it(): void
    {
        $values = $this->flatValues(app(PartnerRegistrationForm::class)->sections($this->partner(), 'en', false));

        $this->assertStringNotContainsString('19851234567890123', $values);
        $this->assertStringContainsString('**************123', $values);
    }

    public function test_the_bangla_form_shows_bangla_digits_and_labels(): void
    {
        $sections = app(PartnerRegistrationForm::class)->sections($this->partner(), 'bn', true);

        $this->assertSame('ব্যক্তিগত তথ্য', $sections[1]['title']);
        $this->assertStringContainsString('০১৭১২৩৪৫৬৭৮', $this->flatValues($sections));
    }

    public function test_there_are_four_signature_places_the_first_is_the_partners_own(): void
    {
        $form = app(PartnerRegistrationForm::class);

        $dealer = $form->signatures($this->partner(), 'en');
        $this->assertCount(4, $dealer);
        $this->assertSame('Dealer\'s signature', $dealer[0]['title']);
        $this->assertSame('Md. Rafiqul Islam', $dealer[0]['name']);

        $sub = $form->signatures($this->partner('sub_dealer'), 'bn');
        $this->assertSame('সাব-ডিলারের স্বাক্ষর', $sub[0]['title']);
    }

    public function test_the_other_three_titles_are_written_by_the_company_and_blank_until_then(): void
    {
        $form = app(PartnerRegistrationForm::class);
        $partner = $this->partner();

        $this->assertSame(['', '', ''], array_column(array_slice($form->signatures($partner, 'en'), 1), 'title'));

        SiteCustomization::save(['trust_form_signer_1' => 'Managing Director', 'trust_form_signer_2_bn' => 'পরিচালক', 'trust_form_signer_3' => 'Accounts Manager']);

        $this->assertSame(['Managing Director', '', 'Accounts Manager'], array_column(array_slice($form->signatures($partner, 'en'), 1), 'title'));
        $this->assertSame('পরিচালক', $form->signatures($partner, 'bn')[2]['title']);
    }

    public function test_staff_with_account_rights_download_a_pdf_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $partner = $this->partner('sub_dealer');

        $response = $this->actingAs($admin)->onPortal('sadmin')->get('/exports/partner-registration-form/'.$partner->id.'?lang=bn')->assertOk();

        $this->assertStringStartsWith('%PDF', (string) file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'account.registration_form_downloaded', 'subject' => (string) $partner->id]);
    }

    public function test_dealers_and_people_who_cannot_manage_accounts_are_refused(): void
    {
        $partner = $this->partner();

        $this->actingAs(User::factory()->create(['role' => 'dealer']))->get('/exports/partner-registration-form/'.$partner->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get('/exports/partner-registration-form/'.$partner->id)->assertForbidden();
    }

    public function test_only_dealers_and_sub_dealers_have_a_form(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $staff = User::factory()->create(['role' => 'editor']);

        $this->actingAs($admin)->get('/exports/partner-registration-form/'.$staff->id)->assertNotFound();
    }

    public function test_the_signature_titles_can_be_saved_from_the_public_trust_settings(): void
    {
        $this->assertContains('form_signer_1', PublicTrustContent::TextKeys);
        $this->assertArrayHasKey('trust_form_signer_3_bn', PublicTrustContent::defaults());
    }
}

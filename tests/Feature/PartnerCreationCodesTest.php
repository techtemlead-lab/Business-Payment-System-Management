<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Owners type place and dealer codes by hand, usually as bare digits. The new-partner
 * form and the server must treat "57481252" and "MNHC-57481252" as the same code.
 */
class PartnerCreationCodesTest extends TestCase
{
    use RefreshDatabase;

    private const FormPage = '/accounts/create?intent=dealer';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'phone' => '01811111111', 'email' => 'owner@example.com']);
        $this->seedPlaces();
    }

    public function test_a_dealer_typed_with_bare_digits_gets_the_branded_codes(): void
    {
        $this->submit($this->partnerForm())->assertRedirect('/accounts')->assertSessionHas('success');

        $dealer = User::query()->where('email', 'partner@example.com')->firstOrFail();
        $this->assertSame('MNHC-57481252', $dealer->dealer_code);
        $this->assertSame('MNHC-57481252', $dealer->refer_code);
        $this->assertNull($dealer->sponsor_id);
    }

    #[DataProvider('dealerCodeSpellings')]
    public function test_a_sub_dealer_can_name_its_dealer_with_or_without_the_prefix(string $typedReference): void
    {
        $dealer = $this->activeDealer('MNHC-57481252', 'MNHC-57481252');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code='.$typedReference.'&refer_code=MNHC-100001-000001')
            ->assertOk()
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.consumers.direct_referral.id', $dealer->id);

        $this->submit($this->partnerForm([
            'role' => 'sub_dealer',
            'place_code' => '100001',
            'dealer_code' => 'MNHC-100001-000001',
            'reference_code' => $typedReference,
            'refer_code' => 'MNHC-100001-000001',
            'shop_name' => 'Rafiq Medical Hall',
        ]))->assertRedirect('/sub-dealers')->assertSessionHas('success');

        $subDealer = User::query()->where('email', 'partner@example.com')->firstOrFail();
        $this->assertSame('sub_dealer', $subDealer->role);
        $this->assertSame($dealer->id, $subDealer->sponsor_id);
    }

    /** @return array<string, array{0: string}> */
    public static function dealerCodeSpellings(): array
    {
        return ['with the prefix' => ['MNHC-57481252'], 'bare digits' => ['57481252'], 'small letters' => ['mnhc-57481252']];
    }

    public function test_the_preview_accepts_a_dealer_code_when_the_refer_code_is_different(): void
    {
        $dealer = $this->activeDealer('MNHC-501', 'REF-SPONSOR');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=MNHC-501&refer_code=MNHC-100001-000001')
            ->assertOk()
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.consumers.direct_referral.id', $dealer->id);
    }

    public function test_the_preview_still_warns_about_a_reference_nobody_holds(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=57481252&refer_code=MNHC-100001-000001')
            ->assertOk()
            ->assertJsonPath('data.warnings', [__('portal.acct_val_reference_missing')]);
    }

    public function test_a_partner_cannot_be_their_own_reference_with_or_without_the_prefix(): void
    {
        $this->activeDealer('MNHC-57481252', 'MNHC-57481252');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=57481252&refer_code=MNHC-57481252')
            ->assertOk()
            ->assertJsonPath('data.warnings.0', __('portal.acct_val_reference_same_as_refer'));

        $this->submit($this->partnerForm([
            'place_code' => '100001',
            'dealer_code' => '100001',
            'reference_code' => '57481252',
            'refer_code' => 'MNHC-57481252',
        ]))->assertSessionHasErrors(['reference_code', 'refer_code']);
    }

    public function test_a_refer_code_in_use_is_caught_with_or_without_the_prefix(): void
    {
        $this->activeDealer('MNHC-57481252', 'MNHC-57481252');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=&refer_code=57481252')
            ->assertOk()
            ->assertJsonPath('data.warnings', [__('portal.acct_val_refer_taken')]);
    }

    public function test_a_bad_id_file_refuses_the_form_before_any_account_is_made(): void
    {
        $this->submit($this->partnerForm([
            'kyc_nid_front' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]))->assertRedirect(self::FormPage)->assertSessionHasErrors('kyc_nid_front');

        $this->assertDatabaseMissing('users', ['email' => 'partner@example.com']);
    }

    public function test_a_refused_form_goes_back_to_the_form_and_says_why(): void
    {
        $this->submit($this->partnerForm(['phone' => $this->owner->phone]))
            ->assertRedirect(self::FormPage)
            ->assertSessionHasErrors(['phone' => __('portal.acct_val_phone_taken_owner')]);

        $this->assertDatabaseMissing('users', ['email' => 'partner@example.com']);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function submit(array $form): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->from(self::FormPage)->post('/commands/accounts', $form);
    }

    private function activeDealer(string $dealerCode, string $referCode): User
    {
        return User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => $dealerCode, 'refer_code' => $referCode]);
    }

    /**
     * The form as the browser sends it, with codes typed as bare digits.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function partnerForm(array $overrides = []): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
            'name' => 'Md. Rafiq',
            'name_bn' => 'মোঃ রফিক',
            'father_name' => 'Abdur Rahman',
            'mother_name' => 'Rahima Begum',
            'place_code' => '57481252',
            'dealer_code' => '57481252',
            'reference_code' => 'MNHC-100',
            'refer_code' => '57481252',
            'phone' => '01712345678',
            'email' => 'partner@example.com',
            'directory_visible' => '0',
            'status' => 'active',
            'reason' => 'Papers checked in person.',
            ...$overrides,
        ];
    }

    private function seedPlaces(): void
    {
        $management = $this->place('management', 'MNHC-100', 'Management', null);
        $division = $this->place('division', 'MNHC-101', 'Dhaka', $management->id);
        $district = $this->place('district', 'MNHC-200', 'Dhaka', $division->id);
        $management->forceFill(['base_place_id' => $division->id])->save();
        $division->forceFill(['base_place_id' => $district->id])->save();
        $this->place('union', 'MNHC-57481252', 'Bilaspur', $this->place('upazila', 'MNHC-1002', 'Dohar', $district->id)->id);
        $this->place('union', 'MNHC-100001', 'Amta', $this->place('upazila', 'MNHC-1001', 'Dhamrai', $district->id)->id);
        DB::table('business_settings')->updateOrInsert(['key' => 'management_referral'], [
            'value' => json_encode(['enabled' => true, 'auto_blank_reference' => true, 'route_referral_slices' => true, 'place_code_id' => $management->id], JSON_THROW_ON_ERROR),
            'version' => 1,
            'updated_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function place(string $level, string $code, string $name, ?int $parentId): PlaceCode
    {
        return PlaceCode::query()->create(['level' => $level, 'code' => $code, 'name' => $name, 'parent_id' => $parentId, 'sort_order' => 1, 'is_active' => true]);
    }
}

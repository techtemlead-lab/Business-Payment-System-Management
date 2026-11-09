<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Audience\PartnerProductEnquiries;
use App\Models\PlaceCode;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The owner's rule: a person's dealer or sub-dealer code IS their refer code. There is no second
 * code to type, old printed codes keep finding the person, and the database never disagrees.
 */
class PartnerCodeIsReferCodeTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_09_142632_make_partner_refer_codes_equal_their_codes.php';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'phone' => '01811111111']);
    }

    public function test_a_new_dealer_gets_their_code_as_refer_code_whatever_the_form_sends(): void
    {
        $this->submit($this->partnerForm(['place_code' => 'MNHC-102', 'refer_code' => 'HAND-PICKED']))
            ->assertRedirect('/accounts')
            ->assertSessionHasNoErrors();

        $dealer = $this->created();
        $this->assertSame('MNHC-102', $dealer->dealer_code);
        $this->assertSame('MNHC-102', $dealer->refer_code);
    }

    public function test_a_changed_dealer_code_is_the_refer_code_too(): void
    {
        $this->submit($this->partnerForm(['place_code' => 'MNHC-102', 'dealer_code' => 'SHOP-77', 'refer_code' => 'HAND-PICKED']))
            ->assertSessionHasNoErrors();

        $this->assertSame('SHOP-77', $this->created()->dealer_code);
        $this->assertSame('SHOP-77', $this->created()->refer_code);
    }

    public function test_a_new_sub_dealer_gets_the_serial_as_refer_code_whatever_the_form_sends(): void
    {
        $union = $this->unionCode();

        $this->submit($this->partnerForm(['role' => 'sub_dealer', 'place_code' => $union, 'shop_name' => 'Rafiq Medical', 'refer_code' => 'HAND-PICKED']))
            ->assertSessionHasNoErrors();

        $this->assertSame($union.'-000001', $this->created()->dealer_code);
        $this->assertSame($union.'-000001', $this->created()->refer_code);
    }

    public function test_a_code_another_person_refers_with_is_refused_beside_the_dealer_code_box(): void
    {
        User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'OTHER-1', 'refer_code' => 'SHOP-77']);

        $this->submit($this->partnerForm(['place_code' => 'MNHC-102', 'dealer_code' => 'SHOP-77']))
            ->assertSessionHasErrors(['dealer_code' => __('portal.acct_val_refer_taken')])
            ->assertSessionDoesntHaveErrors('refer_code');

        $this->assertDatabaseMissing('users', ['email' => 'partner@example.test']);
    }

    public function test_the_company_management_code_is_refused_beside_the_dealer_code_box(): void
    {
        $this->submit($this->partnerForm(['place_code' => 'MNHC-102', 'dealer_code' => 'MNHC-100']))
            ->assertSessionHasErrors(['dealer_code' => __('portal.acct_val_refer_is_management')])
            ->assertSessionDoesntHaveErrors('refer_code');
    }

    public function test_someone_cannot_be_referred_by_their_own_code_and_the_refusal_is_on_the_one_box(): void
    {
        // The only way a new person's code already points at someone: its old holder moved away.
        $this->move($this->seatedDealer('MNHC-102', 'MNHC-102'), 'MNHC-103')->assertSessionHasNoErrors();

        $this->submit($this->partnerForm(['place_code' => 'MNHC-102', 'reference_code' => 'MNHC-102']))
            ->assertSessionHasErrors(['reference_code' => __('portal.acct_val_reference_same_as_refer')])
            ->assertSessionDoesntHaveErrors('refer_code');
    }

    public function test_the_one_box_accepts_a_dealer_code_and_a_sub_dealer_code(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'D-SPONSOR', 'refer_code' => 'D-SPONSOR']);
        $subDealer = User::factory()->subDealer()->create(['status' => 'active', 'dealer_code' => 'S-SPONSOR', 'refer_code' => 'S-SPONSOR']);

        $this->submit($this->partnerForm(['place_code' => 'MNHC-102', 'reference_code' => 'D-SPONSOR']))->assertSessionHasNoErrors();
        $this->assertSame($dealer->id, $this->created()->sponsor_id);

        $this->submit($this->partnerForm(['place_code' => 'MNHC-101', 'reference_code' => 'S-SPONSOR', 'phone' => '01712345679', 'email' => 'second@example.test']))->assertSessionHasNoErrors();
        $this->assertSame($subDealer->id, User::query()->where('email', 'second@example.test')->firstOrFail()->sponsor_id);
    }

    public function test_the_preview_says_which_warnings_belong_to_the_one_box(): void
    {
        User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'TAKEN-1', 'refer_code' => 'TAKEN-1']);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=NOBODY&refer_code=TAKEN-1')
            ->assertOk()
            ->assertJsonPath('data.reference_warnings', [__('portal.acct_val_reference_missing')])
            ->assertJsonPath('data.warnings', [__('portal.acct_val_refer_taken'), __('portal.acct_val_reference_missing')]);
    }

    public function test_a_place_move_replaces_a_hand_chosen_refer_code_and_the_old_ones_still_work(): void
    {
        $dealer = $this->seatedDealer('MNHC-102', 'HAND-PICKED');

        $this->move($dealer, 'MNHC-103')->assertSessionHasNoErrors();

        $moved = $dealer->fresh();
        $this->assertSame('MNHC-103', $moved->dealer_code);
        $this->assertSame('MNHC-103', $moved->refer_code);
        $partners = app(PartnerAccountProvisioning::class);
        $this->assertSame($dealer->id, $partners->findSponsor('HAND-PICKED')?->id, 'A QR already printed with the old refer code still finds the dealer.');
        $this->assertSame($dealer->id, $partners->findSponsor('MNHC-102')?->id);
    }

    public function test_correcting_a_partner_code_by_hand_moves_the_refer_code_with_it(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'OLD-1', 'refer_code' => 'OLD-1']);

        $this->edit($dealer, ['dealer_code' => 'NEW-1'])->assertSessionHasNoErrors();

        $this->assertSame('NEW-1', $dealer->fresh()->dealer_code);
        $this->assertSame('NEW-1', $dealer->fresh()->refer_code);
        $this->assertSame($dealer->id, app(PartnerAccountProvisioning::class)->findSponsor('OLD-1')?->id);
    }

    public function test_correcting_a_code_to_one_someone_else_refers_with_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'OLD-1', 'refer_code' => 'OLD-1']);
        User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'OTHER-1', 'refer_code' => 'NEW-1']);

        $this->edit($dealer, ['dealer_code' => 'NEW-1'])->assertSessionHasErrors('dealer_code');

        $this->assertSame('OLD-1', $dealer->fresh()->dealer_code);
    }

    public function test_saving_an_account_without_changing_the_code_writes_no_former_code(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'SAME-1', 'refer_code' => 'SAME-1']);

        $this->edit($dealer, ['dealer_code' => 'SAME-1'])->assertSessionHasNoErrors();

        $this->assertSame('SAME-1', $dealer->fresh()->refer_code);
        $this->assertDatabaseMissing('partner_former_codes', ['user_id' => $dealer->id]);
    }

    public function test_the_edit_endpoint_cannot_set_a_separate_refer_code(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'SAME-1', 'refer_code' => 'SAME-1']);

        $this->edit($dealer, ['refer_code' => 'HAND-PICKED'])->assertSessionHasNoErrors();

        $this->assertSame('SAME-1', $dealer->fresh()->refer_code);
    }

    public function test_a_reopened_dealer_gets_the_home_code_back_as_refer_code(): void
    {
        $dealer = $this->seatedDealer('MNHC-102', 'MNHC-102');
        $dealer->forceFill([
            'status' => 'suspended',
            'closed_at' => now(),
            'dealer_code' => 'MNHC-102-CLOSED-'.$dealer->id,
            'refer_code' => 'HAND-PICKED',
        ])->save();

        $this->edit($dealer, ['status' => 'active'])->assertSessionHasNoErrors();

        $this->assertSame('MNHC-102', $dealer->fresh()->dealer_code);
        $this->assertSame('MNHC-102', $dealer->fresh()->refer_code);
    }

    public function test_the_migration_makes_every_partner_refer_code_equal_the_code(): void
    {
        $handPicked = $this->partnerRow('D-1', 'HAND-PICKED');
        $blank = $this->partnerRow('D-2', null);
        $alreadyRight = $this->partnerRow('D-3', 'D-3');
        $subDealer = $this->partnerRow('S-1', 'SHOP-REF', 'sub_dealer');

        $this->runMigration();

        $this->assertSame('D-1', $handPicked->fresh()->refer_code);
        $this->assertSame('D-2', $blank->fresh()->refer_code);
        $this->assertSame('D-3', $alreadyRight->fresh()->refer_code);
        $this->assertSame('S-1', $subDealer->fresh()->refer_code);
    }

    public function test_after_the_migration_a_printed_old_code_still_opens_and_refers(): void
    {
        $partner = $this->partnerRow('D-1', 'HAND-PICKED');

        $this->runMigration();

        $this->assertDatabaseHas('partner_former_codes', ['user_id' => $partner->id, 'code' => 'HAND-PICKED']);
        $this->assertSame($partner->id, app(PartnerAccountProvisioning::class)->findSponsor('HAND-PICKED')?->id);
        $this->assertSame($partner->id, app(PartnerProductEnquiries::class)->partnerForCode('hand-picked')?->id);
        $this->onPortal('public')->get('/join/HAND-PICKED')->assertOk()->assertViewHas('code', 'D-1');
    }

    public function test_the_migration_survives_two_partners_holding_each_others_codes(): void
    {
        $first = $this->partnerRow('A-1', 'B-1');
        $second = $this->partnerRow('B-1', 'A-1');

        $this->runMigration();

        $this->assertSame('A-1', $first->fresh()->refer_code);
        $this->assertSame('B-1', $second->fresh()->refer_code);
        $this->assertDatabaseMissing('partner_former_codes', ['code' => 'A-1']);
        $this->assertDatabaseMissing('partner_former_codes', ['code' => 'B-1']);
    }

    public function test_the_migration_leaves_a_closed_accounts_retired_mark_out_of_the_old_codes(): void
    {
        $closed = $this->partnerRow('D-CLOSED', 'MNHC-102-CLOSED-9');

        $this->runMigration();

        $this->assertSame('D-CLOSED', $closed->fresh()->refer_code);
        $this->assertDatabaseMissing('partner_former_codes', ['user_id' => $closed->id]);
    }

    public function test_the_migration_leaves_accounts_with_no_code_and_staff_alone(): void
    {
        $noCode = $this->partnerRow(null, 'ONLY-REFER');
        $staff = User::factory()->create(['role' => 'employee', 'dealer_code' => 'STAFF-1', 'refer_code' => 'STAFF-REF']);

        $this->runMigration();

        $this->assertSame('ONLY-REFER', $noCode->fresh()->refer_code);
        $this->assertSame('STAFF-REF', $staff->fresh()->refer_code);
    }

    public function test_the_migration_does_not_fail_when_another_row_keeps_holding_the_code(): void
    {
        $staff = User::factory()->create(['role' => 'employee', 'dealer_code' => null, 'refer_code' => 'D-1']);
        $partner = $this->partnerRow('D-1', 'OLD-REF');

        $this->runMigration();

        $this->assertSame('OLD-REF', $partner->fresh()->refer_code, 'Left as it was and reported, instead of stopping the deploy.');
        $this->assertSame('D-1', $staff->fresh()->refer_code);
    }

    private function unionCode(): string
    {
        $division = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC-371', 'name' => 'Code District']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC-471', 'name' => 'Code Upazila']);

        return (string) PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-571', 'name' => 'Code Union'])->code;
    }

    private function runMigration(): void
    {
        (require database_path('migrations/'.self::Migration))->up();
    }

    private function partnerRow(?string $dealerCode, ?string $referCode, string $role = 'dealer'): User
    {
        return User::factory()->dealer()->create(['role' => $role, 'status' => 'active', 'dealer_code' => $dealerCode, 'refer_code' => $referCode]);
    }

    private function created(): User
    {
        return User::query()->where('email', 'partner@example.test')->firstOrFail();
    }

    private function seatedDealer(string $placeCode, string $referCode): User
    {
        $place = PlaceCode::findByCode($placeCode);
        $dealer = User::factory()->dealer()->inPlace($place)->create(['status' => 'active', 'dealer_code' => $placeCode, 'refer_code' => $referCode]);
        ReferralTreePath::attachUser($dealer->id, null);
        app(PartnerAccountProvisioning::class)->seatDealer($dealer, $place);
        $child = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $dealer->id]);
        ReferralTreePath::attachUser($child->id, $dealer->id);

        return $dealer;
    }

    private function move(User $dealer, string $placeCode): TestResponse
    {
        return $this->edit($dealer, ['place_code' => $placeCode]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function edit(User $partner, array $changes): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$partner->id, [
            'name' => $partner->name,
            'reason' => 'Office correction',
            ...$changes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function submit(array $form): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->from('/accounts/create')->post('/commands/accounts', $form);
    }

    /**
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
            'place_code' => 'MNHC-102',
            'phone' => '01712345678',
            'email' => 'partner@example.test',
            'reason' => 'Papers checked in person.',
            ...$overrides,
        ];
    }
}

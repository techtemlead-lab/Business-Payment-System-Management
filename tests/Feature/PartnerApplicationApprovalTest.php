<?php

namespace Tests\Feature;

use App\Domain\Audience\PublicPartnerApplication;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerApplicationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->reviewer = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $division = PlaceCode::factory()->division()->create();
        $district = PlaceCode::factory()->district($division)->create();
        $upazila = PlaceCode::factory()->upazila($district)->create();
        $this->union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-100111']);
    }

    /** @param  array<string, string>  $details */
    private function apply(string $role, array $details = []): string
    {
        static $sequence = 0;
        $sequence++;

        return app(PublicPartnerApplication::class)->submit([
            'name' => 'Applicant '.$sequence,
            'email' => 'applicant'.$sequence.'@example.test',
            'phone' => '0171222'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'role' => $role,
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            ...$details,
        ]);
    }

    /** @param  array<string, string|null>  $input */
    private function decide(string $id, array $input): TestResponse
    {
        return $this->actingAs($this->reviewer)->onPortal('sadmin')
            ->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$id.'/review', [
                'decision' => 'approved',
                'reason' => 'Papers checked by the office.',
                'place_code' => $this->union->code,
                ...$input,
            ]);
    }

    public function test_an_approved_dealer_takes_the_place_code_and_holds_the_place(): void
    {
        $id = $this->apply('dealer');

        $this->decide($id, [])->assertRedirect('/partnerships')
            ->assertSessionHas('success', __('portal.flash_application_approved'));

        $dealer = User::query()->where('role', 'dealer')->sole();
        $this->assertSame('MNHC-100111', $dealer->dealer_code);
        $this->assertSame('MNHC-100111', $dealer->refer_code);
        $this->assertTrue(AreaDealerAssignment::query()
            ->where('user_id', $dealer->id)
            ->where('place_code_id', $this->union->id)
            ->where('is_active', true)
            ->exists());
    }

    public function test_an_approved_sub_dealer_gets_the_next_serial_of_the_union(): void
    {
        $this->decide($this->apply('sub_dealer'), [])->assertSessionHasNoErrors();
        $this->decide($this->apply('sub_dealer'), [])->assertSessionHasNoErrors();

        $this->assertSame(
            ['MNHC-100111-000001', 'MNHC-100111-000002'],
            User::query()->where('role', 'sub_dealer')->orderBy('id')->pluck('refer_code')->all(),
        );
        $this->assertSame(0, AreaDealerAssignment::query()->count());
        $this->assertSame(
            'MNHC-100111',
            data_get(User::query()->where('role', 'sub_dealer')->first()->profile, 'union_place_code'),
        );
    }

    public function test_a_place_another_dealer_holds_refuses_the_approval(): void
    {
        $this->decide($this->apply('dealer'), [])->assertSessionHasNoErrors();
        $second = $this->apply('dealer');

        $this->decide($second, [])->assertSessionHasErrors(['place_code' => __('portal.acct_val_place_taken')]);

        $this->assertDatabaseHas('dealer_applications', ['id' => $second, 'status' => 'pending']);
    }

    public function test_a_reference_code_typed_without_the_prefix_finds_the_dealer(): void
    {
        $sponsor = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'MNHC-777', 'refer_code' => 'MNHC-777']);

        $this->decide($this->apply('dealer', ['sponsor_code' => '777']), [])->assertSessionHasNoErrors();

        $this->assertSame($sponsor->id, User::query()->where('dealer_code', 'MNHC-100111')->sole()->sponsor_id);
    }

    public function test_the_reviewer_can_clear_a_reference_code_nobody_holds(): void
    {
        $id = $this->apply('dealer', ['sponsor_code' => 'NOT-A-DEALER']);

        $this->decide($id, [])->assertSessionHasErrors(['sponsor_code' => __('portal.err_staff_sponsor_code')]);
        $this->decide($id, ['sponsor_code' => null])->assertSessionHasNoErrors();

        $this->assertNull(User::query()->where('dealer_code', 'MNHC-100111')->sole()->sponsor_id);
    }

    public function test_a_rejection_says_the_application_was_rejected(): void
    {
        $id = $this->apply('dealer');

        $this->decide($id, ['decision' => 'rejected', 'place_code' => null])
            ->assertSessionHas('success', __('portal.flash_application_rejected'));

        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'rejected']);
    }

    public function test_the_owner_must_give_a_reason_to_approve_a_number_already_in_use(): void
    {
        User::factory()->dealer()->create(['phone' => '01712220099']);
        $id = $this->apply('dealer', ['phone' => '01712220099']);

        $this->decide($id, [])->assertSessionHasErrors(['phone' => __('portal.acct_val_phone_taken_owner')]);

        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'pending']);
    }

    public function test_the_owner_can_approve_a_number_already_in_use_with_a_written_reason(): void
    {
        User::factory()->dealer()->create(['phone' => '01712220099']);
        $id = $this->apply('dealer', ['phone' => '01712220099']);

        $this->decide($id, ['phone_share_reason' => 'Two shops of the same family.'])->assertSessionHasNoErrors();

        $dealer = User::query()->where('dealer_code', 'MNHC-100111')->sole();
        $this->assertSame('01712220099', $dealer->phone);
        $this->assertSame($this->reviewer->id, (int) $dealer->phone_share_approved_by);
        $this->assertSame('Two shops of the same family.', $dealer->phone_share_reason);
        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.phone_share_approved', 'subject' => (string) $dealer->id]);
    }

    public function test_only_the_owner_can_override_a_number_already_in_use(): void
    {
        User::factory()->dealer()->create(['phone' => '01712220099']);
        $id = $this->apply('dealer', ['phone' => '01712220099']);

        $this->actingAs(User::factory()->staff('account_manager')->create())->onPortal('manage')->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Papers checked by the office.', 'place_code' => $this->union->code, 'phone_share_reason' => 'Trying to share it.'])
            ->assertSessionHasErrors(['phone' => __('portal.acct_val_phone_taken')]);

        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'pending']);
        $this->assertSame(1, User::query()->where('phone', '01712220099')->count());
    }

    public function test_a_number_nobody_uses_needs_no_reason(): void
    {
        $this->decide($this->apply('dealer'), [])->assertSessionHasNoErrors();

        $dealer = User::query()->where('dealer_code', 'MNHC-100111')->sole();
        $this->assertNull($dealer->phone_share_approved_by);
        $this->assertNull($dealer->phone_share_reason);
    }

    public function test_the_reviewer_can_put_an_approved_dealer_on_the_website_list(): void
    {
        $this->decide($this->apply('dealer', ['name' => 'Listed Dealer']), ['directory_visible' => '1'])->assertSessionHasNoErrors();

        $this->assertTrue(User::query()->where('dealer_code', 'MNHC-100111')->sole()->directory_visible);
        $this->onPortal('public')->get('/dealers')->assertOk()->assertSee('Listed Dealer');
    }

    public function test_the_reviewer_can_keep_an_approved_dealer_off_the_website_list(): void
    {
        $this->decide($this->apply('dealer', ['name' => 'Private Dealer']), ['directory_visible' => '0'])->assertSessionHasNoErrors();

        $this->assertFalse(User::query()->where('dealer_code', 'MNHC-100111')->sole()->directory_visible);
        $this->onPortal('public')->get('/dealers')->assertOk()->assertDontSee('Private Dealer');
    }

    public function test_a_sub_dealer_never_goes_on_the_dealer_list(): void
    {
        $this->decide($this->apply('sub_dealer'), ['directory_visible' => '1'])->assertSessionHasNoErrors();

        $this->assertFalse(User::query()->where('role', 'sub_dealer')->sole()->directory_visible);
    }

    public function test_the_review_list_shows_the_reference_code_the_applicant_typed(): void
    {
        $this->apply('dealer', ['sponsor_code' => 'MNHC-555']);

        $this->actingAs($this->reviewer)->onPortal('sadmin')->get('/partnerships')
            ->assertInertia(fn (Assert $page) => $page->where('data.applications.data.0.sponsor_code', 'MNHC-555'));
    }
}

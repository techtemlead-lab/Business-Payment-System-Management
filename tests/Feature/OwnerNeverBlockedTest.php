<?php

namespace Tests\Feature;

use App\Domain\Security\OwnerConfirmation;
use App\Domain\Security\OwnerExemption;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\ReasonFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CR-10 (client, 2026-11-18): there is one owner and nobody above them. No owner confirmation,
 * second person, compulsory reason box or self-edit refusal may stop the owner (Super Admin).
 * Staff, and the MD and Chairman, keep every check.
 */
class OwnerNeverBlockedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.preview_mode' => false]);
        $this->owner = User::factory()->staff('super_admin')->create(['password' => 'OwnerPassword12345', 'phone' => '01712345678']);
    }

    public function test_only_an_active_super_admin_is_the_owner(): void
    {
        $this->assertTrue(OwnerExemption::applies($this->owner));
        $this->assertFalse(OwnerExemption::applies(null));
        $this->assertFalse(OwnerExemption::applies(User::factory()->staff('super_admin')->create(['status' => 'suspended'])));
        foreach (['md', 'chairman', 'finance', 'account_manager'] as $role) {
            $this->assertFalse(OwnerExemption::applies(User::factory()->staff($role)->create()), $role);
        }
    }

    public function test_the_owner_changes_their_own_contact_and_sign_in_rules_without_a_password(): void
    {
        $this->assertSame('password', app(OwnerConfirmation::class)->current()['mode']);
        $this->assertSame('none', app(OwnerConfirmation::class)->method($this->owner));

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/contact', ['email' => 'owner-new@example.test'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/password-reset-notice', ['enabled' => '0', 'version' => 0])->assertSessionHasNoErrors();

        $this->assertSame('owner-new@example.test', $this->owner->fresh()->email);
    }

    public function test_the_md_is_still_asked_for_a_password(): void
    {
        $md = User::factory()->staff('md')->create(['password' => 'MdPassword1234567']);

        $this->assertSame('password', app(OwnerConfirmation::class)->method($md));
        $this->actingAs($md)->onPortal('sadmin')->post('/commands/owner/contact', ['email' => 'md-new@example.test'])->assertSessionHasErrors('current_password');
    }

    public function test_the_owner_finishes_their_own_step_and_staff_need_someone_else(): void
    {
        $approval = app(SelfApproval::class);
        $approval->finishOwnStep($this->owner, 'spend', 'blocked');
        $this->assertTrue($approval->ownerFinishesAlone($this->owner));

        $this->expectException(ValidationException::class);
        $approval->finishOwnStep(User::factory()->staff('finance')->create(), 'spend', 'blocked');
    }

    public function test_a_compulsory_reason_box_is_optional_for_the_owner_only(): void
    {
        $finance = User::factory()->staff('finance')->create();
        app(ReasonFields::class)->save($this->owner, ['money.withdrawal_review' => ReasonFields::Required], app(ReasonFields::class)->version());

        $this->assertFalse(ReasonFields::required('money.withdrawal_review', $this->owner));
        $this->assertTrue(ReasonFields::required('money.withdrawal_review', $finance));
    }

    public function test_the_owner_edits_their_own_account_but_cannot_suspend_themselves(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$this->owner->id, ['name' => 'The Owner'])
            ->assertSessionHasNoErrors();
        $this->assertSame('The Owner', $this->owner->fresh()->name);

        $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$this->owner->id, ['name' => 'The Owner', 'status' => 'suspended'])
            ->assertSessionHasErrors('status');
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_staff_still_cannot_edit_their_own_account_from_the_accounts_page(): void
    {
        $manager = User::factory()->staff('account_manager')->create(['name' => 'Manager']);

        $status = $this->actingAs($manager)->onPortal('manage')->patch('/commands/accounts/'.$manager->id, ['name' => 'Changed'])->getStatusCode();

        $this->assertContains($status, [403, 422]);
        $this->assertSame('Manager', $manager->fresh()->name);
    }
}

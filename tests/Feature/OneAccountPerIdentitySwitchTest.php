<?php

namespace Tests\Feature;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Security\SecuritySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Owner's word, 2026-11-17: one mobile number may hold several accounts unless an owner switches
 * "one account per number" on. The switch is off by default.
 */
class OneAccountPerIdentitySwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
        Config::set('madina.preview_mode', false);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
    }

    /** @return array<string, mixed> */
    private function dealerForm(array $overrides = []): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456789',
            'date_of_birth' => '1985-05-01',
            'name' => 'Shared Number Dealer',
            'name_bn' => 'ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC102',
            'phone' => '01711112222',
            'email' => 'shared-number-dealer@example.com',
            'reason' => 'Switch test',
            ...$overrides,
        ];
    }

    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertFalse(app(SecuritySettings::class)->oneAccountPerIdentityEnforced());
        $this->assertFalse(app(AccountPhoneRules::class)->enforced());

        User::factory()->dealer()->create(['phone' => '01711112222']);
        $this->assertFalse(app(AccountPhoneRules::class)->isTaken('+8801711112222'));
    }

    public function test_with_the_switch_off_a_number_can_hold_a_second_account_without_any_approval(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
        User::factory()->dealer()->create(['phone' => '01711112222']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm())
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'shared-number-dealer@example.com')->firstOrFail();
        $this->assertSame('01711112222', $created->phone);
        $this->assertNull($created->phone_share_approved_by);
        $this->assertNull($created->phone_share_reason);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.phone_share_approved']);
    }

    public function test_with_the_switch_off_staff_who_are_not_owners_may_use_a_shared_number_too(): void
    {
        $manager = User::factory()->staff('account_manager')->create(['phone' => '01900000002']);
        User::factory()->dealer()->create(['phone' => '01711112222']);

        $this->actingAs($manager)->onPortal('manage')->post('/commands/accounts', $this->dealerForm([
            'identity_type' => 'birth_certificate',
            'nid' => null,
            'birth_certificate_number' => '19901234567890123',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'shared-number-dealer@example.com', 'phone' => '01711112222']);
    }

    public function test_with_the_switch_off_a_partnership_applicant_may_send_a_number_another_account_has(): void
    {
        User::factory()->dealer()->create(['phone' => '01811112223']);

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', [
            'name' => 'Test Applicant', 'email' => 'applicant@example.test', 'phone' => '01811112223',
            'date_of_birth' => '1990-01-15', 'role' => 'dealer', 'division' => 'Rangpur', 'district' => 'Rangpur',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', __('portal.flash_partnership_received'));

        $this->assertDatabaseCount('dealer_applications', 1);
    }

    public function test_an_invalid_number_is_still_refused_with_the_switch_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '12345']))
            ->assertSessionHasErrors('phone');
        $this->assertDatabaseMissing('users', ['email' => 'shared-number-dealer@example.com']);
    }

    public function test_an_owner_turns_the_rule_on_and_it_is_audited(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['password' => 'OwnerPassword12345', 'phone' => '01900000001']);
        User::factory()->dealer()->create(['phone' => '01711112222']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/one-account-per-identity', [
            'enabled' => '1',
            'version' => 0,
            'current_password' => 'OwnerPassword12345',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(SecuritySettings::class)->oneAccountPerIdentityEnforced());
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'security.one_account_per_identity_changed']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm())
            ->assertSessionHasErrors(['phone' => __('portal.acct_val_phone_taken')]);
        $this->assertDatabaseMissing('users', ['email' => 'shared-number-dealer@example.com']);
    }

    public function test_an_owner_can_turn_the_rule_back_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['password' => 'OwnerPassword12345', 'phone' => '01900000001']);
        app(SecuritySettings::class)->saveOneAccountPerIdentity($owner, true, 0);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/one-account-per-identity', [
            'enabled' => '0',
            'version' => 1,
            'current_password' => 'OwnerPassword12345',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(app(SecuritySettings::class)->oneAccountPerIdentityEnforced());
    }

    public function test_only_owners_can_change_the_switch(): void
    {
        $manager = User::factory()->staff('account_manager')->create(['password' => 'ManagerPassword123']);

        $this->actingAs($manager)->onPortal('manage')->post('/commands/owner/one-account-per-identity', [
            'enabled' => '1',
            'version' => 0,
            'current_password' => 'ManagerPassword123',
        ])->assertForbidden();

        $this->assertFalse(app(SecuritySettings::class)->oneAccountPerIdentityEnforced());
        $this->assertDatabaseMissing('business_settings', ['key' => SecuritySettings::OneAccountPerIdentityKey]);
    }

    public function test_a_stale_version_is_refused(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['password' => 'OwnerPassword12345', 'phone' => '01900000001']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/one-account-per-identity', [
            'enabled' => '1',
            'version' => 7,
            'current_password' => 'OwnerPassword12345',
        ])->assertSessionHasErrors('version');

        $this->assertFalse(app(SecuritySettings::class)->oneAccountPerIdentityEnforced());
    }

    public function test_the_owner_page_carries_the_switch_for_the_screen(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/login-rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityControls.one_account_per_identity.enabled', false)
                ->where('securityControls.one_account_per_identity.version', 0));
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decisions 8, 9, 10 and 12 (2026-11-05): every account has its own valid Bangladeshi
 * mobile number (only an owner may approve a shared one, with a reason), the Super Admin has
 * no way around the sign-in code, and sign-in limits are per account so busy times never
 * block other dealers.
 */
class AccountPhoneRulesTest extends TestCase
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
            'name' => 'Phone Rule Dealer',
            'name_bn' => 'ফোন ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-102',
            'phone' => '01711112222',
            'email' => 'phone-rule-dealer@example.com',
            'reason' => 'Phone rule test',
            ...$overrides,
        ];
    }

    public function test_partner_phone_is_required_and_must_be_a_bangladeshi_mobile(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '']))
            ->assertSessionHasErrors('phone');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '12345']))
            ->assertSessionHasErrors('phone');
        $this->assertDatabaseMissing('users', ['email' => 'phone-rule-dealer@example.com']);
    }

    public function test_a_number_used_by_another_account_is_refused_in_any_format(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
        User::factory()->dealer()->create(['phone' => '01711112222']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '+880 1711-112222']))
            ->assertSessionHasErrors(['phone' => __('portal.acct_val_phone_taken_owner')]);
        $this->assertDatabaseMissing('users', ['email' => 'phone-rule-dealer@example.com']);
    }

    public function test_an_owner_can_allow_a_shared_number_with_a_reason_that_is_audited(): void
    {
        $owner = User::factory()->staff('chairman')->create(['phone' => '01900000001']);
        $existing = User::factory()->dealer()->create(['phone' => '01711112222']);
        // The chairman needs the accounts screen to create partners; owners still decide on sharing.
        DB::table('users')->where('id', $owner->id)->update(['profile' => json_encode(['action_permissions' => ['accounts' => ['view' => true, 'create' => true, 'edit' => true, 'approve' => true]]])]);

        $this->actingAs($owner->fresh())->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm([
            'identity_type' => 'birth_certificate',
            'nid' => null,
            'birth_certificate_number' => '19901234567890123',
            'phone_share_reason' => 'Husband and wife run two shops from one phone.',
        ]))->assertRedirect('/accounts');

        $created = User::query()->where('email', 'phone-rule-dealer@example.com')->firstOrFail();
        $this->assertSame('01711112222', $created->phone);
        $this->assertSame($owner->id, (int) $created->phone_share_approved_by);
        $this->assertSame('Husband and wife run two shops from one phone.', $created->phone_share_reason);
        $audit = DB::table('audit_events')->where('action', 'account.phone_share_approved')->where('subject', (string) $created->id)->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString((string) $existing->id, (string) $audit->metadata);
    }

    public function test_staff_who_are_not_owners_cannot_approve_a_shared_number(): void
    {
        $manager = User::factory()->staff('account_manager')->create(['phone' => '01900000002']);
        User::factory()->dealer()->create(['phone' => '01711112222']);

        $response = $this->actingAs($manager)->onPortal('manage')->post('/commands/accounts', $this->dealerForm([
            'identity_type' => 'birth_certificate',
            'nid' => null,
            'birth_certificate_number' => '19901234567890123',
            'phone_share_reason' => 'Trying to share anyway',
        ]));
        $response->assertSessionHasErrors(['phone' => __('portal.acct_val_phone_taken')]);
        $this->assertDatabaseMissing('users', ['email' => 'phone-rule-dealer@example.com']);
    }

    public function test_staff_accounts_need_a_unique_mobile_too(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
        $form = ['role' => 'finance', 'name' => 'Finance Person', 'email' => 'finance-new@example.com', 'reason' => 'New staff'];

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $form)->assertSessionHasErrors('phone');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', [...$form, 'phone' => '01900000001'])
            ->assertSessionHasErrors('phone');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', [...$form, 'phone' => '8801933334444']);

        $this->assertSame('01933334444', User::query()->where('email', 'finance-new@example.com')->value('phone'));
    }

    public function test_editing_cannot_clear_a_number_or_take_another_accounts_number(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
        $dealer = User::factory()->dealer()->create(['phone' => '01711112222']);
        User::factory()->dealer()->create(['phone' => '01733334444']);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, ['phone' => '', 'reason' => 'Clear'])
            ->assertSessionHasErrors('phone');
        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, ['phone' => '01733334444', 'reason' => 'Swap'])
            ->assertSessionHasErrors('phone');
        $this->assertSame('01711112222', $dealer->fresh()->phone);

        // Writing the same number in another format is not a change and needs no approval.
        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, ['phone' => '+8801711112222', 'name' => 'Renamed', 'reason' => 'Rename'])
            ->assertSessionHasNoErrors();
        $this->assertSame('01711112222', $dealer->fresh()->phone);
        $this->assertSame('Renamed', $dealer->fresh()->name);
    }

    public function test_many_dealers_on_one_network_address_can_all_sign_in_at_once(): void
    {
        Config::set('madina.login_otp.enabled', false);
        for ($i = 1; $i <= 8; $i++) {
            $dealer = User::factory()->dealer()->create(['phone' => sprintf('017000000%02d', $i), 'password' => 'SyntheticPassword123']);
            $this->onPortal('dealer')->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
                ->assertRedirect('/');
            $this->assertAuthenticatedAs($dealer);
            auth()->logout();
            $this->app['session']->flush();
        }
    }

    public function test_the_sign_in_code_goes_only_to_the_number_stored_on_the_account(): void
    {
        Config::set('madina.login_otp.enabled', true);
        $dealer = User::factory()->dealer()->create(['phone' => '01712345678', 'password' => 'SyntheticPassword123']);

        $this->onPortal('dealer')->post('/login', [
            'email' => $dealer->email,
            'password' => 'SyntheticPassword123',
            'phone' => '01899999999',
        ])->assertRedirect('/login/otp');
        $this->onPortal('dealer')->post('/login/otp/resend', ['phone' => '01899999999']);

        $codes = array_values(array_filter((array) config('madina.sms.sent'), fn (array $sms): bool => $sms['event'] === 'login.otp'));
        $this->assertNotEmpty($codes);
        foreach ($codes as $sms) {
            $this->assertSame('01712345678', $sms['phone']);
        }
    }

    public function test_the_server_command_turns_the_sms_code_off_and_on_with_an_audit_entry(): void
    {
        User::factory()->staff('super_admin')->create(['phone' => '01900000001']);

        $this->artisan('madina:sms-login', ['state' => 'off'])->assertSuccessful();
        $this->assertFalse(app(SecuritySettings::class)->partnerLoginOtp()['stored_enabled']);
        $this->assertTrue(DB::table('audit_events')->where('action', 'security.partner_login_otp_changed')->where('metadata', 'like', '%server_command%')->exists());

        $this->artisan('madina:sms-login', ['state' => 'on'])->assertSuccessful();
        $this->assertTrue(app(SecuritySettings::class)->partnerLoginOtp()['stored_enabled']);
        $this->artisan('madina:sms-login', ['state' => 'maybe'])->assertFailed();
    }

    public function test_go_live_check_flags_missing_and_shared_numbers_and_the_sms_switch(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
        $missing = User::factory()->dealer()->create(['phone' => null]);
        User::factory()->dealer()->create(['phone' => '01711112222']);
        // The database itself refuses a second unapproved account on one number.
        $this->assertThrows(fn () => DB::transaction(fn () => User::factory()->dealer()->create(['phone' => '+8801711112222'])), UniqueConstraintViolationException::class);
        User::factory()->dealer()->create(['phone' => '+8801711112222', 'phone_share_approved_by' => $owner->id, 'phone_share_reason' => 'Approved']);
        app(SecuritySettings::class)->savePartnerLoginOtp($owner, false, 0);

        $warnings = app(DeploymentReadiness::class)->inspect()['warnings'];
        $this->assertContains('accounts_missing_mobile', $warnings);
        $this->assertNotContains('accounts_sharing_mobile', $warnings);
        $this->assertContains('sms_login_code_switched_off', $warnings);

        $missing->forceFill(['phone' => '01755556666'])->save();
        app(SecuritySettings::class)->savePartnerLoginOtp($owner, true, 1);

        $warnings = app(DeploymentReadiness::class)->inspect()['warnings'];
        $this->assertNotContains('accounts_missing_mobile', $warnings);
        $this->assertNotContains('accounts_sharing_mobile', $warnings);
        $this->assertNotContains('sms_login_code_switched_off', $warnings);
    }
}

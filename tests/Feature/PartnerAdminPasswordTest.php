<?php

namespace Tests\Feature;

use App\Domain\Accounts\PasswordAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PartnerAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
    }

    public function test_admin_can_reveal_password_created_during_partner_onboarding(): void
    {
        $admin = User::factory()->staff()->create();

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
            'name' => 'Md. Reveal',
            'name_bn' => 'মোঃ রিভিল',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-102',
            'refer_code' => 'REF-REVEAL',
            'phone' => '01712345001',
            'email' => 'reveal@example.com',
            'reason' => 'Password visibility check',
        ])->assertRedirect('/accounts');

        $partner = User::query()->where('email', 'reveal@example.com')->firstOrFail();
        $this->assertSame(User::PasswordManagedByAdmin, $partner->password_managed_by);
        $this->assertNotEmpty($partner->adminVisiblePassword());

        $this->actingAs($admin)->onPortal('sadmin')
            ->getJson('/commands/accounts/'.$partner->id.'/password-status')
            ->assertOk()
            ->assertJsonPath('data.can_reveal', true)
            ->assertJsonPath('data.managed_by', User::PasswordManagedByAdmin)
            ->assertJsonPath('data.password', null);

        $this->actingAs($admin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$partner->id.'/reveal-password')
            ->assertOk()
            ->assertJsonPath('data.password', $partner->adminVisiblePassword());
    }

    public function test_partner_self_password_change_keeps_password_visible_to_admin_per_policy(): void
    {
        $admin = User::factory()->staff()->create();
        $partner = User::factory()->dealer()->create([
            'password' => 'TempPassword123!',
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => 'TempPassword123!',
        ]);

        $this->actingAs($partner)->onPortal('dealer')->post('/security/password', [
            'current_password' => 'TempPassword123!',
            'password' => 'PartnerOwnedPass1',
            'password_confirmation' => 'PartnerOwnedPass1',
        ])->assertRedirect();

        $partner->refresh();
        $this->assertSame(User::PasswordManagedByAdmin, $partner->password_managed_by);
        $this->assertSame('PartnerOwnedPass1', $partner->adminVisiblePassword());
        $this->assertTrue(Hash::check('PartnerOwnedPass1', $partner->password));

        $this->actingAs($admin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$partner->id.'/reveal-password')
            ->assertOk()
            ->assertJsonPath('data.can_reveal', true)
            ->assertJsonPath('data.password', 'PartnerOwnedPass1');
    }

    public function test_admin_reset_makes_new_password_visible_again(): void
    {
        $admin = User::factory()->staff()->create();
        $partner = User::factory()->subDealer()->create([
            'status' => 'active',
            'phone' => '01712345099',
            'password_managed_by' => User::PasswordManagedByPartner,
            'admin_password_cipher' => null,
        ]);

        $response = $this->actingAs($admin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$partner->id.'/reset-password');

        $response->assertOk()
            ->assertJsonPath('data.can_reveal', true)
            ->assertJsonPath('data.managed_by', User::PasswordManagedByAdmin);

        $plain = $response->json('data.password');
        $this->assertIsString($plain);
        $this->assertNotSame('', $plain);

        $partner->refresh();
        $this->assertTrue(Hash::check($plain, $partner->password));
        $this->assertSame($plain, $partner->adminVisiblePassword());

        $sent = config('madina.sms.sent');
        $this->assertNotEmpty($sent);
        $this->assertStringContainsString($plain, $sent[array_key_last($sent)]['message']);
    }

    public function test_admin_can_reset_with_custom_password(): void
    {
        $admin = User::factory()->staff()->create();
        $partner = User::factory()->dealer()->create(['status' => 'active']);

        $response = $this->actingAs($admin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$partner->id.'/reset-password', [
                'password' => 'CustomAdminPass999!',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.can_reveal', true)
            ->assertJsonPath('data.password', 'CustomAdminPass999!');

        $partner->refresh();
        $this->assertTrue(Hash::check('CustomAdminPass999!', $partner->password));
        $this->assertSame('CustomAdminPass999!', $partner->adminVisiblePassword());
    }

    public function test_account_manager_sees_password_status_but_not_the_password(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $partner = User::factory()->dealer()->create([
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => 'TempPassword123!',
        ]);

        $this->actingAs($manager)->onPortal('manage')
            ->getJson('/commands/accounts/'.$partner->id.'/password-status')
            ->assertOk()
            ->assertJsonPath('data.can_reveal', false)
            ->assertJsonPath('data.password', null);

        $this->assertDatabaseMissing('audit_events', ['actor_id' => $manager->id, 'action' => 'account.password_revealed']);
    }

    public function test_owner_password_reveal_is_audited(): void
    {
        $owner = User::factory()->staff()->create();
        $partner = User::factory()->dealer()->create([
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => 'TempPassword123!',
        ]);

        // Opening the panel shows nothing and records nothing.
        $this->actingAs($owner)->onPortal('sadmin')
            ->getJson('/commands/accounts/'.$partner->id.'/password-status')
            ->assertOk()
            ->assertJsonPath('data.can_reveal', true)
            ->assertJsonPath('data.password', null);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.password_revealed']);

        // Pressing "Show password" does, with who and from where.
        $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$partner->id.'/reveal-password')
            ->assertOk()
            ->assertJsonPath('data.password', 'TempPassword123!');

        $event = DB::table('audit_events')->where('action', 'account.password_revealed')->first();
        $this->assertSame($owner->id, (int) $event->actor_id);
        $this->assertSame((string) $partner->id, $event->subject);
        $this->assertNotNull($event->created_at);
        $this->assertArrayHasKey('ip', json_decode($event->metadata, true));
    }

    public function test_only_owners_can_reveal_and_staff_resets_are_audited_without_showing_the_password(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $partner = User::factory()->dealer()->create([
            'phone' => '01712345111',
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => 'TempPassword123!',
        ]);

        $this->actingAs($manager)->onPortal('manage')
            ->postJson('/commands/accounts/'.$partner->id.'/reveal-password')
            ->assertForbidden();

        $this->actingAs($manager)->onPortal('manage')
            ->postJson('/commands/accounts/'.$partner->id.'/reset-password')
            ->assertOk()
            ->assertJsonPath('data.password', null);
        $event = DB::table('audit_events')->where('action', 'account.password_reset_by_admin')->first();
        $this->assertSame($manager->id, (int) $event->actor_id);
        $this->assertFalse(json_decode($event->metadata, true)['new_password_shown_to_actor']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.password_revealed']);
    }

    public function test_staff_reset_without_a_phone_is_refused_when_nobody_could_read_the_new_password(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $partner = User::factory()->dealer()->create(['phone' => null]);

        $this->actingAs($manager)->onPortal('manage')
            ->postJson('/commands/accounts/'.$partner->id.'/reset-password')
            ->assertStatus(422);
    }

    public function test_md_can_reset_and_view_staff_but_not_the_super_admin_md_or_chairman(): void
    {
        $md = User::factory()->staff('md')->create(['profile' => ['action_permissions' => ['accounts' => ['view' => true, 'create' => true, 'edit' => true]]]]);
        $employee = User::factory()->staff('finance')->create([
            'phone' => '01712345222',
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => 'StaffInitialPass1!',
        ]);
        $chairman = User::factory()->staff('chairman')->create(['admin_password_cipher' => 'ChairPass1!']);
        $superAdmin = User::factory()->staff('super_admin')->create();

        $access = app(PasswordAccess::class);
        $this->assertTrue($access->canReset($md, $employee));
        $this->assertTrue($access->canView($md, $employee));
        $this->assertFalse($access->canReset($md, $chairman));
        $this->assertFalse($access->canView($md, $chairman));
        $this->assertFalse($access->canReset($md, $superAdmin));
        $this->assertFalse($access->canReset($md, $md));
        $this->assertTrue($access->canReset($superAdmin, $chairman));
        $this->assertFalse($access->canReset($superAdmin, $superAdmin));

        $this->actingAs($superAdmin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$superAdmin->id.'/reveal-password')
            ->assertForbidden();
    }

    public function test_password_status_is_forbidden_for_non_accounts_staff(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $partner = User::factory()->dealer()->create();

        $this->actingAs($finance)->onPortal('manage')
            ->getJson('/commands/accounts/'.$partner->id.'/password-status')
            ->assertForbidden();
    }

    public function test_admin_can_manage_staff_password_while_non_superadmin_is_rejected(): void
    {
        $admin = User::factory()->staff()->create();
        $employee = User::factory()->staff('employee')->create([
            'status' => 'active',
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => 'StaffInitialPass1!',
        ]);

        // Superadmin CAN view staff password
        $this->actingAs($admin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$employee->id.'/reveal-password')
            ->assertOk()
            ->assertJsonPath('data.can_reveal', true)
            ->assertJsonPath('data.password', 'StaffInitialPass1!');

        // Superadmin CAN reset staff password
        $this->actingAs($admin)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$employee->id.'/reset-password', [
                'password' => 'NewStaffPass888!',
            ])
            ->assertOk()
            ->assertJsonPath('data.password', 'NewStaffPass888!');

        $employee->refresh();
        $this->assertSame('NewStaffPass888!', $employee->adminVisiblePassword());

        // Regular staff without super_admin is forbidden
        $regularStaff = User::factory()->staff('editor')->create();
        $this->actingAs($regularStaff)->onPortal('manage')
            ->getJson('/commands/accounts/'.$employee->id.'/password-status')
            ->assertForbidden();

        $this->actingAs($regularStaff)->onPortal('manage')
            ->postJson('/commands/accounts/'.$employee->id.'/reset-password', [
                'password' => 'HackedPass123!',
            ])
            ->assertForbidden();
    }
}

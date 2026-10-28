<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PartnerAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlaceCodeSeeder::class);
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
            ->getJson('/commands/accounts/'.$partner->id.'/password-status')
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
            ->getJson('/commands/accounts/'.$employee->id.'/password-status')
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

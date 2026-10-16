<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_company_entry_is_separate_and_guest_pages_are_private(): void
    {
        $this->get('/company')->assertRedirect('/company/login');
        $this->get('/company/orders')->assertRedirect('/company/login');
        $this->get('/company/login')->assertOk()->assertSee('Staff Sign In')->assertDontSee('portal-category-nav')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get('/')->assertDontSee('/company/login');
    }

    public function test_correct_credentials_do_not_cross_company_and_dealer_login(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin', 'password' => 'StaffPassword12345']);
        $dealer = User::factory()->create(['role' => 'dealer', 'password' => 'DealerPassword12345']);
        $this->post('/company/login', ['email' => $dealer->email, 'password' => 'DealerPassword12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $staff->email, 'password' => 'StaffPassword12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/company/login', ['email' => $staff->email, 'password' => 'StaffPassword12345'])->assertRedirect('/company');
        $this->assertAuthenticatedAs($staff);
        $this->get('/company')->assertInertia(fn (Assert $p) => $p->where('portalBase', '/company'));
        $this->get('/workspace/orders?q=TEST')->assertRedirect('/company/orders?q=TEST');
        $this->post('/logout')->assertRedirect('/company/login');
        $this->assertGuest();
    }

    public function test_dealer_cannot_open_company_area_and_staff_permissions_still_apply(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'dealer']))->get('/company')->assertForbidden();
        $this->get('/company/accounts')->assertForbidden();
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/company/content')->assertOk();
        $this->get('/company/payments')->assertForbidden();
        $this->get('/company/orders')->assertForbidden();
    }

    public function test_staff_recovery_link_and_reset_return_to_company_access(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['role' => 'finance']);
        $this->post('/company/forgot-password', ['email' => $staff->email])->assertSessionHas('success');
        Notification::assertSentTo($staff, ResetPassword::class, function ($notification) use ($staff) {
            $mail = $notification->toMail($staff);

            return str_starts_with($mail->actionUrl, url('/company/reset-password/'));
        });
        $token = Password::createToken($staff);
        $this->get('/company/reset-password/'.$token.'?email='.urlencode($staff->email))->assertOk()->assertSee('Confirm Password');
        $this->post('/company/reset-password', ['token' => $token, 'email' => $staff->email, 'password' => 'ReplacementPassword123', 'password_confirmation' => 'ReplacementPassword123'])->assertRedirect('/company/login');
    }

    public function test_inertia_logout_uses_full_navigation_to_standalone_company_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->withHeader('X-Inertia', 'true')->post('/logout')->assertStatus(409)->assertHeader('X-Inertia-Location', '/company/login');
        $this->assertGuest();
    }

    public function test_super_admin_can_create_employee_with_manual_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'employee',
            'name' => 'Test Employee',
            'email' => 'employee.test@madina.com',
            'phone' => '01799998888',
            'status' => 'active',
            'permissions' => ['orders', 'inventory'],
            'reason' => 'Onboarding test warehouse employee with orders and inventory permissions.',
        ])->assertRedirect('/company/employees');

        $employee = User::where('email', 'employee.test@madina.com')->firstOrFail();
        $this->assertSame('employee', $employee->role);
        $this->assertTrue($employee->staff());
        $this->assertSame(['orders', 'inventory'], $employee->profile['permissions']);
        $this->assertTrue($employee->allows('orders'));
        $this->assertTrue($employee->allows('inventory'));
        $this->assertFalse($employee->allows('finance'));
        $this->assertFalse($employee->allows('audit'));
    }

    public function test_employee_dashboard_access_is_strictly_restricted_to_granted_permissions(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'profile' => ['permissions' => ['orders', 'inventory']],
        ]);

        $this->actingAs($employee)->get('/company')->assertOk();
        $this->get('/company/orders')->assertOk();
        $this->get('/company/inventory')->assertOk();

        $this->get('/company/treasury')->assertForbidden();
        $this->get('/company/commissions')->assertForbidden();
        $this->get('/company/audit')->assertForbidden();
        $this->get('/company/employees')->assertForbidden();
    }

    public function test_super_admin_can_update_employee_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'profile' => ['permissions' => ['orders']],
        ]);

        $this->actingAs($admin)->patch('/commands/accounts/'.$employee->id, [
            'name' => 'Updated Employee Name',
            'status' => 'active',
            'permissions' => ['orders', 'finance', 'reports'],
            'reason' => 'Promoting employee to finance and reporting.',
        ])->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame(['orders', 'finance', 'reports'], $employee->profile['permissions']);
        $this->assertTrue($employee->allows('finance'));
        $this->assertTrue($employee->allows('reports'));
    }

    public function test_super_admin_can_manage_and_reset_dashboards(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // 1. Super admin can access /company/dashboards
        $this->actingAs($admin)->get('/company/dashboards')->assertOk();

        // 2. Super admin saves dashboard settings
        $response = $this->actingAs($admin)->post('/commands/dashboards', [
            'settings' => [
                'dealer_announcement' => 'Special promotion week for all dealers!',
                'dealer_announcement_type' => 'warning',
                'dealer_maintenance_mode' => true,
                'subdealer_announcement' => 'Sub-dealer inventory update active.',
                'subdealer_announcement_type' => 'info',
                'subdealer_maintenance_mode' => false,
            ],
        ]);
        $response->assertSessionHas('success');

        // 3. Settings are reflected in DashboardManagement::get()
        $settings = DashboardManagement::get();
        $this->assertSame('Special promotion week for all dealers!', $settings['dealer_announcement']);
        $this->assertSame('warning', $settings['dealer_announcement_type']);
        $this->assertTrue((bool) $settings['dealer_maintenance_mode']);

        // 4. Dealer receives customized notice and maintenance status via noticeForUser
        $notice = DashboardManagement::noticeForUser($dealer);
        $this->assertSame('Special promotion week for all dealers!', $notice['text']);
        $this->assertSame('warning', $notice['type']);
        $this->assertTrue($notice['maintenance']);

        // 5. Non-staff dealer cannot access /company/dashboards or POST /commands/dashboards
        $this->actingAs($dealer)->get('/company/dashboards')->assertForbidden();
        $this->actingAs($dealer)->post('/commands/dashboards', ['settings' => []])->assertForbidden();

        // 6. Reset dashboard settings restores defaults
        $this->actingAs($admin)->post('/commands/dashboards/reset')->assertSessionHas('success');
        $resetSettings = DashboardManagement::get();
        $this->assertFalse((bool) $resetSettings['dealer_maintenance_mode']);
        $this->assertSame(DashboardManagement::defaults()['dealer_announcement'], $resetSettings['dealer_announcement']);
    }
}

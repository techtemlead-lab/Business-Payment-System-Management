<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffPermissions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Staff page and the Roles and permissions page: every office worker can be found,
 * disabled, and given a role, and the role really changes.
 */
class StaffPageAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create(['locale' => 'bn']);
    }

    public function test_the_role_chosen_on_the_access_page_is_saved(): void
    {
        DB::table('staff_roles')->insert([
            'key' => 'custom_shop_desk',
            'name' => 'Shop desk',
            'permissions' => json_encode(['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]], JSON_THROW_ON_ERROR),
            'version' => 1,
            'updated_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'support', 'status' => 'active']);
        $matrix = ['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]];

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/operations/access-management')
            ->post('/commands/operations/staff/action-permissions', [
                'staff_id' => $staff->id,
                'role' => 'custom_shop_desk',
                'permissions' => $matrix,
                'version' => app(StaffPermissions::class)->version($staff),
                'reason' => 'Moving this person to the shop desk',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertSame('custom_shop_desk', $staff->role);
        $this->assertTrue($staff->allows('orders'));
        $this->assertFalse($staff->allows('support_desk'));
    }

    public function test_the_staff_page_lists_every_office_role_and_not_the_owners(): void
    {
        foreach (['finance', 'inventory', 'account_manager', 'support', 'editor', 'sr', 'employee', 'custom_shop_desk'] as $role) {
            User::factory()->create(['role' => $role, 'name' => 'Person '.$role]);
        }
        User::factory()->create(['role' => 'md', 'name' => 'The MD']);
        User::factory()->create(['role' => 'chairman', 'name' => 'The Chairman']);
        User::factory()->dealer()->create(['name' => 'A dealer']);

        $data = $this->sectionData('/employees');
        $names = array_column($data['employees']['data'], 'name');

        foreach (['finance', 'inventory', 'account_manager', 'support', 'editor', 'sr', 'employee', 'custom_shop_desk'] as $role) {
            $this->assertContains('Person '.$role, $names);
        }
        $this->assertNotContains('The MD', $names);
        $this->assertNotContains('The Chairman', $names);
        $this->assertNotContains('A dealer', $names);
        $this->assertSame(8, $data['employee_stats']['total']);
    }

    public function test_staff_with_a_built_in_role_can_be_disabled_and_cannot_keep_working(): void
    {
        $staff = User::factory()->create(['role' => 'support', 'status' => 'active']);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/employees')
            ->patch('/commands/accounts/'.$staff->id, ['name' => $staff->name, 'status' => 'suspended', 'reason' => 'Left the company'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('suspended', $staff->fresh()->status);
        $this->actingAs($staff->fresh())->onPortal('manage')->get('/overview')->assertRedirect();
    }

    public function test_the_role_list_uses_plain_names_in_the_page_language(): void
    {
        $data = $this->operationsData('/operations/access-management');
        $names = array_column($data['rolePresets'], 'name', 'key');

        $this->assertSame('সাপোর্ট স্টাফ', $names['support']);
        $this->assertSame('স্টাফ', $names['employee']);
    }

    /** @return array<string, mixed> */
    private function sectionData(string $url): array
    {
        return $this->inertiaProps($url)['data'];
    }

    /** @return array<string, mixed> */
    private function operationsData(string $url): array
    {
        return $this->inertiaProps($url);
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $url): array
    {
        $response = $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))])
            ->get($url)
            ->assertOk();

        return $response->json('props');
    }
}

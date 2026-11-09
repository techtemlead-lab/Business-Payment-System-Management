<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffPermissions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Editing a custom role changes the permissions of everyone who already has it. */
class CustomRoleEditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    /** @param  array<string, array<string, bool>>  $permissions */
    private function saveRole(string $key, array $permissions, int $version): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->from('/operations/access-management')->post('/commands/operations/staff/roles', [
            'key' => $key, 'name' => 'Shop desk', 'permissions' => $permissions, 'version' => $version, 'reason' => 'Changing the shop desk role.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @param  array<string, array<string, bool>>  $permissions */
    private function giveRole(User $staff, string $role, array $permissions): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->from('/operations/access-management')->post('/commands/operations/staff/action-permissions', [
            'staff_id' => $staff->id, 'role' => $role, 'permissions' => $permissions, 'version' => app(StaffPermissions::class)->version($staff), 'reason' => 'Giving this person the role.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_people_who_already_have_a_custom_role_get_its_new_permissions(): void
    {
        $ordersOnly = ['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]];
        $stockOnly = ['inventory' => ['view' => true, 'create' => true, 'edit' => false, 'approve' => false]];
        $this->saveRole(StaffPermissions::NewRoleKey, $ordersOnly, 0);
        $key = (string) DB::table('staff_roles')->value('key');
        $first = User::factory()->staff('employee')->create(['status' => 'active']);
        $second = User::factory()->staff('employee')->create(['status' => 'active']);
        $this->giveRole($first, $key, $ordersOnly);
        $this->giveRole($second, $key, $ordersOnly);
        $this->assertTrue($first->fresh()->allows('orders'));

        $this->saveRole($key, $stockOnly, 1);

        foreach ([$first, $second] as $staff) {
            $this->assertFalse($staff->fresh()->allows('orders'));
            $this->assertTrue($staff->fresh()->allows('inventory'));
            $this->assertTrue($staff->fresh()->canAct('inventory', 'create'));
            $this->assertFalse($staff->fresh()->canAct('inventory', 'edit'));
        }
    }

    public function test_someone_with_another_role_is_not_changed(): void
    {
        $ordersOnly = ['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]];
        $this->saveRole(StaffPermissions::NewRoleKey, $ordersOnly, 0);
        $key = (string) DB::table('staff_roles')->value('key');
        $other = User::factory()->staff('finance')->create(['status' => 'active']);
        $before = $other->fresh()->capabilities();

        $this->saveRole($key, ['inventory' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]], 1);

        $this->assertSame($before, $other->fresh()->capabilities());
    }
}

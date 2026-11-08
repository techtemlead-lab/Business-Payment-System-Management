<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffPermissions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomRoleNameOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_role_needs_only_a_name(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->from('/operations/access-management')
            ->post('/commands/operations/staff/roles', [
                'key' => StaffPermissions::NewRoleKey,
                'name' => 'দোকান দেখাশোনা',
                'permissions' => ['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]],
                'version' => 0,
                'reason' => 'A new role for the shop desk',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $role = DB::table('staff_roles')->sole();
        $this->assertSame('দোকান দেখাশোনা', $role->name);
        $this->assertMatchesRegularExpression('/^custom_role_[a-z0-9]{12}$/', $role->key);
    }
}

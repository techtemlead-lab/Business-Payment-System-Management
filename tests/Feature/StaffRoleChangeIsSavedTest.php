<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffPermissions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRoleChangeIsSavedTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_a_staff_role_on_the_access_page_is_really_saved(): void
    {
        $owner = User::factory()->staff()->create();
        $managingDirector = User::factory()->staff('md')->create();
        $permissions = app(StaffPermissions::class);

        $this->actingAs($owner)->postJson('/api/v1/operations/staff/action-permissions', [
            'staff_id' => $managingDirector->id,
            'version' => $permissions->version($managingDirector),
            'role' => 'employee',
            'permissions' => ['orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]],
            'reason' => 'Moved out of the owner group',
        ])->assertOk();

        $demoted = $managingDirector->fresh();
        $this->assertSame('employee', $demoted->role);
        $this->assertFalse($demoted->owner());
    }
}

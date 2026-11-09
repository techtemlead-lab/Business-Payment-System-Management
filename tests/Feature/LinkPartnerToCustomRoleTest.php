<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinkPartnerToCustomRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function customRole(array $permissions): string
    {
        DB::table('staff_roles')->insert(['key' => 'custom_desk', 'name' => 'Desk', 'permissions' => json_encode($permissions), 'version' => 1, 'updated_by' => User::factory()->staff()->create()->id, 'created_at' => now(), 'updated_at' => now()]);

        return 'custom_desk';
    }

    public function test_a_partner_can_be_linked_to_a_custom_role_and_gets_only_the_modules_it_opens(): void
    {
        $role = $this->customRole([
            'orders' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false],
            'catalog' => ['view' => false, 'create' => false, 'edit' => false, 'approve' => false],
        ]);
        $partner = User::factory()->dealer()->create();

        $this->actingAs(User::factory()->staff()->create())->post('/commands/accounts/'.$partner->id.'/link-staff', ['linked_staff_role' => $role])->assertRedirect();

        $partner->refresh();
        $this->assertSame($role, $partner->linked_staff_role);
        session(['workspace_mode' => 'staff', 'workspace_user_id' => $partner->id]);
        $this->assertSame(['orders'], $partner->capabilities());
    }

    public function test_a_custom_role_that_opens_money_modules_is_still_refused(): void
    {
        $role = $this->customRole(['finance' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]]);
        $partner = User::factory()->dealer()->create();

        $this->actingAs(User::factory()->staff()->create())->post('/commands/accounts/'.$partner->id.'/link-staff', ['linked_staff_role' => $role])->assertSessionHasErrors('permissions');

        $this->assertNull($partner->fresh()->linked_staff_role);
    }
}

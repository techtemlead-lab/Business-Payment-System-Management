<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchStaffPickerRoleNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_staff_picker_gets_the_name_of_a_custom_role(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'name' => 'A Owner']);
        DB::table('staff_roles')->insert([
            'key' => 'custom_role_abc',
            'name' => 'পণ্য দেখাশোনা',
            'permissions' => json_encode(['catalog']),
            'version' => 1,
            'updated_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $custom = User::factory()->create(['role' => 'custom_role_abc', 'status' => 'active', 'name' => 'B Custom']);
        $builtIn = User::factory()->create(['role' => 'finance', 'status' => 'active', 'name' => 'C Finance']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/branches')->assertOk()->assertInertia(function (Assert $page) use ($custom, $builtIn): void {
            $staff = collect($page->toArray()['props']['data']['assignable_staff'])->keyBy('id');
            $this->assertSame('পণ্য দেখাশোনা', $staff[$custom->id]['role_name']);
            $this->assertNull($staff[$builtIn->id]['role_name']);
        });
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffPermissions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Choosing a role on the access page fills in that role's permissions, and a custom role
 * shows the name the owner gave it instead of its stored key.
 */
class StaffRolePresetsAndNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create(['locale' => 'bn']);
    }

    public function test_the_access_page_offers_each_built_in_role_with_its_own_permissions(): void
    {
        $byRole = collect($this->rolePresets())->keyBy('key');

        $this->assertSame([], $byRole['employee']['permissions']);
        $this->assertTrue($byRole['support']['permissions']['support_desk']['edit']);
        $this->assertTrue($byRole['editor']['permissions']['content']['edit']);
    }

    public function test_a_person_moved_to_a_built_in_role_gets_the_work_of_that_role(): void
    {
        $staff = User::factory()->staff('employee')->create();
        $support = collect($this->rolePresets())->firstWhere('key', 'support');

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/operations/access-management')
            ->post('/commands/operations/staff/action-permissions', [
                'staff_id' => $staff->id,
                'version' => app(StaffPermissions::class)->version($staff),
                'role' => 'support',
                'permissions' => $support['permissions'],
                'reason' => 'Moved to the support desk',
            ])->assertRedirect();

        $moved = $staff->fresh();
        $this->assertSame('support', $moved->role);
        $this->assertTrue($moved->allows('support_desk'));
    }

    public function test_a_custom_role_shows_the_name_the_owner_gave_it(): void
    {
        DB::table('staff_roles')->insert([
            'key' => 'custom_role_abc', 'name' => 'পণ্য দেখাশোনা', 'version' => 1, 'updated_by' => $this->owner->id,
            'permissions' => json_encode(['catalog' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = User::factory()->staff('employee')->create();
        $staff->forceFill(['role' => 'custom_role_abc'])->save();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/employees')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.employees.data', fn ($rows): bool => collect($rows)
                ->firstWhere('id', $staff->id)['role_name'] === 'পণ্য দেখাশোনা'));

        $this->actingAs($staff->fresh())->onPortal('manage')->get('/catalog')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.customRoleName', 'পণ্য দেখাশোনা'));
    }

    /** @return list<array{key: string, name: string, permissions: array<string, array<string, bool>>}> */
    private function rolePresets(): array
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))])
            ->get('/operations/access-management')
            ->assertOk()
            ->json('props.rolePresets');
    }
}

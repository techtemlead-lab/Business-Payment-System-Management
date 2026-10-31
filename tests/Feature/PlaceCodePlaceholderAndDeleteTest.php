<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlaceCodePlaceholderAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_default_place_code_seeder_leaves_catalog_empty(): void
    {
        $this->seed(PlaceCodeSeeder::class);

        $this->assertSame(0, PlaceCode::query()->count());

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/place-codes?level=management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.places.data', [])
                ->where('data.places.total', 0));
    }

    public function test_admin_can_create_management_and_division_from_empty_catalog(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => PlaceCode::LevelManagement,
            'code' => '100',
            'name' => 'Management',
            'parent_id' => null,
            'sort_order' => 0,
        ])->assertRedirect();

        $management = PlaceCode::query()->where('level', PlaceCode::LevelManagement)->firstOrFail();
        $this->assertSame('MNHC-100', $management->code);
        $this->assertSame('Management', $management->name);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => PlaceCode::LevelDivision,
            'code' => '101',
            'name' => 'Dhaka',
            'parent_id' => $management->id,
            'sort_order' => 1,
        ])->assertRedirect();

        $division = PlaceCode::query()->where('code', 'MNHC-101')->firstOrFail();
        $this->assertSame($management->id, $division->parent_id);
        $this->assertTrue($division->isReadyForAssignment());
    }

    public function test_admin_can_create_placeholder_then_fill_real_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => PlaceCode::LevelManagement,
            'code' => '—',
            'name' => '—',
            'parent_id' => null,
        ])->assertRedirect();

        $management = PlaceCode::query()->where('level', PlaceCode::LevelManagement)->firstOrFail();
        $this->assertNull($management->code);
        $this->assertSame('—', $management->name);
        $this->assertFalse($management->isReadyForAssignment());

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/place-codes/'.$management->id, [
            'code' => '100',
            'name' => 'Management',
        ])->assertRedirect();

        $management->refresh();
        $this->assertSame('MNHC-100', $management->code);
        $this->assertSame('Management', $management->name);
        $this->assertTrue($management->isReadyForAssignment());
    }

    public function test_admin_can_delete_place_without_children(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $management = PlaceCode::query()->create([
            'level' => PlaceCode::LevelManagement,
            'code' => null,
            'name' => '—',
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->delete('/commands/place-codes/'.$management->id, [
            'reason' => 'Unused management row removed by owner',
        ])->assertRedirect();

        $this->assertDatabaseMissing('place_codes', ['id' => $management->id]);
        $this->assertSame(0, PlaceCode::query()->count());
    }

    public function test_admin_cannot_delete_place_with_children(): void
    {
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dhaka = PlaceCode::query()->where('code', 'MNHC-101')->firstOrFail();
        PlaceCode::factory()->district($dhaka)->create(['code' => 'MNHC-201', 'name' => 'Dhaka']);

        $this->actingAs($admin)->onPortal('sadmin')->from('/place-codes?level=division')
            ->delete('/commands/place-codes/'.$dhaka->id, [
                'reason' => 'Attempt remove with children',
            ])
            ->assertRedirect('/place-codes?level=division')
            ->assertSessionHasErrors('place');

        $this->assertDatabaseHas('place_codes', ['id' => $dhaka->id, 'code' => 'MNHC-101']);
    }

    public function test_placeholder_place_cannot_be_used_for_area_assignment(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $slot = PlaceCode::query()->create([
            'level' => PlaceCode::LevelDivision,
            'code' => null,
            'name' => '—',
            'parent_id' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // Division normally needs a management parent for assignment trees; this
        // asserts placeholder readiness is rejected before hierarchy checks.
        $this->actingAs($admin)->onPortal('sadmin')->from('/area-dealers')
            ->post('/commands/area-dealers', [
                'user_id' => $dealer->id,
                'level' => 'division',
                'place_code_id' => $slot->id,
                'monthly_quota' => '10000.00',
            ])
            ->assertRedirect('/area-dealers')
            ->assertSessionHasErrors('place_code_id');
    }
}

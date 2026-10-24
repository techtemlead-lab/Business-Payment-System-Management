<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlaceCodeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlaceCodeSeeder::class);
    }

    public function test_owner_can_open_code_management_submodules_and_search_districts(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $management = PlaceCode::query()->where('code', 'MNHC-100')->firstOrFail();
        $dhaka = PlaceCode::query()->where('code', 'MNHC-101')->firstOrFail();
        PlaceCode::factory()->district($dhaka)->create(['code' => 'MNHC-201', 'name' => 'Dhaka', 'sort_order' => 1]);
        PlaceCode::factory()->district($dhaka)->create(['code' => 'MNHC-202', 'name' => 'Gazipur', 'sort_order' => 2]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/place-codes?level=management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'place-codes')
                ->where('data.level', 'management')
                ->where('data.searchable', false)
                ->where('data.places.data.0.code', 'MNHC-100')
                ->where('data.places.data.0.name', 'Management'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/place-codes?level=division')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.level', 'division')
                ->where('data.searchable', false)
                ->where('data.places.data.0.code', 'MNHC-101')
                ->where('data.places.data.1.code', 'MNHC-102'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/place-codes?level=district&q=201')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.level', 'district')
                ->where('data.searchable', true)
                ->where('data.places.total', 1)
                ->where('data.places.data.0.code', 'MNHC-201')
                ->where('data.places.data.0.parent_code', 'MNHC-101'));

        $this->assertSame($management->id, $dhaka->parent_id);
    }

    public function test_admin_can_add_district_code_and_set_base_place_chain(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $rangpur = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => 'district',
            'code' => '301',
            'name' => 'Rangpur',
            'parent_id' => $rangpur->id,
        ])->assertRedirect();

        $district = PlaceCode::query()->where('code', 'MNHC-301')->firstOrFail();
        $this->assertSame('district', $district->level);
        $this->assertSame($rangpur->id, $district->parent_id);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => 'upazila',
            'code' => '401',
            'name' => 'Rangpur Sadar',
            'parent_id' => $district->id,
        ])->assertRedirect();

        $upazila = PlaceCode::query()->where('code', 'MNHC-401')->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => 'union',
            'code' => '501',
            'name' => 'Rangpur City Corporation',
            'parent_id' => $upazila->id,
        ])->assertRedirect();

        $union = PlaceCode::query()->where('code', 'MNHC-501')->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/place-codes/'.$upazila->id, [
            'base_place_id' => $union->id,
        ])->assertRedirect();
        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/place-codes/'.$district->id, [
            'base_place_id' => $upazila->id,
        ])->assertRedirect();
        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/place-codes/'.$rangpur->id, [
            'base_place_id' => $district->id,
        ])->assertRedirect();

        $this->assertSame($district->id, $rangpur->fresh()->base_place_id);
        $this->assertSame($upazila->id, $district->fresh()->base_place_id);
        $this->assertSame($union->id, $upazila->fresh()->base_place_id);
    }

    public function test_place_codes_api_lists_module_payload_for_backend_clients(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->getJson('/api/v1/place-codes?level=division')
            ->assertOk()
            ->assertJsonPath('data.level', 'division')
            ->assertJsonPath('data.places.data.0.code', 'MNHC-101');
    }
}

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

    public function test_place_code_seeder_creates_blank_management_and_division_slots(): void
    {
        $this->seed(PlaceCodeSeeder::class);

        $this->assertSame(1, PlaceCode::query()->where('level', PlaceCode::LevelManagement)->count());
        $this->assertSame(8, PlaceCode::query()->where('level', PlaceCode::LevelDivision)->count());

        $management = PlaceCode::query()->where('level', PlaceCode::LevelManagement)->firstOrFail();
        $this->assertNull($management->code);
        $this->assertSame('—', $management->name);

        $divisions = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->orderBy('sort_order')
            ->get();
        $this->assertSame(range(1, 8), $divisions->pluck('sort_order')->all());
        foreach ($divisions as $division) {
            $this->assertNull($division->code);
            $this->assertSame('—', $division->name);
            $this->assertSame($management->id, $division->parent_id);
        }

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/place-codes?level=division')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.places.data.0.code', '—')
                ->where('data.places.data.0.name', '—')
                ->where('data.places.data.0.is_placeholder', true));
    }

    public function test_admin_can_fill_blank_division_slot_with_real_code(): void
    {
        $this->seed(PlaceCodeSeeder::class);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $slot = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('sort_order', 1)
            ->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/place-codes/'.$slot->id, [
            'code' => '101',
            'name' => 'Dhaka',
        ])->assertRedirect();

        $slot->refresh();
        $this->assertSame('MNHC-101', $slot->code);
        $this->assertSame('Dhaka', $slot->name);
        $this->assertTrue($slot->isReadyForAssignment());
    }

    public function test_admin_can_delete_place_without_children(): void
    {
        $this->seed(PlaceCodeSeeder::class);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $slot = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('sort_order', 8)
            ->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->delete('/commands/place-codes/'.$slot->id, [
            'reason' => 'Unused division slot removed by owner',
        ])->assertRedirect();

        $this->assertDatabaseMissing('place_codes', ['id' => $slot->id]);
        $this->assertSame(7, PlaceCode::query()->where('level', PlaceCode::LevelDivision)->count());
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
        $this->seed(PlaceCodeSeeder::class);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $slot = PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('sort_order', 1)
            ->firstOrFail();

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

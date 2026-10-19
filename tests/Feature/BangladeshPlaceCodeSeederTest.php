<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use Database\Seeders\BangladeshPlaceCodeSeeder;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BangladeshPlaceCodeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_bangladesh_geography_is_seeded_without_missing_tiers(): void
    {
        $this->seed(PlaceCodeSeeder::class);
        $this->seed(BangladeshPlaceCodeSeeder::class);

        $this->assertSame(1, PlaceCode::query()->where('level', 'management')->count());
        $this->assertSame(8, PlaceCode::query()->where('level', 'division')->count());
        $this->assertSame(64, PlaceCode::query()->where('level', 'district')->count());
        $this->assertGreaterThanOrEqual(495, PlaceCode::query()->where('level', 'upazila')->count());
        $this->assertGreaterThanOrEqual(4540 + 328 + 12, PlaceCode::query()->where('level', 'union')->count());

        $this->assertDatabaseHas('place_codes', ['code' => '101', 'name' => 'Dhaka', 'level' => 'division']);
        $this->assertDatabaseHas('place_codes', ['code' => '102', 'name' => 'Rangpur', 'level' => 'division']);
        $this->assertDatabaseHas('place_codes', ['name' => 'Rangpur City Corporation', 'level' => 'union']);
        $this->assertDatabaseHas('place_codes', ['name' => 'Dhaka North City Corporation', 'level' => 'union']);
        $this->assertDatabaseHas('place_codes', ['name' => 'Dhaka South City Corporation', 'level' => 'union']);

        $rangpur = PlaceCode::query()->where('code', '102')->firstOrFail();
        $this->assertNotNull($rangpur->base_place_id);
        $district = PlaceCode::query()->whereKey($rangpur->base_place_id)->firstOrFail();
        $this->assertSame('district', $district->level);
        $this->assertSame('Rangpur', $district->name);
        $this->assertNotNull($district->base_place_id);
        $sadar = PlaceCode::query()->whereKey($district->base_place_id)->firstOrFail();
        $this->assertSame('Rangpur Sadar', $sadar->name);
        $corp = PlaceCode::query()->whereKey($sadar->base_place_id)->firstOrFail();
        $this->assertSame('Rangpur City Corporation', $corp->name);

        // Every non-management place has a parent; every upazila belongs to a district.
        $this->assertSame(
            0,
            PlaceCode::query()->where('level', '!=', 'management')->whereNull('parent_id')->count()
        );
        $this->assertSame(
            0,
            PlaceCode::query()
                ->where('level', 'upazila')
                ->whereDoesntHave('parent', fn ($q) => $q->where('level', 'district'))
                ->count()
        );
    }
}

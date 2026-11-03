<?php

namespace Tests\Feature;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AreaDealerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_area_dealers_page_lists_the_dealer_who_holds_each_place_code(): void
    {
        $admin = User::factory()->staff('super_admin')->create();
        $division = PlaceCode::factory()->create([
            'level' => PlaceCode::LevelDivision,
            'code' => 'MNHC-102',
            'name' => 'Rangpur',
        ]);
        $district = PlaceCode::factory()->district($division)->create([
            'code' => 'MNHC-301',
            'name' => 'Rangpur',
        ]);
        PlaceCode::factory()->upazila($district)->create([
            'code' => 'MNHC-401',
            'name' => 'Rangpur Sadar',
        ]);
        User::factory()->dealer()->create([
            'name' => 'Rangpur District Dealer',
            'status' => 'active',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'profile' => ['place_code' => 'MNHC-301'],
        ]);
        User::factory()->dealer()->create([
            'name' => 'Rangpur Sadar Dealer',
            'status' => 'active',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'profile' => ['place_code' => 'MNHC-401', 'upazila' => 'Rangpur Sadar'],
        ]);
        $other = User::factory()->dealer()->create([
            'name' => 'Unseated Person',
            'status' => 'active',
        ]);
        AreaDealerAssignment::query()->create([
            'user_id' => $other->id,
            'level' => 'district',
            'place_code_id' => $district->id,
            'zone_name' => 'Rangpur (MNHC-301)',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'monthly_quota' => '40000.00',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->get('/area-dealers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'area-dealers')
                ->where('data.assignments', function (mixed $rows): bool {
                    $seats = collect($rows);
                    $districtSeat = $seats->first(fn (array $row): bool => is_array($row['place_code'] ?? null) && ($row['place_code']['code'] ?? null) === 'MNHC-301');
                    $sadarSeat = $seats->first(fn (array $row): bool => is_array($row['place_code'] ?? null) && ($row['place_code']['code'] ?? null) === 'MNHC-401');
                    $unseated = $seats->first(fn (array $row): bool => ($row['user']['name'] ?? null) === 'Unseated Person');

                    return is_array($districtSeat)
                        && ($districtSeat['user']['name'] ?? null) === 'Rangpur District Dealer'
                        && ($districtSeat['level'] ?? null) === 'district'
                        && is_array($sadarSeat)
                        && ($sadarSeat['user']['name'] ?? null) === 'Rangpur Sadar Dealer'
                        && ($sadarSeat['level'] ?? null) === 'upazila'
                        && is_array($unseated)
                        && array_key_exists('place_code', $unseated)
                        && $unseated['place_code'] === null;
                }));
    }
}

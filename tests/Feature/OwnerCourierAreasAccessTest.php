<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The courier area matching page is open to every owner (Super Admin, MD, Chairman). The
 * staff-actions gate must not turn the MD or Chairman away when they save a match.
 */
class OwnerCourierAreasAccessTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $division = PlaceCode::factory()->division()->create();
        $this->district = PlaceCode::factory()->district($division)->create();
    }

    /** @return array<string, array{string}> */
    public static function owners(): array
    {
        return ['managing director' => ['md'], 'chairman' => ['chairman']];
    }

    #[DataProvider('owners')]
    public function test_an_owner_can_save_and_remove_a_courier_area_match(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/mappings', [
            'provider' => 'pathao', 'place_code_id' => $this->district->id, 'city_id' => '1', 'city_name' => 'Dhaka', 'zone_id' => '52', 'zone_name' => 'Mirpur',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('courier_area_mappings', ['provider' => 'pathao', 'place_code_id' => $this->district->id, 'city_id' => '1', 'zone_id' => '52']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/mappings/remove', [
            'provider' => 'pathao', 'place_code_id' => $this->district->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('courier_area_mappings', ['provider' => 'pathao', 'place_code_id' => $this->district->id]);
    }

    #[DataProvider('owners')]
    public function test_an_owner_is_told_to_add_courier_keys_before_fetching_areas(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        // Not a 403: the owner reaches the same plain reason the Super Admin gets.
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/courier-areas/fetch', ['provider' => 'pathao'])
            ->assertRedirect()->assertSessionHasErrors('provider');
    }

    public function test_finance_staff_still_cannot_save_a_match(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $status = $this->actingAs($finance)->onPortal('sadmin')->post('/commands/operations/courier-areas/mappings', [
            'provider' => 'pathao', 'place_code_id' => $this->district->id, 'city_id' => '1', 'zone_id' => '52',
        ])->getStatusCode();

        $this->assertContains($status, [403, 404]);
        $this->assertDatabaseCount('courier_area_mappings', 0);
    }
}

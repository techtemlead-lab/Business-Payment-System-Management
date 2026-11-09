<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A dealer's home place is one seat. Any other active seat on the account, from older data or
 * the area-dealers command, stays with the dealer when the home place changes.
 */
class MovingDealerKeepsOtherSeatsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClassicPlaceCodes();
        $this->admin = User::factory()->staff()->create();
        $home = $this->place('MNHC-101');
        $this->dealer = User::factory()->dealer()->create([
            'profile' => ['place_code' => $home->code, 'place_code_id' => $home->id],
        ]);
        $this->seat($home);
    }

    public function test_changing_the_home_place_leaves_the_other_seat_active(): void
    {
        $other = $this->place('MNHC-102');
        $this->seat($other);

        app(PartnerAccountProvisioning::class)->rebindGeography($this->admin, $this->dealer, 'MNHC-103', 'Office moved to a new division');

        $this->assertSame(
            ['MNHC-102', 'MNHC-103'],
            $this->activeSeatCodes(),
            'Only the home seat should move; the Rangpur seat is a separate one.'
        );
    }

    public function test_moving_home_onto_a_seat_the_dealer_already_holds_keeps_one_seat_there(): void
    {
        $other = $this->place('MNHC-102');
        $this->seat($other);

        app(PartnerAccountProvisioning::class)->rebindGeography($this->admin, $this->dealer, 'MNHC-102', 'Office moved to the second area');

        $this->assertSame(['MNHC-102'], $this->activeSeatCodes());
        $this->assertSame('MNHC-102', $this->dealer->fresh()->profile['place_code']);
    }

    public function test_a_dealer_with_one_seat_still_moves_that_seat(): void
    {
        app(PartnerAccountProvisioning::class)->rebindGeography($this->admin, $this->dealer, 'MNHC-103', 'Office moved to a new division');

        $this->assertSame(['MNHC-103'], $this->activeSeatCodes());
        $this->assertDatabaseHas('area_dealer_assignments', [
            'user_id' => $this->dealer->id,
            'place_code_id' => $this->place('MNHC-101')->id,
            'is_active' => false,
            'territory_key' => null,
        ]);
    }

    private function place(string $code): PlaceCode
    {
        return PlaceCode::query()->where('code', $code)->firstOrFail();
    }

    private function seat(PlaceCode $place): void
    {
        AreaDealerAssignment::create([
            'user_id' => $this->dealer->id,
            'level' => $place->level,
            'place_code_id' => $place->id,
            'zone_name' => $place->name,
            'division' => $place->name,
            'monthly_quota' => '50000.00',
            'territory_key' => hash('sha256', $place->level.'|code:'.$place->code),
            'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function activeSeatCodes(): array
    {
        return PlaceCode::query()
            ->whereIn('id', AreaDealerAssignment::query()->where('user_id', $this->dealer->id)->where('is_active', true)->pluck('place_code_id'))
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Geography\PlaceCodeCatalog;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Commission follows a partner's place code. Switching off a place that partners sit on would
 * quietly send their area shares and salary to the company, so it is refused like deleting it.
 */
class PlaceWithPartnersStaysActiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PlaceCode $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClassicPlaceCodes();
        $this->admin = User::factory()->staff()->create();
        $this->place = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
    }

    public function test_a_place_holding_an_active_dealer_seat_cannot_be_switched_off(): void
    {
        $dealer = User::factory()->dealer()->create();
        AreaDealerAssignment::create([
            'user_id' => $dealer->id, 'level' => $this->place->level, 'place_code_id' => $this->place->id, 'zone_name' => $this->place->name,
            'division' => $this->place->name, 'monthly_quota' => '50000.00', 'is_active' => true,
            'territory_key' => hash('sha256', $this->place->level.'|code:'.$this->place->code),
        ]);

        $this->assertRefused();
        $this->assertTrue($this->place->fresh()->is_active);
    }

    public function test_a_place_a_partner_belongs_to_cannot_be_switched_off(): void
    {
        User::factory()->subDealer()->create(['profile' => ['place_code' => $this->place->code, 'place_code_id' => $this->place->id]]);

        $this->assertRefused();
        $this->assertTrue($this->place->fresh()->is_active);
    }

    public function test_the_name_of_a_place_with_partners_can_still_be_changed(): void
    {
        User::factory()->subDealer()->create(['profile' => ['place_code' => $this->place->code, 'place_code_id' => $this->place->id]]);

        app(PlaceCodeCatalog::class)->update($this->place, ['name' => 'Rangpur Division', 'is_active' => true], $this->admin->id);

        $this->assertSame('Rangpur Division', $this->place->fresh()->name);
    }

    public function test_an_empty_place_can_be_switched_off(): void
    {
        app(PlaceCodeCatalog::class)->update($this->place, ['is_active' => false], $this->admin->id);

        $this->assertFalse($this->place->fresh()->is_active);
    }

    public function test_a_place_already_switched_off_can_be_edited(): void
    {
        User::factory()->subDealer()->create(['profile' => ['place_code' => $this->place->code, 'place_code_id' => $this->place->id]]);
        $this->place->forceFill(['is_active' => false])->save();

        app(PlaceCodeCatalog::class)->update($this->place->fresh(), ['name' => 'Old Rangpur', 'is_active' => false], $this->admin->id);

        $this->assertSame('Old Rangpur', $this->place->fresh()->name);
    }

    private function assertRefused(): void
    {
        try {
            app(PlaceCodeCatalog::class)->update($this->place, ['is_active' => false], $this->admin->id);
            $this->fail('Switching off a place with partners should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }
    }
}

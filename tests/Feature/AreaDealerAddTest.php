<?php

namespace Tests\Feature;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Adding an area dealer from the area dealers page: the seat shows on the list and every
 * rule of the existing assignment command still holds.
 */
class AreaDealerAddTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PlaceCode $division;

    private PlaceCode $place;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $this->place = PlaceCode::factory()->district($this->division)->create(['name' => 'Gazipur']);
        $home = PlaceCode::factory()->district($this->division)->create(['name' => 'Tangail']);
        $this->dealer = User::factory()->dealer()->inPlace($home)->create(['status' => 'active']);
    }

    /** @param array<string, mixed> $changes */
    private function add(array $changes = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->owner)->onPortal('sadmin')->post('/commands/area-dealers', [
            'user_id' => $this->dealer->id,
            'level' => 'district',
            'place_code_id' => $this->place->id,
            'monthly_quota' => '12345.50',
            ...$changes,
        ]);
    }

    public function test_the_page_offers_active_dealers_and_only_free_places(): void
    {
        $taken = PlaceCode::factory()->district($this->division)->create(['name' => 'Narsingdi']);
        AreaDealerAssignment::create(['user_id' => $this->dealer->id, 'level' => 'district', 'place_code_id' => $taken->id, 'zone_name' => 'Narsingdi', 'division' => 'Dhaka', 'monthly_quota' => '100', 'is_active' => true]);
        $closed = User::factory()->dealer()->create(['status' => 'suspended']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/area-dealers')->assertInertia(fn (Assert $page) => $page
            ->where('data.dealers_list', fn ($dealers): bool => collect($dealers)->pluck('id')->contains($this->dealer->id) && ! collect($dealers)->pluck('id')->contains($closed->id))
            ->where('data.place_codes', fn ($places): bool => collect($places)->pluck('id')->contains($this->place->id) && ! collect($places)->pluck('id')->contains($taken->id)));
    }

    public function test_an_added_area_dealer_is_saved_and_shows_on_the_list_with_their_home_place(): void
    {
        $this->add()->assertSessionHasNoErrors();

        $this->assertStringContainsString('Gazipur', (string) session('success'));
        $this->assertDatabaseHas('area_dealer_assignments', ['user_id' => $this->dealer->id, 'place_code_id' => $this->place->id, 'level' => 'district', 'is_active' => true, 'monthly_quota' => '12345.50']);
        $this->assertDatabaseHas('audit_events', ['action' => 'area_dealer.assigned', 'subject' => (string) $this->dealer->id, 'actor_id' => $this->owner->id]);

        $this->onPortal('sadmin')->get('/area-dealers')->assertInertia(fn (Assert $page) => $page
            ->where('data.assignments.data', function ($rows): bool {
                $own = collect($rows)->where('user_id', $this->dealer->id)->values();

                return $own->count() === 2
                    && $own[0]['id'] === $this->dealer->id
                    && str_starts_with((string) $own[1]['id'], 'seat-')
                    && $own[1]['place_code']['code'] === $this->place->code
                    && $own[1]['monthly_quota'] === '12345.50';
            }));
    }

    public function test_one_area_has_only_one_active_dealer(): void
    {
        AreaDealerAssignment::create(['user_id' => User::factory()->dealer()->create(['status' => 'active'])->id, 'level' => 'district', 'place_code_id' => $this->place->id, 'zone_name' => 'Gazipur', 'division' => 'Dhaka', 'monthly_quota' => '100', 'is_active' => true]);

        $this->add()->assertSessionHasErrors(['place_code_id' => __('portal.cmd_val_territory_taken')]);

        $this->assertDatabaseMissing('area_dealer_assignments', ['user_id' => $this->dealer->id, 'place_code_id' => $this->place->id]);
    }

    public function test_only_an_active_dealer_can_hold_an_area(): void
    {
        $subDealer = User::factory()->subDealer()->create(['status' => 'active']);
        $suspended = User::factory()->dealer()->create(['status' => 'suspended']);

        $this->add(['user_id' => $subDealer->id])->assertSessionHasErrors(['user_id' => __('portal.cmd_val_active_dealer')]);
        $this->add(['user_id' => $suspended->id])->assertSessionHasErrors(['user_id' => __('portal.cmd_val_active_dealer')]);

        $this->assertDatabaseCount('area_dealer_assignments', 0);
    }

    public function test_missing_or_mismatched_details_are_refused(): void
    {
        $this->add(['user_id' => ''])->assertSessionHasErrors('user_id');
        $this->add(['user_id' => 'not-a-number'])->assertSessionHasErrors('user_id');
        $this->add(['place_code_id' => ''])->assertSessionHasErrors('place_code_id');
        $this->add(['monthly_quota' => ''])->assertSessionHasErrors('monthly_quota');
        $this->add(['monthly_quota' => '-1'])->assertSessionHasErrors('monthly_quota');
        $this->add(['level' => 'division'])->assertSessionHasErrors(['place_code_id' => __('portal.cmd_val_place_level_mismatch')]);

        $this->assertDatabaseCount('area_dealer_assignments', 0);
    }

    public function test_staff_without_the_accounts_edit_right_are_refused(): void
    {
        $viewer = User::factory()->staff('employee')->create([
            'profile' => ['permissions' => ['accounts'], 'action_permissions' => ['accounts' => ['view' => true]]],
        ]);

        $this->actingAs($viewer)->onPortal('manage')->post('/commands/area-dealers', [
            'user_id' => $this->dealer->id,
            'level' => 'district',
            'place_code_id' => $this->place->id,
            'monthly_quota' => '100',
        ])->assertForbidden();

        $this->assertDatabaseCount('area_dealer_assignments', 0);
    }

    public function test_a_view_only_person_is_not_sent_the_pick_lists(): void
    {
        $viewer = User::factory()->staff('employee')->create([
            'profile' => ['permissions' => ['accounts'], 'action_permissions' => ['accounts' => ['view' => true]]],
        ]);

        $this->actingAs($viewer)->onPortal('manage')->get('/area-dealers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.dealers_list', [])
            ->where('data.place_codes', []));
    }
}

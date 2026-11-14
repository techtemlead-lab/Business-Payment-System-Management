<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDealerDirectoryLevelsTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $rangpur;

    private PlaceCode $rangpurDistrict;

    private PlaceCode $pirganj;

    private PlaceCode $pirganjUnion;

    private PlaceCode $badarganj;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->rangpurDistrict = PlaceCode::factory()->district($this->rangpur)->create(['name' => 'Rangpur District']);
        $this->pirganj = PlaceCode::factory()->upazila($this->rangpurDistrict)->create(['name' => 'Pirganj']);
        $this->pirganjUnion = PlaceCode::factory()->union($this->pirganj)->create(['name' => 'Chatra']);
        $this->badarganj = PlaceCode::factory()->upazila($this->rangpurDistrict)->create(['name' => 'Badarganj']);
    }

    public function test_dealers_and_sub_dealers_are_both_listed_with_their_own_badge(): void
    {
        User::factory()->dealer()->inPlace($this->pirganj)->create(['name' => 'Pirganj Dealer', 'directory_visible' => true]);
        User::factory()->subDealer()->inPlace($this->pirganjUnion)->create(['name' => 'Chatra Sub Dealer', 'directory_visible' => true]);
        User::factory()->subDealer()->inPlace($this->pirganj)->create(['name' => 'Private Sub Dealer', 'directory_visible' => false]);

        $this->get('/dealers')->assertOk()
            ->assertSee('Pirganj Dealer')->assertSee('Chatra Sub Dealer')->assertDontSee('Private Sub Dealer')
            ->assertSee(__('site.directory_approved_badge'))->assertSee(__('site.directory_approved_subdealer_badge'));
    }

    public function test_upazila_and_union_filters_narrow_the_list_by_place_code(): void
    {
        User::factory()->dealer()->inPlace($this->rangpur)->create(['name' => 'Division Dealer', 'directory_visible' => true]);
        User::factory()->dealer()->inPlace($this->rangpurDistrict)->create(['name' => 'District Dealer', 'directory_visible' => true]);
        User::factory()->dealer()->inPlace($this->pirganj)->create(['name' => 'Upazila Dealer', 'directory_visible' => true]);
        User::factory()->subDealer()->inPlace($this->pirganjUnion)->create(['name' => 'Union Sub Dealer', 'directory_visible' => true]);
        User::factory()->dealer()->inPlace($this->badarganj)->create(['name' => 'Other Upazila Dealer', 'directory_visible' => true]);

        $this->get('/dealers?division='.$this->rangpur->id)->assertOk()
            ->assertSee('Division Dealer')->assertSee('District Dealer')->assertSee('Upazila Dealer')->assertSee('Union Sub Dealer')->assertSee('Other Upazila Dealer');
        $this->get('/dealers?upazila='.$this->pirganj->id)->assertOk()
            ->assertSee('Upazila Dealer')->assertSee('Union Sub Dealer')
            ->assertDontSee('Other Upazila Dealer')->assertDontSee('District Dealer')->assertDontSee('Division Dealer');
        $this->get('/dealers?union='.$this->pirganjUnion->id)->assertOk()
            ->assertSee('Union Sub Dealer')->assertDontSee('Upazila Dealer')->assertDontSee('Other Upazila Dealer');
        $this->get('/dealers?union=Chatra')->assertOk()->assertDontSee('Union Sub Dealer');
    }

    public function test_pickers_for_every_level_list_places_and_follow_the_chosen_parent(): void
    {
        $khulna = PlaceCode::factory()->division()->create(['name' => 'Khulna']);
        $khulnaUpazila = PlaceCode::factory()->upazila(PlaceCode::factory()->district($khulna)->create(['name' => 'Khulna District']))->create(['name' => 'Dumuria']);
        User::factory()->dealer()->inPlace($this->pirganjUnion)->create(['name' => 'Rangpur Union Dealer', 'directory_visible' => true]);
        User::factory()->dealer()->inPlace($khulnaUpazila)->create(['name' => 'Khulna Upazila Dealer', 'directory_visible' => true]);

        $all = $this->get('/dealers')->assertOk();
        foreach ([$this->rangpur, $this->rangpurDistrict, $this->pirganj, $this->pirganjUnion, $khulna, $khulnaUpazila] as $place) {
            $all->assertSee('<option value="'.$place->id.'"', false);
        }
        $all->assertSee('Chatra (Pirganj)')->assertDontSee('Badarganj');

        $narrow = $this->get('/dealers?division='.$this->rangpur->id)->assertOk();
        $narrow->assertSee('<option value="'.$this->pirganj->id.'"', false)->assertDontSee('<option value="'.$khulnaUpazila->id.'"', false);
        $narrow->assertSee('>Rangpur District</option>', false);
    }

    public function test_a_leftover_lower_pick_that_is_outside_the_new_parent_is_dropped(): void
    {
        $khulna = PlaceCode::factory()->division()->create(['name' => 'Khulna']);
        User::factory()->dealer()->inPlace($this->pirganj)->create(['name' => 'Pirganj Dealer', 'directory_visible' => true]);
        User::factory()->dealer()->inPlace(PlaceCode::factory()->district($khulna)->create(['name' => 'Khulna District']))->create(['name' => 'Khulna Dealer', 'directory_visible' => true]);

        $this->get('/dealers?division='.$khulna->id.'&upazila='.$this->pirganj->id)->assertOk()
            ->assertSee('Khulna Dealer')->assertDontSee('Pirganj Dealer');
    }
}

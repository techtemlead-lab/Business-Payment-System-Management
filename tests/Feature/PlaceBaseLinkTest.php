<?php

namespace Tests\Feature;

use App\Domain\Geography\PlaceGeographyResolver;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The base place link on the Area codes page when places in the chain have no
 * code yet, as on the live Rangpur division > district > Sadar > City Corporation.
 */
class PlaceBaseLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PlaceCode $management;

    private PlaceCode $division;

    private PlaceCode $district;

    private PlaceCode $upazila;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'super_admin']);

        $this->management = PlaceCode::factory()->management()->create();
        $this->division = PlaceCode::factory()->division($this->management)->create(['code' => null, 'name' => 'Rangpur']);
        $this->district = PlaceCode::factory()->district($this->division)->create(['code' => null, 'name' => 'Rangpur Zila']);
        $this->upazila = PlaceCode::factory()->upazila($this->district)->create(['code' => null, 'name' => 'Rangpur Sadar']);
        $this->union = PlaceCode::factory()->union($this->upazila)->create(['code' => null, 'name' => 'Rangpur City Corporation']);

        $this->division->update(['base_place_id' => $this->district->id]);
        $this->district->update(['base_place_id' => $this->upazila->id]);
        $this->upazila->update(['base_place_id' => $this->union->id]);
    }

    /**
     * Send the edit form the way the Area codes page sends it.
     *
     * @param  array<string, mixed>  $changes
     */
    private function saveEditForm(PlaceCode $place, array $changes = [], ?User $actor = null): TestResponse
    {
        $place->refresh();

        return $this->actingAs($actor ?? $this->owner)->onPortal('sadmin')->from('/place-codes?level='.$place->level)
            ->patch('/commands/place-codes/'.$place->id, [
                'code' => $place->code ?? '',
                'name' => $place->name,
                'parent_id' => $place->parent_id,
                'base_place_id' => $place->base_place_id,
                'sort_order' => $place->sort_order,
                'is_active' => true,
                ...$changes,
            ]);
    }

    public function test_upper_place_can_get_a_code_while_its_saved_base_has_no_code_yet(): void
    {
        $this->saveEditForm($this->division, ['code' => '102'])
            ->assertRedirect('/place-codes?level=division')
            ->assertSessionHasNoErrors();

        $this->division->refresh();
        $this->assertSame('MNHC-102', $this->division->code);
        $this->assertSame('Rangpur', $this->division->name);
        $this->assertSame($this->district->id, $this->division->base_place_id);
    }

    public function test_saved_base_is_kept_when_a_save_does_not_send_it(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->patch('/commands/place-codes/'.$this->division->id, ['code' => '102', 'name' => 'Rangpur'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->district->id, $this->division->fresh()->base_place_id);
    }

    public function test_saved_base_that_was_later_turned_off_does_not_block_a_save(): void
    {
        $this->district->update(['code' => 'MNHC-301', 'is_active' => false]);

        $this->saveEditForm($this->division, ['code' => '102'])->assertSessionHasNoErrors();

        $this->assertSame('MNHC-102', $this->division->fresh()->code);
        $this->assertSame($this->district->id, $this->division->fresh()->base_place_id);
    }

    public function test_newly_chosen_base_with_no_code_is_refused_and_names_that_place(): void
    {
        $other = PlaceCode::factory()->district($this->division)->create(['code' => null, 'name' => 'Dinajpur']);

        $this->saveEditForm($this->division, ['base_place_id' => $other->id])
            ->assertRedirect('/place-codes?level=division')
            ->assertSessionHasErrors([
                'base_place_id' => 'Dinajpur (District / Zila) has no code yet. Give that place a code first, then choose it as the base place.',
            ]);

        $this->assertSame($this->district->id, $this->division->fresh()->base_place_id);
    }

    public function test_refusal_is_in_plain_bangla_for_a_bangla_owner(): void
    {
        $other = PlaceCode::factory()->district($this->division)->create(['code' => null, 'name' => 'Dinajpur']);
        $banglaOwner = User::factory()->create(['role' => 'super_admin', 'locale' => 'bn']);

        $this->saveEditForm($this->division, ['base_place_id' => $other->id], $banglaOwner)
            ->assertSessionHasErrors([
                'base_place_id' => 'Dinajpur (জেলা) জায়গাটির এখনো কোনো কোড নেই। আগে ওই জায়গায় একটি কোড দিন, তারপর সেটি বেস প্লেস হিসেবে বেছে নিন।',
            ]);
    }

    public function test_each_other_reason_for_refusing_a_new_base_is_stated(): void
    {
        $turnedOff = PlaceCode::factory()->district($this->division)->create(['code' => 'MNHC-302', 'name' => 'Kurigram', 'is_active' => false]);
        $otherDivision = PlaceCode::factory()->division($this->management)->create(['code' => 'MNHC-103', 'name' => 'Sylhet']);
        $elsewhere = PlaceCode::factory()->district($otherDivision)->create(['code' => 'MNHC-303', 'name' => 'Sunamganj']);

        $this->saveEditForm($this->division, ['base_place_id' => $turnedOff->id])
            ->assertSessionHasErrors(['base_place_id' => 'Kurigram (MNHC-302) is not active. Mark that place Active first, then choose it as the base place.']);

        $this->saveEditForm($this->division, ['base_place_id' => $elsewhere->id])
            ->assertSessionHasErrors(['base_place_id' => 'Sunamganj (MNHC-303) is not directly under this place. Choose a place one level below this one.']);

        $this->saveEditForm($this->division, ['base_place_id' => $this->upazila->id])
            ->assertSessionHasErrors(['base_place_id' => 'Rangpur Sadar (Upazila) is not directly under this place. Choose a place one level below this one.']);

        $this->assertSame($this->district->id, $this->division->fresh()->base_place_id);
    }

    public function test_saved_base_with_no_code_can_be_cleared_and_the_place_keeps_its_name(): void
    {
        $this->saveEditForm($this->division, ['base_place_id' => null])->assertSessionHasNoErrors();

        $this->division->refresh();
        $this->assertNull($this->division->base_place_id);
        $this->assertNull($this->division->code);
        $this->assertSame('Rangpur', $this->division->name);
    }

    public function test_taking_away_a_code_still_blanks_the_place(): void
    {
        $this->division->update(['code' => 'MNHC-102']);

        $this->saveEditForm($this->division, ['code' => ''])->assertSessionHasNoErrors();

        $this->division->refresh();
        $this->assertNull($this->division->code);
        $this->assertSame(PlaceCode::PlaceholderLabel, $this->division->name);
    }

    public function test_edit_form_lists_the_saved_base_and_says_it_has_no_code(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/place-codes?level=division')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.places.data.0.base_place_id', $this->district->id)
                ->where('data.places.data.0.base_options', [[
                    'id' => $this->district->id,
                    'code' => PlaceCode::PlaceholderLabel,
                    'name' => 'Rangpur Zila',
                    'level' => PlaceCode::LevelDistrict,
                    'note' => 'no code yet',
                ]]));
    }

    public function test_edit_form_note_is_in_bangla_for_a_bangla_owner(): void
    {
        $banglaOwner = User::factory()->create(['role' => 'super_admin', 'locale' => 'bn']);

        $this->actingAs($banglaOwner)->onPortal('sadmin')->get('/place-codes?level=division')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.places.data.0.base_options.0.note', 'এখনো কোড নেই'));
    }

    public function test_edit_form_lists_a_base_with_a_code_without_any_note(): void
    {
        $this->district->update(['code' => 'MNHC-301']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/place-codes?level=division')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.places.data.0.base_options', [[
                    'id' => $this->district->id,
                    'code' => 'MNHC-301',
                    'name' => 'Rangpur Zila',
                    'level' => PlaceCode::LevelDistrict,
                ]]));
    }

    public function test_coding_from_the_bottom_up_still_links_the_whole_chain(): void
    {
        PlaceCode::query()->update(['base_place_id' => null]);

        $this->saveEditForm($this->union, ['code' => '501'])->assertSessionHasNoErrors();
        $this->saveEditForm($this->upazila, ['code' => '401', 'base_place_id' => $this->union->id])->assertSessionHasNoErrors();
        $this->saveEditForm($this->district, ['code' => '301', 'base_place_id' => $this->upazila->id])->assertSessionHasNoErrors();
        $this->saveEditForm($this->division, ['code' => '102', 'base_place_id' => $this->district->id])->assertSessionHasNoErrors();

        $geography = app(PlaceGeographyResolver::class)->expandFromAnchor($this->division->fresh());

        $this->assertSame('MNHC-102', $geography['codes']['division']);
        $this->assertSame('MNHC-301', $geography['codes']['district']);
        $this->assertSame('MNHC-401', $geography['codes']['upazila']);
        $this->assertSame('MNHC-501', $geography['codes']['union']);
    }

    public function test_geography_skips_a_base_place_with_no_code(): void
    {
        $this->division->update(['code' => 'MNHC-102']);

        $geography = app(PlaceGeographyResolver::class)->expandFromAnchor($this->division->fresh());

        $this->assertSame('MNHC-102', $geography['codes']['division']);
        $this->assertSame($this->division->id, $geography['place_ids']['division']);
        foreach (['district', 'upazila', 'union'] as $level) {
            $this->assertNull($geography[$level], $level);
            $this->assertNull($geography['codes'][$level], $level);
            $this->assertNull($geography['place_ids'][$level], $level);
        }
    }

    public function test_geography_carries_on_to_a_coded_place_below_one_with_no_code(): void
    {
        $this->division->update(['code' => 'MNHC-102']);
        $this->upazila->update(['code' => 'MNHC-401']);

        $geography = app(PlaceGeographyResolver::class)->expandFromAnchor($this->division->fresh());

        $this->assertNull($geography['place_ids']['district']);
        $this->assertSame('MNHC-401', $geography['codes']['upazila']);
        $this->assertSame($this->upazila->id, $geography['place_ids']['upazila']);
        $this->assertNull($geography['place_ids']['union']);
    }

    public function test_one_buyer_and_many_buyers_get_the_same_geography_past_a_base_with_no_code(): void
    {
        $this->division->update(['code' => 'MNHC-102']);
        $this->upazila->update(['code' => 'MNHC-401']);
        $buyer = User::factory()->dealer()->create(['profile' => ['place_code_id' => $this->division->id]]);
        $resolver = app(PlaceGeographyResolver::class);

        $single = $resolver->forBuyer($buyer);

        $this->assertNull($single['place_ids']['district']);
        $this->assertSame($single, $resolver->forBuyers(collect([$buyer]))[$buyer->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Geography\PlaceGeographyResolver;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Client, 2026-11-22: every partner picks the four area places one by one (division, district,
 * upazila / thana, union). The kind of dealer decides which of them gives the dealer code, and the
 * area commission of the partner's own purchases follows the picked places instead of the base
 * places. Older accounts without the picks keep the base places.
 */
class PartnerAreaChainTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $rangpur;

    private PlaceCode $district;

    private PlaceCode $otherDistrict;

    private PlaceCode $baseUpazila;

    private PlaceCode $baseUnion;

    private PlaceCode $upazila;

    private PlaceCode $upazilaBaseUnion;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seedClassicPlaceCodes();

        $this->rangpur = PlaceCode::query()->where('code', 'MNHC102')->firstOrFail();
        $this->district = PlaceCode::factory()->district($this->rangpur)->create(['code' => 'MNHC302', 'name' => 'Rangpur District']);
        $this->otherDistrict = PlaceCode::factory()->district($this->rangpur)->create(['code' => 'MNHC303', 'name' => 'Gaibandha']);
        $this->baseUpazila = PlaceCode::factory()->upazila($this->district)->create(['code' => 'MNHC402', 'name' => 'Pirganj']);
        $this->baseUnion = PlaceCode::factory()->union($this->baseUpazila)->create(['code' => 'MNHC502', 'name' => 'Kabilpur']);
        $this->upazila = PlaceCode::factory()->upazila($this->district)->create(['code' => 'MNHC403', 'name' => 'Mithapukur']);
        $this->upazilaBaseUnion = PlaceCode::factory()->union($this->upazila)->create(['code' => 'MNHC503', 'name' => 'Balarhat']);
        $this->union = PlaceCode::factory()->union($this->upazila)->create(['code' => 'MNHC504', 'name' => 'Ranipukur']);
        $this->rangpur->forceFill(['base_place_id' => $this->district->id])->save();
        $this->district->forceFill(['base_place_id' => $this->baseUpazila->id])->save();
        $this->baseUpazila->forceFill(['base_place_id' => $this->baseUnion->id])->save();
        $this->upazila->forceFill(['base_place_id' => $this->upazilaBaseUnion->id])->save();
    }

    /** @return array<string, array{string}> */
    public static function areaLevels(): array
    {
        return ['division' => ['division'], 'district' => ['district'], 'upazila' => ['upazila'], 'union' => ['union']];
    }

    #[DataProvider('areaLevels')]
    public function test_each_area_place_is_required_on_the_add_partner_form(string $level): void
    {
        $chain = $this->chainFields();
        unset($chain['area_'.$level.'_id']);

        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => 'district', 'place_code' => 'MNHC302', ...$chain]))
            ->assertSessionHasErrors(['area_'.$level.'_id' => __('portal.acct_val_area_'.$level.'_required')]);

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
    }

    public function test_a_sub_dealer_must_pick_the_four_places_too(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload(['role' => 'sub_dealer', 'place_code' => 'MNHC504', 'area_union_id' => '']))
            ->assertSessionHasErrors(['area_division_id', 'area_district_id', 'area_upazila_id', 'area_union_id']);
    }

    public function test_a_place_outside_the_one_above_it_is_refused(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => 'district', 'place_code' => 'MNHC302', ...$this->chainFields(['union' => $this->baseUnion])]))
            ->assertSessionHasErrors(['area_union_id' => __('portal.acct_val_area_chain_broken')]);

        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    public function test_a_switched_off_a_code_less_or_wrong_level_place_is_refused(): void
    {
        $this->union->forceFill(['is_active' => false])->save();
        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => 'district', 'place_code' => 'MNHC302', ...$this->chainFields()]))
            ->assertSessionHasErrors(['area_union_id' => __('portal.acct_val_area_place_unknown')]);

        $codeLess = PlaceCode::query()->create(['level' => PlaceCode::LevelUnion, 'code' => null, 'name' => 'No code yet', 'parent_id' => $this->upazila->id, 'sort_order' => 0, 'is_active' => true]);
        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => 'district', 'place_code' => 'MNHC302', ...$this->chainFields(['union' => $codeLess])]))
            ->assertSessionHasErrors(['area_union_id' => __('portal.acct_val_area_place_unknown')]);

        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => 'district', 'place_code' => 'MNHC302', ...$this->chainFields(), 'area_upazila_id' => $this->district->id]))
            ->assertSessionHasErrors(['area_upazila_id' => __('portal.acct_val_area_place_unknown')]);

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
    }

    public function test_the_place_of_the_kind_of_dealer_must_be_the_dealer_code_place(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => 'upazila', 'place_code' => 'MNHC402', ...$this->chainFields()]))
            ->assertSessionHasErrors(['place_code' => __('portal.acct_val_area_seat_mismatch')]);

        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function dealerKinds(): array
    {
        return ['division dealer' => ['division'], 'district dealer' => ['district'], 'upazila dealer' => ['upazila'], 'union dealer' => ['union']];
    }

    #[DataProvider('dealerKinds')]
    public function test_each_kind_of_dealer_gets_the_code_of_its_level_and_its_purchases_follow_the_picks(string $kind): void
    {
        $chain = ['division' => $this->rangpur, 'district' => $this->district, 'upazila' => $this->upazila, 'union' => $this->union];

        $this->asOwner()->post('/commands/accounts', $this->payload(['dealer_level' => $kind, 'place_code' => $chain[$kind]->code, ...$this->chainFields()]))
            ->assertRedirect('/accounts')->assertSessionHasNoErrors();

        $dealer = $this->created();
        $this->assertSame($chain[$kind]->code, $dealer->dealer_code);
        $this->assertSame($chain[$kind]->id, AreaDealerAssignment::query()->where('user_id', $dealer->id)->sole()->place_code_id);
        $this->assertSame(['id' => $this->union->id, 'code' => 'MNHC504', 'name' => 'Ranipukur'], $dealer->profile['area_chain']['union']);
        $this->assertSame('Mithapukur', $dealer->profile['upazila']);
        $this->assertSame('Ranipukur', $dealer->profile['union']);

        $geography = app(PlaceGeographyResolver::class)->forBuyer($dealer);
        $this->assertSame('area_chain', $geography['resolution']);
        unset($geography['place_ids']['management']);
        $this->assertSame([
            'division' => $this->rangpur->id,
            'district' => $this->district->id,
            'upazila' => $this->upazila->id,
            'union' => $this->union->id,
        ], $geography['place_ids']);
    }

    public function test_a_sub_dealers_purchases_follow_the_picked_places(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload(['role' => 'sub_dealer', 'place_code' => 'MNHC504', ...$this->chainFields()]))
            ->assertRedirect('/sub-dealers')->assertSessionHasNoErrors();

        $geography = app(PlaceGeographyResolver::class)->forBuyer($this->created());
        $this->assertSame($this->union->id, $geography['place_ids']['union']);
        $this->assertSame($this->upazila->id, $geography['place_ids']['upazila']);
    }

    public function test_an_older_dealer_without_picks_keeps_the_base_places(): void
    {
        $dealer = $this->seatedDealer($this->district);

        $geography = app(PlaceGeographyResolver::class)->forBuyer($dealer);

        $this->assertSame('place_code', $geography['resolution']);
        $this->assertSame($this->baseUpazila->id, $geography['place_ids']['upazila']);
        $this->assertSame($this->baseUnion->id, $geography['place_ids']['union']);
    }

    public function test_a_switched_off_pick_falls_back_level_by_level(): void
    {
        $dealer = $this->seatedDealer($this->district, $this->chainOf($this->rangpur, $this->district, $this->upazila, $this->union));
        $resolver = app(PlaceGeographyResolver::class);

        $this->union->forceFill(['is_active' => false])->save();
        $geography = $resolver->forBuyer($dealer->fresh());
        // The picked upazila still counts; below it the upazila's own base union is used.
        $this->assertSame('area_chain', $geography['resolution']);
        $this->assertSame($this->upazila->id, $geography['place_ids']['upazila']);
        $this->assertSame($this->upazilaBaseUnion->id, $geography['place_ids']['union']);

        $this->upazila->forceFill(['is_active' => false])->save();
        $geography = $resolver->forBuyer($dealer->fresh());
        // Below the seat nothing picked is left: back to the seat's base places.
        $this->assertSame($this->baseUpazila->id, $geography['place_ids']['upazila']);
        $this->assertSame($this->baseUnion->id, $geography['place_ids']['union']);
    }

    public function test_picks_left_outside_the_seat_are_never_used(): void
    {
        $farUpazila = PlaceCode::factory()->upazila($this->otherDistrict)->create(['code' => 'MNHC405']);
        $farUnion = PlaceCode::factory()->union($farUpazila)->create(['code' => 'MNHC506']);
        $dealer = $this->seatedDealer($this->district, $this->chainOf($this->rangpur, $this->otherDistrict, $farUpazila, $farUnion));

        $geography = app(PlaceGeographyResolver::class)->forBuyer($dealer);

        $this->assertSame('place_code', $geography['resolution']);
        $this->assertSame($this->district->id, $geography['place_ids']['district']);
        $this->assertSame($this->baseUnion->id, $geography['place_ids']['union']);
    }

    public function test_many_buyers_at_once_get_what_one_by_one_gives(): void
    {
        $inactiveUnion = PlaceCode::factory()->union($this->upazila)->create(['code' => 'MNHC507', 'is_active' => false]);
        $buyers = collect([
            $this->seatedDealer($this->district, $this->chainOf($this->rangpur, $this->district, $this->upazila, $this->union)),
            $this->seatedDealer($this->rangpur, $this->chainOf($this->rangpur, $this->district, $this->upazila, $inactiveUnion)),
            $this->seatedDealer($this->baseUpazila),
            $this->seatedDealer($this->otherDistrict, $this->chainOf($this->rangpur, $this->district, $this->upazila, $this->union)),
            User::factory()->create(['role' => 'sub_dealer', 'profile' => ['place_code_id' => $this->union->id, 'area_chain' => $this->chainOf($this->rangpur, $this->district, $this->upazila, $this->union)]]),
            User::factory()->create(),
        ]);
        $resolver = app(PlaceGeographyResolver::class);

        $batch = $resolver->forBuyers(User::query()->whereIn('id', $buyers->pluck('id'))->get());

        foreach ($buyers as $buyer) {
            $this->assertSame($resolver->forBuyer($buyer->fresh()), $batch[$buyer->id], 'Buyer '.$buyer->id);
        }
        $this->assertSame($this->union->id, $batch[$buyers[0]->id]['place_ids']['union']);
        $this->assertSame($this->upazilaBaseUnion->id, $batch[$buyers[1]->id]['place_ids']['union']);
        $this->assertSame('place_code', $batch[$buyers[3]->id]['resolution']);
        $this->assertSame('unplaced', $batch[$buyers[5]->id]['resolution']);
    }

    public function test_many_buyers_with_picks_run_a_fixed_number_of_queries(): void
    {
        // Each dealer has his own union, so a query per buyer would show in the count.
        $make = fn (int $count) => collect(range(1, $count))->map(fn (): User => $this->seatedDealer(
            $this->district,
            $this->chainOf($this->rangpur, $this->district, $this->upazila, PlaceCode::factory()->union($this->upazila)->create()),
            false,
        ));
        $resolver = app(PlaceGeographyResolver::class);

        $few = $make(2);
        $fewQueries = $this->countQueries(fn () => $resolver->forBuyers(User::query()->whereIn('id', $few->pluck('id'))->get()));
        $many = $few->merge($make(6));
        $manyQueries = $this->countQueries(fn () => $resolver->forBuyers(User::query()->whereIn('id', $many->pluck('id'))->get()));

        $this->assertSame($fewQueries, $manyQueries);
    }

    public function test_the_office_changes_the_picks_in_the_account_editor_and_it_is_audited(): void
    {
        $dealer = $this->seatedDealer($this->district);

        $this->asOwner()->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [...$this->chainFields(), 'reason' => 'Own shop is in Ranipukur'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->union->id, $dealer->fresh()->profile['area_chain']['union']['id']);
        $this->assertSame($this->union->id, app(PlaceGeographyResolver::class)->forBuyer($dealer->fresh())['place_ids']['union']);
        $audit = DB::table('audit_events')->where('action', 'account.area_chain_updated')->where('subject', (string) $dealer->id)->sole();
        $payload = json_decode((string) $audit->metadata, true);
        $this->assertNull($payload['before']);
        $this->assertSame('MNHC504', $payload['after']['union']['code']);

        $this->asOwner()->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [...$this->chainFields(['upazila' => $this->baseUpazila, 'union' => $this->baseUnion]), 'reason' => 'Moved shop'])
            ->assertSessionHasNoErrors();
        $second = DB::table('audit_events')->where('action', 'account.area_chain_updated')->where('subject', (string) $dealer->id)->orderByDesc('id')->first();
        $this->assertSame('MNHC504', json_decode((string) $second->metadata, true)['before']['union']['code']);
    }

    public function test_the_editor_wants_all_four_places_inside_the_seat(): void
    {
        $dealer = $this->seatedDealer($this->district);

        $this->asOwner()->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, ['area_division_id' => $this->rangpur->id, 'reason' => 'Half'])
            ->assertSessionHasErrors(['area_district_id' => __('portal.acct_val_area_district_required')]);
        $farUpazila = PlaceCode::factory()->upazila($this->otherDistrict)->create(['code' => 'MNHC405']);
        $farUnion = PlaceCode::factory()->union($farUpazila)->create(['code' => 'MNHC506']);
        $this->asOwner()->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [...$this->chainFields(['district' => $this->otherDistrict, 'upazila' => $farUpazila, 'union' => $farUnion]), 'reason' => 'Elsewhere'])
            ->assertSessionHasErrors(['place_code' => __('portal.acct_val_area_seat_mismatch')]);

        $this->asOwner()->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, ['name' => 'Renamed', 'reason' => 'Name only'])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('area_chain', $dealer->fresh()->profile);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'account.area_chain_updated')->count());
    }

    public function test_the_office_partner_page_shows_where_the_area_commission_goes(): void
    {
        $picked = $this->seatedDealer($this->district, $this->chainOf($this->rangpur, $this->district, $this->upazila, $this->union));
        $older = $this->seatedDealer($this->otherDistrict);

        $this->asOwner()->onPortal('sadmin')->get('/accounts/'.$picked->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PartnerProfile')
            ->where('sheet.commission_area.source', 'chosen')
            ->where('sheet.commission_area.levels.upazila', ['name' => 'Mithapukur', 'code' => 'MNHC403'])
            ->where('sheet.commission_area.levels.union', ['name' => 'Ranipukur', 'code' => 'MNHC504']));
        $this->asOwner()->onPortal('sadmin')->get('/accounts/'.$older->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.commission_area.source', 'default')
            ->where('sheet.commission_area.levels.district', ['name' => 'Gaibandha', 'code' => 'MNHC303']));
    }

    public function test_the_form_and_the_editor_have_the_four_area_lists(): void
    {
        foreach (['js/pages/AccountCreate.tsx', 'js/components/AccountEditor.tsx'] as $file) {
            $source = (string) file_get_contents(resource_path($file));
            $this->assertStringContainsString('area_union_id', $source, $file);
            $this->assertStringNotContainsString('ঐচ্ছিক', $source, $file);
        }
    }

    /**
     * @param  array<string, PlaceCode>  $swap
     * @return array<string, int>
     */
    private function chainFields(array $swap = []): array
    {
        $places = [...['division' => $this->rangpur, 'district' => $this->district, 'upazila' => $this->upazila, 'union' => $this->union], ...$swap];

        return collect($places)->mapWithKeys(fn (PlaceCode $place, string $level): array => ['area_'.$level.'_id' => $place->id])->all();
    }

    /** @return array<string, array{id: int, code: string, name: string}> */
    private function chainOf(PlaceCode $division, PlaceCode $district, PlaceCode $upazila, PlaceCode $union): array
    {
        return collect(compact('division', 'district', 'upazila', 'union'))
            ->map(fn (PlaceCode $place): array => ['id' => $place->id, 'code' => (string) $place->code, 'name' => (string) $place->name])->all();
    }

    /** @param  array<string, array{id: int, code: string, name: string}>|null  $chain */
    private function seatedDealer(PlaceCode $place, ?array $chain = null, bool $exclusive = true): User
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'profile' => array_filter(['place_code_id' => $place->id, 'place_code' => $place->code, 'area_chain' => $chain])]);
        AreaDealerAssignment::query()->create([
            'user_id' => $dealer->id,
            'level' => $place->level,
            'zone_name' => $place->name,
            'place_code_id' => $place->id,
            'monthly_quota' => '0',
            'is_active' => $exclusive,
        ]);

        return $dealer;
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function asOwner(): static
    {
        return $this->actingAs(User::query()->where('role', 'super_admin')->first() ?? User::factory()->create(['role' => 'super_admin']));
    }

    private function created(): User
    {
        return User::query()->where('email', 'new.partner@example.test')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19911234567890111',
            'date_of_birth' => '1991-02-02',
            'name' => 'New Partner',
            'name_bn' => 'নতুন পার্টনার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'email' => 'new.partner@example.test',
            'phone' => '01812345678',
            'reason' => 'Area chain test',
            ...$overrides,
        ];
    }
}

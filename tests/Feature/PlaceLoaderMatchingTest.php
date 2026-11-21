<?php

namespace Tests\Feature;

use App\Domain\Geography\DefaultPlaceCatalog;
use App\Models\PlaceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The default place loader on the live state after a hand-merged duplicate: the district that
 * carries the office's own code, its children under it, a switched-off duplicate on the catalog
 * code, and code-less duplicate unions. It must add nothing that already exists.
 */
class PlaceLoaderMatchingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{division: PlaceCode, dead: PlaceCode, live: PlaceCode, upazilas: int, unions: int} */
    private function liveRangpurState(): array
    {
        $places = json_decode((string) file_get_contents(database_path('data/bangladesh-place-codes.json')), true, 512, JSON_THROW_ON_ERROR)['places'];
        $make = fn (array $place, ?int $parentId, ?string $code = null, bool $active = true): PlaceCode => PlaceCode::query()->create([
            'level' => $place['level'], 'code' => $code ?? 'MNHC'.$place['code'], 'name' => $place['name'], 'parent_id' => $parentId, 'is_active' => $active, 'sort_order' => 0,
        ]);
        $byCode = array_column($places, null, 'code');

        $management = $make($byCode['100'], null);
        $division = $make($byCode['102'], $management->id);
        // The catalog's empty Rangpur district, switched off, created first so it has the lower id.
        $dead = $make($byCode['220'], $division->id, null, false);
        // The office's own Rangpur district, with its own code (the catalog's Dhaka district code).
        $live = $make($byCode['220'], $division->id, 'MNHC201');

        $upazilas = 0;
        $unions = 0;
        foreach ($places as $upazila) {
            if ($upazila['level'] !== 'upazila' || $upazila['parent_code'] !== '220') {
                continue;
            }
            $upazilaRow = $make($upazila, $live->id);
            $upazilas++;
            foreach ($places as $union) {
                if ($union['level'] === 'union' && $union['parent_code'] === $upazila['code']) {
                    $make($union, $upazilaRow->id);
                    $unions++;
                    if ($unions === 3) {
                        // A code-less, switched-off duplicate of this union made earlier by mistake.
                        PlaceCode::query()->create(['level' => 'union', 'code' => null, 'name' => $union['name'], 'parent_id' => $upazilaRow->id, 'is_active' => false, 'sort_order' => 0]);
                    }
                }
            }
        }

        return ['division' => $division, 'dead' => $dead, 'live' => $live, 'upazilas' => $upazilas, 'unions' => $unions];
    }

    public function test_the_live_district_keeps_its_children_and_nothing_is_added_below_a_switched_off_duplicate(): void
    {
        $state = $this->liveRangpurState();
        $before = PlaceCode::query()->where('parent_id', $state['live']->id)->count();

        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame(8, $state['upazilas']);
        $this->assertSame($before, PlaceCode::query()->where('parent_id', $state['live']->id)->count());
        $this->assertSame(0, PlaceCode::query()->where('parent_id', $state['dead']->id)->count());
        // Every Rangpur union of the list is still one live place: no second copy was added.
        $liveUnions = PlaceCode::query()->where('level', 'union')->where('is_active', true)
            ->whereIn('parent_id', PlaceCode::query()->where('parent_id', $state['live']->id)->pluck('id'))->count();
        $this->assertSame($state['unions'], $liveUnions);
        // Only what the live state lacks is added: 7 divisions, 63 districts and the 491 upazilas outside Rangpur.
        $this->assertSame(7, $summary['by_level']['division']['created']);
        $this->assertSame(63, $summary['by_level']['district']['created']);
        $this->assertSame(499 - 8, $summary['by_level']['upazila']['created']);
    }

    public function test_no_place_is_added_twice_even_when_the_loader_runs_again(): void
    {
        $state = $this->liveRangpurState();
        $catalog = app(DefaultPlaceCatalog::class);
        $catalog->load();
        $count = PlaceCode::query()->count();

        $again = $catalog->load();

        $this->assertSame($count, PlaceCode::query()->count());
        $this->assertSame(0, $again['created']);
        $duplicates = PlaceCode::query()->where('is_active', true)
            ->selectRaw('level, parent_id, lower(name) as n, count(*) as c')->groupBy('level', 'parent_id', 'n')->havingRaw('count(*) > 1')->get();
        $this->assertCount(0, $duplicates);
        $this->assertSame($state['live']->id, PlaceCode::query()->where('level', 'district')->where('name', 'Rangpur')->where('is_active', true)->sole()->id);
    }

    public function test_the_base_chain_of_the_live_district_is_set_through_the_live_rows(): void
    {
        $state = $this->liveRangpurState();

        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame($state['live']->id, $state['division']->fresh()->base_place_id);
        $sadar = PlaceCode::query()->where('level', 'upazila')->where('name', 'Rangpur Sadar')->where('parent_id', $state['live']->id)->sole();
        $this->assertSame($sadar->id, $state['live']->fresh()->base_place_id);
        $this->assertNull($state['dead']->fresh()->base_place_id);
        $this->assertNotSame([], $summary['base_set_list']);
    }
}

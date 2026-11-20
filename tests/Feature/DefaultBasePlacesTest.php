<?php

namespace Tests\Feature;

use App\Domain\Geography\DefaultBasePlaces;
use App\Domain\Geography\DefaultPlaceCatalog;
use App\Domain\Geography\PlaceCodeCatalog;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Default base places: the headquarters of each division, district and upazila, found from names,
 * set only where empty, and never replacing what the office chose.
 */
class DefaultBasePlacesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{code: string, level: string, name: string, parent_code: ?string, kind: string}> */
    private function fixture(): array
    {
        $p = fn (string $code, string $level, string $name, ?string $parent, string $kind = ''): array => ['code' => $code, 'level' => $level, 'name' => $name, 'parent_code' => $parent, 'kind' => $kind ?: $level];

        return [
            $p('100', 'management', 'Management', null),
            $p('101', 'division', 'Barishal', '100'),
            $p('102', 'division', 'Unmatched', '100'),
            $p('201', 'district', 'Barisal', '101'),
            $p('202', 'district', 'Cumilla', '101'),
            $p('203', 'district', 'Dhaka', '101'),
            $p('204', 'district', 'Kotwali Town', '101'),
            $p('1001', 'upazila', 'Barisal Sadar', '201'),
            $p('1002', 'upazila', 'Comilla Sadar', '202'),
            $p('1003', 'upazila', 'Sadarsouth', '202'),
            $p('1004', 'upazila', 'Dhaka Metro', '203', 'metro_upazila'),
            $p('1005', 'upazila', 'Savar', '203'),
            $p('1006', 'upazila', 'Zajira', '203'),
            $p('1007', 'upazila', 'Lonely', '203'),
            $p('1008', 'upazila', 'Empty', '203'),
            $p('1009', 'upazila', 'Mixed', '203'),
            $p('1010', 'upazila', 'Barisal Sadar Two', '204'),
            $p('100001', 'union', 'Amta', '1005'),
            $p('100002', 'union', 'Savar Pourashava', '1005', 'pourashava'),
            $p('100003', 'union', 'Zajira Sadar', '1006'),
            $p('100004', 'union', 'Zajira Pourashava', '1006', 'pourashava'),
            $p('100005', 'union', 'Only Child', '1007'),
            $p('100006', 'union', 'One', '1009'),
            $p('100007', 'union', 'Two', '1009'),
            $p('600001', 'union', 'Dhaka North City Corporation', '1004', 'city_corporation'),
            $p('600002', 'union', 'Dhaka South City Corporation', '1004', 'city_corporation'),
        ];
    }

    /** @return array<string, string> */
    private function links(): array
    {
        return array_column((new DefaultBasePlaces)->derive($this->fixture())['links'], 'base_code', 'code');
    }

    public function test_each_level_picks_its_headquarters_by_name(): void
    {
        $links = $this->links();

        // Division: the district with its name, whatever the spelling (Barishal / Barisal).
        $this->assertSame('201', $links['101']);
        // District: its Sadar upazila, older spelling included; with no Sadar, its one metro area.
        $this->assertSame('1001', $links['201']);
        $this->assertSame('1002', $links['202']);
        $this->assertSame('1004', $links['203']);
        // Upazila: the pourashava when the name is carried by a pourashava and a union; its only child otherwise.
        $this->assertSame('100002', $links['1005']);
        $this->assertSame('100004', $links['1006']);
        $this->assertSame('100005', $links['1007']);
    }

    public function test_places_without_a_certain_answer_are_left_empty_with_a_reason(): void
    {
        $result = (new DefaultBasePlaces)->derive($this->fixture());
        $empty = array_column($result['empty'], 'reason', 'code');

        $this->assertSame('no_match', $empty['102']);
        $this->assertSame('no_match', $empty['204']);
        $this->assertSame('no_children', $empty['1008']);
        $this->assertSame('no_match', $empty['1009']);
        // Two city corporations in one metro area: no way to tell which one is the headquarters.
        $this->assertSame('no_match', $empty['1004']);
        $this->assertArrayNotHasKey('100', $empty);
        $this->assertArrayNotHasKey('100001', $empty);
    }

    public function test_a_fresh_load_sets_base_places_from_the_real_list_and_a_second_load_adds_nothing(): void
    {
        $summary = app(DefaultPlaceCatalog::class)->load();

        $places = json_decode((string) file_get_contents(database_path('data/bangladesh-place-codes.json')), true, 512, JSON_THROW_ON_ERROR)['places'];
        $expected = array_count_values(array_map(
            fn (array $link): string => collect($places)->firstWhere('code', $link['code'])['level'],
            (new DefaultBasePlaces)->derive($places)['links'],
        ));
        $this->assertSame($expected['upazila'], $summary['by_level']['upazila']['base_set']);
        $this->assertSame(8, $summary['by_level']['division']['base_set']);
        $this->assertSame(64, $summary['by_level']['district']['base_set']);
        $this->assertSame(0, $summary['by_level']['union']['base_set']);
        $base = fn (string $code): ?string => PlaceCode::query()->where('code', $code)->firstOrFail()->basePlace?->name;
        $this->assertSame('Dhaka', $base('MNHC101'));
        $this->assertSame('Barisal', $base('MNHC106'));
        $this->assertSame('Dhaka Metro', $base('MNHC201'));
        $this->assertSame('Comilla Sadar', PlaceCode::query()->where('level', 'district')->where('name', 'Cumilla')->firstOrFail()->basePlace?->name);
        $this->assertSame('Zajira Pourashava', PlaceCode::query()->where('level', 'upazila')->where('name', 'Zajira')->firstOrFail()->basePlace?->name);
        // Every chosen base is a usable child directly below its place.
        foreach (PlaceCode::query()->whereNotNull('base_place_id')->with('basePlace')->get() as $place) {
            $this->assertSame($place->id, $place->basePlace->parent_id);
            $this->assertTrue($place->basePlace->is_active);
            $this->assertNotNull(PlaceCode::normalizeCode($place->basePlace->code));
        }
        $reasons = array_unique(array_column($summary['base_empty'], 'reason'));
        sort($reasons);
        $this->assertSame(['no_children', 'no_match'], $reasons);

        $again = app(DefaultPlaceCatalog::class)->load();
        $this->assertSame(0, $again['linked']);
        $this->assertSame(0, array_sum(array_column($again['by_level'], 'base_set')));
    }

    public function test_a_base_the_office_already_chose_is_kept(): void
    {
        $catalog = app(DefaultPlaceCatalog::class);
        $catalog->load();
        $dhaka = PlaceCode::query()->where('code', 'MNHC101')->firstOrFail();
        $gazipur = PlaceCode::query()->where('level', 'district')->where('name', 'Gazipur')->firstOrFail();
        $dhaka->update(['base_place_id' => $gazipur->id]);
        PlaceCode::query()->where('code', 'MNHC202')->update(['base_place_id' => null]);

        $summary = $catalog->load();

        $this->assertSame($gazipur->id, $dhaka->fresh()->base_place_id);
        $this->assertSame(1, $summary['by_level']['district']['base_set']);
    }

    public function test_a_candidate_that_is_turned_off_or_has_no_code_is_never_chosen(): void
    {
        $management = PlaceCode::query()->create(['level' => 'management', 'code' => 'MNHC100', 'name' => 'Management', 'sort_order' => 0, 'is_active' => true]);
        $dhaka = PlaceCode::query()->create(['level' => 'division', 'code' => 'MNHC101', 'name' => 'Dhaka', 'parent_id' => $management->id, 'is_active' => true]);
        // The Dhaka district is turned off by the office.
        $off = PlaceCode::query()->create(['level' => 'district', 'code' => 'MNHC201', 'name' => 'Dhaka', 'parent_id' => $dhaka->id, 'is_active' => false]);
        // The Gazipur district cannot take its default code (MNHC203 is held by another place), so it stays without one.
        $gazipur = PlaceCode::query()->create(['level' => 'district', 'code' => null, 'name' => 'Gazipur', 'parent_id' => $dhaka->id, 'is_active' => true]);
        PlaceCode::query()->create(['level' => 'district', 'code' => 'MNHC203', 'name' => 'Other', 'parent_id' => $dhaka->id, 'is_active' => true]);

        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertNull($dhaka->fresh()->base_place_id);
        $this->assertTrue((bool) collect($summary['base_empty'])->contains(fn (array $e): bool => $e['name'] === 'Dhaka' && $e['level'] === 'division' && $e['reason'] === 'candidate_unusable'));
        $this->assertFalse($off->fresh()->is_active);
        $this->assertNotNull($gazipur->fresh());
    }

    public function test_the_office_can_still_change_a_default_base_place(): void
    {
        app(DefaultPlaceCatalog::class)->load();
        $owner = User::factory()->create(['role' => 'super_admin']);
        $dhaka = PlaceCode::query()->where('code', 'MNHC101')->firstOrFail();
        $gazipur = PlaceCode::query()->where('level', 'district')->where('name', 'Gazipur')->firstOrFail();

        $updated = app(PlaceCodeCatalog::class)->update($dhaka, ['base_place_id' => $gazipur->id], $owner->id);

        $this->assertSame($gazipur->id, $updated->base_place_id);
        app(DefaultPlaceCatalog::class)->load();
        $this->assertSame($gazipur->id, $dhaka->fresh()->base_place_id);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Geography\DefaultPlaceCatalog;
use App\Domain\Geography\PlaceCodeCatalog;
use App\Models\PlaceCode;
use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The full Bangladesh place list with default codes is there by default (owner decision,
 * 2026-11-17), and loading it never overwrites what the office already set.
 */
class DefaultPlaceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_install_gets_the_whole_list_with_default_codes(): void
    {
        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame(1 + 8 + 64 + 499 + 4866, $summary['created']);
        $this->assertSame([], $summary['codes_taken']);
        $this->assertSame(1 + 8 + 64 + 499 + 4866, PlaceCode::query()->whereNotNull('code')->count());
        $this->assertDatabaseHas('place_codes', ['code' => 'MNHC100', 'level' => 'management', 'parent_id' => null]);
        $dhaka = PlaceCode::query()->where('code', 'MNHC101')->firstOrFail();
        $this->assertSame(['division', 'Dhaka'], [$dhaka->level, $dhaka->name]);
        $this->assertSame('MNHC100', $dhaka->parent->code);
        $this->assertSame('MNHC101', PlaceCode::query()->where('code', 'MNHC201')->firstOrFail()->parent->code);
        $this->assertSame('MNHC201', PlaceCode::query()->where('code', 'MNHC1001')->firstOrFail()->parent->code);
        $amta = PlaceCode::query()->where('code', 'MNHC100001')->firstOrFail();
        $this->assertSame(['union', 'Amta', 'MNHC1001'], [$amta->level, $amta->name, $amta->parent->code]);
        $this->assertSame(0, PlaceCode::query()->where('level', '!=', 'management')->whereNull('parent_id')->count());
        $this->assertTrue(PlaceCode::query()->where('code', 'MNHC102')->firstOrFail()->isReadyForAssignment());
        $this->assertNotNull(PlaceCode::query()->where('code', 'MNHC102')->value('base_place_id'));

        $again = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame(0, $again['created'] + $again['coded'] + $again['filled'] + $again['linked']);
        $this->assertSame(1 + 8 + 64 + 499 + 4866, PlaceCode::query()->count());
    }

    public function test_loading_keeps_what_the_office_already_set(): void
    {
        $management = PlaceCode::query()->create(['level' => 'management', 'code' => 'MNHC100', 'name' => 'Head Office', 'sort_order' => 0, 'is_active' => true]);
        // The office gave Dhaka its own code, and renamed the division on Rangpur's default code.
        $dhaka = PlaceCode::query()->create(['level' => 'division', 'code' => 'MNHC555', 'name' => 'DHAKA', 'parent_id' => $management->id, 'is_active' => true]);
        $zone = PlaceCode::query()->create(['level' => 'division', 'code' => 'MNHC102', 'name' => 'North Zone', 'parent_id' => $management->id, 'is_active' => true]);
        // A district waiting for its code, and a division typed with the older spelling and no parent.
        $gazipur = PlaceCode::query()->create(['level' => 'district', 'code' => null, 'name' => 'Gazipur', 'parent_id' => $dhaka->id, 'is_active' => true]);
        $chattogram = PlaceCode::query()->create(['level' => 'division', 'code' => null, 'name' => 'Chittagong', 'is_active' => false]);
        // A district holding the default code of the Dhamrai upazila.
        $savar = PlaceCode::query()->create(['level' => 'district', 'code' => 'MNHC1001', 'name' => 'Savar Area', 'parent_id' => $dhaka->id, 'is_active' => true]);

        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame(['MNHC100', 'Head Office'], [$management->fresh()->code, $management->fresh()->name]);
        $this->assertSame(['MNHC555', 'DHAKA'], [$dhaka->fresh()->code, $dhaka->fresh()->name]);
        $this->assertSame(['MNHC102', 'North Zone'], [$zone->fresh()->code, $zone->fresh()->name]);
        $this->assertSame(1, PlaceCode::query()->where('level', 'management')->count());
        $this->assertSame(1, PlaceCode::query()->where('level', 'division')->whereRaw('LOWER(name) = ?', ['dhaka'])->count());
        $this->assertSame(13 + 1, PlaceCode::query()->where('level', 'district')->where('parent_id', $dhaka->id)->count());

        $this->assertSame('MNHC203', $gazipur->fresh()->code);
        $this->assertSame(1, PlaceCode::query()->where('level', 'district')->where('name', 'Gazipur')->count());

        $chattogram->refresh();
        $this->assertSame(['MNHC103', 'Chittagong', $management->id, false], [$chattogram->code, $chattogram->name, $chattogram->parent_id, $chattogram->is_active]);

        // The division on MNHC102 is taken as Rangpur: its name stays and Rangpur's districts go under it.
        $this->assertFalse(PlaceCode::query()->where('level', 'division')->where('name', 'Rangpur')->exists());
        $this->assertSame(8, PlaceCode::query()->where('level', 'district')->where('parent_id', $zone->id)->count());
        $this->assertSame(8, PlaceCode::query()->where('level', 'division')->count());

        // Dhamrai is still added, but without a code, since the office uses MNHC1001 for another place.
        $this->assertSame(['MNHC1001', 'Savar Area'], [$savar->fresh()->code, $savar->fresh()->name]);
        $dhamrai = PlaceCode::query()->where('level', 'upazila')->where('name', 'Dhamrai')->firstOrFail();
        $this->assertNull($dhamrai->code);
        $this->assertSame(['MNHC1001'], $summary['codes_taken']);
        $this->assertSame(PlaceCode::query()->where('code', 'MNHC201')->value('id'), $dhamrai->parent_id);
        $this->assertTrue(PlaceCode::query()->where('code', 'MNHC100001')->where('parent_id', $dhamrai->id)->exists());
    }

    public function test_a_parentless_place_stays_put_when_its_name_belongs_to_several_places(): void
    {
        $orphan = PlaceCode::query()->create([
            'level' => 'upazila',
            'code' => null,
            'name' => 'Kaliganj',
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(DefaultPlaceCatalog::class)->load();

        $orphan->refresh();
        $this->assertNull($orphan->parent_id);
        $this->assertNull($orphan->code);
        $this->assertSame(4, PlaceCode::query()->where('level', 'upazila')->where('name', 'Kaliganj')->whereNotNull('parent_id')->count());
    }

    public function test_the_place_codes_seeder_loads_the_list_and_the_panel_can_still_change_a_code(): void
    {
        $this->seed(PlaceCodeSeeder::class);
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->assertSame(64, PlaceCode::query()->where('level', 'district')->count());
        $amta = PlaceCode::query()->where('code', 'MNHC100001')->firstOrFail();

        $updated = app(PlaceCodeCatalog::class)->update($amta, ['code' => 'MNHC-190001'], $owner->id);

        $this->assertSame('MNHC190001', $updated->code);
        $this->assertSame($amta->id, PlaceCode::findByCode('MNHC-190001')?->id);
    }
}

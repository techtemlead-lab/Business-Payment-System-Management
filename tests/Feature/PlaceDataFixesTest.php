<?php

namespace Tests\Feature;

use App\Domain\Geography\DefaultPlaceCatalog;
use App\Models\PlaceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php artisan madina:fix-place-data brings a table built from the older default list in line with the
 * corrected one: dry run by default, repeatable, nothing deleted, referenced duplicates left on.
 */
class PlaceDataFixesTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $code): PlaceCode
    {
        return PlaceCode::query()->where('code', PlaceCode::normalizeCode($code))->firstOrFail();
    }

    /** Puts the table back into the shape of the older list. */
    private function makeItOld(): void
    {
        app(DefaultPlaceCatalog::class)->load();

        // The Eidgaon union back under Cox's Bazar Sadar, and its upazila with a stale metropolitan base place.
        $this->place('102042')->update(['parent_id' => $this->place('1205')->id]);
        $this->place('9001')->update(['base_place_id' => $this->place('600001')->id]);
        // Two copies of Karnafuli unions under Patiya: Juldha is free, Sikalbaha is somebody's base place.
        foreach (['101757' => 'Juldha', '101764' => 'Sikalbaha'] as $code => $name) {
            PlaceCode::query()->create(['level' => 'union', 'code' => PlaceCode::normalizeCode($code), 'name' => $name, 'parent_id' => $this->place('1181')->id, 'is_active' => true]);
        }
        $this->place('1181')->update(['base_place_id' => $this->place('101764')->id]);
    }

    public function test_a_dry_run_shows_the_corrections_and_saves_nothing(): void
    {
        $this->makeItOld();

        $this->artisan('madina:fix-place-data')
            ->expectsOutputToContain('DRY RUN: nothing was saved')
            ->expectsOutputToContain('move_union')
            ->assertSuccessful();

        $this->assertSame($this->place('1205')->id, $this->place('102042')->parent_id);
        $this->assertNotNull($this->place('9001')->base_place_id);
        $this->assertTrue((bool) $this->place('101757')->is_active);
    }

    public function test_apply_moves_clears_and_switches_off_but_never_a_referenced_duplicate_and_can_be_repeated(): void
    {
        $this->makeItOld();

        $this->artisan('madina:fix-place-data', ['--apply' => true])->expectsOutputToContain('Corrections made')->assertSuccessful();

        $this->assertSame($this->place('1206')->id, $this->place('102042')->parent_id);
        $this->assertSame('MNHC102042', $this->place('102042')->code);
        $this->assertNull($this->place('9001')->base_place_id);
        $this->assertFalse((bool) $this->place('101757')->is_active);
        // Sikalbaha under Patiya is a base place of Patiya: left on and reported.
        $this->assertTrue((bool) $this->place('101764')->is_active);
        // Nothing was deleted.
        $this->assertSame(1, PlaceCode::query()->where('code', 'MNHC101757')->count());

        $this->artisan('madina:fix-place-data', ['--apply' => true])
            ->expectsOutputToContain('referenced')
            ->assertSuccessful();
        $this->assertFalse((bool) $this->place('101757')->is_active);
    }

    public function test_the_loader_applies_the_same_corrections_before_filling_the_list(): void
    {
        $this->makeItOld();

        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame($this->place('1206')->id, $this->place('102042')->parent_id);
        $this->assertSame(0, PlaceCode::query()->where('level', 'union')->where('name', 'Eidgaon')->whereNull('code')->count());
        $this->assertContains('move_union', array_column($summary['fixes'], 'step'));
        $this->assertSame('Eidgaon', $this->place('1206')->fresh()->basePlace?->name);
    }
}

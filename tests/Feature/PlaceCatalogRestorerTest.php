<?php

namespace Tests\Feature;

use App\Domain\Geography\PlaceCatalogRestorer;
use App\Models\CompanyBranch;
use App\Models\PlaceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceCatalogRestorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_place_keeps_its_id_and_regains_deleted_parents(): void
    {
        $kept = PlaceCode::query()->create([
            'level' => PlaceCode::LevelDistrict,
            'code' => 'MNHC-202',
            'name' => 'Gazipur',
            'parent_id' => null,
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $branch = CompanyBranch::factory()->create(['place_code_id' => $kept->id]);

        app(PlaceCatalogRestorer::class)->restore($this->sampleCatalog());

        $this->assertModelExists($kept);
        $kept->refresh();
        $division = PlaceCode::query()->findOrFail($kept->parent_id);
        $management = PlaceCode::query()->findOrFail($division->parent_id);
        $dhaka = PlaceCode::query()->where('level', PlaceCode::LevelDistrict)->where('name', 'Dhaka')->firstOrFail();
        $this->assertSame('MNHC-202', $kept->code);
        $this->assertSame($kept->id, $branch->fresh()->place_code_id);
        $this->assertSame(PlaceCode::LevelDivision, $division->level);
        $this->assertSame('Dhaka', $division->name);
        $this->assertNull($division->code);
        $this->assertSame(PlaceCode::LevelManagement, $management->level);
        $this->assertSame($division->id, $dhaka->parent_id);
        $this->assertSame($dhaka->id, $kept->base_place_id);
        $this->assertSame(4, PlaceCode::query()->count());
    }

    public function test_existing_named_places_are_reused_instead_of_copied(): void
    {
        $restorer = app(PlaceCatalogRestorer::class);
        $restorer->restore($this->sampleCatalog());
        $ids = PlaceCode::query()->orderBy('id')->pluck('id')->all();

        $restorer->restore($this->sampleCatalog());

        $this->assertSame($ids, PlaceCode::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, PlaceCode::query()->whereNotNull('code')->count());
    }

    public function test_bangladesh_place_catalog_stays_the_same_when_restored_again(): void
    {
        $payload = json_decode((string) file_get_contents(database_path('data/bangladesh-place-codes.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = count($payload['places']);
        $restorer = app(PlaceCatalogRestorer::class);
        $restorer->restoreFromCatalogFile();
        $dhaka = PlaceCode::query()->where('level', PlaceCode::LevelDivision)->where('name', 'Dhaka')->firstOrFail();
        $parentId = $dhaka->parent_id;

        $restorer->restoreFromCatalogFile();

        $dhaka->refresh();
        $this->assertSame($expected, PlaceCode::query()->count());
        $this->assertSame($parentId, $dhaka->parent_id);
        $this->assertSame(count($payload['base_links']), PlaceCode::query()->whereNotNull('base_place_id')->count());
    }

    /**
     * @return array{places: list<array<string, mixed>>, base_links: list<array<string, mixed>>}
     */
    private function sampleCatalog(): array
    {
        return [
            'places' => [
                ['code' => '100', 'level' => 'management', 'name' => 'Management', 'parent_code' => null, 'sort_order' => 0],
                ['code' => '101', 'level' => 'division', 'name' => 'Dhaka', 'parent_code' => '100', 'sort_order' => 1],
                ['code' => '201', 'level' => 'district', 'name' => 'Dhaka', 'parent_code' => '101', 'sort_order' => 1],
                ['code' => '202', 'level' => 'district', 'name' => 'Gazipur', 'parent_code' => '101', 'sort_order' => 2],
            ],
            'base_links' => [
                ['code' => '202', 'base_code' => '201'],
            ],
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\PlaceCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the full Bangladesh administrative place registry:
 * management, 8 divisions, 64 districts, all upazilas, unions, pourashavas,
 * and major city corporations.
 */
class BangladeshPlaceCodeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/bangladesh-place-codes.json');
        if (! is_file($path)) {
            throw new RuntimeException('Missing '.$path.'. Generate it from bangladesh-geo.json before seeding.');
        }

        /** @var array{places: list<array<string, mixed>>, base_links?: list<array{code:string,base_code:string}>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $places = $payload['places'] ?? [];
        if ($places === []) {
            throw new RuntimeException('bangladesh-place-codes.json contains no places.');
        }

        DB::transaction(function () use ($places, $payload): void {
            $idByCode = PlaceCode::query()->pluck('id', 'code')->all();

            foreach (array_chunk($places, 250) as $chunk) {
                foreach ($chunk as $place) {
                    $code = (string) $place['code'];
                    $parentCode = $place['parent_code'] ?? null;
                    $parentId = $parentCode ? ($idByCode[(string) $parentCode] ?? null) : null;
                    if ($parentCode && ! $parentId) {
                        throw new RuntimeException('Parent code '.$parentCode.' missing while inserting '.$code);
                    }

                    $attributes = [
                        'level' => (string) $place['level'],
                        'name' => (string) $place['name'],
                        'parent_id' => $parentId,
                        'sort_order' => (int) ($place['sort_order'] ?? 0),
                        'is_active' => true,
                    ];

                    if (isset($idByCode[$code])) {
                        PlaceCode::query()->whereKey($idByCode[$code])->update($attributes);
                    } else {
                        $model = PlaceCode::query()->create([
                            'code' => $code,
                            ...$attributes,
                        ]);
                        $idByCode[$code] = $model->id;
                    }
                }
            }

            foreach ($payload['base_links'] ?? [] as $link) {
                $placeId = $idByCode[(string) $link['code']] ?? null;
                $baseId = $idByCode[(string) $link['base_code']] ?? null;
                if (! $placeId || ! $baseId) {
                    continue;
                }
                PlaceCode::query()->whereKey($placeId)->update(['base_place_id' => $baseId]);
            }
        }, 3);

        $this->command?->info(sprintf(
            'Bangladesh places loaded: %d management, %d divisions, %d districts, %d upazilas, %d union/city places.',
            PlaceCode::query()->where('level', PlaceCode::LevelManagement)->count(),
            PlaceCode::query()->where('level', PlaceCode::LevelDivision)->count(),
            PlaceCode::query()->where('level', PlaceCode::LevelDistrict)->count(),
            PlaceCode::query()->where('level', PlaceCode::LevelUpazila)->count(),
            PlaceCode::query()->where('level', PlaceCode::LevelUnion)->count(),
        ));
    }
}

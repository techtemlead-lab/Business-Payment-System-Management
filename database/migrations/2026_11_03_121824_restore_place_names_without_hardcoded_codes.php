<?php

use App\Models\PlaceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restores Bangladesh place names with a blank code. The owner fills MNHC-
 * numbers in the Place codes panel. Skipped during tests, which build their
 * own small place-code fixtures.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('place_codes')) {
            return;
        }

        if (PlaceCode::query()->exists()) {
            return;
        }

        $path = database_path('data/bangladesh-place-codes.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{places?: list<array<string, mixed>>, base_links?: list<array{code:string,base_code:string}>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $places = $payload['places'] ?? [];
        if ($places === []) {
            return;
        }

        DB::transaction(function () use ($places, $payload): void {
            /** @var array<string, int> $idBySource */
            $idBySource = [];

            foreach (array_chunk($places, 250) as $chunk) {
                foreach ($chunk as $place) {
                    $source = (string) $place['code'];
                    $parentSource = $place['parent_code'] ?? null;
                    $parentId = is_string($parentSource) && $parentSource !== ''
                        ? ($idBySource[$parentSource] ?? null)
                        : null;

                    $model = PlaceCode::query()->create([
                        'level' => (string) $place['level'],
                        'code' => null,
                        'name' => (string) $place['name'],
                        'parent_id' => $parentId,
                        'sort_order' => (int) ($place['sort_order'] ?? 0),
                        'is_active' => true,
                    ]);
                    $idBySource[$source] = $model->id;
                }
            }

            foreach ($payload['base_links'] ?? [] as $link) {
                $placeId = $idBySource[(string) $link['code']] ?? null;
                $baseId = $idBySource[(string) $link['base_code']] ?? null;
                if (! $placeId || ! $baseId) {
                    continue;
                }
                PlaceCode::query()->whereKey($placeId)->update(['base_place_id' => $baseId]);
            }
        }, 3);
    }

    public function down(): void
    {
        // Names are reference data the owner can edit. Do not delete them on rollback.
    }
};

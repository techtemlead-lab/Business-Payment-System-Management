<?php

namespace Database\Seeders;

use App\Models\PlaceCode;
use Illuminate\Database\Seeder;

/**
 * Seeds hierarchy slots only: one Management row and eight Division slots.
 * Codes and geographic names stay blank (—) until an admin fills them in the panel.
 */
class PlaceCodeSeeder extends Seeder
{
    public function run(): void
    {
        $management = PlaceCode::query()
            ->where('level', PlaceCode::LevelManagement)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->first();

        if ($management === null) {
            $management = PlaceCode::query()->create([
                'level' => PlaceCode::LevelManagement,
                'code' => null,
                'name' => '—',
                'parent_id' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]);
        } elseif ($management->code === null && PlaceCode::isPlaceholderLabel($management->name)) {
            $management->forceFill(['sort_order' => 0, 'is_active' => true])->save();
        }

        for ($sortOrder = 1; $sortOrder <= 8; $sortOrder++) {
            $exists = PlaceCode::query()
                ->where('level', PlaceCode::LevelDivision)
                ->where('parent_id', $management->id)
                ->where('sort_order', $sortOrder)
                ->exists();

            if ($exists) {
                continue;
            }

            PlaceCode::query()->create([
                'level' => PlaceCode::LevelDivision,
                'code' => null,
                'name' => '—',
                'parent_id' => $management->id,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
        }
    }
}

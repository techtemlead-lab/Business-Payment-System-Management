<?php

namespace Database\Factories;

use App\Models\PlaceCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceCode>
 */
class PlaceCodeFactory extends Factory
{
    protected $model = PlaceCode::class;

    public function definition(): array
    {
        return [
            'level' => PlaceCode::LevelDivision,
            'code' => (string) fake()->unique()->numberBetween(110, 9999),
            'name' => fake()->unique()->city(),
            'parent_id' => null,
            'base_place_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function management(string $code = '100'): static
    {
        return $this->state(fn (): array => [
            'level' => PlaceCode::LevelManagement,
            'code' => $code,
            'name' => 'Management',
            'parent_id' => null,
        ]);
    }

    public function division(?PlaceCode $parent = null): static
    {
        return $this->state(fn (): array => [
            'level' => PlaceCode::LevelDivision,
            'parent_id' => $parent?->id,
        ]);
    }

    public function district(PlaceCode $parent): static
    {
        return $this->state(fn (): array => [
            'level' => PlaceCode::LevelDistrict,
            'parent_id' => $parent->id,
        ]);
    }

    public function upazila(PlaceCode $parent): static
    {
        return $this->state(fn (): array => [
            'level' => PlaceCode::LevelUpazila,
            'parent_id' => $parent->id,
        ]);
    }

    public function union(PlaceCode $parent): static
    {
        return $this->state(fn (): array => [
            'level' => PlaceCode::LevelUnion,
            'parent_id' => $parent->id,
        ]);
    }
}

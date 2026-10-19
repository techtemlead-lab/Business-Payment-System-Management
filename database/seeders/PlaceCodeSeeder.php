<?php

namespace Database\Seeders;

use App\Models\PlaceCode;
use Illuminate\Database\Seeder;

class PlaceCodeSeeder extends Seeder
{
    public function run(): void
    {
        $management = PlaceCode::query()->firstOrCreate(
            ['code' => '100'],
            [
                'level' => PlaceCode::LevelManagement,
                'name' => 'Management',
                'parent_id' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]
        );

        $divisions = [
            ['code' => '101', 'name' => 'Dhaka', 'sort_order' => 1],
            ['code' => '102', 'name' => 'Rangpur', 'sort_order' => 2],
            ['code' => '103', 'name' => 'Chattogram', 'sort_order' => 3],
            ['code' => '104', 'name' => 'Rajshahi', 'sort_order' => 4],
            ['code' => '105', 'name' => 'Khulna', 'sort_order' => 5],
            ['code' => '106', 'name' => 'Barishal', 'sort_order' => 6],
            ['code' => '107', 'name' => 'Sylhet', 'sort_order' => 7],
            ['code' => '108', 'name' => 'Mymensingh', 'sort_order' => 8],
        ];

        foreach ($divisions as $division) {
            PlaceCode::query()->firstOrCreate(
                ['code' => $division['code']],
                [
                    'level' => PlaceCode::LevelDivision,
                    'name' => $division['name'],
                    'parent_id' => $management->id,
                    'sort_order' => $division['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}

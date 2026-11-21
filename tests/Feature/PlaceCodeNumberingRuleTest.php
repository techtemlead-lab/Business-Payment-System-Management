<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The numbering rule of the default place codes, read from database/data/bangladesh-place-codes.json:
 * management 100; divisions 101-108; districts 201-264 by division then name; upazilas 1001-1495 in
 * district order, then 9001-9004 for the metropolitan areas; unions 100001 upward in upazila order,
 * pourashavas 500001 upward and city corporations 600001 upward.
 */
class PlaceCodeNumberingRuleTest extends TestCase
{
    /** @return array<string, list<array<string, mixed>>> */
    private function byLevel(): array
    {
        $places = json_decode((string) file_get_contents(database_path('data/bangladesh-place-codes.json')), true, 512, JSON_THROW_ON_ERROR)['places'];
        $grouped = [];
        foreach ($places as $place) {
            $grouped[$place['level']][] = $place;
        }

        return $grouped;
    }

    public function test_management_division_and_district_codes_run_without_gaps(): void
    {
        $levels = $this->byLevel();

        $this->assertSame(['100'], array_column($levels['management'], 'code'));
        $this->assertSame(array_map('strval', range(101, 108)), array_column($levels['division'], 'code'));
        $this->assertSame(array_map('strval', range(201, 264)), array_column($levels['district'], 'code'));
    }

    public function test_districts_are_grouped_by_division_in_division_order(): void
    {
        $divisionOf = array_column($this->byLevel()['division'], 'code', 'code');
        $parents = array_map(fn (array $d): int => (int) $d['parent_code'], $this->byLevel()['district']);

        $this->assertSame($parents, collect($parents)->sort()->values()->all());
        $this->assertSame(array_map('strval', array_keys($divisionOf)), array_map('strval', array_values(array_unique($parents))));
    }

    public function test_upazila_codes_follow_district_order_and_metro_areas_take_the_9000_block(): void
    {
        $upazilas = $this->byLevel()['upazila'];
        $regular = array_values(array_filter($upazilas, fn (array $u): bool => $u['kind'] === 'upazila'));
        $metro = array_values(array_filter($upazilas, fn (array $u): bool => $u['kind'] === 'metro_upazila'));

        $this->assertSame(array_map('strval', range(1001, 1495)), array_column($regular, 'code'));
        $this->assertSame(array_map('strval', range(9001, 9004)), array_column($metro, 'code'));
        $districts = array_map(fn (array $u): int => (int) $u['parent_code'], $regular);
        $this->assertSame($districts, collect($districts)->sort()->values()->all());
    }

    public function test_union_pourashava_and_city_corporation_codes_are_six_digits_in_upazila_order(): void
    {
        $blocks = ['union' => [100001, 199999], 'pourashava' => [500001, 599999], 'city_corporation' => [600001, 699999]];
        $last = [];
        foreach ($this->byLevel()['union'] as $place) {
            [$from, $to] = $blocks[$place['kind']];
            $number = (int) $place['code'];
            $this->assertGreaterThanOrEqual($from, $number);
            $this->assertLessThanOrEqual($to, $number);
            $this->assertGreaterThan($last[$place['kind']] ?? $from - 1, $number, 'Codes of one kind must increase in file order');
            $last[$place['kind']] = $number;
        }
        // Three numbers (101276, 103655, 103939) are unused: codes are never renumbered, since live may already use them.
        $this->assertSame(104540, $last['union']);
        $this->assertSame(500328, $last['pourashava']);
        $this->assertSame(600012, $last['city_corporation']);
    }
}

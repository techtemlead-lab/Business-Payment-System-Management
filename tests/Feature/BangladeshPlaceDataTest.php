<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use Tests\TestCase;

/**
 * The default Bangladesh place list in database/data/bangladesh-place-codes.json: complete,
 * every place under the level just above it, and every default code unique and in its range.
 * Checked against the known structure (8 divisions, 64 districts, 495 upazilas, 12 city
 * corporations); the union and pourashava counts are those of the source data.
 */
class BangladeshPlaceDataTest extends TestCase
{
    /** Digits each level's default code has: MNHC100, MNHC101, MNHC201, MNHC1001, MNHC100001. */
    private const Digits = ['management' => 3, 'division' => 3, 'district' => 3, 'upazila' => 4, 'union' => 6];

    public function test_the_data_file_is_a_complete_and_consistent_place_list(): void
    {
        $places = json_decode((string) file_get_contents(database_path('data/bangladesh-place-codes.json')), true, 512, JSON_THROW_ON_ERROR)['places'];
        $byCode = [];
        foreach ($places as $place) {
            $this->assertArrayNotHasKey($place['code'], $byCode, 'Duplicate code '.$place['code']);
            $byCode[$place['code']] = $place;
        }
        $counts = array_count_values(array_column($places, 'level'));
        $kinds = array_count_values(array_column($places, 'kind'));

        $this->assertSame(1, $counts['management']);
        $this->assertSame(8, $counts['division']);
        $this->assertSame(64, $counts['district']);
        $this->assertSame(499, $counts['upazila']);
        $this->assertSame(495, $kinds['upazila']);
        $this->assertSame(4, $kinds['metro_upazila']);
        $this->assertSame(4866, $counts['union']);
        $this->assertSame(4526, $kinds['union']);
        $this->assertSame(328, $kinds['pourashava']);
        $this->assertSame(12, $kinds['city_corporation']);

        $depth = array_flip(PlaceCode::Levels);
        $siblings = [];
        foreach ($places as $place) {
            $code = $place['code'];
            $level = $place['level'];
            $this->assertMatchesRegularExpression('/^\d{'.self::Digits[$level].'}$/D', $code, $code.' has the wrong number of digits');
            $number = (int) $code;
            $this->assertTrue(match ($level) {
                'management' => $number === 100,
                'division' => $number >= 101 && $number <= 108,
                'district' => $number >= 201 && $number <= 264,
                'upazila' => $number >= 1001,
                'union' => $number >= 100001,
            }, $code.' is outside the '.$level.' range');
            $this->assertNotSame('', trim($place['name']), $code.' has no English name');
            $this->assertNotSame('', trim((string) $place['bn_name']), $code.' has no Bengali name');

            if ($level === 'management') {
                $this->assertNull($place['parent_code']);

                continue;
            }
            $parent = $byCode[$place['parent_code']] ?? null;
            $this->assertNotNull($parent, $code.' has a missing parent');
            $this->assertSame($depth[$level] - 1, $depth[$parent['level']], $code.' is not under the level just above it');

            $siblingKey = $place['parent_code'].'|'.mb_strtolower($place['name']);
            $this->assertArrayNotHasKey($siblingKey, $siblings, $code.' repeats a name under the same parent');
            $siblings[$siblingKey] = true;
        }

        $districtsPerDivision = [];
        foreach ($places as $place) {
            if ($place['level'] === 'district') {
                $districtsPerDivision[$byCode[$place['parent_code']]['name']] = ($districtsPerDivision[$byCode[$place['parent_code']]['name']] ?? 0) + 1;
            }
        }
        $this->assertSame(
            ['Dhaka' => 13, 'Rangpur' => 8, 'Chattogram' => 11, 'Rajshahi' => 8, 'Khulna' => 10, 'Barishal' => 6, 'Sylhet' => 4, 'Mymensingh' => 4],
            $districtsPerDivision,
        );
    }
}

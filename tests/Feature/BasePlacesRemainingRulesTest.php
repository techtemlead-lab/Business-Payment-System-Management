<?php

namespace Tests\Feature;

use App\Domain\Geography\DefaultBasePlaces;
use App\Domain\Geography\DefaultPlaceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The second pass of default base places for upazilas whose children are not named like them:
 * the one municipality among the children, or the headquarters union found in public sources.
 */
class BasePlacesRemainingRulesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{code: string, level: string, name: string, parent_code: ?string, kind: string}> */
    private function fixture(): array
    {
        $p = fn (string $code, string $level, string $name, ?string $parent, string $kind = ''): array => ['code' => $code, 'level' => $level, 'name' => $name, 'parent_code' => $parent, 'kind' => $kind ?: $level];

        return [
            $p('201', 'district', 'Test', null),
            $p('1001', 'upazila', 'Palash', '201'),
            $p('1002', 'upazila', 'Bagmara', '201'),
            $p('1003', 'upazila', 'Kaharol', '201'),
            $p('1004', 'upazila', 'Gangachara', '201'),
            $p('1005', 'upazila', 'Mystery', '201'),
            $p('100001', 'union', 'Ghorasal Pourashava', '1001', 'pourashava'),
            $p('100002', 'union', 'Char Sindur', '1001'),
            $p('100003', 'union', 'Jinardi', '1001'),
            $p('100004', 'union', 'Bhabaniganj Pourashava', '1002', 'pourashava'),
            $p('100005', 'union', 'Taherpur Paurashava', '1002', 'pourashava'),
            $p('100006', 'union', 'Dhulauri', '1002'),
            $p('100007', 'union', 'Kaharole', '1003'),
            $p('100008', 'union', 'Mukundapur', '1003'),
            $p('100009', 'union', 'Gangachora', '1004'),
            $p('100010', 'union', 'Alamnagar', '1004'),
            $p('100011', 'union', 'Alpha', '1005'),
            $p('100012', 'union', 'Beta', '1005'),
        ];
    }

    public function test_the_one_municipality_among_the_children_is_the_base(): void
    {
        $result = (new DefaultBasePlaces)->derive($this->fixture());
        $links = array_column($result['links'], 'base_code', 'code');

        $this->assertSame('100001', $links['1001']);
        $this->assertSame('upazila_only_pourashava', $result['rules']['1001']);
        // Two municipalities (Pourashava / Paurashava): not guessed, listed with the reason.
        $this->assertArrayNotHasKey('1002', $links);
        $empty = array_column($result['empty'], null, 'code');
        $this->assertSame('several_pourashavas', $empty['1002']['reason']);
        $this->assertSame(['Bhabaniganj Pourashava', 'Taherpur Paurashava', 'Dhulauri'], $empty['1002']['candidates']);
        $this->assertSame('Test', $empty['1002']['parent']);
    }

    public function test_the_headquarters_union_from_a_source_is_used_only_when_it_matches_exactly_one_union(): void
    {
        $result = (new DefaultBasePlaces(['1003' => 'Kaharol', '1004' => 'Gangachara', '1005' => 'Somewhere Else']))->derive($this->fixture());
        $links = array_column($result['links'], 'base_code', 'code');
        $empty = array_column($result['empty'], 'reason', 'code');

        $this->assertSame('100007', $links['1003']);
        $this->assertSame('100009', $links['1004']);
        $this->assertSame('upazila_headquarters_union', $result['rules']['1003']);
        $this->assertSame('headquarters_not_matched', $empty['1005']);
        // No source entry and no name like the upazila's: no guess.
        $withoutSource = (new DefaultBasePlaces)->derive($this->fixture());
        $this->assertSame('no_match', array_column($withoutSource['empty'], 'reason', 'code')['1005']);
    }

    public function test_a_union_named_like_the_upazila_in_another_spelling_is_the_base(): void
    {
        $result = (new DefaultBasePlaces)->derive($this->fixture());
        $links = array_column($result['links'], 'base_code', 'code');

        $this->assertSame('100007', $links['1003']);
        $this->assertSame('100009', $links['1004']);
        $this->assertSame('upazila_union_named_like_upazila_other_spelling', $result['rules']['1003']);
        $this->assertSame('upazila_union_named_like_upazila_other_spelling', $result['rules']['1004']);
        // Nothing named like Mystery: still empty, never guessed.
        $this->assertArrayNotHasKey('1005', $links);
    }

    public function test_the_spelling_variant_never_replaces_the_only_municipality_or_an_ambiguous_pair(): void
    {
        $p = fn (string $code, string $level, string $name, ?string $parent, string $kind = ''): array => ['code' => $code, 'level' => $level, 'name' => $name, 'parent_code' => $parent, 'kind' => $kind ?: $level];
        $places = [
            $p('201', 'district', 'Test', null),
            $p('1001', 'upazila', 'Kaharole', '201'),
            $p('1002', 'upazila', 'Shibpar', '201'),
            $p('100001', 'union', 'Kaharol', '1001'),
            $p('100002', 'union', 'Some Pourashava', '1001', 'pourashava'),
            $p('100003', 'union', 'Shibpur', '1002'),
            $p('100004', 'union', 'Shibpor', '1002'),
        ];

        $result = (new DefaultBasePlaces)->derive($places);
        $links = array_column($result['links'], 'base_code', 'code');

        $this->assertSame('100002', $links['1001']);
        $this->assertSame('upazila_only_pourashava', $result['rules']['1001']);
        $this->assertArrayNotHasKey('1002', $links);
    }

    public function test_the_headquarters_file_only_names_real_upazilas_and_is_applied_by_the_loader(): void
    {
        $catalog = app(DefaultPlaceCatalog::class);
        $review = $catalog->headquartersReview();
        $places = json_decode((string) file_get_contents(database_path('data/bangladesh-place-codes.json')), true, 512, JSON_THROW_ON_ERROR)['places'];
        $upazilas = array_column(array_filter($places, fn (array $p): bool => $p['level'] === 'upazila'), 'name', 'code');
        foreach ($review as $code => $row) {
            $this->assertArrayHasKey($code, $upazilas, 'Unknown upazila code '.$code);
            $this->assertContains($row['confidence'], ['high', 'medium', 'low']);
        }
        $this->assertSame(array_keys(array_filter($review, fn (array $r): bool => $r['confidence'] === 'high' && ! empty($r['hq_union']))), array_keys($catalog->headquarters()));

        $first = $catalog->load();
        $again = $catalog->load();

        $this->assertSame(0, $again['linked']);
        $rules = array_column($first['base_set_list'], 'rule');
        $this->assertContains('upazila_only_pourashava', $rules);
        $this->assertContains('upazila_union_named_like_upazila_other_spelling', $rules);

        // Every upazila the loader leaves without a base place has a researched line in the review file.
        $left = array_column(array_filter($first['base_empty'], fn (array $e): bool => $e['level'] === 'upazila'), 'code');
        $this->assertSame([], array_values(array_diff($left, array_keys($review))), 'Upazilas without a base place and without a researched line');
    }

    public function test_the_review_csv_lists_the_upazilas_left_without_a_base(): void
    {
        $path = sys_get_temp_dir().'/base-places-review-'.uniqid().'.csv';

        $this->artisan('madina:seed-place-codes', ['--review-csv' => $path])->assertSuccessful();

        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), file($path, FILE_IGNORE_NEW_LINES));
        unlink($path);
        $this->assertSame(['upazila', 'district', 'headquarters union found', 'candidates', 'source URL', 'confidence', 'reason', 'note'], $rows[0]);
        $this->assertGreaterThanOrEqual(1, count($rows) - 1);
        $this->assertNotSame('', $rows[1][1], 'The district column is filled');
    }
}

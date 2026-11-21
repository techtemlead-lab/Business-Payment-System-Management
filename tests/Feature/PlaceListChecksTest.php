<?php

namespace Tests\Feature;

use App\Domain\Geography\DefaultBasePlaces;
use App\Domain\Geography\DefaultPlaceCatalog;
use App\Domain\Geography\PlaceListChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The default place list is checked for rows that are not what they look like, and the base place
 * rules and counts leave those rows out.
 */
class PlaceListChecksTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function places(): array
    {
        $payload = json_decode((string) file_get_contents(database_path(DefaultPlaceCatalog::DataFile)), true, 512, JSON_THROW_ON_ERROR);

        return $payload['places'];
    }

    public function test_the_list_has_495_real_upazilas_and_four_metropolitan_areas_typed_as_upazila(): void
    {
        $upazilas = array_filter($this->places(), fn (array $p): bool => $p['level'] === 'upazila');
        $this->assertCount(499, $upazilas);
        $this->assertCount(495, array_filter($upazilas, fn (array $p): bool => $p['kind'] === 'upazila'));

        $issues = (new PlaceListChecks)->review($this->places());
        $metro = array_column(array_filter($issues, fn (array $i): bool => $i['issue'] === 'not_a_real_upazila'), 'name');
        sort($metro);
        $this->assertSame(['Chattogram City', 'Dhaka Metro', 'Khulna City', 'Rajshahi City'], $metro);
        $this->assertSame([], array_filter($issues, fn (array $i): bool => in_array($i['issue'], ['upazila_duplicate', 'upazila_bad_parent', 'union_bad_parent'], true)));
    }

    public function test_unions_listed_under_two_upazilas_and_under_the_wrong_upazila_are_found(): void
    {
        $issues = (new PlaceListChecks)->review($this->places());
        $byIssue = [];
        foreach ($issues as $i) {
            $byIssue[$i['issue']][$i['name'].' / '.$i['parent']] = true;
        }

        $this->assertArrayHasKey('Juldha / Karnafuli', $byIssue['union_listed_under_two_upazilas']);
        $this->assertArrayHasKey('Juldha / Patiya', $byIssue['union_listed_under_two_upazilas']);
        $this->assertArrayHasKey('Perul (North) / Lalmai', $byIssue['union_listed_under_two_upazilas']);
        $this->assertArrayHasKey('Perul (North) / Sadarsouth', $byIssue['union_listed_under_two_upazilas']);
        // Same name in two upazilas by coincidence (Mohammadpur) is not a duplicate list.
        $this->assertArrayNotHasKey('Mohammadpur / Chatkhil', $byIssue['union_listed_under_two_upazilas']);
        $this->assertSame(['Eidgaon / Coxsbazar Sadar', 'Guimara / Matiranga', 'Madhyanagar / Dharmapasha'], array_keys($byIssue['union_under_wrong_upazila']));
    }

    public function test_metropolitan_areas_get_no_base_place_and_flagged_unions_are_never_chosen(): void
    {
        $result = (new DefaultBasePlaces)->derive($this->places());
        $byCode = array_column($this->places(), null, 'code');

        foreach (['9001', '9002', '9003', '9004'] as $metro) {
            $this->assertArrayNotHasKey($metro, array_column($result['links'], 'base_code', 'code'));
            $this->assertNotContains($metro, array_column($result['empty'], 'code'));
        }
        $excluded = (new PlaceListChecks)->excludedUnionCodes((new PlaceListChecks)->review($this->places()));
        foreach ($result['links'] as $link) {
            $this->assertArrayNotHasKey($link['base_code'], $excluded, 'A flagged union was chosen as a base: '.$byCode[$link['base_code']]['name']);
        }
    }

    public function test_the_loader_reports_honest_upazila_counts(): void
    {
        $summary = app(DefaultPlaceCatalog::class)->load();

        $this->assertSame(495, $summary['upazilas']['real']);
        $this->assertSame(495, $summary['upazilas']['with_base'] + $summary['upazilas']['without_base']);
        $this->assertSame($summary['upazilas']['without_base'], array_sum($summary['upazilas']['reasons']));
        $this->assertSame($summary['upazilas']['without_base'], count(array_filter($summary['base_empty'], fn (array $e): bool => $e['level'] === 'upazila')));
    }

    public function test_the_command_prints_the_counts_and_writes_the_check_csv(): void
    {
        $path = sys_get_temp_dir().'/place-list-check-'.uniqid().'.csv';

        $this->artisan('madina:seed-place-codes', ['--place-list-csv' => $path])
            ->expectsOutputToContain('Upazilas (real ones only, metropolitan areas excluded): 495 in total')
            ->assertSuccessful();

        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), file($path, FILE_IGNORE_NEW_LINES));
        unlink($path);
        $this->assertSame(['issue', 'code', 'name', 'parent', 'detail'], $rows[0]);
        $this->assertContains('not_a_real_upazila', array_column($rows, 0));
    }
}

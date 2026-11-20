<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The default Bangladesh place list (management, divisions, districts, upazilas, unions) with
 * its default codes MNHC100, MNHC101…, MNHC201…, MNHC1001…, MNHC100001…
 *
 * Fill-only: a place the office already has is never renamed, re-coded or moved. An existing
 * place is recognised by its level, parent and name (older spellings such as Chittagong count),
 * or by its code at the same level. It only gets the default code when it has none yet and no
 * other place uses that code, and a blank name or missing parent is filled in. Every place the
 * office lacks is added; when its default code is already taken it is added without a code, for
 * the office to fill in. Running it again changes nothing.
 */
/**
 * @phpstan-type PlaceSummary array{created: int, coded: int, filled: int, linked: int, codes_taken: list<string>, by_level: array<string, array<string, int>>, conflicts: list<array{code: string, level: string, name: string, held_by: string}>, base_empty: list<array{code: string, level: string, name: string, reason: string, candidates: list<string>, parent: string}>, base_set_list: list<array{code: string, level: string, name: string, base: string, rule: string}>}
 */
final class DefaultPlaceCatalog
{
    public const DataFile = 'data/bangladesh-place-codes.json';

    public const HeadquartersFile = 'data/bangladesh-upazila-headquarters.json';

    private const InsertChunk = 500;

    /** Older or other common English spellings, read as the spelling the list uses. */
    public const NameAliases = [
        'chittagong' => 'chattogram',
        'barisal' => 'barishal',
        'comilla' => 'cumilla',
        'jessore' => 'jashore',
        'bogra' => 'bogura',
        'jhalokati' => 'jhalakathi',
        'jhalokathi' => 'jhalakathi',
        'jhalakati' => 'jhalakathi',
        'maulvibazar' => 'moulvibazar',
        'netrakona' => 'netrokona',
        'khagrachari' => 'khagrachhari',
        'laxmipur' => 'lakshmipur',
    ];

    /**
     * Rows of the data file, parents before children.
     *
     * @return array{places: list<array{code: string, level: string, name: string, parent_code: ?string, sort_order: int, bn_name: ?string, kind: string}>, base_links: list<array{code: string, base_code: string}>}
     */
    public function payload(): array
    {
        $path = database_path(self::DataFile);
        if (! is_file($path)) {
            throw new RuntimeException('Missing '.$path.'.');
        }

        /** @var array{places?: list<array{code: string, level: string, name: string, parent_code: ?string, sort_order: int, bn_name: ?string, kind: string}>, base_links?: list<array{code: string, base_code: string}>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $places = $payload['places'] ?? [];
        if ($places === []) {
            throw new RuntimeException(self::DataFile.' contains no places.');
        }
        $depth = array_flip(PlaceCode::Levels);
        usort($places, fn (array $a, array $b): int => $depth[$a['level']] <=> $depth[$b['level']]);

        return ['places' => $places, 'base_links' => $payload['base_links'] ?? []];
    }

    /**
     * @return PlaceSummary
     */
    public function load(bool $dryRun = false): array
    {
        $payload = $this->payload();

        if ($dryRun) {
            // Runs the real fill and rolls it back, so the report is exactly what a real run would do.
            DB::beginTransaction();
            try {
                return $this->fill($payload);
            } finally {
                DB::rollBack();
            }
        }

        $summary = DB::transaction(fn (): array => $this->fill($payload), 3);
        if ($summary['codes_taken'] !== []) {
            Log::warning('Default place list: these default codes already belong to other places, so the matching places were left without a code.', [
                'codes' => $summary['codes_taken'],
            ]);
        }

        return $summary;
    }

    /**
     * @param  array{places: list<array{code: string, level: string, name: string, parent_code: ?string, sort_order: int, bn_name: ?string, kind: string}>, base_links: list<array{code: string, base_code: string}>}  $payload
     * @return PlaceSummary
     */
    private function fill(array $payload): array
    {
        $summary = ['created' => 0, 'coded' => 0, 'filled' => 0, 'linked' => 0, 'codes_taken' => [], 'by_level' => [], 'conflicts' => [], 'base_empty' => [], 'base_set_list' => []];
        foreach (PlaceCode::Levels as $level) {
            $summary['by_level'][$level] = ['created' => 0, 'coded' => 0, 'taken' => 0, 'other_code' => 0, 'base_set' => 0];
        }

        /** @var array<int, \stdClass> $rows */
        $rows = [];
        /** @var array<string, int> $codeOwner The place id that holds each code, by its current spelling */
        $codeOwner = [];
        /** @var array<string, list<int>> $byStructure */
        $byStructure = [];
        foreach (DB::table('place_codes')->lockForUpdate()->orderBy('id')->get(['id', 'level', 'code', 'name', 'parent_id', 'base_place_id', 'is_active']) as $row) {
            $row->id = (int) $row->id;
            $row->parent_id = $row->parent_id === null ? null : (int) $row->parent_id;
            $row->base_place_id = $row->base_place_id === null ? null : (int) $row->base_place_id;
            $rows[$row->id] = $row;
            $this->index($row, $codeOwner, $byStructure);
        }

        /** @var array<int, true> $claimed Existing places already matched to a row of the list */
        $claimed = [];
        /** @var array<string, int> $idBySource */
        $idBySource = [];
        $now = now();

        $byLevel = [];
        foreach ($payload['places'] as $place) {
            $byLevel[$place['level']][] = $place;
        }

        foreach (PlaceCode::Levels as $level) {
            $pending = [];
            foreach ($byLevel[$level] ?? [] as $place) {
                $code = PlaceCode::normalizeCode($place['code']);
                if ($code === null) {
                    throw new RuntimeException('Bad code '.$place['code'].' in '.self::DataFile.'.');
                }
                $parentId = null;
                if ($place['parent_code'] !== null) {
                    $parentId = $idBySource[$place['parent_code']] ?? throw new RuntimeException('Parent '.$place['parent_code'].' of '.$place['code'].' is missing.');
                }

                $existing = $this->match($place, $code, $parentId, $rows, $codeOwner, $byStructure, $claimed);
                if ($existing !== null) {
                    $claimed[$existing->id] = true;
                    $idBySource[$place['code']] = $existing->id;
                    $this->fillGaps($existing, $place, $code, $parentId, $codeOwner, $summary, $now, $rows);

                    continue;
                }

                // The same place under another spelling is already matched: never add a second one.
                $sameName = $this->bestStructureMatch($byStructure[$level.'|'.($parentId ?? '').'|'.$this->nameKey($place['name'])] ?? [], $rows, []);
                if ($sameName !== null) {
                    $idBySource[$place['code']] = $sameName->id;

                    continue;
                }

                $free = ! isset($codeOwner[$code]);
                if (! $free) {
                    $this->recordTaken($summary, $code, $level, $place['name'], $codeOwner[$code], $rows);
                }
                $pending[] = [
                    'source' => $place['code'],
                    'row' => [
                        'level' => $level,
                        'code' => $free ? $code : null,
                        'name' => $place['name'],
                        'parent_id' => $parentId,
                        'base_place_id' => null,
                        'sort_order' => (int) $place['sort_order'],
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ];
                if ($free) {
                    $codeOwner[$code] = 0;
                }
            }

            $this->insert($level, $pending, $rows, $codeOwner, $idBySource);
            $summary['created'] += count($pending);
            $summary['by_level'][$level]['created'] += count($pending);
        }

        $this->linkBasePlaces($payload, $rows, $idBySource, $summary, $now);

        return $summary;
    }

    /**
     * The place the office already has for this row of the list, if any.
     *
     * @param  array{code: string, level: string, name: string, parent_code: ?string, sort_order: int, bn_name: ?string, kind: string}  $place
     * @param  array<int, \stdClass>  $rows
     * @param  array<string, int>  $codeOwner
     * @param  array<string, list<int>>  $byStructure
     * @param  array<int, true>  $claimed
     * @return \stdClass|null
     */
    private function match(array $place, string $code, ?int $parentId, array $rows, array $codeOwner, array $byStructure, array $claimed): ?object
    {
        $byCode = isset($codeOwner[$code]) && $codeOwner[$code] > 0 ? $rows[$codeOwner[$code]] : null;
        if ($place['level'] === PlaceCode::LevelManagement) {
            if ($byCode !== null && $byCode->level === PlaceCode::LevelManagement) {
                return $byCode;
            }
            foreach ($rows as $row) {
                if ($row->level === PlaceCode::LevelManagement && ! isset($claimed[$row->id])) {
                    return $row;
                }
            }

            return null;
        }

        $name = $this->nameKey($place['name']);
        // Under its parent first; a place the office saved without a parent counts too.
        foreach ([$parentId, null] as $parent) {
            $best = $this->bestStructureMatch($byStructure[$place['level'].'|'.($parent ?? '').'|'.$name] ?? [], $rows, $claimed);
            if ($best !== null) {
                return $best;
            }
        }

        if ($byCode !== null && ! isset($claimed[$byCode->id]) && $byCode->level === $place['level']
            && ($byCode->parent_id === null || $byCode->parent_id === $parentId)) {
            return $byCode;
        }

        return null;
    }

    /**
     * Among places with the same level, parent and name (a duplicate the office made, or kept
     * switched off), the live one wins: active before turned off, then coded before code-less,
     * then the oldest. A turned-off duplicate can never become the parent of the places added below.
     *
     * @param  list<int>  $ids
     * @param  array<int, \stdClass>  $rows
     * @param  array<int, true>  $claimed
     */
    private function bestStructureMatch(array $ids, array $rows, array $claimed): ?object
    {
        $best = null;
        foreach ($ids as $id) {
            if (isset($claimed[$id])) {
                continue;
            }
            $row = $rows[$id];
            $score = ($row->is_active ? 2 : 0) + (is_string($row->code) && ! PlaceCode::isPlaceholderLabel($row->code) ? 1 : 0);
            if ($best === null || $score > $best[0]) {
                $best = [$score, $row];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * Fill only what the office left blank: the code, the name and the parent.
     *
     * @param  \stdClass  $row
     * @param  array{code: string, level: string, name: string, parent_code: ?string, sort_order: int, bn_name: ?string, kind: string}  $place
     * @param  array<string, int>  $codeOwner
     * @param  PlaceSummary  $summary
     *
     * @param-out PlaceSummary $summary
     */
    private function fillGaps(object $row, array $place, string $code, ?int $parentId, array &$codeOwner, array &$summary, CarbonInterface $now, array $rows): void
    {
        $changes = [];
        if ($row->code === null || PlaceCode::isPlaceholderLabel($row->code)) {
            if (isset($codeOwner[$code])) {
                $this->recordTaken($summary, $code, $place['level'], $place['name'], $codeOwner[$code], $rows);
            } else {
                $changes['code'] = $code;
                $codeOwner[$code] = (int) $row->id;
                $summary['coded']++;
                $summary['by_level'][$place['level']]['coded']++;
            }
        } elseif (PlaceCode::normalizeCode($row->code) !== $code) {
            // The office chose its own code for this place: kept as it is, only counted.
            $summary['by_level'][$place['level']]['other_code']++;
        }
        if (PlaceCode::isPlaceholderLabel($row->name)) {
            $changes['name'] = $place['name'];
        }
        if ($row->parent_id === null && $parentId !== null) {
            $changes['parent_id'] = $parentId;
        }
        if ($changes === []) {
            return;
        }
        if (array_diff_key($changes, ['code' => true]) !== []) {
            $summary['filled']++;
        }
        DB::table('place_codes')->where('id', $row->id)->update([...$changes, 'updated_at' => $now]);
        foreach ($changes as $column => $value) {
            $row->{$column} = $value;
        }
    }

    /**
     * Upazila headquarters unions found in public sources (database/data/bangladesh-upazila-headquarters.json),
     * only the entries an authoritative source states outright (confidence high).
     *
     * @return array<string, string> Upazila code => union as the source writes it
     */
    public function headquarters(): array
    {
        $path = database_path(self::HeadquartersFile);
        if (! is_file($path)) {
            return [];
        }
        /** @var array{upazilas?: list<array{code: string, hq_union: ?string, confidence: string}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $map = [];
        foreach ($data['upazilas'] ?? [] as $row) {
            if ($row['confidence'] === 'high' && is_string($row['hq_union']) && $row['hq_union'] !== '') {
                $map[$row['code']] = $row['hq_union'];
            }
        }

        return $map;
    }

    /**
     * Every researched headquarters entry, whatever its confidence, for the review file.
     *
     * @return array<string, array{hq_union: ?string, found_in_source: ?string, source_url: ?string, confidence: string, note: ?string}>
     */
    public function headquartersReview(): array
    {
        $path = database_path(self::HeadquartersFile);
        if (! is_file($path)) {
            return [];
        }
        /** @var array{upazilas?: list<array{code: string, hq_union: ?string, found_in_source: ?string, source_url: ?string, confidence: string, note: ?string}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return array_column($data['upazilas'] ?? [], null, 'code');
    }

    /**
     * Sets the base place of every division, district and upazila that has none: first the links
     * of the data file, then the headquarters found by DefaultBasePlaces. A base the office already
     * set is never touched, and a candidate that is turned off, has no code or no name, or is not
     * directly under the place is left out and the place is listed as empty.
     *
     * @param  array{places: list<array{code: string, level: string, name: string, parent_code: ?string, sort_order: int, bn_name: ?string, kind: string}>, base_links: list<array{code: string, base_code: string}>}  $payload
     * @param  array<int, \stdClass>  $rows
     * @param  array<string, int>  $idBySource
     * @param  PlaceSummary  $summary
     *
     * @param-out PlaceSummary $summary
     */
    private function linkBasePlaces(array $payload, array &$rows, array $idBySource, array &$summary, CarbonInterface $now): void
    {
        $derived = (new DefaultBasePlaces($this->headquarters()))->derive($payload['places']);
        $links = $payload['base_links'];
        $fromFile = array_column($links, 'code');
        foreach ($derived['links'] as $link) {
            if (! in_array($link['code'], $fromFile, true)) {
                $links[] = $link;
            }
        }

        /** @var array<string, string> $emptyReason */
        $emptyReason = [];
        /** @var array<string, list<string>> $candidatesOf */
        $candidatesOf = [];
        foreach ($links as $link) {
            $placeId = $idBySource[$link['code']] ?? null;
            $baseId = $idBySource[$link['base_code']] ?? null;
            if ($placeId === null || $baseId === null || $rows[$placeId]->base_place_id !== null) {
                continue;
            }
            $candidate = $rows[$baseId];
            $problem = match (true) {
                $candidate->parent_id !== $placeId => 'candidate_not_below',
                ! $candidate->is_active => 'candidate_turned_off',
                PlaceCode::normalizeCode($candidate->code) === null => 'candidate_no_code',
                PlaceCode::isPlaceholderLabel($candidate->name) => 'candidate_no_name',
                default => null,
            };
            if ($problem !== null) {
                $emptyReason[$link['code']] = $problem;
                $candidatesOf[$link['code']] = [(string) $candidate->name];

                continue;
            }
            DB::table('place_codes')->where('id', $placeId)->update(['base_place_id' => $baseId, 'updated_at' => $now]);
            $rows[$placeId]->base_place_id = $baseId;
            $summary['linked']++;
            $summary['by_level'][(string) $rows[$placeId]->level]['base_set']++;
            $summary['base_set_list'][] = ['code' => $link['code'], 'level' => (string) $rows[$placeId]->level, 'name' => (string) $rows[$placeId]->name, 'base' => (string) $candidate->name, 'rule' => $derived['rules'][$link['code']] ?? 'data_file'];
        }

        $candidates = $candidatesOf;
        foreach ($derived['empty'] as $item) {
            $emptyReason[$item['code']] ??= $item['reason'];
            $candidates[$item['code']] ??= $item['candidates'];
        }
        $parentNames = array_column($payload['places'], 'name', 'code');
        foreach ($payload['places'] as $place) {
            $placeId = $idBySource[$place['code']] ?? null;
            if ($placeId === null || ! isset($emptyReason[$place['code']]) || $rows[$placeId]->base_place_id !== null) {
                continue;
            }
            $summary['base_empty'][] = ['code' => $place['code'], 'level' => $place['level'], 'name' => $place['name'], 'reason' => $emptyReason[$place['code']], 'candidates' => $candidates[$place['code']] ?? [], 'parent' => $parentNames[(string) $place['parent_code']] ?? ''];
        }
    }

    /**
     * A default code that another place already holds: the place goes without a code, the office decides.
     *
     * @param  PlaceSummary  $summary
     *
     * @param-out PlaceSummary $summary
     *
     * @param  array<int, \stdClass>  $rows
     */
    private function recordTaken(array &$summary, string $code, string $level, string $name, int $holderId, array $rows): void
    {
        $holder = $rows[$holderId] ?? null;
        $summary['codes_taken'][] = $code;
        $summary['by_level'][$level]['taken']++;
        $summary['conflicts'][] = [
            'code' => $code,
            'level' => $level,
            'name' => $name,
            'held_by' => $holder === null ? 'a place added in this run' : $holder->level.' '.$holder->name,
        ];
    }

    /**
     * Insert one level in chunks and learn the new ids. Names are unique under a parent in the
     * list, and nothing new under that parent had that name, so parent and name find each row.
     *
     * @param  list<array{source: string, row: array<string, mixed>}>  $pending
     * @param  array<int, \stdClass>  $rows
     * @param  array<string, int>  $codeOwner
     * @param  array<string, int>  $idBySource
     */
    private function insert(string $level, array $pending, array &$rows, array &$codeOwner, array &$idBySource): void
    {
        if ($pending === []) {
            return;
        }
        $lastId = (int) DB::table('place_codes')->max('id');
        foreach (array_chunk(array_column($pending, 'row'), self::InsertChunk) as $chunk) {
            DB::table('place_codes')->insert($chunk);
        }

        $newIds = [];
        foreach (DB::table('place_codes')->where('level', $level)->where('id', '>', $lastId)->get(['id', 'level', 'code', 'name', 'parent_id', 'base_place_id', 'is_active']) as $row) {
            $row->id = (int) $row->id;
            $row->parent_id = $row->parent_id === null ? null : (int) $row->parent_id;
            $row->base_place_id = null;
            $rows[$row->id] = $row;
            $newIds[($row->parent_id ?? '').'|'.$row->name] = $row->id;
            if (is_string($row->code)) {
                $codeOwner[$row->code] = $row->id;
            }
        }
        foreach ($pending as $item) {
            $idBySource[$item['source']] = $newIds[($item['row']['parent_id'] ?? '').'|'.$item['row']['name']]
                ?? throw new RuntimeException('Could not find the new place '.$item['source'].' after adding it.');
        }
    }

    /**
     * @param  \stdClass  $row
     * @param  array<string, int>  $codeOwner
     * @param  array<string, list<int>>  $byStructure
     */
    private function index(object $row, array &$codeOwner, array &$byStructure): void
    {
        if (is_string($row->code) && ! PlaceCode::isPlaceholderLabel($row->code)) {
            $codeOwner[PlaceCode::normalizeCode($row->code) ?? $row->code] ??= (int) $row->id;
        }
        if (! PlaceCode::isPlaceholderLabel($row->name)) {
            $byStructure[$row->level.'|'.($row->parent_id ?? '').'|'.$this->nameKey($row->name)][] = (int) $row->id;
        }
    }

    /** Lower-case letters and digits only, with older spellings read as today's. */
    private function nameKey(string $name): string
    {
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));

        return self::NameAliases[$key] ?? $key;
    }
}

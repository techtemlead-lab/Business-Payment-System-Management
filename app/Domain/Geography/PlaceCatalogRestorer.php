<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;
use Illuminate\Support\Facades\DB;

/**
 * Puts the Bangladesh place-name catalog back after the November 3 clear.
 *
 * That clear deletes every place that is not already linked to a branch,
 * dealer area, or partner profile, and it drops parent links on the rows it
 * keeps. The following restore then exits because the table is not empty.
 * Surviving rows are reused by their MNHC code so those links keep the same id.
 */
final class PlaceCatalogRestorer
{
    public function restoreFromCatalogFile(?string $path = null): void
    {
        $path ??= database_path('data/bangladesh-place-codes.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{places?: list<array<string, mixed>>, base_links?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->restore($payload);
    }

    /**
     * @param  array{places?: list<array<string, mixed>>, base_links?: list<array<string, mixed>>}  $payload
     */
    public function restore(array $payload): void
    {
        $places = $payload['places'] ?? [];
        if ($places === []) {
            return;
        }

        DB::transaction(function () use ($places, $payload): void {
            /** @var array<string, int> $byCode */
            $byCode = [];
            /** @var array<string, list<int>> $byStructure */
            $byStructure = [];
            /** @var array<int, true> $codedIds */
            $codedIds = [];
            /** @var array<int, array{parent_id: ?int, base_place_id: ?int}> $state */
            $state = [];

            foreach (DB::table('place_codes')->orderBy('id')->get(['id', 'level', 'code', 'name', 'parent_id', 'base_place_id']) as $row) {
                $id = (int) $row->id;
                $parentId = $row->parent_id === null ? null : (int) $row->parent_id;
                $basePlaceId = $row->base_place_id === null ? null : (int) $row->base_place_id;
                $state[$id] = ['parent_id' => $parentId, 'base_place_id' => $basePlaceId];
                $byStructure[$this->structureKey((string) $row->level, $parentId, (string) $row->name)][] = $id;
                if (! is_string($row->code) || $row->code === '') {
                    continue;
                }
                $codedIds[$id] = true;
                $byCode[$row->code] = $id;
                $normalized = PlaceCode::normalizeCode($row->code);
                if ($normalized === null) {
                    continue;
                }
                $byCode[$normalized] = $id;
                $byCode[substr($normalized, strlen(PlaceCode::BrandPrefix) + 1)] = $id;
            }
            foreach ($byStructure as &$ids) {
                sort($ids, SORT_NUMERIC);
            }
            unset($ids);

            /** @var array<int, true> $used */
            $used = [];
            /** @var array<string, int> $idBySource */
            $idBySource = [];
            $now = now();

            foreach ($places as $place) {
                $source = (string) $place['code'];
                $level = (string) $place['level'];
                $name = (string) $place['name'];
                $parentSource = $place['parent_code'] ?? null;
                $parentId = is_scalar($parentSource) && (string) $parentSource !== ''
                    ? ($idBySource[(string) $parentSource] ?? null)
                    : null;
                $id = $this->claimByCode($source, $byCode, $used)
                    ?? $this->claimByStructure($level, $parentId, $name, $byStructure, $codedIds, $used);

                if ($id === null) {
                    $id = (int) DB::table('place_codes')->insertGetId([
                        'level' => $level,
                        'code' => null,
                        'name' => $name,
                        'parent_id' => $parentId,
                        'sort_order' => (int) ($place['sort_order'] ?? 0),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $state[$id] = ['parent_id' => $parentId, 'base_place_id' => null];
                } elseif ($state[$id]['parent_id'] !== $parentId) {
                    DB::table('place_codes')->where('id', $id)->update([
                        'parent_id' => $parentId,
                        'updated_at' => $now,
                    ]);
                    $state[$id]['parent_id'] = $parentId;
                }

                $used[$id] = true;
                $idBySource[$source] = $id;
            }

            foreach ($payload['base_links'] ?? [] as $link) {
                $placeId = $idBySource[(string) ($link['code'] ?? '')] ?? null;
                $baseId = $idBySource[(string) ($link['base_code'] ?? '')] ?? null;
                if ($placeId === null || $baseId === null || $state[$placeId]['base_place_id'] === $baseId) {
                    continue;
                }
                DB::table('place_codes')->where('id', $placeId)->update([
                    'base_place_id' => $baseId,
                    'updated_at' => $now,
                ]);
                $state[$placeId]['base_place_id'] = $baseId;
            }
        });
    }

    /**
     * @param  array<string, int>  $byCode
     * @param  array<int, true>  $used
     */
    private function claimByCode(string $source, array $byCode, array $used): ?int
    {
        $keys = [$source];
        $normalized = PlaceCode::normalizeCode($source);
        if ($normalized !== null) {
            $keys[] = $normalized;
            $keys[] = substr($normalized, strlen(PlaceCode::BrandPrefix) + 1);
        }
        foreach ($keys as $key) {
            $id = $byCode[$key] ?? null;
            if ($id !== null && ! isset($used[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<int>>  $byStructure
     * @param  array<int, true>  $codedIds
     * @param  array<int, true>  $used
     */
    private function claimByStructure(string $level, ?int $parentId, string $name, array $byStructure, array $codedIds, array $used): ?int
    {
        foreach ($byStructure[$this->structureKey($level, $parentId, $name)] ?? [] as $id) {
            if (isset($used[$id]) || isset($codedIds[$id])) {
                continue;
            }

            return $id;
        }

        return null;
    }

    private function structureKey(string $level, ?int $parentId, string $name): string
    {
        return $level.'|'.($parentId ?? 0).'|'.mb_strtolower(trim($name));
    }
}

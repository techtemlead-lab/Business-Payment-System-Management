<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Brings a place table built from an older default list in line with the corrected list
 * (database/data/bangladesh-place-fixes.json). Idempotent, nothing is ever deleted:
 *
 * - clear_metro_base: a metropolitan area (Dhaka Metro, ...) is not an upazila, so it gets no upazila
 *   base place. The district base place that points at it is left alone.
 * - move_union: a union that sits under the wrong upazila moves to the right one, found by its code, and
 *   keeps its code. Only when the name still matches.
 * - switch_off_duplicate: the copy of a union listed under two upazilas is switched off (reversible),
 *   unless something refers to it (a dealer seat, a branch, a partner profile, a base place); then it
 *   is only reported.
 *
 * Each action comes back as a row: step, code, name, status (done / already / skipped / referenced / missing), detail.
 *
 * @phpstan-type FixRow array{step: string, code: string, name: string, status: string, detail: string}
 */
final class PlaceDataFixes
{
    public const File = 'data/bangladesh-place-fixes.json';

    /**
     * @return list<FixRow>
     */
    public function apply(): array
    {
        $rows = [];
        $placeFile = database_path(DefaultPlaceCatalog::DataFile);
        $fixFile = database_path(self::File);
        if (! is_file($placeFile) || ! is_file($fixFile)) {
            throw new RuntimeException('Missing '.$placeFile.' or '.$fixFile.'.');
        }
        /** @var array{places: list<array{code: string, level: string, name: string, kind?: string}>} $list */
        $list = json_decode((string) file_get_contents($placeFile), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{moves?: list<array{code: string, name: string, to_parent_code: string}>, duplicates?: list<array{code: string, name: string, keep_code: string}>} $fixes */
        $fixes = json_decode((string) file_get_contents($fixFile), true, 512, JSON_THROW_ON_ERROR);

        foreach ($list['places'] as $place) {
            if ($place['level'] === PlaceCode::LevelUpazila && ($place['kind'] ?? 'upazila') !== 'upazila') {
                $rows[] = $this->clearMetroBase($place);
            }
        }
        foreach ($fixes['moves'] ?? [] as $move) {
            $rows[] = $this->moveUnion($move);
        }
        foreach ($fixes['duplicates'] ?? [] as $duplicate) {
            $rows[] = $this->switchOffDuplicate($duplicate);
        }

        return $rows;
    }

    /**
     * @param  array{code: string, name: string}  $place
     * @return FixRow
     */
    private function clearMetroBase(array $place): array
    {
        $row = $this->find($place['code']);
        if ($row === null) {
            return $this->row('clear_metro_base', $place, 'missing', 'Not in this database.');
        }
        if ($row->base_place_id === null) {
            return $this->row('clear_metro_base', $place, 'already', 'No upazila base place.');
        }
        DB::table('place_codes')->where('id', $row->id)->update(['base_place_id' => null, 'updated_at' => now()]);

        return $this->row('clear_metro_base', $place, 'done', 'Base place #'.$row->base_place_id.' cleared (a metropolitan area is not an upazila).');
    }

    /**
     * @param  array{code: string, name: string, to_parent_code: string}  $move
     * @return FixRow
     */
    private function moveUnion(array $move): array
    {
        $row = $this->find($move['code']);
        $target = $this->find($move['to_parent_code']);
        if ($row === null) {
            return $this->row('move_union', $move, 'missing', 'Not in this database.');
        }
        if ($this->nameKey((string) $row->name) !== $this->nameKey($move['name'])) {
            return $this->row('move_union', $move, 'skipped', 'The place with this code is now called '.$row->name.'; left alone.');
        }
        if ($target === null) {
            return $this->row('move_union', $move, 'missing', 'The upazila '.$move['to_parent_code'].' is not in this database yet.');
        }
        if ((int) $row->parent_id === (int) $target->id) {
            return $this->row('move_union', $move, 'already', 'Already under '.$target->name.'.');
        }
        DB::table('place_codes')->where('id', $row->id)->update(['parent_id' => $target->id, 'updated_at' => now()]);

        return $this->row('move_union', $move, 'done', 'Moved under '.$target->name.' (code unchanged).');
    }

    /**
     * @param  array{code: string, name: string, keep_code: string}  $duplicate
     * @return FixRow
     */
    private function switchOffDuplicate(array $duplicate): array
    {
        $row = $this->find($duplicate['code']);
        $keep = $this->find($duplicate['keep_code']);
        if ($row === null) {
            return $this->row('switch_off_duplicate', $duplicate, 'missing', 'Not in this database.');
        }
        if ($this->nameKey((string) $row->name) !== $this->nameKey($duplicate['name'])) {
            return $this->row('switch_off_duplicate', $duplicate, 'skipped', 'The place with this code is now called '.$row->name.'; left alone.');
        }
        if (! $row->is_active) {
            return $this->row('switch_off_duplicate', $duplicate, 'already', 'Already switched off.');
        }
        if ($keep === null) {
            return $this->row('switch_off_duplicate', $duplicate, 'skipped', 'The copy to keep ('.$duplicate['keep_code'].') is not in this database; left alone.');
        }
        if ($this->isReferenced((int) $row->id, (string) $row->code)) {
            return $this->row('switch_off_duplicate', $duplicate, 'referenced', 'Used by a dealer seat, branch, partner or base place: left on. Move those to '.$keep->name.' ('.$duplicate['keep_code'].') by hand first.');
        }
        DB::table('place_codes')->where('id', $row->id)->update(['is_active' => false, 'updated_at' => now()]);

        return $this->row('switch_off_duplicate', $duplicate, 'done', 'Switched off; the copy to keep is '.$keep->name.' ('.$duplicate['keep_code'].').');
    }

    private function find(string $code): ?object
    {
        $normalized = PlaceCode::normalizeCode($code);

        return $normalized === null ? null : DB::table('place_codes')->where('code', $normalized)->first(['id', 'code', 'name', 'parent_id', 'base_place_id', 'is_active']);
    }

    /** Same references as the older clean-up migrations: dealer seats, branches, base places, partner profiles. */
    private function isReferenced(int $placeId, string $code): bool
    {
        if (Schema::hasTable('area_dealer_assignments') && DB::table('area_dealer_assignments')->where('place_code_id', $placeId)->exists()) {
            return true;
        }
        if (Schema::hasTable('company_branches') && DB::table('company_branches')->where('place_code_id', $placeId)->exists()) {
            return true;
        }
        if (DB::table('place_codes')->where('base_place_id', $placeId)->orWhere('parent_id', $placeId)->exists()) {
            return true;
        }
        if (! Schema::hasTable('users')) {
            return false;
        }

        return DB::table('users')
            ->where(function ($query) use ($placeId, $code): void {
                $query->whereRaw("COALESCE(profile->>'place_code_id', '') = ?", [(string) $placeId])
                    ->orWhereRaw("COALESCE(profile->>'place_code', '') = ?", [$code]);
            })
            ->exists();
    }

    /**
     * @param  array{code: string, name: string}  $place
     * @return FixRow
     */
    private function row(string $step, array $place, string $status, string $detail): array
    {
        return ['step' => $step, 'code' => $place['code'], 'name' => $place['name'], 'status' => $status, 'detail' => $detail];
    }

    private function nameKey(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }
}

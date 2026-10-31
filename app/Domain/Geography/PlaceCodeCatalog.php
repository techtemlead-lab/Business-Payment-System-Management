<?php

namespace App\Domain\Geography;

use App\Models\AreaDealerAssignment;
use App\Models\CompanyBranch;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlaceCodeCatalog
{
    /** @var array<string, string|null> */
    public const ParentLevel = [
        PlaceCode::LevelManagement => null,
        PlaceCode::LevelDivision => PlaceCode::LevelManagement,
        PlaceCode::LevelDistrict => PlaceCode::LevelDivision,
        PlaceCode::LevelUpazila => PlaceCode::LevelDistrict,
        PlaceCode::LevelUnion => PlaceCode::LevelUpazila,
    ];

    /** @var array<string, string|null> */
    public const ChildLevel = [
        PlaceCode::LevelManagement => PlaceCode::LevelDivision,
        PlaceCode::LevelDivision => PlaceCode::LevelDistrict,
        PlaceCode::LevelDistrict => PlaceCode::LevelUpazila,
        PlaceCode::LevelUpazila => PlaceCode::LevelUnion,
        PlaceCode::LevelUnion => null,
    ];

    /** Levels that show a search bar in the admin module. */
    /** @var list<string> */
    public const SearchableLevels = [
        PlaceCode::LevelDistrict,
        PlaceCode::LevelUpazila,
        PlaceCode::LevelUnion,
    ];

    public function assertLevel(string $level): string
    {
        if (! in_array($level, PlaceCode::Levels, true)) {
            throw ValidationException::withMessages(['level' => __('portal.err_pc_invalid_level')]);
        }

        return $level;
    }

    public static function localizedLevelLabel(string $level): string
    {
        return __('portal.pc_lvl_'.$level);
    }

    /**
     * @return LengthAwarePaginator<int, PlaceCode>
     */
    public function paginate(string $level, ?string $query = null, int $perPage = 30): LengthAwarePaginator
    {
        $level = $this->assertLevel($level);

        return PlaceCode::query()
            ->with(['parent:id,code,name,level', 'basePlace:id,code,name,level'])
            ->where('level', $level)
            ->when(
                $query !== null && $query !== '' && in_array($level, self::SearchableLevels, true),
                function ($builder) use ($query): void {
                    $term = '%'.mb_strtolower(trim($query)).'%';
                    $builder->where(function ($inner) use ($term): void {
                        $inner->whereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereHas('parent', fn ($parent) => $parent->whereRaw('LOWER(name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term]));
                    });
                }
            )
            ->orderBy('sort_order')
            ->orderByRaw('code ASC NULLS LAST')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{code?:string|null,name?:string|null,parent_id?:int|null,base_place_id?:int|null,sort_order?:int,is_active?:bool}  $input
     */
    public function create(string $level, array $input, ?int $actorId = null): PlaceCode
    {
        $level = $this->assertLevel($level);
        $payload = $this->validatedPayload($level, $input);

        return DB::transaction(function () use ($level, $payload, $actorId): PlaceCode {
            $place = PlaceCode::create([
                'level' => $level,
                ...$payload,
            ]);
            Audit::record($actorId, 'place_code.created', (string) $place->id, [
                'level' => $level,
                'code' => $place->code,
                'name' => $place->name,
            ]);

            return $place->load(['parent', 'basePlace']);
        }, 3);
    }

    /**
     * @param  array{code?:string|null,name?:string|null,parent_id?:int|null,base_place_id?:int|null,sort_order?:int,is_active?:bool}  $input
     */
    public function update(PlaceCode $place, array $input, ?int $actorId = null): PlaceCode
    {
        $payload = $this->validatedPayload($place->level, [
            'code' => array_key_exists('code', $input) ? $input['code'] : $place->code,
            'name' => array_key_exists('name', $input) ? $input['name'] : $place->name,
            'parent_id' => array_key_exists('parent_id', $input) ? $input['parent_id'] : $place->parent_id,
            'base_place_id' => array_key_exists('base_place_id', $input) ? $input['base_place_id'] : $place->base_place_id,
            'sort_order' => $input['sort_order'] ?? $place->sort_order,
            'is_active' => $input['is_active'] ?? $place->is_active,
        ], $place);

        return DB::transaction(function () use ($place, $payload, $actorId): PlaceCode {
            $place->update($payload);
            Audit::record($actorId, 'place_code.updated', (string) $place->id, [
                'level' => $place->level,
                'code' => $place->code,
                'name' => $place->name,
            ]);

            return $place->fresh(['parent', 'basePlace']);
        }, 3);
    }

    public function delete(PlaceCode $place, string $reason, ?int $actorId = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => __('portal.err_pc_delete_reason')]);
        }

        if ($place->children()->exists()) {
            throw ValidationException::withMessages(['place' => __('portal.err_pc_delete_has_children')]);
        }

        if (AreaDealerAssignment::query()->where('place_code_id', $place->id)->exists()) {
            throw ValidationException::withMessages(['place' => __('portal.err_pc_delete_has_assignments')]);
        }

        if (CompanyBranch::query()->where('place_code_id', $place->id)->exists()) {
            throw ValidationException::withMessages(['place' => __('portal.err_pc_delete_has_assignments')]);
        }

        if (PlaceCode::query()->where('base_place_id', $place->id)->exists()) {
            throw ValidationException::withMessages(['place' => __('portal.err_pc_delete_has_children')]);
        }

        if ($place->code !== null) {
            $normalized = PlaceCode::normalizeCode($place->code) ?? $place->code;
            $legacy = substr((string) $normalized, strlen(PlaceCode::BrandPrefix) + 1);
            $dealerLinked = User::query()
                ->where(function ($query) use ($place, $normalized, $legacy): void {
                    $query->whereRaw("COALESCE(profile->>'place_code_id', '') = ?", [(string) $place->id])
                        ->orWhereRaw("COALESCE(profile->>'place_code', '') IN (?, ?)", [$normalized, $legacy]);
                })
                ->exists();
            if ($dealerLinked) {
                throw ValidationException::withMessages(['place' => __('portal.err_pc_delete_has_assignments')]);
            }
        }

        DB::transaction(function () use ($place, $reason, $actorId): void {
            $snapshot = [
                'level' => $place->level,
                'code' => $place->code,
                'name' => $place->name,
                'reason' => $reason,
            ];
            $place->delete();
            Audit::record($actorId, 'place_code.deleted', (string) $place->id, $snapshot);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{code:?string,name:string,parent_id:?int,base_place_id:?int,sort_order:int,is_active:bool}
     */
    private function validatedPayload(string $level, array $input, ?PlaceCode $existing = null): array
    {
        $rawCode = trim((string) ($input['code'] ?? ''));
        $rawName = trim((string) ($input['name'] ?? ''));

        if (PlaceCode::isPlaceholderLabel($rawCode)) {
            $code = null;
        } else {
            $code = PlaceCode::normalizeCode($rawCode);
            if ($code === null) {
                throw ValidationException::withMessages(['code' => __('portal.err_pc_code_format')]);
            }
        }

        if ($code === null) {
            $name = PlaceCode::PlaceholderLabel;
        } else {
            if (PlaceCode::isPlaceholderLabel($rawName)) {
                throw ValidationException::withMessages(['name' => __('portal.err_pc_name_required')]);
            }
            $name = $rawName;
        }

        if ($code !== null) {
            $legacy = substr($code, strlen(PlaceCode::BrandPrefix) + 1);
            $duplicate = PlaceCode::query()
                ->whereIn('code', [$code, $legacy])
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['code' => __('portal.err_pc_code_taken')]);
            }
        }

        $parentLevel = self::ParentLevel[$level];
        $parentId = $input['parent_id'] ?? null;
        if ($parentLevel === null) {
            if ($parentId !== null) {
                throw ValidationException::withMessages(['parent_id' => __('portal.err_pc_management_no_parent')]);
            }
            $parentId = null;
        } else {
            if ($parentId === null) {
                throw ValidationException::withMessages(['parent_id' => __('portal.err_pc_parent_required', [
                    'level' => mb_strtolower(self::localizedLevelLabel($parentLevel)),
                ])]);
            }
            $parent = PlaceCode::query()->whereKey($parentId)->where('level', $parentLevel)->where('is_active', true)->first();
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => __('portal.err_pc_parent_invalid')]);
            }
        }

        $basePlaceId = $input['base_place_id'] ?? null;
        if ($basePlaceId !== null) {
            if (! $existing) {
                throw ValidationException::withMessages(['base_place_id' => __('portal.err_pc_base_after_save')]);
            }
            $childLevel = self::ChildLevel[$level];
            if ($childLevel === null) {
                throw ValidationException::withMessages(['base_place_id' => __('portal.err_pc_union_no_base')]);
            }
            $base = PlaceCode::query()
                ->whereKey($basePlaceId)
                ->where('level', $childLevel)
                ->where('parent_id', $existing->id)
                ->where('is_active', true)
                ->first();
            if (! $base || ! $base->isReadyForAssignment()) {
                throw ValidationException::withMessages(['base_place_id' => __('portal.err_pc_base_invalid')]);
            }
        }

        return [
            'code' => $code,
            'name' => $name,
            'parent_id' => $parentId !== null ? (int) $parentId : null,
            'base_place_id' => $basePlaceId !== null ? (int) $basePlaceId : null,
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }

    /**
     * @return list<array{id:int,code:string,name:string,level:string}>
     */
    public function optionsForParent(string $level): array
    {
        $parentLevel = self::ParentLevel[$level] ?? null;
        if ($parentLevel === null) {
            return [];
        }

        return PlaceCode::query()
            ->where('level', $parentLevel)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByRaw('code ASC NULLS LAST')
            ->get(['id', 'code', 'name', 'level'])
            ->map(fn (PlaceCode $place): array => [
                'id' => $place->id,
                'code' => PlaceCode::displayLabel($place->code),
                'name' => PlaceCode::displayLabel($place->name),
                'level' => $place->level,
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,code:string,name:string,level:string}>
     */
    public function optionsForBase(PlaceCode $place): array
    {
        $childLevel = self::ChildLevel[$place->level] ?? null;
        if ($childLevel === null) {
            return [];
        }

        return PlaceCode::query()
            ->where('parent_id', $place->id)
            ->where('level', $childLevel)
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level', 'is_active'])
            ->filter(fn (PlaceCode $child): bool => $child->isReadyForAssignment())
            ->map(fn (PlaceCode $child): array => [
                'id' => $child->id,
                'code' => PlaceCode::displayLabel($child->code),
                'name' => PlaceCode::displayLabel($child->name),
                'level' => $child->level,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function modulePayload(string $level, ?string $query = null): array
    {
        $level = $this->assertLevel($level);
        $places = $this->paginate($level, $query);

        return [
            'level' => $level,
            'level_label' => self::localizedLevelLabel($level),
            'searchable' => in_array($level, self::SearchableLevels, true),
            'filters' => ['q' => $query ?? '', 'level' => $level],
            'submodules' => collect(PlaceCode::Levels)->map(fn (string $key): array => [
                'key' => $key,
                'label' => self::localizedLevelLabel($key),
                'href' => '/place-codes?level='.$key,
            ])->values()->all(),
            'parent_options' => $this->optionsForParent($level),
            'places' => $places->through(function (PlaceCode $place): array {
                return [
                    'id' => $place->id,
                    'code' => PlaceCode::displayLabel($place->code),
                    'name' => PlaceCode::displayLabel($place->name),
                    'level' => $place->level,
                    'parent_id' => $place->parent_id,
                    'parent_code' => $place->parent ? PlaceCode::displayLabel($place->parent->code) : null,
                    'parent_name' => $place->parent ? PlaceCode::displayLabel($place->parent->name) : null,
                    'base_place_id' => $place->base_place_id,
                    'base_place_code' => $place->basePlace ? PlaceCode::displayLabel($place->basePlace->code) : null,
                    'base_place_name' => $place->basePlace ? PlaceCode::displayLabel($place->basePlace->name) : null,
                    'base_options' => $this->optionsForBase($place),
                    'sort_order' => $place->sort_order,
                    'is_active' => $place->is_active,
                    'is_placeholder' => ! $place->isReadyForAssignment(),
                    'created_at' => $place->created_at?->toIso8601String(),
                ];
            }),
        ];
    }
}

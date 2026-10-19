<?php

namespace App\Models;

use Database\Factories\PlaceCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaceCode extends Model
{
    /** @use HasFactory<PlaceCodeFactory> */
    use HasFactory;

    public const LevelManagement = 'management';

    public const LevelDivision = 'division';

    public const LevelDistrict = 'district';

    public const LevelUpazila = 'upazila';

    public const LevelUnion = 'union';

    /** @var list<string> */
    public const Levels = [
        self::LevelManagement,
        self::LevelDivision,
        self::LevelDistrict,
        self::LevelUpazila,
        self::LevelUnion,
    ];

    /** @var list<string> */
    public const AssignmentLevels = [
        self::LevelDivision,
        self::LevelDistrict,
        self::LevelUpazila,
        self::LevelUnion,
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function basePlace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_place_id');
    }

    public function areaAssignments(): HasMany
    {
        return $this->hasMany(AreaDealerAssignment::class);
    }

    public function isManagement(): bool
    {
        return $this->level === self::LevelManagement;
    }

    /** @return array{division:?string,district:?string,upazila:?string,union:?string} */
    public function pathNames(): array
    {
        $path = [
            'division' => null,
            'district' => null,
            'upazila' => null,
            'union' => null,
        ];
        $cursor = $this;
        $guard = 0;
        while ($cursor && $guard++ < 8) {
            if (array_key_exists($cursor->level, $path)) {
                $path[$cursor->level] = $cursor->name;
            }
            $cursor = $cursor->parent;
        }

        return $path;
    }
}

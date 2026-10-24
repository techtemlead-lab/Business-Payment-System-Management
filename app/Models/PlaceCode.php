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

    public const BrandPrefix = 'MNHC';

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

    /**
     * Normalize a place / management code to the branded form MNHC-{digits}.
     * Accepts bare digits (100) or already-prefixed values (MNHC-100).
     */
    public static function normalizeCode(?string $code): ?string
    {
        $raw = strtoupper(trim((string) $code));
        if ($raw === '') {
            return null;
        }

        $prefix = self::BrandPrefix.'-';
        if (str_starts_with($raw, $prefix)) {
            $numeric = substr($raw, strlen($prefix));
        } elseif (str_starts_with($raw, self::BrandPrefix)) {
            $numeric = ltrim(substr($raw, strlen(self::BrandPrefix)), '-');
        } else {
            $numeric = $raw;
        }

        if (! preg_match('/^\d{2,8}$/', $numeric)) {
            return null;
        }

        return $prefix.$numeric;
    }

    /**
     * Prefix a dealer / sub-dealer serial that embeds a place code.
     * Examples: 100 → MNHC-100, 501-001 → MNHC-501-001, MNHC-501-001 → unchanged.
     */
    public static function brandSerial(?string $serial): ?string
    {
        $raw = strtoupper(trim((string) $serial));
        if ($raw === '') {
            return null;
        }

        $prefix = self::BrandPrefix.'-';
        if (str_starts_with($raw, $prefix)) {
            return $raw;
        }

        if (preg_match('/^(\d{2,8})(?:-([A-Z0-9]{1,16}))?$/', $raw, $matches) !== 1) {
            return $raw;
        }

        return $prefix.$matches[1].(isset($matches[2]) && $matches[2] !== '' ? '-'.$matches[2] : '');
    }

    public static function findByCode(string $code, bool $activeOnly = true): ?self
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === null) {
            return null;
        }

        $legacy = substr($normalized, strlen(self::BrandPrefix) + 1);

        return self::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->where(function ($q) use ($normalized, $legacy): void {
                $q->where('code', $normalized)->orWhere('code', $legacy);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$normalized])
            ->first();
    }

    protected static function booted(): void
    {
        static::saving(function (PlaceCode $place): void {
            $normalized = self::normalizeCode($place->code);
            if ($normalized !== null) {
                $place->code = $normalized;
            }
        });
    }

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

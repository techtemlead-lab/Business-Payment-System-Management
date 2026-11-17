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

    public const PlaceholderLabel = '—';

    /**
     * True for empty / hyphen placeholders awaiting admin fill-in.
     */
    public static function isPlaceholderLabel(?string $value): bool
    {
        $raw = trim((string) $value);

        return $raw === '' || $raw === '-' || $raw === self::PlaceholderLabel || $raw === '–';
    }

    /**
     * Display null/empty/hyphen place values as an em dash.
     */
    public static function displayLabel(?string $value): string
    {
        return self::isPlaceholderLabel($value) ? self::PlaceholderLabel : (string) $value;
    }

    /**
     * A place is ready for dealer/area assignment only when it has a real branded code and name.
     */
    public function isReadyForAssignment(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->code === null || self::isPlaceholderLabel($this->code)) {
            return false;
        }

        if (self::normalizeCode($this->code) === null) {
            return false;
        }

        if (self::isPlaceholderLabel($this->name)) {
            return false;
        }

        return true;
    }

    /**
     * The digits of a place / management code, whichever way it was typed:
     * 100, MNHC100, MNHC-100 and "mnhc 100" all give 100. Null when it is not a code.
     */
    public static function codeDigits(?string $code): ?string
    {
        $raw = self::compact($code);
        if ($raw === '' || self::isPlaceholderLabel($raw)) {
            return null;
        }

        $numeric = str_starts_with($raw, self::BrandPrefix)
            ? ltrim(substr($raw, strlen(self::BrandPrefix)), '-')
            : $raw;

        return preg_match('/^\d{2,8}$/D', $numeric) === 1 ? $numeric : null;
    }

    /**
     * Normalize a place / management code to the branded form MNHC{digits}, e.g. MNHC100.
     * Accepts bare digits (100), the older hyphen form (MNHC-100), any case and spaces.
     */
    public static function normalizeCode(?string $code): ?string
    {
        $digits = self::codeDigits($code);

        return $digits === null ? null : self::BrandPrefix.$digits;
    }

    /**
     * Every spelling a stored place code may have: the current MNHC100, the older MNHC-100
     * (cards printed before the change) and the bare digits 100 (rows from before the brand).
     *
     * @return list<string>
     */
    public static function storedCodeForms(?string $code): array
    {
        $digits = self::codeDigits($code);
        if ($digits === null) {
            return [];
        }

        return [self::BrandPrefix.$digits, self::BrandPrefix.'-'.$digits, $digits];
    }

    /**
     * Prefix a dealer / sub-dealer serial that embeds a place code. The only hyphen kept is the
     * one between the place code and the serial. Examples: 100 → MNHC100,
     * 100001-000001 → MNHC100001-000001, MNHC-100001-000001 → MNHC100001-000001.
     * Anything else is returned upper-cased and unchanged.
     */
    public static function brandSerial(?string $serial): ?string
    {
        $raw = self::compact($serial);
        if ($raw === '') {
            return null;
        }

        $parts = self::serialParts($raw);
        if ($parts === null) {
            return $raw;
        }

        return self::BrandPrefix.$parts['digits'].($parts['serial'] !== '' ? '-'.$parts['serial'] : '');
    }

    /**
     * Every spelling a typed dealer, sub-dealer or refer code may be stored under, upper-cased:
     * as typed, the current MNHC100001-000001, the older MNHC-100001-000001 and the bare
     * 100001-000001. Old ID cards and shared links keep working this way.
     *
     * @return list<string>
     */
    public static function serialForms(?string $code): array
    {
        $typed = strtoupper(trim((string) $code));
        if ($typed === '') {
            return [];
        }

        $forms = [$typed];
        $parts = self::serialParts(self::compact($typed));
        if ($parts !== null) {
            $tail = $parts['serial'] !== '' ? '-'.$parts['serial'] : '';
            $forms[] = self::BrandPrefix.$parts['digits'].$tail;
            $forms[] = self::BrandPrefix.'-'.$parts['digits'].$tail;
            $forms[] = $parts['digits'].$tail;
        }

        return array_values(array_unique($forms));
    }

    public static function findByCode(string $code, bool $activeOnly = true): ?self
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === null) {
            return null;
        }

        return self::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->whereIn('code', self::storedCodeForms($normalized))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$normalized])
            ->first();
    }

    /** Upper-cased with every space removed, so "mnhc 100" reads as MNHC100. */
    private static function compact(?string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', (string) $value));
    }

    /**
     * The place-code digits and the serial after them, without the brand prefix.
     *
     * @return array{digits: string, serial: string}|null
     */
    private static function serialParts(string $compact): ?array
    {
        $body = str_starts_with($compact, self::BrandPrefix)
            ? ltrim(substr($compact, strlen(self::BrandPrefix)), '-')
            : $compact;

        if (preg_match('/^(\d{2,8})(?:-([A-Z0-9]{1,16}))?$/D', $body, $matches) !== 1) {
            return null;
        }

        return ['digits' => $matches[1], 'serial' => $matches[2] ?? ''];
    }

    protected static function booted(): void
    {
        static::saving(function (PlaceCode $place): void {
            if ($place->code === null || self::isPlaceholderLabel($place->code)) {
                $place->code = null;
            } else {
                $normalized = self::normalizeCode($place->code);
                if ($normalized !== null) {
                    $place->code = $normalized;
                }
            }

            if (self::isPlaceholderLabel($place->name)) {
                $place->name = self::PlaceholderLabel;
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

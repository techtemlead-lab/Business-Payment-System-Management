<?php

namespace App\Domain\Accounts;

use Illuminate\Support\Facades\Cache;

/**
 * Bangla names for the English place names saved on a partner (division, district, upazila, union),
 * taken from database/data/bangladesh-geo.json.
 *
 * Names were typed or picked in English with many spellings (Pirgonj and Pirganj, Chattagram and
 * Chittagong), so a name is matched forgivingly: first by its letters only (ignoring case, spaces and
 * punctuation), then by its consonants only. A name is looked up inside the place above it first
 * ("Sadar" under the right district); only when that place is unknown does it fall back to a name that
 * is the same wherever it appears. When nothing matches, the saved name is returned unchanged.
 */
final class BanglaPlaceNames
{
    /** Spellings whose consonants differ from the data file's spelling. */
    private const Aliases = ['chittagong' => 'chattagram', 'chattogram' => 'chattagram', 'dacca' => 'dhaka'];

    /** @var array<string, mixed>|null */
    private static ?array $index = null;

    /**
     * @param  array{division?: ?string, district?: ?string, upazila?: ?string, union?: ?string}  $names
     * @return array{division: ?string, district: ?string, upazila: ?string, union: ?string}
     */
    public static function translate(array $names): array
    {
        $index = self::index();
        $division = self::find($index['divisions'], $names['division'] ?? null, $index['flat']['division']);
        $district = self::find($division['children'] ?? [], $names['district'] ?? null, $index['flat']['district']);
        $upazila = self::find($district['children'] ?? [], $names['upazila'] ?? null, $index['flat']['upazila']);
        $union = self::find($upazila['children'] ?? [], $names['union'] ?? null, $index['flat']['union']);
        // R1: Pourashavas and city corporations are not in the union list of the geo file, only in the place-code data.
        $municipal = $union === null ? self::findMunicipal($index['municipal'], $names['upazila'] ?? null, $names['union'] ?? null) : null;
        $metroUpazila = $upazila === null && is_string($names['upazila'] ?? null) ? ($index['municipal']['metro'][self::exactKey($names['upazila'])] ?? null) : null;

        return [
            'division' => $division['bn'] ?? ($names['division'] ?? null),
            'district' => $district['bn'] ?? ($names['district'] ?? null),
            'upazila' => $upazila['bn'] ?? $metroUpazila ?? ($names['upazila'] ?? null),
            'union' => $union['bn'] ?? $municipal ?? ($names['union'] ?? null),
        ];
    }

    /**
     * The key of a Pourashava or city corporation name: the spelling variants Pourashava, Paurashava,
     * Pouroshova and Municipality fold into one, and a city corporation gets its own marker.
     */
    public static function municipalKey(string $name): string
    {
        $key = self::exactKey($name);
        if (preg_match('/^(.*?)(?:citycorporation|corporation)$/', $key, $m) === 1 && $m[1] !== '') {
            return $m[1].'|city';
        }

        return preg_replace('/(?:pourashava|paurashava|pouroshova|pourosova|porashava|pouroshava|municipality|municipal|pouro|paura)$/', '', $key) ?? $key;
    }

    /** The letters of a name only, lower case; Bangla and other scripts pass through as they are. */
    public static function exactKey(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $key) ?? '';

        return self::Aliases[$key] ?? $key;
    }

    /** The consonants of an English name, with the usual spelling pairs folded together. */
    public static function consonantKey(string $name): string
    {
        $key = self::exactKey($name);
        if ($key === '' || preg_match('/[^a-z0-9]/', $key) === 1) {
            return '';
        }
        $key = strtr(str_replace(['sh', 'ph', 'kh', 'gh', 'th', 'dh'], ['s', 'f', 'k', 'g', 't', 'd'], $key), ['w' => 'b', 'v' => 'b', 'z' => 'j', 'q' => 'k', 'x' => 'ks', 'y' => '']);
        $key = preg_replace('/[aeiou]/', '', $key) ?? '';

        return preg_replace('/(.)\1+/', '$1', $key) ?? '';
    }

    /**
     * @param  array{byUpazila: array<string, string>, flat: array<string, string|null>, metro: array<string, string>}  $municipal
     */
    private static function findMunicipal(array $municipal, mixed $upazila, mixed $name): ?string
    {
        if (! is_string($name) || trim($name) === '' || trim($name) === '—') {
            return null;
        }
        $key = self::municipalKey($name);
        if (is_string($upazila) && trim($upazila) !== '') {
            $inside = $municipal['byUpazila'][self::exactKey($upazila).'|'.$key] ?? null;
            if ($inside !== null) {
                return $inside;
            }
        }

        // Without a matching upazila, only a name that says Pourashava or City Corporation itself is safe:
        // a bare "Saidpur" is far more likely a union.
        if ($key === self::exactKey($name)) {
            return null;
        }

        return $municipal['flat'][$key] ?? null;
    }

    /**
     * @param  array{e?: array<string, array<string, mixed>>, s?: array<string, array<string, mixed>|null>}  $level
     * @param  array{e: array<string, string|null>, s: array<string, string|null>}  $flat
     * @return array<string, mixed>|null
     */
    private static function find(array $level, mixed $name, array $flat): ?array
    {
        if (! is_string($name) || trim($name) === '' || trim($name) === '—') {
            return null;
        }
        $exact = self::exactKey($name);
        $consonants = self::consonantKey($name);
        if (isset($level['e'][$exact])) {
            return $level['e'][$exact];
        }
        if ($consonants !== '' && isset($level['s'][$consonants])) {
            return $level['s'][$consonants];
        }
        // The place above is unknown, so only a name that means the same wherever it appears is safe.
        if ($level === []) {
            $bn = $flat['e'][$exact] ?? ($consonants !== '' ? ($flat['s'][$consonants] ?? null) : null);

            return $bn === null ? null : ['bn' => $bn, 'children' => []];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $path = database_path('data/bangladesh-geo.json');
        $codes = database_path('data/bangladesh-place-codes.json');
        $key = 'bangla-place-names:v2:'.(string) @filemtime($path).':'.(string) @filemtime($codes);

        return self::$index = Cache::rememberForever($key, fn (): array => self::build($path, $codes));
    }

    /** @return array<string, mixed> */
    private static function build(string $path, string $codes): array
    {
        $tree = json_decode((string) file_get_contents($path), true);
        $flat = ['division' => ['e' => [], 's' => []], 'district' => ['e' => [], 's' => []], 'upazila' => ['e' => [], 's' => []], 'union' => ['e' => [], 's' => []]];
        $remember = function (string $level, string $name, string $bn) use (&$flat): void {
            foreach ([['e', self::exactKey($name)], ['s', self::consonantKey($name)]] as [$map, $key]) {
                if ($key === '') {
                    continue;
                }
                // A name that is written with different Bangla in different places is ambiguous: keep none.
                $flat[$level][$map][$key] = array_key_exists($key, $flat[$level][$map]) && $flat[$level][$map][$key] !== $bn ? null : $bn;
            }
        };
        $add = function (array &$level, string $name, string $bn, array $children = []): void {
            $node = ['bn' => $bn, 'children' => $children];
            $level['e'][self::exactKey($name)] = $node;
            $short = self::consonantKey($name);
            if ($short !== '') {
                // Two names with the same consonants in one place are ambiguous: keep neither.
                $level['s'][$short] = array_key_exists($short, $level['s'] ?? []) ? null : $node;
            }
        };
        $divisions = ['e' => [], 's' => []];
        foreach (is_array($tree) ? $tree : [] as $division) {
            $districts = ['e' => [], 's' => []];
            foreach ($division['districts'] ?? [] as $district) {
                $upazilas = ['e' => [], 's' => []];
                foreach ($district['upazilas'] ?? [] as $upazila) {
                    $unions = ['e' => [], 's' => []];
                    foreach ($upazila['unions'] ?? [] as $union) {
                        if (self::usable($union)) {
                            $remember('union', $union['name'], $union['bn_name']);
                            $add($unions, $union['name'], $union['bn_name']);
                        }
                    }
                    if (self::usable($upazila)) {
                        $remember('upazila', $upazila['name'], $upazila['bn_name']);
                        $add($upazilas, $upazila['name'], $upazila['bn_name'], $unions);
                    }
                }
                if (self::usable($district)) {
                    $remember('district', $district['name'], $district['bn_name']);
                    $add($districts, $district['name'], $district['bn_name'], $upazilas);
                }
            }
            if (self::usable($division)) {
                $remember('division', $division['name'], $division['bn_name']);
                $add($divisions, $division['name'], $division['bn_name'], $districts);
            }
        }

        return ['divisions' => $divisions, 'flat' => $flat, 'municipal' => self::buildMunicipal($codes)];
    }

    /**
     * Pourashavas and city corporations (and the metro "upazilas" that hold city corporations) from the
     * place-code data. A Pourashava is written with "পৌরসভা" after its name, a city corporation already
     * carries its own word in the data.
     *
     * @return array{byUpazila: array<string, string>, flat: array<string, string|null>, metro: array<string, string>}
     */
    private static function buildMunicipal(string $codes): array
    {
        $data = json_decode((string) @file_get_contents($codes), true);
        $places = is_array($data) && is_array($data['places'] ?? null) ? $data['places'] : [];
        $nameOf = [];
        foreach ($places as $place) {
            if (is_array($place) && isset($place['code'], $place['name'])) {
                $nameOf[(string) $place['code']] = (string) $place['name'];
            }
        }
        $out = ['byUpazila' => [], 'flat' => [], 'metro' => []];
        foreach ($places as $place) {
            if (! is_array($place) || ! is_string($place['name'] ?? null) || ! is_string($place['bn_name'] ?? null) || trim($place['bn_name']) === '') {
                continue;
            }
            $kind = $place['kind'] ?? null;
            if ($kind === 'metro_upazila') {
                $out['metro'][self::exactKey($place['name'])] = $place['bn_name'];

                continue;
            }
            if ($kind !== 'pourashava' && $kind !== 'city_corporation') {
                continue;
            }
            $bn = $kind === 'pourashava' ? trim($place['bn_name']).' পৌরসভা' : trim($place['bn_name']);
            $key = self::municipalKey($place['name']);
            $out['flat'][$key] = array_key_exists($key, $out['flat']) && $out['flat'][$key] !== $bn ? null : $bn;
            $parent = $nameOf[(string) ($place['parent_code'] ?? '')] ?? null;
            if ($parent !== null) {
                $out['byUpazila'][self::exactKey($parent).'|'.$key] = $bn;
            }
        }

        return $out;
    }

    private static function usable(mixed $place): bool
    {
        return is_array($place) && is_string($place['name'] ?? null) && is_string($place['bn_name'] ?? null) && trim($place['bn_name']) !== '';
    }
}

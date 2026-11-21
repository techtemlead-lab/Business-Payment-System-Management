<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;

/**
 * Sanity checks of the default place list (database/data/bangladesh-place-codes.json), for the owner's
 * review. Nothing is deleted or moved: rows that are not what they look like are listed, and the base
 * place rules leave the flagged unions out.
 *
 * - not_a_real_upazila: metropolitan areas (Dhaka Metro, Chattogram City, ...) are typed upazila in the
 *   list, but Bangladesh has 495 upazilas and these four are not among them.
 * - union_listed_under_two_upazilas: a new upazila was cut from an older one and the unions of both
 *   are listed under both (Karnafuli / Patiya, Lalmai / Sadarsouth).
 * - union_under_wrong_upazila: a union named like an upazila of the same district that has no unions
 *   of its own, listed under another upazila (Eidgaon, Guimara, Madhyanagar).
 * - upazila_duplicate, upazila_bad_parent, union_bad_parent: wrongly typed or parented rows.
 *
 * @phpstan-type Issue array{issue: string, code: string, name: string, parent: string, detail: string}
 */
final class PlaceListChecks
{
    /** Two upazilas that share at least this many same-named unions are a split, not a coincidence of names. */
    public const SharedUnionsToCallItASplit = 3;

    /**
     * @param  list<array{code: string, level: string, name: string, parent_code: ?string, kind?: string}>  $places
     * @return list<Issue>
     */
    public function review(array $places): array
    {
        $byCode = [];
        foreach ($places as $place) {
            $byCode[$place['code']] = $place;
        }
        /** @var list<Issue> $issues */
        $issues = [];
        $add = function (string $issue, array $p, string $detail) use (&$issues, $byCode): void {
            $issues[] = ['issue' => $issue, 'code' => $p['code'], 'name' => $p['name'], 'parent' => (string) ($byCode[(string) $p['parent_code']]['name'] ?? ''), 'detail' => $detail];
        };

        $upazilas = [];
        $unionsOf = [];
        $seenUpazila = [];
        foreach ($places as $place) {
            if ($place['level'] === PlaceCode::LevelUpazila) {
                if (($place['kind'] ?? 'upazila') !== 'upazila') {
                    $add('not_a_real_upazila', $place, 'Metropolitan area typed as an upazila in the list; not one of the 495 upazilas. No base place.');

                    continue;
                }
                $upazilas[$place['code']] = $place;
                if (($byCode[(string) $place['parent_code']]['level'] ?? '') !== PlaceCode::LevelDistrict) {
                    $add('upazila_bad_parent', $place, 'The parent is not a district.');
                }
                $key = $place['parent_code'].'|'.$this->key($place['name']);
                if (isset($seenUpazila[$key])) {
                    $add('upazila_duplicate', $place, 'The same name twice in one district (first: '.$seenUpazila[$key].').');
                }
                $seenUpazila[$key] = $place['code'];
            } elseif ($place['level'] === PlaceCode::LevelUnion && $place['parent_code'] !== null) {
                $unionsOf[$place['parent_code']][] = $place;
            }
        }

        $sharedWith = [];
        foreach ($unionsOf as $parentCode => $unions) {
            $parent = $byCode[$parentCode] ?? null;
            if ($parent === null || $parent['level'] !== PlaceCode::LevelUpazila) {
                foreach ($unions as $union) {
                    $add('union_bad_parent', $union, 'The parent is not an upazila-level place.');
                }

                continue;
            }
            if (! isset($upazilas[$parentCode])) {
                continue;
            }
            foreach ($unions as $union) {
                $sharedWith[$parent['parent_code'].'|'.$this->key($union['name']).'|'.($union['kind'] ?? 'union')][$parentCode] = $union;
            }
        }

        $pairs = [];
        foreach ($sharedWith as $byParent) {
            $codes = array_map('strval', array_keys($byParent));
            sort($codes);
            for ($i = 0; $i < count($codes); $i++) {
                for ($j = $i + 1; $j < count($codes); $j++) {
                    $pairs[$codes[$i].'|'.$codes[$j]][] = [$byParent[$codes[$i]], $byParent[$codes[$j]]];
                }
            }
        }
        foreach ($pairs as $pair => $shared) {
            if (count($shared) < self::SharedUnionsToCallItASplit) {
                continue;
            }
            [$a, $b] = explode('|', (string) $pair);
            $names = ($byCode[$a]['name'] ?? $a).' and '.($byCode[$b]['name'] ?? $b);
            foreach ($shared as [$one, $two]) {
                $add('union_listed_under_two_upazilas', $one, 'Also listed under '.($byCode[$b]['name'] ?? $b).' ('.$two['code'].'); '.count($shared).' unions are shared by '.$names.'.');
                $add('union_listed_under_two_upazilas', $two, 'Also listed under '.($byCode[$a]['name'] ?? $a).' ('.$one['code'].'); '.count($shared).' unions are shared by '.$names.'.');
            }
        }

        $byStem = [];
        foreach ($upazilas as $upazila) {
            $byStem[$upazila['parent_code'].'|'.$this->stem($upazila['name'])][] = $upazila;
        }
        foreach ($unionsOf as $parentCode => $unions) {
            $parent = $upazilas[$parentCode] ?? null;
            if ($parent === null) {
                continue;
            }
            foreach ($unions as $union) {
                if (($union['kind'] ?? 'union') !== 'union') {
                    continue;
                }
                foreach ($byStem[$parent['parent_code'].'|'.$this->stem($union['name'])] ?? [] as $named) {
                    if ($named['code'] !== $parentCode && ($unionsOf[$named['code']] ?? []) === []) {
                        $add('union_under_wrong_upazila', $union, 'The upazila '.$named['name'].' of the same district has no unions in the list; this union of the same name sits under '.$parent['name'].'.');
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<Issue>  $issues
     * @return array<string, true> Codes of unions the base place rules must not choose
     */
    public function excludedUnionCodes(array $issues): array
    {
        $codes = [];
        foreach ($issues as $issue) {
            if (in_array($issue['issue'], ['union_listed_under_two_upazilas', 'union_under_wrong_upazila', 'union_bad_parent'], true)) {
                $codes[$issue['code']] = true;
            }
        }

        return $codes;
    }

    private function key(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name));
    }

    private function stem(string $name): string
    {
        return (string) preg_replace('/(citycorporation|pourashava|paurashava|municipality|union|sadar|city|metro)$/', '', $this->key($name));
    }
}

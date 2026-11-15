<?php

namespace App\Domain\Geography;

/**
 * The four cascading place pickers (division, district, upazila, union) of the public
 * dealer list. Works on the places returned by PlaceTree::areaOptions and on place code ids only.
 */
final class DirectoryAreaFilter
{
    /** @var list<string> From the widest place to the narrowest. */
    public const Levels = ['division', 'district', 'upazila', 'union'];

    /**
     * @param  array<int, array{id: int, name: string, level: string, parent_id: int|null}>  $places
     * @param  array<string, mixed>  $query  The page's query string.
     * @return array{chosen: array<string, int|null>, options: array<string, list<array{id: int, name: string}>>}
     */
    public static function build(array $places, array $query): array
    {
        $chosen = self::chosen($places, $query);

        $options = [];
        foreach (self::Levels as $index => $level) {
            $nearestChosenAbove = null;
            foreach (array_reverse(array_slice(self::Levels, 0, $index)) as $upper) {
                if ($chosen[$upper] !== null) {
                    $nearestChosenAbove = $chosen[$upper];
                    break;
                }
            }

            $options[$level] = [];
            foreach ($places as $place) {
                if ($place['level'] !== $level) {
                    continue;
                }
                if ($nearestChosenAbove !== null && ! self::isUnder($places, $place['id'], $nearestChosenAbove)) {
                    continue;
                }
                $parentName = $place['parent_id'] !== null ? ($places[$place['parent_id']]['name'] ?? null) : null;
                $options[$level][] = [
                    'id' => $place['id'],
                    'name' => $nearestChosenAbove === null && $parentName !== null ? $place['name'].' ('.$parentName.')' : $place['name'],
                ];
            }
        }

        return ['chosen' => $chosen, 'options' => $options];
    }

    /**
     * The place ids the page asks for. A place that is not listed keeps its id, so the page
     * shows no dealers for it, and a value that is not an id at all becomes the id 0. A listed place that lies under a different place than the one
     * chosen above it is a leftover from an earlier pick and is dropped.
     *
     * @param  array<int, array{id: int, name: string, level: string, parent_id: int|null}>  $places
     * @param  array<string, mixed>  $query
     * @return array<string, int|null>
     */
    private static function chosen(array $places, array $query): array
    {
        $chosen = array_fill_keys(self::Levels, null);
        foreach (self::Levels as $index => $level) {
            $id = PlaceTree::idFrom($query[$level] ?? null);
            if ($id === null) {
                // A typed name is never matched: place 0 does not exist, so nothing is listed.
                $chosen[$level] = filled($query[$level] ?? null) ? 0 : null;

                continue;
            }
            if (isset($places[$id]) && $places[$id]['level'] === $level) {
                foreach (array_slice(self::Levels, 0, $index) as $upper) {
                    if ($chosen[$upper] !== null && ! self::isUnder($places, $id, $chosen[$upper])) {
                        continue 2;
                    }
                }
            }
            $chosen[$level] = $id;
        }

        return $chosen;
    }

    /**
     * @param  array<int, array{id: int, name: string, level: string, parent_id: int|null}>  $places
     */
    private static function isUnder(array $places, int $placeId, int $ancestorId): bool
    {
        $cursor = $placeId;
        for ($guard = 0; $cursor !== null && $guard < 8; $guard++) {
            if ($cursor === $ancestorId) {
                return true;
            }
            $cursor = $places[$cursor]['parent_id'] ?? null;
        }

        return false;
    }
}

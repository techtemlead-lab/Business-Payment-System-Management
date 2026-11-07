<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * One page of a list that was built in PHP (because each row needs figures worked out from
 * several tables), so a phone downloads 25 rows instead of every leader or partner. Summary
 * figures must be worked out from the full list before it is cut into pages.
 */
final class ListPage
{
    public const PerPage = 25;

    /**
     * @template TRow
     *
     * @param  iterable<TRow>  $rows
     * @return LengthAwarePaginator<int, TRow>
     */
    public static function of(iterable $rows, Request $request, string $pageName = 'page', int $perPage = self::PerPage): LengthAwarePaginator
    {
        $items = collect($rows)->values();
        $last = max(1, (int) ceil($items->count() / $perPage));
        $page = min($last, max(1, (int) $request->query($pageName, '1')));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $pageName],
        );
    }

    /**
     * Rows whose named fields contain the search words (any letter case). An empty search keeps every row.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  Collection<int, TRow>|array<int, TRow>  $rows
     * @param  list<string>  $fields  dot paths, e.g. "user.name"
     * @return Collection<int, TRow>
     */
    public static function search(Collection|array $rows, ?string $query, array $fields): Collection
    {
        $rows = collect($rows);
        $needle = mb_strtolower(trim((string) $query));
        if ($needle === '') {
            return $rows->values();
        }

        return $rows->filter(function ($row) use ($fields, $needle): bool {
            foreach ($fields as $field) {
                $value = data_get($row, $field);
                if ($value !== null && str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** The search words from the request, trimmed and at most 100 characters. */
    public static function query(Request $request, string $name = 'q'): string
    {
        return mb_substr(trim((string) $request->query($name, '')), 0, 100);
    }
}

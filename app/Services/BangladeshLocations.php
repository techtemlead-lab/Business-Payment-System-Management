<?php

namespace App\Services;

class BangladeshLocations
{
    public const LEVELS = ['division', 'district', 'upazila', 'union_name'];

    private ?array $catalog = null;

    public function metadata(): array
    {
        return $this->catalog()['metadata'];
    }

    /** @return array{metadata: array<string, mixed>, divisions: list<array<string, mixed>>} */
    private function catalog(): array
    {
        return $this->catalog ??= json_decode(file_get_contents(resource_path('bangladesh-locations.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function key(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s\p{P}]+/u', '', trim($value)) ?? trim($value));
    }

    /** @param array{name: string, bn_name: string, aliases: list<string>} $node */
    private function matches(array $node, string $value): bool
    {
        foreach ([$node['name'], $node['bn_name'], ...$node['aliases']] as $alias) {
            if ($this->key($alias) === $this->key($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string|null> $filters */
    private function nodes(array $filters): array
    {
        $nodes = $this->catalog()['divisions'];
        $levels = [];
        foreach (self::LEVELS as $level) {
            if ($level === 'upazila') {
                array_push($nodes, ...$this->metropolitanNodes($filters));
            }
            $levels[$level] = $nodes;
            $children = [];
            foreach ($nodes as $node) {
                if (empty($filters[$level]) || $this->matches($node, $filters[$level])) {
                    array_push($children, ...($node['children'] ?? []));
                }
            }
            $nodes = $children;
        }

        return $levels;
    }

    /** @param array<string, string|null> $filters */
    private function metropolitanNodes(array $filters): array
    {
        $nodes = [];
        foreach ($this->catalog()['metropolitan_thanas'] as $thana) {
            foreach ($this->catalog()['divisions'] as $division) {
                if ($division['name'] !== $thana['division'] || (! empty($filters['division']) && ! $this->matches($division, $filters['division']))) {
                    continue;
                }
                foreach ($division['children'] as $district) {
                    if ($district['name'] === $thana['district'] && (empty($filters['district']) || $this->matches($district, $filters['district']))) {
                        $nodes[] = $thana;
                    }
                }
            }
        }

        return $nodes;
    }

    /** @param array<string, string|null> $filters */
    public function canonicalFilters(array $filters): array
    {
        foreach (self::LEVELS as $level) {
            if (! empty($filters[$level])) {
                foreach ($this->nodes($filters)[$level] as $node) {
                    if ($this->matches($node, $filters[$level])) {
                        $filters[$level] = $node['name'];
                        break;
                    }
                }
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string|null>  $filters
     * @param  list<string>  $recordedValues
     * @return array{values: list<string>, labels: array<string, string>, legacy: list<string>}
     */
    public function options(string $level, array $filters, array $recordedValues): array
    {
        $position = array_search($level, self::LEVELS, true);
        if ($position === false) {
            throw new \InvalidArgumentException('Unknown location level.');
        }
        if ($position > 0 && empty($filters[self::LEVELS[$position - 1]]) && empty($filters[$level])) {
            return ['values' => [], 'labels' => [], 'legacy' => []];
        }
        $nodes = $this->nodes($filters)[$level];
        $values = [];
        $labels = [];
        $legacy = [];
        foreach ($nodes as $node) {
            $values[] = $node['name'];
            $labels[$node['name']] = $node['name'].($node['bn_name'] ? ' · '.$node['bn_name'] : '');
        }
        foreach ($recordedValues as $value) {
            $known = false;
            foreach ($nodes as $node) {
                if ($this->matches($node, $value)) {
                    $known = true;
                    break;
                }
            }
            if (! $known) {
                $values[] = $value;
                $legacy[] = $value;
                $labels[$value] = $value.' (existing account location)';
            }
        }
        $values = array_values(array_unique($values));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return ['values' => $values, 'labels' => $labels, 'legacy' => array_values(array_unique($legacy))];
    }

    /**
     * @param  array<string, string|null>  $filters
     * @param  list<string>  $recordedValues
     * @return list<string>
     */
    public function matchingValues(string $level, string $selection, array $filters, array $recordedValues): array
    {
        $keys = [$this->key($selection) => true];
        foreach ($this->nodes($filters)[$level] as $node) {
            if ($this->matches($node, $selection)) {
                foreach ([$node['name'], $node['bn_name'], ...$node['aliases']] as $alias) {
                    $keys[$this->key($alias)] = true;
                }
            }
        }

        return array_values(array_filter($recordedValues, fn (string $value): bool => isset($keys[$this->key($value)])));
    }
}

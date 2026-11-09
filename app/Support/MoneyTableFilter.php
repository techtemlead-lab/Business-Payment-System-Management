<?php

namespace App\Support;

/**
 * One drop-down filter of a money table. The request can only ever pick one of the offered values.
 */
final class MoneyTableFilter
{
    /**
     * @param  string  $key  the flat query string name
     * @param  string  $column  fixed SQL compared with the chosen value
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $column,
        public array $options,
    ) {}
}

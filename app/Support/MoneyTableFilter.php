<?php

namespace App\Support;

/**
 * One filter of a money table: a drop-down, a number range, a date range or a piece of text.
 * The request can only ever pick one of the offered values or a value of the right shape.
 */
final class MoneyTableFilter
{
    public const Select = 'select';

    public const NumberRange = 'range';

    public const DateRange = 'date_range';

    public const Text = 'text';

    /**
     * @param  string  $key  the flat query string name; a range is named by $from and $to instead
     * @param  ?string  $column  fixed SQL compared with the chosen value; null when the read model applies the filter itself. A date range compares dates, so write a timestamp as its Dhaka day.
     * @param  list<array{value: string, label: string, count?: int}>  $options
     * @param  self::Select|self::NumberRange|self::DateRange|self::Text  $type
     * @param  'main'|'more'  $group  main filters sit on the toolbar, the rest in the "More filters" sheet
     * @param  ?string  $from  the query string name of a range's lower end
     * @param  ?string  $to  the query string name of a range's upper end
     * @param  bool  $decimal  a number range also takes two decimal places
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $column,
        public array $options = [],
        public string $type = self::Select,
        public string $group = 'main',
        public ?string $from = null,
        public ?string $to = null,
        public bool $decimal = false,
    ) {}

    /** @param 'main'|'more' $group */
    public static function numberRange(string $key, string $label, ?string $column, string $from, string $to, bool $decimal = false, string $group = 'more'): self
    {
        return new self($key, $label, $column, [], self::NumberRange, $group, $from, $to, $decimal);
    }

    /** @param 'main'|'more' $group */
    public static function dateRange(string $key, string $label, ?string $column, string $from, string $to, string $group = 'more'): self
    {
        return new self($key, $label, $column, [], self::DateRange, $group, $from, $to);
    }

    /** @param 'main'|'more' $group */
    public static function text(string $key, string $label, ?string $column, string $group = 'more'): self
    {
        return new self($key, $label, $column, [], self::Text, $group);
    }

    /**
     * What the screen needs to draw this filter. A plain drop-down on the toolbar sends only its options.
     *
     * @return array{key: string, label: string, options: list<array{value: string, label: string, count?: int}>, type?: string, group?: string, from?: string, to?: string, decimal?: bool}
     */
    public function forScreen(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'options' => $this->options,
            ...($this->type !== self::Select ? ['type' => $this->type] : []),
            ...($this->group !== 'main' ? ['group' => $this->group] : []),
            ...($this->from !== null ? ['from' => $this->from] : []),
            ...($this->to !== null ? ['to' => $this->to] : []),
            ...($this->decimal ? ['decimal' => true] : []),
        ];
    }
}

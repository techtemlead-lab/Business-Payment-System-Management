<?php

namespace App\Domain\Commissions;

/**
 * Old and new values of the commission rules a change touches, for the audit log
 * and for the owner's approval list.
 */
final class PolicyChangeSummary
{
    private const Fields = ['percentage', 'is_active', 'amount_threshold', 'cap_limit', 'parameters'];

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, array{before: array<string, mixed>, after: array<string, mixed>}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $old = is_array($before[$key] ?? null) ? $before[$key] : [];
            foreach (self::Fields as $field) {
                if ($field === 'parameters') {
                    foreach ((array) ($rule['parameters'] ?? []) as $name => $value) {
                        $previous = $old['parameters'][$name] ?? null;
                        if ((string) json_encode($previous) !== (string) json_encode($value)) {
                            $changes[$key]['before']['parameters'][$name] = $previous;
                            $changes[$key]['after']['parameters'][$name] = $value;
                        }
                    }

                    continue;
                }
                if (array_key_exists($field, $rule) && (string) json_encode($old[$field] ?? null) !== (string) json_encode($rule[$field])) {
                    $changes[$key]['before'][$field] = $old[$field] ?? null;
                    $changes[$key]['after'][$field] = $rule[$field];
                }
            }
        }

        return $changes;
    }

    /** @param array<string, array{before?: array<string, mixed>, after?: array<string, mixed>}> $changes */
    public static function describe(array $changes): string
    {
        $lines = [];
        foreach ($changes as $key => $change) {
            $name = __('portal.rule_name_'.$key);
            $name = $name === 'portal.rule_name_'.$key ? $key : $name;
            $parts = [];
            foreach ((array) ($change['after'] ?? []) as $field => $value) {
                if ($field === 'parameters') {
                    foreach ((array) $value as $parameter => $newValue) {
                        $parts[] = $parameter.' '.self::show($change['before']['parameters'][$parameter] ?? null).' → '.self::show($newValue);
                    }

                    continue;
                }
                $suffix = $field === 'percentage' ? '%' : '';
                $label = $field === 'percentage' ? '' : $field.' ';
                $parts[] = $label.self::show($change['before'][$field] ?? null).$suffix.' → '.self::show($value).$suffix;
            }
            $lines[] = $name.': '.implode(', ', $parts);
        }

        return implode('; ', $lines);
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'on' : 'off',
            default => (string) $value,
        };
    }
}

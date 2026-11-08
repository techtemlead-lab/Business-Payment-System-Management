<?php

namespace App\Domain\Clinic;

use App\Support\LocalDigits;

/**
 * Blood pressure, weight, pulse and temperature taken at a visit. A visit is the prescription
 * written that day. Temperature is in Fahrenheit, as clinics here usually record it.
 */
final class Vitals
{
    /**
     * Validation rules for the "vitals" part of a prescription.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'vitals' => 'nullable|array',
            'vitals.bp_systolic' => 'nullable|integer|min:40|max:300|required_with:vitals.bp_diastolic',
            'vitals.bp_diastolic' => 'nullable|integer|min:20|max:200|required_with:vitals.bp_systolic|lt:vitals.bp_systolic',
            'vitals.weight_kg' => 'nullable|numeric|decimal:0,2|min:0.5|max:400',
            'vitals.pulse' => 'nullable|integer|min:20|max:300',
            'vitals.temperature_f' => 'nullable|numeric|decimal:0,1|min:85|max:115',
        ];
    }

    /**
     * Only the readings that were taken; null when there are none.
     *
     * @param  array<string, mixed>|null  $vitals
     * @return array<string, string>|null
     */
    public static function clean(?array $vitals): ?array
    {
        $readings = [];
        foreach (['bp_systolic', 'bp_diastolic', 'weight_kg', 'pulse', 'temperature_f'] as $field) {
            if (filled($vitals[$field] ?? null)) {
                $readings[$field] = (string) $vitals[$field];
            }
        }

        return $readings === [] ? null : $readings;
    }

    /**
     * The readings as labelled lines for a screen or a print page.
     *
     * @param  array<string, string>|null  $vitals
     * @return list<array{label: string, value: string}>
     */
    public static function lines(?array $vitals, string $locale): array
    {
        if ($vitals === null) {
            return [];
        }
        $digits = fn (string $value): string => LocalDigits::text($value, $locale);
        $lines = [];
        if (isset($vitals['bp_systolic'], $vitals['bp_diastolic'])) {
            $lines[] = ['label' => __('clinic_records.vital_bp', [], $locale), 'value' => $digits($vitals['bp_systolic'].'/'.$vitals['bp_diastolic']).' mmHg'];
        }
        $others = [
            'weight_kg' => ['vital_weight', 'vital_unit_kg'],
            'pulse' => ['vital_pulse', 'vital_unit_pulse'],
            'temperature_f' => ['vital_temperature', 'vital_unit_f'],
        ];
        foreach ($others as $field => [$label, $unit]) {
            if (isset($vitals[$field])) {
                $lines[] = ['label' => __('clinic_records.'.$label, [], $locale), 'value' => $digits($vitals[$field]).' '.__('clinic_records.'.$unit, [], $locale)];
            }
        }

        return $lines;
    }
}

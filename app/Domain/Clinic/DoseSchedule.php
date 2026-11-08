<?php

namespace App\Domain\Clinic;

use App\Models\PrescriptionLine;
use App\Support\LocalDigits;

/**
 * Structured dosing such as 1+0+1: how much in the morning, at noon and at night, before or
 * after food, and for how long. The free-text dose, frequency and duration on a line stay
 * available for anything this doesn't cover.
 */
final class DoseSchedule
{
    /** @var list<string> */
    public const Amounts = ['0', '¼', '½', '¾', '1', '1½', '2', '2½', '3', '4', '5'];

    /** @var list<string> */
    public const FoodTimings = ['before', 'after'];

    /** @var list<string> */
    public const DurationUnits = ['day', 'week', 'month'];

    /** @var list<string> */
    private const Slots = ['morning', 'noon', 'night'];

    /** Three amounts joined by "+", with at least one above zero. */
    public static function isValid(string $schedule): bool
    {
        $parts = explode('+', $schedule);
        if (count($parts) !== count(self::Slots)) {
            return false;
        }
        foreach ($parts as $part) {
            if (! in_array($part, self::Amounts, true)) {
                return false;
            }
        }

        return array_filter($parts, fn (string $part): bool => $part !== '0') !== [];
    }

    /**
     * The dosing in one line for the patient, such as "১+০+১ (সকালে ১ · রাতে ১) · খাবারের পরে · ৭ দিন".
     * Empty when the line has no structured dosing.
     */
    public static function summary(PrescriptionLine $line, string $locale): string
    {
        $pieces = [];
        if ($line->dose_schedule !== null) {
            $parts = explode('+', $line->dose_schedule);
            $slots = [];
            foreach (self::Slots as $index => $slot) {
                if (($parts[$index] ?? '0') !== '0') {
                    $slots[] = __('clinic_records.dose_'.$slot, ['amount' => LocalDigits::text($parts[$index], $locale)], $locale);
                }
            }
            $pieces[] = LocalDigits::text($line->dose_schedule, $locale).' ('.implode(' · ', $slots).')';
        }
        if ($line->food_timing !== null) {
            $pieces[] = __('clinic_records.food_'.$line->food_timing, [], $locale);
        }
        if ($line->duration_count !== null && $line->duration_unit !== null) {
            $pieces[] = trans_choice('clinic_records.duration_'.$line->duration_unit, $line->duration_count, ['value' => LocalDigits::text($line->duration_count, $locale)], $locale);
        }

        return implode(' · ', $pieces);
    }
}

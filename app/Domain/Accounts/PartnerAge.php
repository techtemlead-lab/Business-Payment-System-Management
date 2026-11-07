<?php

namespace App\Domain\Accounts;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Dealers and sub-dealers must be adults: a date of birth less than 18 years
 * before today (Dhaka calendar) is refused when someone applies or when staff
 * create a partner account.
 */
final class PartnerAge
{
    public const MinimumYears = 18;

    /** The latest date of birth that is still old enough today, as Y-m-d. */
    public static function latestAllowedBirthDate(): string
    {
        return CarbonImmutable::now(self::timezone())->startOfDay()->subYearsNoOverflow(self::MinimumYears)->toDateString();
    }

    /** True when the date can be read and the person is at least 18 today. */
    public static function isAdult(mixed $dateOfBirth): bool
    {
        if (! is_string($dateOfBirth) || trim($dateOfBirth) === '') {
            return false;
        }
        try {
            $born = CarbonImmutable::parse(trim($dateOfBirth), self::timezone())->toDateString();
        } catch (\Throwable) {
            return false;
        }

        return $born <= self::latestAllowedBirthDate();
    }

    /**
     * Validation rule that fails with a plain message for anyone under 18.
     *
     * @return Closure(string, mixed, Closure(string): void): void
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! self::isAdult($value)) {
                $fail(__('portal.acct_val_dob_under_age', ['years' => self::MinimumYears]));
            }
        };
    }

    private static function timezone(): string
    {
        return (string) config('madina.business_timezone', 'Asia/Dhaka');
    }
}

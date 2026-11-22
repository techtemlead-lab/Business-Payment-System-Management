<?php

namespace App\Support;

/**
 * The company's own website address as it is printed on paper (letterheads of the invoice, stock print,
 * product lists and the other office PDFs). The owner wants one form everywhere: https://www.madinanhc.com.
 * Whatever form a setting or the server address holds ("madinanhc.com", "www.madinanhc.com",
 * "https://madinanhc.com/"), the company's own domain always prints in that form. Any other address is
 * returned unchanged, so a test or staging copy never shows the company's name by mistake.
 */
final class CompanyWebsite
{
    /** The printed form, used when the config does not set another one. */
    public const Default = 'https://www.madinanhc.com';

    /** The address to print for the company's own site. */
    public static function url(): string
    {
        $configured = rtrim(trim((string) config('madina.company_website', self::Default)), '/');

        return $configured !== '' ? $configured : self::Default;
    }

    /** Whether a value is only the company's own domain, in any spelling (scheme, "www.", trailing slash). */
    public static function isOwnDomain(string $value): bool
    {
        return self::bare($value) === self::bare(self::url());
    }

    /** The company's own domain becomes the printed form; every other value stays as it is. */
    public static function normalise(string $value): string
    {
        return self::isOwnDomain($value) ? self::url() : $value;
    }

    /** "https://www.Madinanhc.com/" becomes "madinanhc.com"; text that is more than a bare domain comes back as ''. */
    private static function bare(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('~^(?:https?://)?(?:www\.)?([a-z0-9][a-z0-9.-]*\.[a-z]{2,})/?$~', $value, $found) !== 1) {
            return '';
        }

        return $found[1];
    }
}

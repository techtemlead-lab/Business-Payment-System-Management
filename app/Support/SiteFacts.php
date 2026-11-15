<?php

namespace App\Support;

/**
 * Lets website text refer to a company fact by a word in curly brackets, such as
 * {phone} or {address}. The fact itself is stored once, so changing it changes every
 * sentence that uses it.
 */
final class SiteFacts
{
    public static function fill(string $text): string
    {
        if (! str_contains($text, '{')) {
            return $text;
        }

        return strtr($text, self::values());
    }

    /** The trade licence number: the owner's own, or the number built into the site. */
    public static function tradeLicence(): string
    {
        $licence = trim((string) (SiteCustomization::forPublicPages()['trust_trade_licence'] ?? ''));

        return $licence !== '' ? $licence : (string) __('site.trade_licence_number');
    }

    /**
     * The trademark application number for the top bar. The owner may write more after the number
     * (class, filing date); only the first word is shown here, so the bar stays short.
     */
    public static function trademarkNumber(): string
    {
        $saved = trim((string) (SiteCustomization::forPublicPages()['trust_trademark'] ?? ''));
        $number = $saved !== '' ? (string) preg_split('/[\s(]/', $saved, 2)[0] : '';

        return $number !== '' ? $number : (string) __('site.trademark_number');
    }

    /** @return array<string, string> */
    private static function values(): array
    {
        $all = SiteCustomization::forPublicPages();
        $phone = trim((string) ($all['contact_phone'] ?? ''));
        $second = SiteCustomization::secondPhone();

        return [
            '{company}' => SiteChrome::plain('contact_company_title', $all['contact_company_title'] ?? null),
            '{phone}' => LocalDigits::text($phone),
            '{hotline}' => LocalDigits::text($second ?? ''),
            '{phones}' => LocalDigits::text(implode(' / ', array_filter([$phone, $second]))),
            '{email}' => trim((string) ($all['contact_email'] ?? '')),
            '{address}' => SiteChrome::plain('office_address', $all['office_address'] ?? null),
            '{location}' => SiteChrome::plain('footer_location', $all['footer_location'] ?? null),
            '{licence}' => LocalDigits::text(self::tradeLicence()),
        ];
    }
}

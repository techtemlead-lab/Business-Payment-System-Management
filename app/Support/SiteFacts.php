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

    /** @return array<string, string> */
    private static function values(): array
    {
        $all = SiteCustomization::all();
        $phone = trim((string) ($all['contact_phone'] ?? ''));
        $second = SiteCustomization::secondPhone();
        $licence = trim((string) ($all['trust_trade_licence'] ?? ''));

        return [
            '{company}' => SiteChrome::plain('contact_company_title', $all['contact_company_title'] ?? null),
            '{phone}' => LocalDigits::text($phone),
            '{hotline}' => LocalDigits::text($second ?? ''),
            '{phones}' => LocalDigits::text(implode(' / ', array_filter([$phone, $second]))),
            '{email}' => trim((string) ($all['contact_email'] ?? '')),
            '{address}' => SiteChrome::plain('office_address', $all['office_address'] ?? null),
            '{location}' => SiteChrome::plain('footer_location', $all['footer_location'] ?? null),
            '{licence}' => LocalDigits::text($licence !== '' ? $licence : (string) __('site.trade_licence_number')),
        ];
    }
}

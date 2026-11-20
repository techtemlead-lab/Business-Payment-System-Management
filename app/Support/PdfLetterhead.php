<?php

namespace App\Support;

/**
 * The letterhead (logo, name, address, contacts, licence and trademark) at the top of the office
 * PDFs, as the owner set it in the website editor.
 */
final class PdfLetterhead
{
    /**
     * @return array{name: string, address: string, whatsapp: string, mobile: string, email: string, website: string, website_url: string, icons: array<string, string>, licence: string, trademark: string, logo_path: ?string}
     */
    public static function company(): array
    {
        $custom = SiteCustomization::inVisitorLanguage(SiteCustomization::all());
        $defaults = SiteCustomization::defaults();
        $letterhead = static fn (string $key): string => filled($custom[$key] ?? null) ? (string) $custom[$key] : (string) ($defaults[$key] ?? '');
        $logoPath = SiteCustomization::logoFilePath();
        if ($logoPath === null || ! is_file($logoPath)) {
            $logoPath = public_path('madina-header-logo.png');
        }
        if (! is_file($logoPath)) {
            $logoPath = public_path('branding/madina-logo-latest.png');
        }
        $contacts = LetterheadContacts::lines();

        return [
            'name' => $letterhead('contact_company_title'),
            'address' => $letterhead('office_address'),
            ...$contacts,
            'icons' => LetterheadContacts::icons(),
            'licence' => SiteFacts::tradeLicence(),
            'trademark' => SiteFacts::trademarkNumber(),
            'logo_path' => is_file($logoPath) ? $logoPath : null,
        ];
    }
}

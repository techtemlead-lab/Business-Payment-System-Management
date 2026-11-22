<?php

namespace App\Support;

/**
 * The contact lines printed on a letterhead (stock list and invoice): the WhatsApp number,
 * the mobile number, the owner's email and the website address, all taken from what the
 * owner set in the website editor so the paper always matches the public footer.
 */
final class LetterheadContacts
{
    /** The icon files, beside the logo in public/branding, keyed by the line they sit before. */
    private const Icons = [
        'whatsapp' => 'whatsapp.svg',
        'mobile' => 'call.svg',
        'email' => 'mail.svg',
        'website' => 'web.svg',
    ];

    /**
     * @return array{whatsapp: string, mobile: string, email: string, website: string, website_url: string}
     */
    public static function lines(): array
    {
        $custom = SiteCustomization::inVisitorLanguage(SiteCustomization::all());
        $defaults = SiteCustomization::defaults();
        $setting = static fn (string $key): string => filled($custom[$key] ?? null) ? (string) $custom[$key] : (string) ($defaults[$key] ?? '');
        $phones = self::phones($setting('contact_phone'), SiteCustomization::secondPhone(), $setting('social_whatsapp'));
        $site = self::website();

        return [
            'whatsapp' => $phones['whatsapp'],
            'mobile' => self::mobileLine($phones, (string) $setting('invoice_marketed_phones')),
            'email' => trim($setting('contact_email')),
            'website' => $site['label'],
            'website_url' => $site['url'],
        ];
    }

    /**
     * Where each icon file is. A PDF reads the file from disk; a page in the browser needs its web address.
     *
     * @return array{whatsapp: string, mobile: string, email: string, website: string}
     */
    public static function icons(bool $forBrowser = false): array
    {
        $icons = [];
        foreach (self::Icons as $line => $file) {
            $icons[$line] = $forBrowser ? asset('branding/contact-icons/'.$file) : str_replace('\\', '/', public_path('branding/contact-icons/'.$file));
        }

        return $icons;
    }

    /**
     * The WhatsApp line is the website number that matches the owner's WhatsApp link, or the first
     * number when none matches. The other number is the mobile line.
     *
     * @return array{whatsapp: string, mobile: string}
     */
    public static function phones(string $primary, ?string $second, string $whatsappLink): array
    {
        $tail = static fn (string $number): string => substr((string) preg_replace('/\D/', '', $number), -10);
        $numbers = array_values(array_filter([trim($primary), trim((string) $second)], static fn (string $number): bool => $number !== ''));
        $linkTail = $tail((string) strtok($whatsappLink, '?'));

        $whatsapp = $numbers[0] ?? '';
        foreach ($numbers as $number) {
            if ($linkTail !== '' && $tail($number) === $linkTail) {
                $whatsapp = $number;
                break;
            }
        }

        $mobile = '';
        foreach ($numbers as $number) {
            if ($tail($number) !== $tail($whatsapp)) {
                $mobile = $number;
                break;
            }
        }

        return [
            'whatsapp' => self::display($whatsapp),
            'mobile' => self::display($mobile),
        ];
    }

    /**
     * The mobile line: the second website number, then any further number the owner listed for the
     * invoice letterhead, in the same +880 form. The WhatsApp number is never repeated here.
     *
     * @param  array{whatsapp: string, mobile: string}  $phones
     */
    private static function mobileLine(array $phones, string $listed): string
    {
        $tail = static fn (string $number): string => substr((string) preg_replace('/\D/', '', $number), -10);
        $numbers = [];
        foreach (array_merge([$phones['mobile']], preg_split('/[,;\/]+/', $listed) ?: []) as $number) {
            $shown = self::display($number);
            if ($shown === '' || $tail($shown) === $tail($phones['whatsapp']) || in_array($tail($shown), array_map($tail, $numbers), true)) {
                continue;
            }
            $numbers[] = $shown;
        }

        return implode(', ', $numbers);
    }

    /** A phone number the way the website shows it (+8801…); empty when none is set. */
    private static function display(?string $number): string
    {
        $number = trim((string) $number);

        return $number === '' ? '' : SiteCustomization::internationalDisplay($number);
    }

    /**
     * The public website's address, as a short label and a full link. Empty on a machine that has
     * no real domain set, so a local test copy never prints "localhost" on paper.
     *
     * @return array{label: string, url: string}
     */
    private static function website(): array
    {
        $host = PortalHost::host('public');
        if (! str_contains($host, '.')) {
            return ['label' => '', 'url' => ''];
        }
        // The company's own domain always prints as https://www.madinanhc.com, whatever form the server address has.
        if (CompanyWebsite::isOwnDomain($host)) {
            return ['label' => CompanyWebsite::url(), 'url' => CompanyWebsite::url()];
        }
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return ['label' => $host, 'url' => $scheme.'://'.$host.'/'];
    }
}

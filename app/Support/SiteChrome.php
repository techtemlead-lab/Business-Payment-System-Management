<?php

namespace App\Support;

final class SiteChrome
{
    /**
     * Resolve public marketing chrome copy.
     *
     * English uses staff CMS overrides when present.
     * Bangla keeps translated defaults while the CMS still holds the English
     * language-file default or the Website CMS factory string. Intentional CMS
     * edits (any locale) win in every language.
     */
    /**
     * Owner text that has its own Bangla box in the website editor.
     *
     * Bangla visitors see the Bangla box when it is filled. Otherwise they
     * get the same rule as text(): an owner edit is shown as written, and
     * untouched factory text shows the built-in Bangla default.
     */
    public static function ownerText(string $key, ?string $custom, ?string $customBangla): string
    {
        $bangla = trim((string) $customBangla);
        if (app()->getLocale() === 'bn' && $bangla !== '') {
            return SiteFacts::fill($bangla);
        }

        return self::text($key, $custom);
    }

    /** True when the text has Arabic letters, so the page can mark it right-to-left. */
    public static function isArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    /** The words for this visitor, with any {phone}-style word filled in from the one saved fact. */
    public static function text(string $key, ?string $custom = null): string
    {
        return SiteFacts::fill(self::plain($key, $custom));
    }

    /** The words for this visitor, before any {phone}-style word is filled in. */
    public static function plain(string $key, ?string $custom = null): string
    {
        $translated = (string) __('site.'.$key);
        $custom = $custom !== null ? trim((string) $custom) : '';

        if ($custom === '') {
            return $translated;
        }

        // Old factory wording saved by an earlier editor save is not an owner edit.
        if (in_array($custom, SiteCustomizationRetiredDefaults::all()[$key] ?? [], true)) {
            return $translated;
        }

        $legacyCompanyBar = ['MADINA NATURAL HEALTH CARE', 'Madina Natural Health Care'];
        if ($key === 'company_bar' && in_array($custom, $legacyCompanyBar, true)) {
            return (string) __('site.company_bar');
        }

        if (app()->getLocale() === 'bn') {
            $englishDefault = (string) trans('site.'.$key, [], 'en');
            $factoryDefault = trim((string) (SiteCustomization::defaults()[$key] ?? ''));
            if ($custom === $englishDefault || ($factoryDefault !== '' && $custom === $factoryDefault)) {
                return $translated;
            }
        }

        return $custom;
    }
}

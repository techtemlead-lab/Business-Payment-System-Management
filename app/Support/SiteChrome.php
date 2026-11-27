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

    /** Words kept in two boxes: the visitor's own language when filled, otherwise whichever one is. */
    public static function eitherLanguage(?string $english, ?string $bangla): string
    {
        $english = trim((string) $english);
        $bangla = trim((string) $bangla);

        if (app()->getLocale() === 'bn') {
            return $bangla !== '' ? $bangla : $english;
        }

        return $english !== '' ? $english : $bangla;
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

    /**
     * Words for a line that must read in the visitor's own language (the moving news line on the home page).
     *
     * Same rules as text(), plus one guard: a Bangla visitor never gets text with no Bangla letters in it, and an
     * English visitor never gets text written only in Bangla letters. In either case the built-in wording of that
     * language is shown instead. An owner text saved in one language therefore cannot reach the other language;
     * the owner's Bangla box (plain() reads it) is how a different Bangla text is set.
     */
    public static function inVisitorLanguage(string $key, ?string $custom = null): string
    {
        $text = self::plain($key, $custom);
        $hasBangla = preg_match('/\p{Bengali}/u', $text) === 1;

        if (app()->getLocale() === 'bn' && ! $hasBangla) {
            $text = (string) __('site.'.$key);
        } elseif (app()->getLocale() !== 'bn' && $hasBangla && preg_match('/[A-Za-z]/', $text) !== 1) {
            $text = (string) trans('site.'.$key, [], 'en');
        }

        return SiteFacts::fill($text);
    }

    /** The words for this visitor, before any {phone}-style word is filled in. */
    public static function plain(string $key, ?string $custom = null): string
    {
        // The owner's own Bangla box for this wording, when filled, is what Bangla visitors read (CR-06).
        if (app()->getLocale() === 'bn' && in_array($key, SiteCustomization::banglaTwinKeys(), true)) {
            $bangla = trim((string) (SiteCustomization::all()[$key.'_bn'] ?? ''));
            if ($bangla !== '') {
                return $bangla;
            }
        }
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

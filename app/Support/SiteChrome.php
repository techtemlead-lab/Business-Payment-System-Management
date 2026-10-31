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
    public static function text(string $key, ?string $custom = null): string
    {
        $translated = (string) __('site.'.$key);
        $custom = $custom !== null ? trim((string) $custom) : '';

        if ($custom === '') {
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

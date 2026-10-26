<?php

namespace App\Support;

final class SiteChrome
{
    /**
     * Resolve public marketing chrome copy. Bangla always prefers lang keys so
     * English CMS defaults do not block localization; English keeps staff overrides.
     */
    public static function text(string $key, ?string $custom = null): string
    {
        if (app()->getLocale() === 'bn') {
            return (string) __('site.'.$key);
        }

        $legacyCompanyBar = ['MADINA NATURAL HEALTH CARE', 'Madina Natural Health Care'];
        if ($key === 'company_bar' && filled($custom) && in_array((string) $custom, $legacyCompanyBar, true)) {
            return (string) __('site.company_bar');
        }

        return filled($custom) ? (string) $custom : (string) __('site.'.$key);
    }
}

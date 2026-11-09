<?php

namespace App\Support;

/**
 * Tabs that join related dealer and sub-dealer pages into one place. Every tab is
 * the page's existing address, so old links and bookmarks still open the right tab.
 */
final class PartnerHubTabs
{
    /** @var list<string> */
    private const NoticeSections = ['circulars', 'notifications'];

    /**
     * The tabs a portal page belongs to, or null when the page has none or an office user is viewing it.
     *
     * @return array{title: string, active: string, tabs: list<array{key: string, path: string, label: string}>}|null
     */
    public static function forSection(string $section, bool $isStaff): ?array
    {
        if ($isStaff) {
            return null;
        }
        if (in_array($section, self::NoticeSections, true)) {
            return self::notices($section);
        }

        return null;
    }

    /**
     * Office notices for everyone, and updates meant only for this partner.
     *
     * @return array{title: string, active: string, tabs: list<array{key: string, path: string, label: string}>}
     */
    public static function notices(string $active): array
    {
        return [
            'title' => __('partner_hub.notices_title'),
            'active' => $active,
            'tabs' => [
                ['key' => 'circulars', 'path' => '/circulars', 'label' => __('partner_hub.tab_office_notices')],
                ['key' => 'notifications', 'path' => '/notifications', 'label' => __('partner_hub.tab_my_updates')],
            ],
        ];
    }
}

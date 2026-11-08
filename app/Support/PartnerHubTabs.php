<?php

namespace App\Support;

/**
 * Tabs that join related dealer and sub-dealer pages into one place. Every tab is
 * the page's existing address, so old links and bookmarks still open the right tab.
 */
final class PartnerHubTabs
{
    /** @var list<string> */
    private const MoneySections = ['earnings', 'withdrawals', 'payment-receipts', 'full-report'];

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
        if (in_array($section, self::MoneySections, true)) {
            return self::money($section);
        }
        if (in_array($section, self::NoticeSections, true)) {
            return self::notices($section);
        }

        return null;
    }

    /**
     * "আমার টাকা": earnings, withdrawals, payment receipts and every record together.
     *
     * @return array{title: string, active: string, tabs: list<array{key: string, path: string, label: string}>}
     */
    public static function money(string $active): array
    {
        return [
            'title' => __('partner_hub.money_title'),
            'active' => $active,
            'tabs' => [
                ['key' => 'earnings', 'path' => '/earnings', 'label' => __('partner_hub.tab_earnings')],
                ['key' => 'withdrawals', 'path' => '/withdrawals', 'label' => __('partner_hub.tab_withdrawals')],
                ['key' => 'payment-receipts', 'path' => '/payment-receipts', 'label' => __('partner_hub.tab_receipts')],
                ['key' => 'full-report', 'path' => '/full-report', 'label' => __('partner_hub.tab_all_records')],
            ],
        ];
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

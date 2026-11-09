<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Tabs that join related dealer and sub-dealer pages into one place. Every tab is
 * the page's existing address, so old links and bookmarks still open the right tab.
 * A tab is offered only if the page itself would open, so the office's switches still remove it.
 */
final class PartnerHubTabs
{
    /** @var list<string> */
    private const NoticeSections = ['circulars', 'notifications'];

    /**
     * Pages a partner uses as one place, keyed by the row's id in portalNav.ts. `title`
     * is the row's name in the portal lang file.
     *
     * @var array<string, array{title: string, tabs: list<string>}>
     */
    private const Hubs = [
        'partner-orders' => ['title' => 'nav_module_commerce', 'tabs' => ['orders', 'deliveries']],
        'partner-team' => ['title' => 'nav_module_network', 'tabs' => ['network', 'customers', 'ranks', 'top-sellers', 'contests']],
        'partner-learning' => ['title' => 'nav_module_learning', 'tabs' => ['training', 'events', 'sales-kit']],
        'partner-office' => ['title' => 'nav_module_office', 'tabs' => ['messages', 'circulars', 'share-feedback', 'help']],
        'partner-account' => ['title' => 'nav_module_account', 'tabs' => ['profile', 'security', 'agreement']],
    ];

    /** A sub-dealer's team page stays reachable by address but is not one of their tabs. */
    private const DealerOnlyTabs = ['network'];

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
        $user = Auth::user();
        if (! $user instanceof User) {
            return null;
        }
        foreach (self::Hubs as $hub) {
            if (in_array($section, $hub['tabs'], true)) {
                return self::hub($user, $hub['title'], $hub['tabs'], $section);
            }
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

    /**
     * @param  list<string>  $tabs
     * @return array{title: string, active: string, tabs: list<array{key: string, path: string, label: string}>}|null
     */
    private static function hub(User $user, string $title, array $tabs, string $active): ?array
    {
        $portal = $user->role === 'sub_dealer' ? 'subdealer' : 'dealer';
        $open = [];
        foreach ($tabs as $tab) {
            $isHidden = $portal === 'subdealer' && in_array($tab, self::DealerOnlyTabs, true);
            if (! $isHidden && OfficeHubs::mayOpenSection($user, $portal, $tab)) {
                $open[] = ['key' => $tab, 'path' => '/'.$tab, 'label' => (string) __('office_menu.partner_tab_'.str_replace('-', '_', $tab))];
            }
        }
        if (count($open) < 2 || ! in_array($active, array_column($open, 'key'), true)) {
            return null;
        }

        return ['title' => (string) __('portal.'.$title), 'active' => $active, 'tabs' => $open];
    }
}

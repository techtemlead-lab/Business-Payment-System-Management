<?php

namespace App\Support;

use App\Domain\Support\PartnerEmptySections;
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

    /** The sidebar's one entry for the pages a partner opens less often (CR-14). */
    public const MoreSection = 'more';

    /**
     * The pages on the partner More page, in groups. Prescriptions are never listed: they stay in the sidebar.
     *
     * @var array<string, array<string, string>>
     */
    private const MoreGroups = [
        'learn' => ['training' => '/training', 'events' => '/events', 'sales-kit' => '/sales-kit'],
        'grow' => ['top-sellers' => '/top-sellers', 'contests' => '/contests'],
        'office' => ['share-feedback' => '/share-feedback', 'agreement' => '/agreement'],
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

    /**
     * The partner More page's boxes for the pages this partner can open, without empty groups.
     *
     * @return list<array{key: string, label: string, links: list<array{key: string, label: string, description: string, href: string}>}>
     */
    public static function moreGroups(User $user): array
    {
        $portal = $user->role === 'sub_dealer' ? 'subdealer' : 'dealer';
        // Pages the company has not filled yet are left out, the same as in the sidebar.
        $empty = app(PartnerEmptySections::class)->for($user);
        $groups = [];
        foreach (self::MoreGroups as $key => $links) {
            $open = [];
            foreach ($links as $section => $href) {
                if (! in_array($section, $empty, true) && OfficeHubs::mayOpenSection($user, $portal, $section)) {
                    $name = str_replace('-', '_', $section);
                    $open[] = [
                        'key' => $section,
                        'label' => (string) __('office_menu.partner_tab_'.$name),
                        'description' => (string) __('cr14.more_desc_'.$name),
                        'href' => $href,
                    ];
                }
            }
            if ($open !== []) {
                $groups[] = ['key' => $key, 'label' => (string) __('cr14.more_group_'.$key), 'links' => $open];
            }
        }

        return $groups;
    }

    public static function hasMoreToOpen(User $user): bool
    {
        return in_array($user->role, ['dealer', 'sub_dealer'], true) && self::moreGroups($user) !== [];
    }
}

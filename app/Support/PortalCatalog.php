<?php

namespace App\Support;

use App\Domain\Support\PartnerChat;
use App\Models\User;

final class PortalCatalog
{
    /** @return array<string, mixed> */
    public static function definition(string $portal): array
    {
        $definition = config('portals.'.$portal);
        abort_unless(is_array($definition), 404);

        return $definition;
    }

    /** @return list<string> */
    public static function sections(string $portal): array
    {
        return array_values(self::definition($portal)['sections'] ?? []);
    }

    /** @return array<string, string> */
    public static function staffCapabilities(string $portal): array
    {
        return self::definition($portal)['staff_capabilities'] ?? [];
    }

    public static function page(string $portal): string
    {
        return (string) (self::definition($portal)['page'] ?? 'Portal');
    }

    /** @return array<string, list<string>> */
    public static function nav(string $portal): array
    {
        return self::definition($portal)['nav'] ?? [];
    }

    /**
     * Section names the portal's catch-all `/{section?}` route may match.
     * Partner portals also serve the staff desk (manage sections) for dealers
     * with a linked staff role; staff portals accept legacy alias slugs.
     *
     * @return list<string>
     */
    public static function routeSections(string $portal): array
    {
        $sections = self::sections($portal);
        if (in_array($portal, ['dealer', 'subdealer'], true)) {
            $sections = [...$sections, ...self::sections('manage')];
        }

        return array_values(array_unique([...$sections, 'customization', 'capital-flow']));
    }

    public static function allowsSection(string $portal, string $section): bool
    {
        return in_array($section, self::sections($portal), true);
    }

    public static function assertSection(User $user, string $portal, string $section): void
    {
        abort_unless(self::allowsSection($portal, $section), 404);
        if (in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            DashboardManagement::assertPartnerSection($user, $section);
        }
        if ($section === 'benefits-fund') {
            abort_unless($portal === 'sadmin' && $user->owner(), 403);
        }
        if (in_array($portal, ['manage', 'sadmin'], true) && $section === 'agreement') {
            // Owners edit the agreement; staff who manage accounts see who accepted it.
            // A dealer working in the staff desk still reads their own agreement there.
            abort_unless($user->owner() || $user->allows('accounts') || in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['accounts', 'sub-dealers', 'employees'], true)) {
            // Owners (Super Admin, MD, Chairman) open the account lists to reset or see passwords.
            abort_unless($user->owner() || $user->allows('accounts'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['enquiries', 'feedback'], true)) {
            abort_unless($user->allows('content') || $user->allows('accounts'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'catalog') {
            abort_unless($user->allows('catalog') || $user->allows('pricing') || $user->allows('inventory') || $user->allows('orders') || $user->allows('finance'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'branches') {
            abort_unless($user->allows('inventory') || $user->allows('accounts') || $user->owner(), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'branch-performance') {
            abort_unless($user->owner() || $user->allows('reports') || $user->allows('finance') || $user->allows('inventory'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['orders', 'deliveries'], true)) {
            abort_unless($user->allows('orders') || $user->allows('finance'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['training', 'events'], true)) {
            abort_unless($user->owner() || $user->allows('content'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'partner-messages') {
            abort_unless(PartnerChat::canAnswer($user), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['reports', 'top-sellers'], true)) {
            abort_unless($user->allows('reports') || $user->allows('orders') || $user->allows('finance'), 403);
        } else {
            $capability = self::staffCapabilities($portal)[$section] ?? null;
            if ($capability !== null) {
                abort_unless($user->allows($capability), 403);
            }
        }
    }

    /** @return array{sections: list<string>, nav: array<string, list<string>>, page: string} */
    public static function inertiaPayload(string $portal): array
    {
        return [
            'sections' => self::sections($portal),
            'nav' => self::nav($portal),
            'page' => self::page($portal),
        ];
    }
}

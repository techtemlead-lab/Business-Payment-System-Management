<?php

namespace App\Support;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Staff\LeadershipAccess;
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

        return array_values(array_unique([...$sections, 'customization']));
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
        if ($section === 'prescriptions') {
            // Off for every partner until the owner verifies their registration; the owner-doctor always has it.
            abort_unless(PrescriptionAccess::canPrescribe($user), 403, (string) __('prescription.err_not_enabled'));
        }
        if ($section === 'data-cleanup') {
            // Deleting records: the Super Admin alone (SA-07).
            abort_unless($portal === 'sadmin' && $user->role === 'super_admin', 403);
        }
        if ($section === 'login-rules' || $section === 'privacy-requests' || $section === 'visitors' || $section === 'reason-fields' || $section === 'errors') {
            // Owner-only pages on the owner panel: the login switches, data requests, the website visitor
            // count and the reason box switches. The MD and Chairman need the Super Admin's grant (D11).
            abort_unless($portal === 'sadmin' && LeadershipAccess::granted($user, 'owner_tools'), 403);
        }
        if ($portal === 'sadmin' && in_array($section, ['sales-kit', 'schemes', 'contests'], true)) {
            // Owner-managed partner tools: staff on the owner panel do not edit these.
            abort_unless(LeadershipAccess::granted($user, 'owner_tools'), 403);
        }
        if (in_array($portal, ['manage', 'sadmin'], true) && $section === 'agreement') {
            // Owners edit the agreement; staff who manage accounts see who accepted it.
            // A dealer working in the staff desk still reads their own agreement there.
            abort_unless(LeadershipAccess::granted($user, 'owner_tools') || $user->allows('accounts') || in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['accounts', 'sub-dealers', 'employees'], true)) {
            // Owners open the account lists to reset or see passwords; the MD and Chairman need the grant (D11).
            abort_unless(LeadershipAccess::granted($user, 'accounts') || $user->allows('accounts'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'network-tree') {
            // Owners see every dealer's tree, not only staff who manage accounts (MD and Chairman: with the grant).
            abort_unless(LeadershipAccess::granted($user, 'accounts') || $user->allows('accounts'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'enquiries') {
            abort_unless($user->allows('content') || $user->allows('accounts') || $user->allows('support_desk'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'support-admin') {
            abort_unless(LeadershipAccess::granted($user, 'owner_tools') || $user->allows('accounts') || $user->allows('support_desk'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'feedback') {
            // Reviews go on the public website, so only website (content) staff check them.
            abort_unless($user->allows('content'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'catalog') {
            abort_unless($user->allows('catalog') || $user->allows('pricing') || $user->allows('inventory') || $user->allows('orders') || $user->allows('finance'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'branches') {
            abort_unless($user->allows('inventory') || $user->allows('accounts') || LeadershipAccess::granted($user, 'owner_tools'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'branch-performance') {
            abort_unless(LeadershipAccess::granted($user, 'owner_tools') || $user->allows('reports') || $user->allows('finance') || $user->allows('inventory'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['orders', 'deliveries'], true)) {
            abort_unless($user->allows('orders') || $user->allows('finance'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['training', 'events'], true)) {
            abort_unless(LeadershipAccess::granted($user, 'owner_tools') || $user->allows('content'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['purchasing', 'stock-counts'], true)) {
            // F-verify-09: buying from suppliers and counting stock are working pages, not reports: they need the
            // right to enter or approve stock, not only to look at stock movement.
            abort_unless($user->canAct('inventory', 'create') || $user->canAct('inventory', 'approve'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'partner-messages') {
            abort_unless(PartnerChat::canAnswer($user), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && $section === 'reports') {
            // Only an old address now: it redirects, and field staff go to their report files.
            abort_unless($user->allows('reports') || $user->allows('orders') || $user->allows('finance') || $user->allows('field_sales'), 403);
        } elseif (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['top-sellers', 'partner-scorecard'], true)) {
            abort_unless($user->allows('reports') || $user->allows('orders') || $user->allows('finance'), 403);
        } else {
            $capability = self::staffCapabilities($portal)[$section] ?? null;
            if ($capability !== null) {
                abort_unless($user->allows($capability), 403);
            }
        }
    }

    /** @return array{sections: list<string>, page: string} */
    public static function inertiaPayload(string $portal): array
    {
        return [
            'sections' => self::sections($portal),
            'page' => self::page($portal),
        ];
    }
}

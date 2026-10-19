<?php

namespace App\Support;

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
        return self::definition($portal)['sections'] ?? [];
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

    public static function allowsSection(string $portal, string $section): bool
    {
        return in_array($section, self::sections($portal), true);
    }

    public static function assertSection(User $user, string $portal, string $section): void
    {
        abort_unless(self::allowsSection($portal, $section), 404);
        if ($section === 'benefits-fund') {
            abort_unless($portal === 'sadmin' && $user->owner(), 403);
        }
        $capability = self::staffCapabilities($portal)[$section] ?? null;
        if ($capability !== null) {
            abort_unless($user->allows($capability), 403);
        }
        if (in_array($portal, ['manage', 'sadmin'], true) && $section === 'catalog') {
            abort_unless($user->allows('catalog') || $user->allows('pricing') || $user->allows('inventory') || $user->allows('orders') || $user->allows('finance'), 403);
        }
        if (in_array($portal, ['manage', 'sadmin'], true) && in_array($section, ['orders', 'deliveries', 'dues'], true)) {
            abort_unless($user->allows('orders') || $user->allows('finance'), 403);
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

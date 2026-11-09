<?php

namespace Tests\Support;

use RuntimeException;

/**
 * The text of the signed-in portal page, wherever its markup now lives: `Portal.tsx`, its shared
 * helpers and every section file moved out of it. A test that reads the page's source reads this,
 * so that a string moved to a section is still seen and an "is absent" check still checks something.
 */
final class PortalSource
{
    public static function text(): string
    {
        return implode("\n", array_map(self::read(...), self::files()));
    }

    /** @return list<string> */
    private static function files(): array
    {
        $sections = glob(resource_path('js/components/office/sections/*.tsx'));

        if ($sections === false || $sections === []) {
            throw new RuntimeException('No section files found, so the portal source would be incomplete.');
        }

        return [
            resource_path('js/pages/Portal.tsx'),
            resource_path('js/components/office/portalShared.tsx'),
            ...$sections,
        ];
    }

    private static function read(string $path): string
    {
        $text = file_get_contents($path);

        if ($text === false || $text === '') {
            throw new RuntimeException('Could not read '.$path);
        }

        return $text;
    }
}

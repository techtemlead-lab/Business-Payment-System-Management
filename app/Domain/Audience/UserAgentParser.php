<?php

namespace App\Domain\Audience;

/**
 * Reads a browser's user-agent text into a device kind, a browser and a system name: just enough for the
 * owner to tell a phone from a computer. No version numbers are kept.
 */
final class UserAgentParser
{
    public const Pc = 'pc';

    public const Phone = 'phone';

    public const Tablet = 'tablet';

    /**
     * @return array{device_kind: string, browser: string|null, os: string|null}
     */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        return ['device_kind' => self::kind($ua), 'browser' => $browser, 'os' => $os];
    }

    private static function kind(string $ua): string
    {
        if (str_contains($ua, 'iPad') || str_contains($ua, 'Tablet') || (str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile'))) {
            return self::Tablet;
        }
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'iPhone') || str_contains($ua, 'iPod') || str_contains($ua, 'Android')) {
            return self::Phone;
        }

        return self::Pc;
    }
}

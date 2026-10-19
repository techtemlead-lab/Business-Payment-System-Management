<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class PortalHost
{
    /** @var list<string> */
    public const Portals = ['public', 'dealer', 'subdealer', 'manage', 'sadmin'];

    public static function domain(): string
    {
        $domain = (string) config('madina.app_domain');

        return $domain !== '' ? $domain : 'localhost';
    }

    public static function host(string $portal): string
    {
        $domain = self::domain();

        return $portal === 'public' ? $domain : $portal.'.'.$domain;
    }

    public static function current(?Request $request = null): ?string
    {
        $host = strtolower(($request ?? request())->getHost());
        $domain = strtolower(self::domain());
        if ($host === $domain) {
            return 'public';
        }
        foreach (['dealer', 'subdealer', 'manage', 'sadmin'] as $portal) {
            if ($host === $portal.'.'.$domain) {
                return $portal;
            }
        }

        return null;
    }

    public static function url(string $portal, string $path = '/'): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $suffix = $path === '' || $path === '/' ? '/' : (str_starts_with($path, '/') ? $path : '/'.$path);

        return $scheme.'://'.self::host($portal).$port.$suffix;
    }

    public static function origin(string $portal): string
    {
        return rtrim(self::url($portal, '/'), '/');
    }

    public static function forRole(string $role): string
    {
        if (in_array($role, ['super_admin', 'md', 'chairman'], true)) {
            return 'sadmin';
        }
        if ($role === 'sub_dealer') {
            return 'subdealer';
        }
        if ($role === 'dealer') {
            return 'dealer';
        }
        if ($role === 'employee' || str_starts_with($role, 'custom_') || array_key_exists($role, config('madina.roles'))) {
            return 'manage';
        }

        return 'dealer';
    }

    public static function forUser(User $user): string
    {
        if ($user->owner()) {
            return 'sadmin';
        }
        if ($user->role === 'sub_dealer') {
            return 'subdealer';
        }
        if ($user->staff()) {
            return 'manage';
        }

        return 'dealer';
    }

    public static function loginUrl(User|string $roleOrPortal): string
    {
        if ($roleOrPortal instanceof User) {
            return self::url(self::forUser($roleOrPortal), '/login');
        }
        $portal = in_array($roleOrPortal, self::Portals, true) ? $roleOrPortal : self::forRole($roleOrPortal);
        if ($portal === 'public') {
            return self::url('public', '/');
        }

        return self::url($portal, '/login');
    }

    public static function homeUrl(User $user): string
    {
        return self::url(self::forUser($user), '/');
    }

    /**
     * Map a legacy single-host path onto the portal that now owns it.
     *
     * @return array{host: string, uri: string}|null
     */
    public static function testRequest(string $uri, ?User $actor = null): ?array
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $portal = self::testPortal($path, $actor);
        if ($portal === null) {
            return null;
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return [
            'host' => self::host($portal),
            'uri' => self::stripLegacy($path).$query,
        ];
    }

    private static function testPortal(string $path, ?User $actor): ?string
    {
        if ($path === '/owner' || str_starts_with($path, '/owner/')) {
            return 'sadmin';
        }
        if ($path === '/company' || str_starts_with($path, '/company/')) {
            return $actor?->owner() ? 'sadmin' : 'manage';
        }
        if ($path === '/workspace' || str_starts_with($path, '/workspace/')) {
            if ($actor?->role === 'sub_dealer') {
                return 'subdealer';
            }
            if ($actor?->owner()) {
                return 'sadmin';
            }
            if ($actor?->staff()) {
                return 'manage';
            }

            return 'dealer';
        }
        if (self::isCredentialPath($path)) {
            return $actor instanceof User ? self::forUser($actor) : 'dealer';
        }
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/commands/') || str_starts_with($path, '/exports/')) {
            return $actor instanceof User ? self::forUser($actor) : 'dealer';
        }

        return null;
    }

    private static function isCredentialPath(string $path): bool
    {
        return in_array($path, ['/login', '/forgot-password', '/reset-password', '/logout'], true)
            || str_starts_with($path, '/reset-password/')
            || $path === '/security'
            || str_starts_with($path, '/security/');
    }

    private static function stripLegacy(string $path): string
    {
        foreach (['/company', '/workspace', '/owner'] as $prefix) {
            if ($path === $prefix) {
                return '/';
            }
            if (str_starts_with($path, $prefix.'/')) {
                $stripped = substr($path, strlen($prefix));

                return $stripped === '' ? '/' : $stripped;
            }
        }

        return $path;
    }
}

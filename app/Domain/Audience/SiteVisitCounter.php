<?php

namespace App\Domain\Audience;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records one page opened on the public website: a row in `site_visits` and the day's totals in
 * `site_visit_days`. A visitor is a random number kept in the browser's cookie, counted once
 * for each business day. No name, phone or IP address is kept.
 */
final class SiteVisitCounter
{
    /** The browser cookie that holds the visitor's random number. */
    public const VisitorCookie = 'madina_vid';

    /** How long the cookie lasts, in minutes: one year. */
    public const VisitorCookieMinutes = 60 * 24 * 365;

    /** Raw visits are kept this many months; the daily totals are kept for good. */
    public const KeepVisitsMonths = 12;

    public const Sources = ['direct', 'facebook', 'google', 'youtube', 'whatsapp', 'instagram', 'tiktok', 'other', 'internal'];

    /** A screen this narrow counts as a phone, whatever the browser calls itself. */
    private const PhoneScreenWidth = 768;

    private const BotPattern = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp\/|embedly|quora link preview|outbrain|pinterest|vkshare|w3c_validator|headless|lighthouse|pingdom|uptime|monitor|curl\/|wget\/|python-requests|go-http-client|okhttp|axios\/|node-fetch/i';

    private const PhonePattern = '/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i';

    /**
     * Referrer host endings and the source they mean. A host matches when it is the ending
     * itself or a subdomain of it, so "google.com.evil.example" is not Google.
     *
     * @var array<string, list<string>>
     */
    private const SourceHosts = [
        'facebook' => ['facebook.com', 'fb.com', 'fb.me', 'messenger.com'],
        'google' => ['google.com', 'google.com.bd', 'googleadservices.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'whatsapp' => ['whatsapp.com', 'wa.me'],
        'instagram' => ['instagram.com'],
        'tiktok' => ['tiktok.com'],
    ];

    /** Address marks that ad links add, for apps that open a page without saying where from. */
    private const SourceTags = ['fbclid' => 'facebook', 'gclid' => 'google'];

    public static function looksLikeBot(string $browser): bool
    {
        return trim($browser) === '' || preg_match(self::BotPattern, $browser) === 1;
    }

    public static function isVisitorId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{32}$/D', $value) === 1;
    }

    public static function newVisitorId(): string
    {
        return Str::random(32);
    }

    /**
     * The page address as it is kept, or null when it is not a page address at all.
     * A referral link is kept as "/join" so the partner's code is never stored.
     */
    public static function page(mixed $path): ?string
    {
        if (! is_string($path) || strlen($path) > 255 || preg_match('#^/[A-Za-z0-9\-._~/%]*$#D', $path) !== 1 || str_contains($path, '//')) {
            return null;
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return str_starts_with($path, '/join/') ? '/join' : $path;
    }

    /**
     * Where the visitor came from. `$siteHost` is the host the page was opened on: a referrer
     * from it means the visitor only moved between two of our pages.
     */
    public static function source(mixed $referrer, mixed $tag, string $siteHost): string
    {
        $host = is_string($referrer) && $referrer !== '' ? parse_url($referrer, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            $host = strtolower($host);
            if ($host === strtolower($siteHost)) {
                return 'internal';
            }
            foreach (self::SourceHosts as $source => $endings) {
                foreach ($endings as $ending) {
                    if ($host === $ending || str_ends_with($host, '.'.$ending)) {
                        return $source;
                    }
                }
            }

            return 'other';
        }

        return is_string($tag) ? (self::SourceTags[$tag] ?? 'direct') : 'direct';
    }

    public static function device(string $browser, mixed $screenWidth): string
    {
        $width = is_numeric($screenWidth) ? (int) $screenWidth : 0;

        return preg_match(self::PhonePattern, $browser) === 1 || ($width > 0 && $width <= self::PhoneScreenWidth) ? 'mobile' : 'desktop';
    }

    /** Today's date in the business timezone, as the day a visit belongs to. */
    public static function businessDay(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->setTimezone((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString();
    }

    /**
     * Keep one page view. The visitor is added to the day's visitor total only the first
     * time that browser is seen on that business day.
     */
    public function record(string $visitorId, string $page, string $source, string $device): void
    {
        $day = self::businessDay();
        DB::transaction(function () use ($visitorId, $page, $source, $device, $day): void {
            $firstToday = ! DB::table('site_visits')->where('visitor_id', $visitorId)->where('visited_on', $day)->exists();
            DB::table('site_visits')->insert([
                'visitor_id' => $visitorId,
                'path' => $page,
                'source' => $source,
                'device' => $device,
                'visited_on' => $day,
                'created_at' => now(),
            ]);
            DB::statement(
                'INSERT INTO site_visit_days (day, views, visitors, updated_at) VALUES (?, 1, ?, ?)
                 ON CONFLICT (day) DO UPDATE SET views = site_visit_days.views + 1, visitors = site_visit_days.visitors + EXCLUDED.visitors, updated_at = EXCLUDED.updated_at',
                [$day, $firstToday ? 1 : 0, now()],
            );
        });
    }

    /** Delete raw visits older than the keeping time. The daily totals are left alone. */
    public function prune(): int
    {
        $cutoff = CarbonImmutable::parse(self::businessDay())->subMonthsNoOverflow(self::KeepVisitsMonths)->toDateString();

        return DB::table('site_visits')->where('visited_on', '<', $cutoff)->delete();
    }
}

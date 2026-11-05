<?php

namespace App\Domain\Content;

use App\Support\YouTubeVideo;

/**
 * Checks a training video link and turns it into a safe embed address.
 *
 * Only https links on YouTube or Facebook are accepted. YouTube plays through
 * the privacy-enhanced youtube-nocookie.com player; Facebook through its video
 * plugin. These two embed hosts are the only ones the site's frame-src allows.
 */
final class TrainingVideoLink
{
    /** Hosts a staff member may paste a link from. */
    public const YouTubeHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'];

    public const FacebookHosts = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.watch'];

    /** Hosts the browser loads the player from (keep in step with the CSP frame-src). */
    public const EmbedHosts = ['https://www.youtube-nocookie.com', 'https://www.facebook.com'];

    public static function isAllowed(string $url): bool
    {
        return self::embedUrl($url) !== null;
    }

    /** The player address for an allowed link, or null when the link is not allowed. */
    public static function embedUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s"\'<>\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, self::YouTubeHosts, true)) {
            $id = YouTubeVideo::id($url);

            return $id === null ? null : 'https://www.youtube-nocookie.com/embed/'.$id;
        }
        if (in_array($host, self::FacebookHosts, true)) {
            $path = trim($parts['path'] ?? '', '/');
            if ($path === '') {
                return null;
            }
            $clean = 'https://'.$host.'/'.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');

            return 'https://www.facebook.com/plugins/video.php?href='.rawurlencode($clean).'&show_text=false';
        }

        return null;
    }

    /** "youtube" or "facebook" for an allowed link, otherwise null. */
    public static function provider(string $url): ?string
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return match (true) {
            in_array($host, self::YouTubeHosts, true) => 'youtube',
            in_array($host, self::FacebookHosts, true) => 'facebook',
            default => null,
        };
    }
}

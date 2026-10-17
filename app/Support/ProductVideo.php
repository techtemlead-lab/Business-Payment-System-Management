<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductVideo
{
    /** @return array{id: string, hash: ?string}|null */
    public static function vimeo(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array(strtolower($parts['host'] ?? ''), ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            return null;
        }
        if (! preg_match('~^/(?:video/)?([0-9]{6,12})(?:/([a-fA-F0-9]{6,64}))?/?$~D', $parts['path'] ?? '', $match)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $hash = $match[2] ?? $query['h'] ?? null;
        if ($hash !== null && (! is_string($hash) || ! preg_match('/^[a-fA-F0-9]{6,64}$/D', $hash))) {
            return null;
        }

        return ['id' => $match[1], 'hash' => $hash];
    }

    public static function direct(string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')
            || ! preg_match('/\.(mp4|webm)$/iD', $parts['path'] ?? '')) {
            return null;
        }

        return $url;
    }

    /** @return array<string, mixed>|null */
    public static function homepage(): ?array
    {
        $row = DB::table('business_settings')->where('key', 'homepage_video')->first();
        $settings = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }
        $product = Product::where('active', true)->find($settings['product_id'] ?? null);
        if (! $product) {
            return null;
        }
        $media = $product->media();

        return $media['video_url'] || $media['video_embed_url'] ? [...$settings, 'product_name' => $product->name, 'media' => $media] : null;
    }
}

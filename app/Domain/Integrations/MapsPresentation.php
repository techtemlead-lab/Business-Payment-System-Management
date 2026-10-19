<?php

namespace App\Domain\Integrations;

final class MapsPresentation
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * @return array{provider: string, enabled: bool, embed_url: ?string, directions_url: string, script_url: ?string}
     */
    public function forAddress(string $address): array
    {
        $maps = $this->settings->mapsPublicConfig();
        $query = rawurlencode($address);
        $directions = 'https://www.openstreetmap.org/search?query='.$query;
        $embed = 'https://www.openstreetmap.org/export/embed.html?bbox=90.0%2C23.5%2C90.6%2C24.0&layer=mapnik&marker=0%2C0';
        if (filled($address)) {
            $embed = 'https://maps.google.com/maps?q='.$query.'&z=15&output=embed';
        }
        if ($maps['enabled'] && filled($maps['map_embed_url'])) {
            $embed = (string) $maps['map_embed_url'];
            $directions = 'https://www.google.com/maps/search/?api=1&query='.$query;

            return [
                'provider' => 'google_embed',
                'enabled' => true,
                'embed_url' => $embed,
                'directions_url' => $directions,
                'script_url' => null,
            ];
        }
        if ($maps['enabled']) {
            $directions = 'https://www.google.com/maps/search/?api=1&query='.$query;
        }

        return [
            'provider' => $maps['enabled'] ? 'google_search' : 'openstreetmap',
            'enabled' => (bool) $maps['enabled'],
            'embed_url' => $embed,
            'directions_url' => $directions,
            // Intentionally omitted: browser Maps JavaScript API keys must not be published.
            'script_url' => null,
        ];
    }
}

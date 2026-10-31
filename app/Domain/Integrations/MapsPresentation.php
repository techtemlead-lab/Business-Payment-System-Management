<?php

namespace App\Domain\Integrations;

final class MapsPresentation
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * Build public map embed + directions links for the head-office location.
     *
     * Directions always open Google Maps. The query comes from CMS
     * `office_address` (and optional coordinates) — never a hardcoded URL.
     *
     * @return array{provider: string, enabled: bool, embed_url: ?string, directions_url: string, script_url: ?string}
     */
    public function forAddress(string $address, ?float $latitude = null, ?float $longitude = null): array
    {
        $maps = $this->settings->mapsPublicConfig();
        $address = trim($address);
        $hasCoordinates = $latitude !== null
            && $longitude !== null
            && $latitude >= -90.0 && $latitude <= 90.0
            && $longitude >= -180.0 && $longitude <= 180.0;

        $destination = $hasCoordinates
            ? $this->formatCoordinates($latitude, $longitude)
            : $address;

        if ($destination === '') {
            $destination = 'Bangladesh';
        }

        $query = rawurlencode($destination);
        $directions = 'https://www.google.com/maps/dir/?api=1&destination='.$query;
        $embed = 'https://maps.google.com/maps?q='.$query.'&z=16&output=embed';

        if ($maps['enabled'] && filled($maps['map_embed_url'])) {
            return [
                'provider' => 'google_embed',
                'enabled' => true,
                'embed_url' => (string) $maps['map_embed_url'],
                'directions_url' => $directions,
                'script_url' => null,
            ];
        }

        return [
            'provider' => $maps['enabled'] ? 'google_search' : 'google_maps',
            'enabled' => (bool) $maps['enabled'],
            'embed_url' => $embed,
            'directions_url' => $directions,
            // Intentionally omitted: browser Maps JavaScript API keys must not be published.
            'script_url' => null,
        ];
    }

    private function formatCoordinates(float $latitude, float $longitude): string
    {
        return rtrim(rtrim(number_format($latitude, 6, '.', ''), '0'), '.').','
            .rtrim(rtrim(number_format($longitude, 6, '.', ''), '0'), '.');
    }
}

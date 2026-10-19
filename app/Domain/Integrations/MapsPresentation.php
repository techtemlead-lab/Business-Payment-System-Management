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
        $embed = null;
        $script = null;
        if ($maps['enabled']) {
            if (filled($maps['map_embed_url'])) {
                $embed = (string) $maps['map_embed_url'];
            } elseif (filled($maps['api_key'])) {
                $embed = 'https://www.google.com/maps/embed/v1/place?key='.rawurlencode((string) $maps['api_key']).'&q='.$query;
                $script = 'https://maps.googleapis.com/maps/api/js?key='.rawurlencode((string) $maps['api_key']).'&libraries=places';
            }
            $directions = 'https://www.google.com/maps/search/?api=1&query='.$query;
        }

        return [
            'provider' => $maps['enabled'] && $embed ? 'google' : 'openstreetmap',
            'enabled' => (bool) $maps['enabled'],
            'embed_url' => $embed,
            'directions_url' => $directions,
            'script_url' => $script,
        ];
    }
}

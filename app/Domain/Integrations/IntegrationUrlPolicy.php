<?php

namespace App\Domain\Integrations;

use Illuminate\Validation\ValidationException;

/**
 * Where an integration may send its stored credentials and customer data (SA-03).
 *
 * - Only https addresses.
 * - A provider that ships with a default address may only point at that provider's own domain
 *   (pathao.com, bka.sh, sslcommerz.com …), so nobody with settings access can send the courier
 *   or payment password to their own server.
 * - A provider without a default address (custom SMS gateway, birth-registration service, Rocket)
 *   may point at any public host, never at an internal, private or link-local address.
 * - The map box only accepts Google's own embed address.
 *
 * The HTTP clients also refuse redirects, so an allowed host cannot bounce a request elsewhere.
 */
final class IntegrationUrlPolicy
{
    public const UrlFields = ['base_url', 'balance_url'];

    public const MapEmbedPrefix = 'https://www.google.com/maps/embed';

    /** Second-level labels under which a company's own domain is three labels long (pathao.com vs redx.com.bd). */
    private const ShortSuffixes = ['com', 'net', 'org', 'gov', 'edu', 'co', 'ac'];

    /** @var (callable(string): list<string>)|null */
    private $resolver;

    /** @param  (callable(string): list<string>)|null  $resolver  host to IP list; tests pass a fake */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param  array<string, mixed>  $defaults  the provider's catalog defaults
     * @param  array<string, mixed>  $values  the values about to be saved
     *
     * @throws ValidationException
     */
    public function assertAllowed(string $group, array $defaults, array $values): void
    {
        $domains = $this->providerDomains($defaults);
        foreach (self::UrlFields as $field) {
            $url = trim((string) ($values[$field] ?? ''));
            if ($url === '' || ! array_key_exists($field, $defaults)) {
                continue;
            }
            $this->assertUrl($field, $url, $domains);
        }
        if ($group === 'maps') {
            $embed = trim((string) ($values['map_embed_url'] ?? ''));
            if ($embed !== '' && ! str_starts_with($embed, self::MapEmbedPrefix)) {
                throw ValidationException::withMessages(['map_embed_url' => __('security.err_map_embed_url')]);
            }
        }
    }

    /**
     * The domains a provider may use, taken from its default addresses.
     *
     * @param  array<string, mixed>  $defaults
     * @return list<string>
     */
    public function providerDomains(array $defaults): array
    {
        $domains = [];
        foreach (self::UrlFields as $field) {
            $host = parse_url((string) ($defaults[$field] ?? ''), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domains[] = $this->registrableDomain(strtolower($host));
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  list<string>  $domains
     *
     * @throws ValidationException
     */
    private function assertUrl(string $field, string $url, array $domains): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages([$field => __('security.err_integration_https')]);
        }
        $host = rtrim($host, '.');
        if ($this->reservedTestHost($host)) {
            return;
        }
        if ($domains !== []) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return;
                }
            }
            throw ValidationException::withMessages([$field => __('security.err_integration_host', ['hosts' => implode(', ', $domains)])]);
        }
        $addresses = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false ? [trim($host, '[]')] : $this->resolve($host);
        if ($addresses === []) {
            throw ValidationException::withMessages([$field => __('security.err_integration_unreachable')]);
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw ValidationException::withMessages([$field => __('security.err_integration_private')]);
            }
        }
    }

    private function isPublicAddress(string $address): bool
    {
        // ::ffff:10.0.0.1 is the IPv4 address 10.0.0.1 written as IPv6.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // 100.64.0.0/10 (carrier-grade NAT) is shared internal space that PHP's filter lets through.
        $long = ip2long($address);

        return $long === false || ($long & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000);
    }

    /**
     * On a developer machine and in the automated tests, the reserved names .test, .example and
     * .invalid (RFC 2606) stand in for the providers. They can never be real servers, and the live
     * site refuses them like any other address.
     */
    private function reservedTestHost(string $host): bool
    {
        return app()->environment(['local', 'testing'])
            && preg_match('/(^|\.)(test|example|invalid)$/', $host) === 1;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }
        $v4 = gethostbynamel($host) ?: [];
        $v6 = [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $v6[] = $record['ipv6'];
            }
        }

        return array_values(array_merge($v4, $v6));
    }

    private function registrableDomain(string $host): string
    {
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }
        $take = in_array($labels[$count - 2], self::ShortSuffixes, true) && strlen($labels[$count - 1]) === 2 ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }
}

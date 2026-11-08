<?php

namespace App\Domain\Orders;

use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\IntegrationSettings;

/**
 * The couriers a dealer can pick on the order form: only the ones the owner switched on
 * in Integrations, plus "the company decides" (stored as null on the order).
 */
final class CourierOptions
{
    public const CompanyDecides = 'company';

    /**
     * Where each courier lets anyone follow a parcel with its tracking number. Paperfly and
     * eCourier are the addresses of the tracking forms on their own websites. A courier with no
     * confirmed address is left out, so people see its name and the number instead of a bad link.
     */
    private const PublicTrackingPages = [
        'steadfast' => 'https://steadfast.com.bd/t/:tracking',
        'pathao' => 'https://merchant.pathao.com/tracking?consignment_id=:consignment',
        'redx' => 'https://redx.com.bd/track-parcel/?trackingId=:tracking',
        'paperfly' => 'https://paperfly.com.bd/track?q=:tracking',
        'ecourier' => 'https://ecourier.com.bd/tracking?tracking_no=:tracking',
    ];

    /** @var list<array{key: string, label: string}>|null */
    private ?array $enabled = null;

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * Couriers switched on by the owner, read once per request.
     *
     * @return list<array{key: string, label: string, manual?: bool, tracking_url?: string}>
     */
    public function enabled(): array
    {
        return $this->enabled ??= $this->settings->enabledCourierOptions();
    }

    /**
     * What the order form offers: the switched-on couriers, then "the company decides".
     *
     * @return list<array{key: string, label: string}>
     */
    public function choices(?string $locale = null): array
    {
        return [...$this->enabled(), ['key' => self::CompanyDecides, 'label' => __('courier.company_decides', [], $locale)]];
    }

    /** @return list<string> */
    public function choiceKeys(): array
    {
        return array_column($this->choices(), 'key');
    }

    public function isEnabled(?string $provider): bool
    {
        return $provider !== null && in_array($provider, array_column($this->enabled(), 'key'), true);
    }

    /** A courier with no booking system: staff send the parcel and update it by hand. */
    public static function isManual(?string $provider): bool
    {
        return in_array($provider, IntegrationCatalog::ManualCouriers, true);
    }

    /**
     * The public tracking pages the owner has for the switched-on manual couriers.
     *
     * @return array<string, string>
     */
    public function manualTrackingPages(): array
    {
        return array_filter(array_column(array_filter($this->enabled(), fn (array $option): bool => $option['manual'] ?? false), 'tracking_url', 'key'), 'filled');
    }

    /** The value saved on the order: a courier key, or null when the company decides. */
    public static function stored(?string $choice): ?string
    {
        return $choice === null || $choice === '' || $choice === self::CompanyDecides ? null : $choice;
    }

    /** A courier key as people read it ("Steadfast"), or "Company decides" for null. */
    public static function label(?string $provider, ?string $locale = null): string
    {
        if ($provider === null || $provider === '' || $provider === self::CompanyDecides) {
            return __('courier.company_decides', [], $locale);
        }
        if (self::isManual($provider)) {
            return __('courier_more.provider_'.$provider, [], $locale);
        }

        return in_array($provider, self::knownProviders(), true)
            ? __('portal.si_provider_'.$provider, [], $locale)
            : ucfirst($provider);
    }

    /** @return list<string> every courier the app knows how to book, switched on or not */
    public static function knownProviders(): array
    {
        return array_keys(IntegrationCatalog::groups()['couriers']['providers']);
    }

    /**
     * The courier's own public page for following this parcel, when there is a number to follow.
     *
     * @param  array<string, string>  $manualPages  from manualTrackingPages()
     */
    public static function trackingUrl(?string $provider, ?string $tracking, ?string $consignment = null, array $manualPages = []): ?string
    {
        $pattern = self::PublicTrackingPages[$provider ?? ''] ?? $manualPages[$provider ?? ''] ?? null;
        $tracking = trim((string) $tracking);
        $consignment = trim((string) $consignment) ?: $tracking;
        if ($pattern === null || $tracking === '') {
            return null;
        }

        return strtr($pattern, [':tracking' => rawurlencode($tracking), ':consignment' => rawurlencode($consignment)]);
    }
}

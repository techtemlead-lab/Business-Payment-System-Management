<?php

namespace App\Support;

use App\Models\User;

/**
 * Open Graph / Twitter Card payloads for public homepage, products, and referral landing pages.
 */
final class SocialShareMeta
{
    /** Landscape share card image used when CMS does not set a custom og_image. */
    public const DefaultShareImage = '/branding/madina-hero-banner-1.jpg';

    public const DefaultShareImageWidth = 1376;

    public const DefaultShareImageHeight = 768;

    /**
     * @return array{title:string,description:string,url:string,image:?string,image_width:?int,image_height:?int,type:string,site_name:string}
     */
    public static function homepage(): array
    {
        $custom = SiteCustomization::all();
        $customImage = trim((string) ($custom['og_image'] ?? ''));
        $image = self::absoluteUrl($customImage !== '' ? $customImage : self::DefaultShareImage);
        $usingDefault = $customImage === '';

        return [
            'title' => (string) ($custom['meta_title'] ?? __('site.meta_title_default')),
            'description' => (string) ($custom['meta_description'] ?? __('site.meta_description')),
            'url' => PortalHost::url('public', '/'),
            'image' => $image,
            'image_width' => $usingDefault ? self::DefaultShareImageWidth : null,
            'image_height' => $usingDefault ? self::DefaultShareImageHeight : null,
            'type' => 'website',
            'site_name' => (string) config('app.name', 'Madina Natural Health Care'),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{title:string,description:string,url:string,image:?string,type:string,site_name:string,partner:?array{name:string,phone:?string,photo:?string,code:string}}
     */
    public static function product(array $product, ?User $partner = null): array
    {
        $name = (string) ($product['name'] ?? 'Product');
        $description = trim(strip_tags((string) ($product['description'] ?? '')));
        if ($description === '') {
            $description = __('site.meta_description');
        }
        $url = self::preferAppScheme((string) ($product['url'] ?? PortalHost::url('public', '/products/'.($product['id'] ?? ''))));
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = PortalHost::url('public', str_starts_with($url, '/') ? $url : '/'.$url);
        }
        $rawImage = trim((string) ($product['image_url'] ?? $product['image_path'] ?? ''));
        $usingDefault = $rawImage === '';
        $image = self::absoluteUrl($usingDefault ? self::DefaultShareImage : $rawImage);
        $imageWidth = $usingDefault ? self::DefaultShareImageWidth : null;
        $imageHeight = $usingDefault ? self::DefaultShareImageHeight : null;
        $partnerPayload = null;

        if ($partner && in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            $code = (string) ($partner->refer_code ?: $partner->dealer_code);
            $url .= (str_contains($url, '?') ? '&' : '?').'ref='.rawurlencode($code);
            $partnerPayload = [
                'name' => (string) $partner->name,
                'phone' => $partner->phone ? (string) $partner->phone : null,
                'photo' => self::partnerPhoto($partner),
                'code' => $code,
            ];
            $contactLine = $partner->phone
                ? __('site.share_product_partner_contact', ['name' => $partner->name, 'phone' => $partner->phone])
                : __('site.share_product_partner_name', ['name' => $partner->name]);
            $description = $contactLine.' '.$description;
            if ($partnerPayload['photo']) {
                $image = $partnerPayload['photo'];
                $imageWidth = null;
                $imageHeight = null;
            }
        }

        return [
            'title' => $name.' · '.(string) config('app.name', 'Madina'),
            'description' => mb_substr($description, 0, 280),
            'url' => $url,
            'image' => $image,
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
            'type' => 'product',
            'site_name' => (string) config('app.name', 'Madina Natural Health Care'),
            'partner' => $partnerPayload,
        ];
    }

    /**
     * @return array{title:string,description:string,url:string,image:?string,type:string,site_name:string,partner:array{name:string,phone:?string,photo:?string,code:string}}
     */
    public static function referral(User $partner): array
    {
        $code = (string) ($partner->refer_code ?: $partner->dealer_code);
        $partnerPhoto = self::partnerPhoto($partner);
        $photo = $partnerPhoto ?: self::absoluteUrl(self::DefaultShareImage);
        $description = __('site.share_refer_description', [
            'name' => $partner->name,
            'code' => $code,
            'company' => (string) config('app.name', 'Madina Natural Health Care'),
        ]);

        return [
            'title' => __('site.share_refer_title', ['name' => $partner->name, 'company' => (string) config('app.name', 'Madina')]),
            'description' => mb_substr($description, 0, 280),
            'url' => PortalHost::url('public', '/join/'.$code),
            'image' => $photo,
            'image_width' => $partnerPhoto ? null : self::DefaultShareImageWidth,
            'image_height' => $partnerPhoto ? null : self::DefaultShareImageHeight,
            'type' => 'profile',
            'site_name' => (string) config('app.name', 'Madina Natural Health Care'),
            'partner' => [
                'name' => (string) $partner->name,
                'phone' => $partner->phone ? (string) $partner->phone : null,
                'photo' => $photo,
                'code' => $code,
            ],
        ];
    }

    public static function partnerPhoto(User $partner): ?string
    {
        $profile = is_array($partner->profile) ? $partner->profile : [];
        $url = $profile['photo_url'] ?? $profile['avatar_url'] ?? null;

        return is_string($url) && $url !== '' ? self::absoluteUrl($url) : null;
    }

    /**
     * @return array{title:string,description:string,url:string,image:?string,type:string,site_name:string}
     */
    public static function page(string $page, ?string $title = null, ?string $description = null): array
    {
        $home = self::homepage();
        $path = $page === 'home' || $page === '' ? '/' : '/'.ltrim($page, '/');
        $label = $title ?: (ucfirst(str_replace('-', ' ', $page)).' · '.$home['site_name']);

        return [
            ...$home,
            'title' => $label,
            'description' => $description ?: $home['description'],
            'url' => PortalHost::url('public', $path),
            'type' => 'website',
        ];
    }

    public static function absoluteUrl(string $pathOrUrl): string
    {
        if ($pathOrUrl === '') {
            return PortalHost::url('public', self::DefaultShareImage);
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return self::preferAppScheme($pathOrUrl);
        }

        return PortalHost::url('public', str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/'.$pathOrUrl);
    }

    /**
     * Prefer the APP_URL scheme so crawlers always see https share cards in production.
     */
    public static function preferAppScheme(string $url): string
    {
        $appScheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        if ($appScheme !== 'https' || ! str_starts_with($url, 'http://')) {
            return $url;
        }

        return 'https://'.substr($url, strlen('http://'));
    }
}

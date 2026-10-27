<?php

namespace App\Support;

use App\Models\User;

/**
 * Open Graph / Twitter Card payloads for public homepage, products, and referral landing pages.
 */
final class SocialShareMeta
{
    /**
     * @return array{title:string,description:string,url:string,image:?string,type:string,site_name:string}
     */
    public static function homepage(): array
    {
        $custom = SiteCustomization::all();
        $image = self::absoluteUrl((string) ($custom['og_image'] ?? $custom['logo_url'] ?? '/reference/favicon.png'));

        return [
            'title' => (string) ($custom['meta_title'] ?? __('site.meta_title_default')),
            'description' => (string) ($custom['meta_description'] ?? __('site.meta_description')),
            'url' => PortalHost::url('public', '/'),
            'image' => $image,
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
        $url = (string) ($product['url'] ?? PortalHost::url('public', '/products/'.($product['id'] ?? '')));
        $image = self::absoluteUrl((string) ($product['image_url'] ?? $product['image_path'] ?? '/reference/favicon.png'));
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
            }
        }

        return [
            'title' => $name.' · '.(string) config('app.name', 'Madina'),
            'description' => mb_substr($description, 0, 280),
            'url' => $url,
            'image' => $image,
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
        $photo = self::partnerPhoto($partner) ?: self::absoluteUrl('/reference/favicon.png');
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

    public static function absoluteUrl(string $pathOrUrl): string
    {
        if ($pathOrUrl === '') {
            return PortalHost::url('public', '/reference/favicon.png');
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return PortalHost::url('public', str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/'.$pathOrUrl);
    }
}

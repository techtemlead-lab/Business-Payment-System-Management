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

    /** Bump when replacing the default share image so Facebook/Messenger re-fetch the card. */
    public const DefaultShareImageVersion = 'og-20261027';

    public const DefaultShareImageWidth = 1376;

    public const DefaultShareImageHeight = 768;

    public const DefaultShareImageType = 'image/jpeg';

    /**
     * @return array{title:string,description:string,url:string,image:?string,image_width:?int,image_height:?int,image_type:?string,type:string,site_name:string}
     */
    public static function homepage(): array
    {
        $custom = SiteCustomization::all();
        $customImage = trim((string) ($custom['og_image'] ?? ''));
        $usingDefault = $customImage === '';
        $image = self::absoluteUrl($usingDefault ? self::DefaultShareImage : $customImage, $usingDefault);

        return [
            'title' => (string) ($custom['meta_title'] ?? __('site.meta_title_default')),
            'description' => (string) ($custom['meta_description'] ?? __('site.meta_description')),
            'url' => PortalHost::url('public', '/'),
            'image' => $image,
            'image_width' => $usingDefault ? self::DefaultShareImageWidth : null,
            'image_height' => $usingDefault ? self::DefaultShareImageHeight : null,
            'image_type' => $usingDefault ? self::DefaultShareImageType : null,
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
        $image = self::absoluteUrl($usingDefault ? self::DefaultShareImage : $rawImage, $usingDefault);
        $imageWidth = $usingDefault ? self::DefaultShareImageWidth : 800;
        $imageHeight = $usingDefault ? self::DefaultShareImageHeight : 800;
        $ext = strtolower(pathinfo(parse_url($image, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $imageType = $usingDefault
            ? self::DefaultShareImageType
            : match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/jpeg',
            };
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
                $imageWidth = 400;
                $imageHeight = 400;
                $imageType = 'image/jpeg';
            }
        }

        return [
            'title' => $name.' · '.(string) config('app.name', 'Madina Natural Health Care'),
            'description' => mb_substr($description, 0, 280),
            'url' => $url,
            'image' => $image,
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
            'image_type' => $imageType,
            'type' => 'product',
            'site_name' => (string) config('app.name', 'Madina Natural Health Care'),
            'price' => isset($product['price']) ? (string) $product['price'] : (isset($product['mrp']) ? (string) $product['mrp'] : null),
            'currency' => 'BDT',
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
        $usingDefault = $partnerPhoto === null;
        $photo = $partnerPhoto ?: self::absoluteUrl(self::DefaultShareImage, true);
        $description = __('site.share_refer_description', [
            'name' => $partner->name,
            'code' => $code,
            'company' => (string) config('app.name', 'Madina Natural Health Care'),
        ]);

        return [
            'title' => __('site.share_refer_title', ['name' => $partner->name, 'company' => (string) config('app.name', 'Madina Natural Health Care')]),
            'description' => mb_substr($description, 0, 280),
            'url' => PortalHost::url('public', '/join/'.$code),
            'image' => $photo,
            'image_width' => $usingDefault ? self::DefaultShareImageWidth : 400,
            'image_height' => $usingDefault ? self::DefaultShareImageHeight : 400,
            'image_type' => $usingDefault ? self::DefaultShareImageType : 'image/jpeg',
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
        $presets = self::pagePresets($page);
        $label = $title ?: ($presets['title'] ?? (ucfirst(str_replace('-', ' ', $page)).' · '.$home['site_name']));
        $desc = $description ?: ($presets['description'] ?? $home['description']);

        return [
            ...$home,
            'title' => $label,
            'description' => $desc,
            'url' => PortalHost::url('public', $path),
            'type' => 'website',
        ];
    }

    /**
     * Pre-configured metadata for key informational pages.
     *
     * @return array{title:string,description:string}
     */
    public static function pagePresets(string $page): array
    {
        $site = (string) config('app.name', 'Madina Natural Health Care');
        $presets = [
            'products' => [
                'title' => 'Products · '.$site,
                'description' => 'Explore authentic herbal, natural health, and wellness products from Madina Natural Health Care.',
            ],
            'dealers' => [
                'title' => 'Dealer Directory · '.$site,
                'description' => 'Find authorized Madina dealers, distribution points, and contact details across Bangladesh.',
            ],
            'about' => [
                'title' => 'About Us · '.$site,
                'description' => 'Learn about Madina Natural Health Care, our mission, natural wellness philosophy, and dedication to health.',
            ],
            'contact' => [
                'title' => 'Contact Us · '.$site,
                'description' => 'Get in touch with Madina Natural Health Care for corporate enquiries, product information, or dealer support.',
            ],
            'partnership' => [
                'title' => 'Partnership · '.$site,
                'description' => 'Become a business partner with Madina Natural Health Care as an authorized dealer or sub-dealer.',
            ],
            'services' => [
                'title' => 'Our Services · '.$site,
                'description' => 'Explore professional healthcare, consultation, and supply chain services by Madina Natural Health Care.',
            ],
            'quality' => [
                'title' => 'Quality Promise · '.$site,
                'description' => 'Our 100% natural, tested, and high-quality herbal product commitment.',
            ],
            'faq' => [
                'title' => 'FAQ · '.$site,
                'description' => 'Frequently asked questions regarding Madina products, orders, delivery, and dealer network.',
            ],
            'privacy' => [
                'title' => 'Privacy Policy · '.$site,
                'description' => 'Privacy Policy and data protection terms of Madina Natural Health Care.',
            ],
            'terms' => [
                'title' => 'Terms of Use · '.$site,
                'description' => 'Terms of service and usage conditions for Madina Natural Health Care.',
            ],
            'notice' => [
                'title' => 'Company Notices · '.$site,
                'description' => 'Official notices and announcements from Madina Natural Health Care management.',
            ],
        ];

        return $presets[$page] ?? [
            'title' => ucfirst(str_replace('-', ' ', $page)).' · '.$site,
            'description' => 'Madina Natural Health Care products, company information and dealer network.',
        ];
    }

    /**
     * Branded Open Graph metadata for portal sign-in and auth screens.
     *
     * @return array{title:string,description:string,url:string,image:string,image_width:int,image_height:int,image_type:string,type:string,site_name:string}
     */
    public static function portalAuth(string $portal, string $mode = 'login'): array
    {
        $site = (string) config('app.name', 'Madina Natural Health Care');
        $portalLabel = match ($portal) {
            'subdealer' => 'Sub-dealer Portal',
            'sadmin' => 'Owner Portal',
            'manage' => 'Staff Portal',
            default => 'Dealer Portal',
        };
        $modeTitle = match ($mode) {
            'otp' => 'OTP Verification',
            'forgot' => 'Forgot Password',
            'reset' => 'Reset Password',
            default => 'Account Sign In',
        };

        $title = $modeTitle.' · '.$portalLabel.' · '.$site;
        $description = 'Authorized secure login portal for '.$portalLabel.' partners and operators of '.$site.'.';

        return [
            'title' => $title,
            'description' => $description,
            'url' => url()->current(),
            'image' => self::absoluteUrl(self::DefaultShareImage, true),
            'image_width' => self::DefaultShareImageWidth,
            'image_height' => self::DefaultShareImageHeight,
            'image_type' => self::DefaultShareImageType,
            'type' => 'website',
            'site_name' => $site,
        ];
    }

    public static function absoluteUrl(string $pathOrUrl, bool $versionDefaultImage = false): string
    {
        if ($pathOrUrl === '') {
            return self::withShareImageVersion(PortalHost::url('public', self::DefaultShareImage));
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            $absolute = self::preferAppScheme($pathOrUrl);

            return $versionDefaultImage ? self::withShareImageVersion($absolute) : $absolute;
        }

        $absolute = PortalHost::url('public', str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/'.$pathOrUrl);

        return $versionDefaultImage ? self::withShareImageVersion($absolute) : $absolute;
    }

    /**
     * Cache-bust the default hero so Messenger/Facebook re-scrape after OG fixes.
     */
    public static function withShareImageVersion(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'v='.self::DefaultShareImageVersion;
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

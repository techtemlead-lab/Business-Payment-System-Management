<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class SiteCustomization
{
    private const SLUG = 'frontend_customization';

    public static function defaults(): array
    {
        return [
            // Top Bar & Header Branding
            'top_bar_text' => 'MADINA NATURAL HEALTH CARE',
            'search_placeholder' => 'Search products...',
            'header_offers_title' => 'Offers',
            'header_offers_subtitle' => 'Latest Offers',
            'header_projects_title' => 'Projects',
            'header_projects_subtitle' => 'Dealer Network',
            'header_account_title' => 'Account',
            'header_account_subtitle' => 'Partner Login',
            'header_shop_title' => 'Shop',
            'nav_categories' => "Medical Equipment\nLeather Items\nConsumer Items\nMedicine\nAgriculture Product\nCosmetics\nFurniture\nService",

            // Announcement & Ticker
            'announcement_text' => 'Natural food supplements, unani medicine, personal care, agriculture and wellness products are available now.',

            // Carousel Slides (1 to 5)
            'slides' => [
                ['image' => '/reference/slider-01.webp', 'link' => '/products', 'alt' => 'Promotion 1'],
                ['image' => '/reference/slider-02.webp', 'link' => '/products', 'alt' => 'Promotion 2'],
                ['image' => '/reference/slider-03.webp', 'link' => '/products', 'alt' => 'Promotion 3'],
                ['image' => '/reference/slider-04.webp', 'link' => '/products', 'alt' => 'Promotion 4'],
                ['image' => '/reference/slider-05.webp', 'link' => '/products', 'alt' => 'Promotion 5'],
            ],

            // Side Banners
            'side_banner_1_image' => '/reference/side-01.png',
            'side_banner_1_link' => '/products',
            'side_banner_1_alt' => 'Product promotion',
            'side_banner_2_image' => '/reference/side-02.webp',
            'side_banner_2_link' => '/contact',
            'side_banner_2_alt' => 'Dealer network promotion',

            // Quick Shortcuts (5 items)
            'shortcuts' => [
                ['title' => 'Our Products', 'subtitle' => 'browse wellness items', 'icon' => 'fa-seedling', 'link' => '/products'],
                ['title' => 'Raise a Complain', 'subtitle' => 'Share your experience', 'icon' => 'fa-comments', 'link' => '/contact'],
                ['title' => 'Our Services', 'subtitle' => 'Natural Health Care and Physiotherapy Center', 'icon' => 'fa-hand-holding-heart', 'link' => '/about'],
                ['title' => 'Contact Us', 'subtitle' => 'Support & Inquiries', 'icon' => 'fa-headset', 'link' => '/contact'],
                ['title' => 'Our Projects', 'subtitle' => 'Entrepreneur', 'icon' => 'fa-diagram-project', 'link' => '/dealers'],
            ],

            // Section Headings
            'featured_categories_title' => 'Featured Categories',
            'featured_categories_subtitle' => 'Get Your Desired Product from Featured Category!',
            'featured_products_title' => 'Featured Products',
            'featured_products_subtitle' => 'Check & Get Your Desired Product!',

            // Homepage About
            'about_headline' => 'Natural Food, Medicine & Wellness Product Shop in Bangladesh',
            'about_lead' => 'Madina Natural Health Care connects products, dealers and communities across Bangladesh. Explore the collection and contact the company for product information and partnership enquiries.',
            'about_summary_title' => 'Read More',
            'about_summary_body' => 'Our partner workspace brings product requests, deliveries, verified manual payments and company support together. Dealer accounts are created by authorized company staff.',

            // Footer & Support
            'footer_support_subtitle' => 'Company support',
            'footer_support_title' => 'Contact Us',
            'footer_support_link' => '/contact',
            'footer_directory_subtitle' => 'Find your local partner',
            'footer_directory_title' => 'Dealer Directory',
            'footer_directory_link' => '/dealers',
            'footer_about_title' => 'Madina Natural Health Care',
            'footer_about_desc' => 'Products, partners and communities across Bangladesh.',

            // Social Media & Chat
            'social_facebook' => '/contact',
            'social_youtube' => '/contact',
            'social_linkedin' => '/contact',
            'social_instagram' => '/contact',
            'social_whatsapp' => '/contact',
            'whatsapp_link' => '/contact',

            // Copyright
            'copyright_text' => '© ' . date('Y') . ' Madina Natural Health Care. All rights reserved.',
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();
        try {
            $record = DB::table('content_pages')->where('slug', self::SLUG)->first();
            if (!$record || empty($record->body)) {
                return $defaults;
            }
            $stored = json_decode($record->body, true);
            if (!is_array($stored)) {
                return $defaults;
            }

            $merged = $defaults;
            foreach ($defaults as $key => $defaultVal) {
                if (isset($stored[$key])) {
                    if (is_array($defaultVal)) {
                        // Array merging for slides and shortcuts
                        if (is_array($stored[$key])) {
                            $items = [];
                            foreach ($defaultVal as $idx => $subDefault) {
                                $subStored = $stored[$key][$idx] ?? [];
                                $item = $subDefault;
                                if (is_array($subStored)) {
                                    foreach ($subDefault as $k => $v) {
                                        if (isset($subStored[$k]) && trim((string)$subStored[$k]) !== '') {
                                            $item[$k] = (string)$subStored[$k];
                                        }
                                    }
                                }
                                $items[] = $item;
                            }
                            $merged[$key] = $items;
                        }
                    } else {
                        // String scalar value
                        $trimmed = trim((string)$stored[$key]);
                        if ($trimmed !== '') {
                            $merged[$key] = $trimmed;
                        }
                    }
                }
            }
            return $merged;
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    public static function save(array $payload): void
    {
        $defaults = self::defaults();
        $clean = [];

        foreach ($defaults as $key => $defaultVal) {
            if (is_array($defaultVal)) {
                $subItems = [];
                $inputArray = $payload[$key] ?? [];
                if (is_array($inputArray)) {
                    foreach ($defaultVal as $idx => $subDefault) {
                        $subInput = $inputArray[$idx] ?? [];
                        $cleanSub = [];
                        foreach ($subDefault as $k => $v) {
                            $val = isset($subInput[$k]) ? trim((string)$subInput[$k]) : '';
                            $cleanSub[$k] = $val;
                        }
                        $subItems[] = $cleanSub;
                    }
                } else {
                    $subItems = $defaultVal;
                }
                $clean[$key] = $subItems;
            } else {
                $val = isset($payload[$key]) ? trim((string)$payload[$key]) : '';
                $clean[$key] = $val;
            }
        }

        DB::table('content_pages')->updateOrInsert(
            ['slug' => self::SLUG],
            [
                'title' => 'Frontend Customization',
                'body' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published' => true,
                'updated_at' => now(),
            ]
        );
    }

    public static function reset(): void
    {
        DB::table('content_pages')->where('slug', self::SLUG)->delete();
    }
}

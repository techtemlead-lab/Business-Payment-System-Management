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
            'header_about_title' => 'About Us',
            'header_about_subtitle' => 'Our Story',
            'header_contact_title' => 'Contact',
            'header_contact_subtitle' => 'Get Support',
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

            // Campaign & Special Offers
            'campaign_badge' => 'SPECIAL CAMPAIGN & OFFERS',
            'campaign_title' => 'Special Discounts & Promotional Offers',
            'campaign_subtitle' => 'Explore limited-time wellness packages, bulk entrepreneur offers and seasonal healthcare discounts.',
            'campaign_card1_tag' => 'Featured Campaign',
            'campaign_card1_title' => 'Healthcare Entrepreneur & Partner Package',
            'campaign_card1_desc' => 'Comprehensive natural health package including diagnostic machine and physiotherapy equipment support for regional centers.',
            'campaign_card1_price' => '20,000 Tk',
            'campaign_card1_mrp' => '30,000 Tk',
            'campaign_card1_save' => 'Save 10,000 Tk',
            'campaign_card1_btn' => 'View Package',
            'campaign_card2_tag' => 'Seasonal Offer',
            'campaign_card2_title' => 'Family Wellness & Immunity Kit',
            'campaign_card2_desc' => 'Selected organic food supplements, unani tonics and herbal wellness essentials for full family vitality.',
            'campaign_card2_price' => '6,100 Tk',
            'campaign_card2_mrp' => '7,000 Tk',
            'campaign_card2_save' => 'Save 900 Tk',
            'campaign_card2_btn' => 'Explore Kit',

            // Section Headings & Callouts
            'featured_categories_title' => 'Featured Categories',
            'featured_categories_subtitle' => 'Get Your Desired Product from Featured Category!',
            'dealer_banner_title' => 'Authorized Dealer Network',
            'dealer_banner_subtitle' => 'Find your nearest authorized dealer or sub-dealer pharmacy with GPS distance calculation!',
            'dealer_banner_btn_text' => 'Find Nearest Dealer',
            'featured_products_title' => 'Featured Products',
            'featured_products_subtitle' => 'Check & Get Your Desired Product!',
            'featured_products_view_all' => 'View All Products',
            'products_empty_title' => 'Our collection is being prepared.',

            // Homepage About
            'about_headline' => 'Natural Food, Medicine & Wellness Product Shop in Bangladesh',
            'about_lead' => 'Madina Natural Health Care connects products, dealers and communities across Bangladesh. Explore the collection and contact the company for product information and partnership enquiries.',
            'about_summary_title' => 'Read More',
            'about_summary_body' => 'Our partner workspace brings product requests, deliveries, verified manual payments and company support together. Dealer accounts are created by authorized company staff.',

            // About Us Page (/about)
            'about_hero_title' => 'About Madina Natural Health Care',
            'about_hero_lead' => 'Madina Natural Health Care connects quality wellness products, dealers, healthcare practitioners and communities through an approved distribution network across Bangladesh.',
            'about_history_title' => 'Company History',
            'about_history' => 'Established with a commitment to pure wellness, Madina Natural Health Care began by addressing the growing need for authentic natural food supplements, unani medicines and accessible community healthcare. Over the years, our organization has expanded from local healthcare service points into a nationwide network connecting dedicated dealers, sub-dealers and health centers across Bangladesh.',
            'about_mission_title' => 'Our Mission',
            'about_mission' => 'To formulate, source and deliver reliable, high-standard natural health products while empowering local entrepreneurship through a transparent, staff-verified dealer and distribution ecosystem.',
            'about_vision_title' => 'Our Vision',
            'about_vision' => 'To become Bangladesh’s foremost trusted name in natural preventive healthcare, unani medicine and community wellness, fostering healthier families and resilient partner livelihoods in every district.',
            'about_objectives_title' => 'Core Objectives',
            'about_obj1_title' => 'Authentic Wellness',
            'about_obj1_desc' => 'Supply safe, verified unani and dietary supplements with clear quality standards.',
            'about_obj2_title' => 'Empowered Partners',
            'about_obj2_desc' => 'Support authorized dealers and sub-dealers with fair terms, structured training and dedicated portals.',
            'about_obj3_title' => 'Accessible Care',
            'about_obj3_desc' => 'Connect rural and urban communities with nearby partner outlets and physiotherapy assistance.',
            'about_obj4_title' => 'Transparent Operations',
            'about_obj4_desc' => 'Maintain staff-audited orders, manual payment verifications and clear distribution records.',
            'about_btn_products' => 'Explore Products',
            'about_btn_dealers' => 'Find a Dealer',
            'about_btn_contact' => 'Contact Head Office',

            // Contact Page (/contact)
            'contact_title' => 'Contact Us',
            'contact_company_title' => 'Madina Natural Health Care',
            'contact_company_desc' => 'For product information, dealer enquiries or support, send us a message or visit our head office.',
            'contact_topic1_title' => 'Dealer Enquiries',
            'contact_topic1_desc' => 'Dealer accounts are created by company staff after review.',
            'contact_topic2_title' => 'Products & Support',
            'contact_topic2_desc' => 'Ask about availability, orders and your local dealer.',
            'contact_topic3_title' => 'Head Office',
            'office_address' => 'Noyarhat, Ashulia, Savar, Dhaka, Bangladesh',
            'contact_form_title' => 'Send an Enquiry',
            'map_title' => 'Office & Healthcare Center Location',
            'map_subtitle' => 'Visit Madina Natural Health Care at our registered center or navigate with map directions.',
            'map_facility' => 'Madina Natural Health Care & Physiotherapy Center',
            'map_directions_text' => 'Open Map Directions',

            // Dealer Directory Page (/dealers)
            'directory_title' => 'Dealer Directory',
            'directory_subtitle' => 'Find a company-approved dealer point or search nearest dealers in your area.',
            'directory_gps_btn' => 'Use My Current Location (Nearest Dealers)',
            'directory_empty_title' => 'No published dealers in this area yet.',
            'directory_empty_desc' => 'Contact us for help finding a local dealer.',

            // Products Catalog Page (/products)
            'catalog_categories_title' => 'Categories',
            'catalog_all_products_label' => 'All Products',
            'catalog_price_title' => 'Price Range',
            'catalog_instock_label' => 'In-stock products only',
            'catalog_apply_filters_btn' => 'Apply Filters',
            'catalog_clear_filters_btn' => 'Clear Filters',
            'catalog_sort_by_label' => 'Sort By',
            'catalog_empty_title' => 'No products found',
            'catalog_empty_desc' => 'Try another search or clear your filters.',
            'catalog_empty_btn' => 'Browse All Products',

            // Product Cards & Product Details (/product/{slug})
            'product_in_stock_label' => 'In Stock',
            'product_out_of_stock_label' => 'Awaiting Stock',
            'product_add_to_cart_btn' => 'Add to Cart',
            'product_buy_now_btn' => 'Buy Now',
            'product_share_label' => 'Share:',
            'product_save_btn' => 'Save',
            'product_compare_btn' => 'Add to Compare',
            'product_payment_mode_label' => 'Cash / Manual Payment',
            'product_purchase_note' => 'Partner product requests and manual payments are reviewed by company staff.',
            'product_video_title' => 'Product Video',
            'product_tab_description' => 'Description',
            'product_tab_specification' => 'Specification',

            // Footer & Support
            'footer_support_heading' => 'SUPPORT',
            'footer_about_heading' => 'ABOUT US',
            'footer_stay_connected_heading' => 'STAY CONNECTED',
            'footer_support_subtitle' => 'Company support',
            'footer_support_title' => 'Contact Us',
            'footer_support_link' => '/contact',
            'footer_directory_subtitle' => 'Find your local partner',
            'footer_directory_title' => 'Dealer Directory',
            'footer_directory_link' => '/dealers',
            'footer_link_about' => 'About Us',
            'footer_link_vendor' => 'Vendor Program',
            'footer_link_orders' => 'Order Tracking',
            'footer_link_blog' => 'Blog & Tips',
            'footer_link_contact' => 'Contact Us',
            'footer_link_dealer_apply' => 'Apply for Dealership',
            'footer_about_title' => 'Madina Natural Health Care',
            'footer_about_desc' => 'Products, partners and communities across Bangladesh.',

            // Floating Tools & Dialogs
            'floating_compare_label' => 'COMPARE',
            'floating_cart_label' => 'CART',
            'floating_chat_label' => 'CHAT',
            'cart_dialog_title' => 'Your Cart',
            'cart_dialog_btn' => 'Sign in to request products',
            'cart_dialog_notice' => 'Product requests and payments are reviewed by company staff.',
            'compare_dialog_title' => 'Product Comparison',
            'compare_dialog_desc' => 'Select products to compare.',

            // Social Media & Chat
            'social_facebook' => '/contact',
            'social_youtube' => '/contact',
            'social_linkedin' => '/contact',
            'social_instagram' => '/contact',
            'social_whatsapp' => '/contact',
            'whatsapp_link' => '/contact',

            // Auth & Partner Portal Login (/login)
            'auth_login_title' => 'Account Login',
            'auth_login_subtitle' => 'Welcome back! Please enter your credentials.',
            'auth_dealer_help_text' => 'Need a dealer account?',
            'auth_dealer_contact_text' => 'Contact the company',

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

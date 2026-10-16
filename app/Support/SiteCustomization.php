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
            // Carousel Slides (3 Dedicated Hero Banners)
            'slides' => [
                ['image' => '/branding/madina-hero-banner-1.jpg', 'link' => '/products', 'alt' => 'Madina Natural Health Care - Authentic Herbal & Wellness Products'],
                ['image' => '/branding/madina-hero-banner-2.jpg', 'link' => '/products', 'alt' => 'Madina Authentic Unani & Herbal Solutions'],
                ['image' => '/branding/madina-hero-banner-3.jpg', 'link' => '/about', 'alt' => 'Madina Holistic Wellness & Physiotherapy Center'],
            ],

            // Side Banners (Dedicated Mini-Carousels - 100% Unique to Side Sections)
            'side_banner_1_slides' => [
                ['image' => '/branding/madina-side-banner-1.jpg', 'link' => '/products', 'alt' => 'Madina Premium Natural Care - 100% Pure Botanical Ingredients'],
                ['image' => '/branding/madina-top-skincare.jpg', 'link' => '/products', 'alt' => 'Madina Pure Herbal Skincare & Hair Vitality'],
                ['image' => '/branding/madina-top-immunity.jpg', 'link' => '/products', 'alt' => 'Madina Daily Immunity & Vitality Boosters'],
            ],
            'side_banner_2_slides' => [
                ['image' => '/branding/madina-side-banner-2.jpg', 'link' => '/dealers', 'alt' => 'Madina Authorized Dealer Network - Nationwide Distribution'],
                ['image' => '/branding/madina-promotion-popup.jpg', 'link' => '/about', 'alt' => 'Madina Natural Health Care & Physiotherapy Center'],
                ['image' => '/branding/madina-bottom-pharmacy.jpg', 'link' => '/dealers', 'alt' => 'Madina Authorized Partner Pharmacy Outlets Across All 64 Districts'],
            ],
            'side_banner_1_image' => '/branding/madina-side-banner-1.jpg',
            'side_banner_1_link' => '/products',
            'side_banner_1_alt' => 'Madina Premium Natural Care - 100% Pure Botanical Ingredients',
            'side_banner_2_image' => '/branding/madina-side-banner-2.jpg',
            'side_banner_2_link' => '/dealers',
            'side_banner_2_alt' => 'Madina Authorized Dealer Network - Nationwide Distribution',

            // Quick Shortcuts
            'shortcuts_section_enabled' => '1',
            'shortcuts' => [
                ['title' => 'Our Products', 'subtitle' => 'browse wellness items', 'icon' => 'fa-seedling', 'link' => '/products'],
                ['title' => 'Raise a Complain', 'subtitle' => 'Share your experience', 'icon' => 'fa-comments', 'link' => '/contact?topic=Complaint'],
                ['title' => 'Our Services', 'subtitle' => 'Natural Health Care and Physiotherapy Center', 'icon' => 'fa-hand-holding-heart', 'link' => '/about'],
                ['title' => 'Contact Us', 'subtitle' => 'Support & Inquiries', 'icon' => 'fa-headset', 'link' => '/contact'],
                ['title' => 'Our Projects', 'subtitle' => 'Entrepreneur', 'icon' => 'fa-diagram-project', 'link' => '/dealers'],
            ],

            // Campaign & Special Offers
            'campaign_section_enabled' => '1',
            'campaign_badge' => 'SPECIAL CAMPAIGN & OFFERS',
            'campaign_title' => 'Special Discounts & Promotional Offers',
            'campaign_subtitle' => 'Explore limited-time wellness packages, bulk entrepreneur offers and seasonal healthcare discounts.',
            'campaign_cards' => [
                [
                    'id' => 'card-1',
                    'type' => 'bundle',
                    'product_id' => '',
                    'tag' => 'Featured Campaign',
                    'title' => 'Healthcare Entrepreneur & Partner Package',
                    'desc' => 'Comprehensive natural health package including diagnostic machine and physiotherapy equipment support for regional centers.',
                    'feat1' => 'Complete Health & Equipment Support',
                    'feat2' => 'Authorized Distribution Rights',
                    'price' => '20,000 Tk',
                    'mrp' => '30,000 Tk',
                    'save' => 'Save 10,000 Tk',
                    'btn' => 'View Package',
                    'link' => '/products',
                    'image' => '',
                    'highlight' => '1',
                    'bundle_discount_mode' => 'total',
                    'bundle_discount_pct' => 33,
                    'bundle_items' => [],
                ],
                [
                    'id' => 'card-2',
                    'type' => 'bundle',
                    'product_id' => '',
                    'tag' => 'Seasonal Offer',
                    'title' => 'Family Wellness & Immunity Kit',
                    'desc' => 'Selected organic food supplements, unani tonics and herbal wellness essentials for full family vitality.',
                    'feat1' => '100% Pure Botanical Extracts & Honey',
                    'feat2' => 'Everyday Family Vitality & Immunity',
                    'price' => '6,100 Tk',
                    'mrp' => '7,000 Tk',
                    'save' => 'Save 900 Tk',
                    'btn' => 'Explore Kit',
                    'link' => '/products',
                    'image' => '',
                    'highlight' => '0',
                    'bundle_discount_mode' => 'total',
                    'bundle_discount_pct' => 13,
                    'bundle_items' => [],
                ],
            ],
            // Retain legacy flat keys for backward compatibility
            'campaign_card1_product_id' => '',
            'campaign_card1_tag' => 'Featured Campaign',
            'campaign_card1_title' => 'Healthcare Entrepreneur & Partner Package',
            'campaign_card1_desc' => 'Comprehensive natural health package including diagnostic machine and physiotherapy equipment support for regional centers.',
            'campaign_card1_feat1' => 'Complete Health & Equipment Support',
            'campaign_card1_feat2' => 'Authorized Distribution Rights',
            'campaign_card1_price' => '20,000 Tk',
            'campaign_card1_mrp' => '30,000 Tk',
            'campaign_card1_save' => 'Save 10,000 Tk',
            'campaign_card1_btn' => 'View Package',
            'campaign_card1_link' => '/products',
            'campaign_card1_image' => '',
            'campaign_card2_product_id' => '',
            'campaign_card2_tag' => 'Seasonal Offer',
            'campaign_card2_title' => 'Family Wellness & Immunity Kit',
            'campaign_card2_desc' => 'Selected organic food supplements, unani tonics and herbal wellness essentials for full family vitality.',
            'campaign_card2_feat1' => '100% Pure Botanical Extracts & Honey',
            'campaign_card2_feat2' => 'Everyday Family Vitality & Immunity',
            'campaign_card2_price' => '6,100 Tk',
            'campaign_card2_mrp' => '7,000 Tk',
            'campaign_card2_save' => 'Save 900 Tk',
            'campaign_card2_btn' => 'Explore Kit',
            'campaign_card2_link' => '/products',
            'campaign_card2_image' => '',

            // Featured Categories Section
            'featured_categories_enabled' => '1',
            'featured_categories_title' => 'Featured Categories',
            'featured_categories_subtitle' => 'Get Your Desired Product from Featured Category!',
            'featured_categories_items' => "Medical Equipment | fa-briefcase-medical\nMeat & Fish | fa-drumstick-bite\nFood Supplement | fa-seedling\nPersonal Care | fa-spa\nWater Purifier | fa-droplet\nLeather Items | fa-briefcase\nUnani Medicine | fa-mortar-pestle\nConsumer Items | fa-basket-shopping\nMedicine | fa-capsules\nElectric Kettle | fa-mug-hot\nAgriculture Product | fa-leaf\nCookeries Items | fa-kitchen-set\nCosmetics | fa-wand-magic-sparkles\nRoom Heater | fa-temperature-high\nFurniture | fa-couch\nService | fa-hand-holding-heart\nElectronic Devices | fa-plug\nFashion & Lifestyle | fa-vest",
            'dealer_banner_title' => 'Authorized Dealer Network',
            'dealer_banner_subtitle' => 'Find your nearest authorized dealer or sub-dealer pharmacy with GPS distance calculation!',
            'dealer_banner_btn_text' => 'Find Nearest Dealer',

            // Featured Products Section
            'featured_products_enabled' => '1',
            'featured_products_badge' => 'POPULAR SELECTION',
            'featured_products_title' => 'Featured Products',
            'featured_products_subtitle' => 'Check & Get Your Desired Product!',
            'featured_products_view_all' => 'View All Products',
            'featured_products_view_all_link' => '/products',
            'featured_products_tabs_enabled' => '1',
            'featured_products_mode' => 'auto',
            'featured_products_items' => [],
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
            'contact_phone_title' => 'Customer Care & Hotline',
            'contact_phone' => '+8801730532966',
            'contact_phone_hours' => 'Sat - Thu: 9:00 AM - 8:00 PM',
            'contact_email_title' => 'Official Email',
            'contact_email' => 'contact@madinanatural.com',
            'contact_topic1_title' => 'Dealer Enquiries',
            'contact_topic1_desc' => 'Dealer accounts are created by company staff after review.',
            'contact_topic2_title' => 'Products & Support',
            'contact_topic2_desc' => 'Ask about availability, orders and your local dealer.',
            'contact_topic3_title' => 'Head Office',
            'contact_social_title' => 'Connect With Us Online',
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
            'footer_support_title' => '+8801730532966',
            'footer_support_link' => 'tel:+8801730532966',
            'footer_support_icon' => 'fa-solid fa-phone',
            'footer_directory_subtitle' => 'Find your local partner',
            'footer_directory_title' => 'Dealer Directory',
            'footer_directory_link' => '/dealers',
            'footer_directory_icon' => 'fa-solid fa-location-dot',
            'footer_phone' => '+8801730532966',
            'footer_email' => 'contact@madinanatural.com',
            'footer_address' => 'Noyarhat, Ashulia, Savar, Dhaka',
            'footer_link_about' => 'About Us',
            'footer_link_about_url' => '/about',
            'footer_link_vendor' => 'Vendor Program',
            'footer_link_vendor_url' => '/contact',
            'footer_link_orders' => 'Order Tracking',
            'footer_link_orders_url' => '/workspace/orders',
            'footer_link_blog' => 'Blog & Tips',
            'footer_link_blog_url' => '/about',
            'footer_link_contact' => 'Contact Us',
            'footer_link_contact_url' => '/contact',
            'footer_link_dealer_apply' => 'Apply for Dealership',
            'footer_link_dealer_apply_url' => '/contact',
            'footer_about_title' => 'Madina Natural Health Care',
            'footer_about_desc' => 'Products, partners and communities across Bangladesh.',
            'footer_logo_url' => '/madina-header-logo-white.png?v=6',

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
            'social_facebook' => '#',
            'social_youtube' => '#',
            'social_linkedin' => '#',
            'social_instagram' => '#',
            'social_whatsapp' => 'https://wa.me/8801730532966',
            'social_twitter' => '',
            'social_tiktok' => '',
            'social_telegram' => '',
            'whatsapp_link' => 'https://wa.me/8801730532966',

            // Mobile App Store Badges (default to #)
            'app_google_play_link' => '#',
            'app_apple_store_link' => '#',
            'app_badges_enabled' => '1',

            // Payment Methods Logo & Visibility
            'payment_methods_enabled' => '1',
            'payment_methods_image' => '/reference/icons/payment_logo.png',
            'payment_methods_alt' => 'Payment methods',

            // Auth & Partner Portal Login (/login)
            'auth_login_title' => 'Account Login',
            'auth_login_subtitle' => 'Welcome back! Please enter your credentials.',
            'auth_dealer_help_text' => 'Need a dealer account?',
            'auth_dealer_contact_text' => 'Contact the company',

            // Copyright
            'copyright_text' => '© ' . date('Y') . ' Madina Natural Health Care. All rights reserved.',
        ];
    }

    public static function raw(): array
    {
        try {
            $record = DB::table('content_pages')->where('slug', self::SLUG)->first();
            if (!$record || empty($record->body)) {
                return [];
            }
            $stored = json_decode($record->body, true);
            return is_array($stored) ? $stored : [];
        } catch (\Throwable $e) {
            return [];
        }
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
                        if (is_array($stored[$key])) {
                            // Dynamic arrays: shortcuts, campaign_cards & featured_products_items support variable length!
                            if ($key === 'shortcuts' || $key === 'campaign_cards' || $key === 'featured_products_items') {
                                $items = [];
                                foreach ($stored[$key] as $subIdx => $subStored) {
                                    if (!is_array($subStored)) continue;
                                    $subDefault = $defaultVal[$subIdx] ?? ($defaultVal[0] ?? []);
                                    $item = [];
                                    foreach ($subDefault as $k => $v) {
                                        if (isset($subStored[$k])) {
                                            $item[$k] = is_array($subStored[$k]) ? $subStored[$k] : trim((string)$subStored[$k]);
                                        } else {
                                            $item[$k] = $v;
                                        }
                                    }
                                    foreach ($subStored as $k => $v) {
                                        if (!array_key_exists($k, $item)) {
                                            $item[$k] = is_array($v) ? $v : (is_scalar($v) ? (string)$v : $v);
                                        }
                                    }
                                    $items[] = $item;
                                }
                                $merged[$key] = $items;
                            } else {
                                // Fixed-size arrays (like carousel slides)
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

            // Fallback for campaign_cards if missing from stored but stored has legacy flat keys
            if (empty($stored['campaign_cards'])) {
                $hasLegacy = isset($stored['campaign_card1_title']) || isset($stored['campaign_card2_title']) || isset($stored['campaign_card1_price']);
                if ($hasLegacy) {
                    $c1 = [
                        'id' => 'card-1',
                        'product_id' => (string)($stored['campaign_card1_product_id'] ?? $defaults['campaign_card1_product_id']),
                        'tag' => (string)($stored['campaign_card1_tag'] ?? $defaults['campaign_card1_tag']),
                        'title' => (string)($stored['campaign_card1_title'] ?? $defaults['campaign_card1_title']),
                        'desc' => (string)($stored['campaign_card1_desc'] ?? $defaults['campaign_card1_desc']),
                        'feat1' => (string)($stored['campaign_card1_feat1'] ?? $defaults['campaign_card1_feat1']),
                        'feat2' => (string)($stored['campaign_card1_feat2'] ?? $defaults['campaign_card1_feat2']),
                        'price' => (string)($stored['campaign_card1_price'] ?? $defaults['campaign_card1_price']),
                        'mrp' => (string)($stored['campaign_card1_mrp'] ?? $defaults['campaign_card1_mrp']),
                        'save' => (string)($stored['campaign_card1_save'] ?? $defaults['campaign_card1_save']),
                        'btn' => (string)($stored['campaign_card1_btn'] ?? $defaults['campaign_card1_btn']),
                        'link' => (string)($stored['campaign_card1_link'] ?? $defaults['campaign_card1_link']),
                        'image' => (string)($stored['campaign_card1_image'] ?? $defaults['campaign_card1_image']),
                        'highlight' => '1',
                    ];
                    $c2 = [
                        'id' => 'card-2',
                        'product_id' => (string)($stored['campaign_card2_product_id'] ?? $defaults['campaign_card2_product_id']),
                        'tag' => (string)($stored['campaign_card2_tag'] ?? $defaults['campaign_card2_tag']),
                        'title' => (string)($stored['campaign_card2_title'] ?? $defaults['campaign_card2_title']),
                        'desc' => (string)($stored['campaign_card2_desc'] ?? $defaults['campaign_card2_desc']),
                        'feat1' => (string)($stored['campaign_card2_feat1'] ?? $defaults['campaign_card2_feat1']),
                        'feat2' => (string)($stored['campaign_card2_feat2'] ?? $defaults['campaign_card2_feat2']),
                        'price' => (string)($stored['campaign_card2_price'] ?? $defaults['campaign_card2_price']),
                        'mrp' => (string)($stored['campaign_card2_mrp'] ?? $defaults['campaign_card2_mrp']),
                        'save' => (string)($stored['campaign_card2_save'] ?? $defaults['campaign_card2_save']),
                        'btn' => (string)($stored['campaign_card2_btn'] ?? $defaults['campaign_card2_btn']),
                        'link' => (string)($stored['campaign_card2_link'] ?? $defaults['campaign_card2_link']),
                        'image' => (string)($stored['campaign_card2_image'] ?? $defaults['campaign_card2_image']),
                        'highlight' => '0',
                    ];
                    $merged['campaign_cards'] = [$c1, $c2];
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
                $inputArray = $payload[$key] ?? null;
                if (is_array($inputArray)) {
                    if ($key === 'shortcuts' || $key === 'campaign_cards' || $key === 'featured_products_items') {
                        // Dynamic list: preserve all items sent by client
                        $template = $defaultVal[0] ?? [];
                        foreach ($inputArray as $subInput) {
                            if (!is_array($subInput)) continue;
                            $cleanSub = [];
                            foreach ($template as $k => $v) {
                                if (isset($subInput[$k])) {
                                    $cleanSub[$k] = is_array($subInput[$k]) ? $subInput[$k] : trim((string)$subInput[$k]);
                                } else {
                                    $cleanSub[$k] = is_array($v) ? [] : '';
                                }
                            }
                            foreach ($subInput as $k => $v) {
                                if (!array_key_exists($k, $cleanSub)) {
                                    $cleanSub[$k] = is_array($v) ? $v : (is_scalar($v) ? trim((string)$v) : $v);
                                }
                            }
                            $subItems[] = $cleanSub;
                        }
                    } else {
                        // Fixed lists like carousel slides
                        foreach ($defaultVal as $idx => $subDefault) {
                            $subInput = $inputArray[$idx] ?? [];
                            $cleanSub = [];
                            foreach ($subDefault as $k => $v) {
                                $val = isset($subInput[$k]) ? trim((string)$subInput[$k]) : '';
                                $cleanSub[$k] = $val;
                            }
                            $subItems[] = $cleanSub;
                        }
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

        // Backward compatibility sync for legacy flat keys if campaign_cards are saved
        if (!empty($clean['campaign_cards']) && is_array($clean['campaign_cards'])) {
            $c1 = $clean['campaign_cards'][0] ?? null;
            if ($c1) {
                $clean['campaign_card1_product_id'] = $c1['product_id'] ?? '';
                $clean['campaign_card1_tag'] = $c1['tag'] ?? '';
                $clean['campaign_card1_title'] = $c1['title'] ?? '';
                $clean['campaign_card1_desc'] = $c1['desc'] ?? '';
                $clean['campaign_card1_feat1'] = $c1['feat1'] ?? '';
                $clean['campaign_card1_feat2'] = $c1['feat2'] ?? '';
                $clean['campaign_card1_price'] = $c1['price'] ?? '';
                $clean['campaign_card1_mrp'] = $c1['mrp'] ?? '';
                $clean['campaign_card1_save'] = $c1['save'] ?? '';
                $clean['campaign_card1_btn'] = $c1['btn'] ?? '';
                $clean['campaign_card1_link'] = $c1['link'] ?? '';
                $clean['campaign_card1_image'] = $c1['image'] ?? '';
            }
            $c2 = $clean['campaign_cards'][1] ?? null;
            if ($c2) {
                $clean['campaign_card2_product_id'] = $c2['product_id'] ?? '';
                $clean['campaign_card2_tag'] = $c2['tag'] ?? '';
                $clean['campaign_card2_title'] = $c2['title'] ?? '';
                $clean['campaign_card2_desc'] = $c2['desc'] ?? '';
                $clean['campaign_card2_feat1'] = $c2['feat1'] ?? '';
                $clean['campaign_card2_feat2'] = $c2['feat2'] ?? '';
                $clean['campaign_card2_price'] = $c2['price'] ?? '';
                $clean['campaign_card2_mrp'] = $c2['mrp'] ?? '';
                $clean['campaign_card2_save'] = $c2['save'] ?? '';
                $clean['campaign_card2_btn'] = $c2['btn'] ?? '';
                $clean['campaign_card2_link'] = $c2['link'] ?? '';
                $clean['campaign_card2_image'] = $c2['image'] ?? '';
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

    public static function getShortcuts(): array
    {
        $all = self::all();
        $enabled = filter_var($all['shortcuts_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return [];
        }
        $raw = $all['shortcuts'] ?? self::defaults()['shortcuts'];
        if (!is_array($raw) || empty($raw)) {
            $raw = self::defaults()['shortcuts'];
        }
        $defaultsList = self::defaults()['shortcuts'];
        $items = [];
        foreach ($raw as $idx => $s) {
            if (!is_array($s)) continue;
            $defS = $defaultsList[$idx] ?? [
                'title' => 'Quick Shortcut #' . ($idx + 1),
                'subtitle' => 'Explore section',
                'icon' => 'fa-circle-chevron-right',
                'link' => '/products',
            ];
            $title = trim((string)($s['title'] ?? ''));
            $subtitle = trim((string)($s['subtitle'] ?? ''));
            $icon = trim((string)($s['icon'] ?? ''));
            $link = trim((string)($s['link'] ?? ''));

            $items[] = [
                'title' => $title !== '' ? $title : ($defS['title'] ?? 'Quick Link'),
                'subtitle' => $subtitle !== '' ? $subtitle : ($defS['subtitle'] ?? ''),
                'icon' => $icon !== '' ? $icon : ($defS['icon'] ?? 'fa-circle-chevron-right'),
                'link' => $link !== '' ? $link : ($defS['link'] ?? '/products'),
            ];
        }
        return $items;
    }

    public static function getCampaignCards(): array
    {
        $customization = self::all();
        $cards = $customization['campaign_cards'] ?? [];
        if (!is_array($cards) || empty($cards)) {
            $cards = self::defaults()['campaign_cards'];
        }

        $defaultCards = self::defaults()['campaign_cards'];
        $items = [];
        foreach ($cards as $idx => $card) {
            if (!is_array($card)) continue;

            $defCard = $defaultCards[$idx] ?? [
                'type' => 'bundle',
                'tag' => 'Special Offer',
                'title' => 'Promotional Package #' . ($idx + 1),
                'desc' => 'Explore this limited-time wellness package and seasonal healthcare offer.',
                'feat1' => '100% Pure Botanical Ingredients',
                'feat2' => 'Fast Nationwide Delivery & Support',
                'price' => '2,500 Tk',
                'mrp' => '3,000 Tk',
                'save' => 'Save 500 Tk',
                'btn' => 'View Package',
                'link' => '/products',
                'image' => '',
                'highlight' => ($idx === 0 ? '1' : '0'),
                'bundle_items' => [],
            ];

            $type = trim((string)($card['type'] ?? ($defCard['type'] ?? 'bundle')));
            if ($type !== 'single' && $type !== 'bundle') {
                $type = !empty($card['product_id']) ? 'single' : 'bundle';
            }

            $title = trim((string)($card['title'] ?? ''));
            $price = trim((string)($card['price'] ?? ''));
            $mrp = trim((string)($card['mrp'] ?? ''));
            $save = trim((string)($card['save'] ?? ''));
            $tag = trim((string)($card['tag'] ?? ''));
            $desc = trim((string)($card['desc'] ?? ''));
            $feat1 = trim((string)($card['feat1'] ?? ''));
            $feat2 = trim((string)($card['feat2'] ?? ''));
            $btn = trim((string)($card['btn'] ?? ''));
            $link = trim((string)($card['link'] ?? ''));
            $image = trim((string)($card['image'] ?? ''));
            $productId = trim((string)($card['product_id'] ?? ''));
            $highlight = filter_var($card['highlight'] ?? ($idx === 0), FILTER_VALIDATE_BOOLEAN);

            $promoDiscountPct = isset($card['promo_discount_pct']) && $card['promo_discount_pct'] !== ''
                ? (float)$card['promo_discount_pct']
                : null;
            $bundleItems = is_array($card['bundle_items'] ?? null) ? $card['bundle_items'] : [];
            $bundleDiscountMode = trim((string)($card['bundle_discount_mode'] ?? 'total'));
            $bundleDiscountPct = isset($card['bundle_discount_pct']) && $card['bundle_discount_pct'] !== ''
                ? (float)$card['bundle_discount_pct']
                : null;

            // Mode A: Single Product Logic
            if ($type === 'single' && $productId !== '') {
                $product = \App\Models\Product::find($productId);
                if ($product) {
                    $numMrp = (float)$product->mrp;
                    if ($title === '') $title = $product->name;
                    if ($desc === '') $desc = $product->description ?: '';
                    if ($image === '') $image = $product->media()['image_url'] ?? '';
                    if ($link === '') $link = route('products.show', $product->id);
                    if ($btn === '') $btn = 'Order Special Offer';

                    if ($promoDiscountPct !== null && $promoDiscountPct > 0) {
                        $calcPrice = round($numMrp * (1 - ($promoDiscountPct / 100)), 2);
                        $calcSave = round($numMrp - $calcPrice, 2);
                        if ($price === '') $price = number_format($calcPrice) . ' Tk';
                        if ($mrp === '') $mrp = number_format($numMrp) . ' Tk';
                        if ($save === '') $save = 'Save ' . number_format($calcSave) . " Tk ({$promoDiscountPct}% OFF)";
                        if ($tag === '') $tag = "⚡ {$promoDiscountPct}% Special Offer";
                    } else {
                        if ($mrp === '') $mrp = number_format($numMrp) . ' Tk';
                    }
                }
            }

            // Mode B: Group Package Bundle Logic
            if ($type === 'bundle' && !empty($bundleItems)) {
                $totalBundleMrp = 0;
                $resolvedItems = [];
                foreach ($bundleItems as $bItem) {
                    $bProdId = $bItem['product_id'] ?? null;
                    $bProd = $bProdId ? \App\Models\Product::find($bProdId) : null;
                    $itemMrp = $bProd ? (float)$bProd->mrp : (float)($bItem['mrp'] ?? 0);
                    $itemName = $bProd ? $bProd->name : ($bItem['name'] ?? 'Bundle Item');
                    $itemSku = $bProd ? $bProd->sku : ($bItem['sku'] ?? '');
                    $itemCustomPct = (float)($bItem['custom_discount_pct'] ?? 0);
                    $itemPrice = $itemCustomPct > 0
                        ? round($itemMrp * (1 - ($itemCustomPct / 100)), 2)
                        : (float)($bItem['item_price'] ?? $itemMrp);

                    $totalBundleMrp += $itemMrp;
                    $resolvedItems[] = [
                        'product_id' => $bProdId,
                        'name' => $itemName,
                        'sku' => $itemSku,
                        'mrp' => $itemMrp,
                        'custom_discount_pct' => $itemCustomPct,
                        'item_price' => $itemPrice,
                    ];
                }

                if ($bundleDiscountMode === 'itemized') {
                    $totalBundlePrice = array_sum(array_column($resolvedItems, 'item_price'));
                    $totalBundleSavings = max(0, $totalBundleMrp - $totalBundlePrice);
                    $effPct = $totalBundleMrp > 0 ? round(($totalBundleSavings / $totalBundleMrp) * 100) : 0;
                } else {
                    $effPct = $bundleDiscountPct !== null ? $bundleDiscountPct : 0;
                    $totalBundlePrice = $effPct > 0
                        ? round($totalBundleMrp * (1 - ($effPct / 100)), 2)
                        : $totalBundleMrp;
                    $totalBundleSavings = max(0, $totalBundleMrp - $totalBundlePrice);
                }

                if ($totalBundleMrp > 0) {
                    if ($mrp === '') $mrp = number_format($totalBundleMrp) . ' Tk';
                    if ($price === '') $price = number_format($totalBundlePrice) . ' Tk';
                    if ($save === '') {
                        $save = $totalBundleSavings > 0
                            ? ('Save ' . number_format($totalBundleSavings) . ' Tk' . ($effPct > 0 ? " ({$effPct}% OFF)" : ''))
                            : '';
                    }
                }
                if ($btn === '') $btn = 'View Package';
                if ($tag === '') $tag = 'Featured Package';
                $bundleItems = $resolvedItems;
            }

            // Defaults fallback
            $title = $title !== '' ? $title : ($defCard['title'] ?? 'Campaign Offer');
            $tag = $tag !== '' ? $tag : ($defCard['tag'] ?? 'Special Offer');
            $desc = $desc !== '' ? $desc : ($defCard['desc'] ?? '');
            $feat1 = $feat1 !== '' ? $feat1 : ($defCard['feat1'] ?? '');
            $feat2 = $feat2 !== '' ? $feat2 : ($defCard['feat2'] ?? '');
            $price = $price !== '' ? $price : ($defCard['price'] ?? '');
            $mrp = $mrp !== '' ? $mrp : ($defCard['mrp'] ?? '');
            $btn = $btn !== '' ? $btn : ($defCard['btn'] ?? 'View Package');
            $link = $link !== '' ? $link : ($defCard['link'] ?? '/products');
            $image = $image !== '' ? $image : ($defCard['image'] ?? '');

            // Auto-compute save badge if MRP and Price are numeric
            $cleanPrice = trim(preg_replace('/[^\d,.]/', '', $price));
            $cleanMrp = trim(preg_replace('/[^\d,.]/', '', $mrp));
            if ($save === '' && $cleanMrp !== '' && $cleanPrice !== '') {
                $numMrp = (float)str_replace(',', '', $cleanMrp);
                $numPrice = (float)str_replace(',', '', $cleanPrice);
                if ($numMrp > $numPrice) {
                    $save = 'Save ৳ ' . number_format($numMrp - $numPrice);
                }
            }
            if ($save === '') {
                $save = $defCard['save'] ?? '';
            }

            $items[] = [
                'id' => $card['id'] ?? ('card-' . ($idx + 1)),
                'type' => $type,
                'product_id' => $productId,
                'promo_discount_pct' => $promoDiscountPct,
                'bundle_discount_mode' => $bundleDiscountMode,
                'bundle_discount_pct' => $bundleDiscountPct,
                'bundle_items' => $bundleItems,
                'tag' => $tag,
                'title' => $title,
                'desc' => $desc,
                'feat1' => $feat1,
                'feat2' => $feat2,
                'price' => $price,
                'mrp' => $mrp,
                'save' => $save,
                'btn' => $btn,
                'link' => $link,
                'image' => $image,
                'highlight' => $highlight,
            ];
        }

        return $items;
    }

    public static function getCampaignForProduct(int|string $productId, string $productName = ''): ?array
    {
        $customization = self::all();
        $enabled = filter_var($customization['campaign_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return null;
        }

        $cards = self::getCampaignCards();

        foreach ($cards as $idx => $card) {
            $configuredId = trim((string)($card['product_id'] ?? ''));
            $link = trim((string)($card['link'] ?? ''));
            $title = trim((string)($card['title'] ?? ''));

            $isMatch = false;

            // 1. Exact product ID match
            if ($configuredId !== '' && $configuredId === (string)$productId) {
                $isMatch = true;
            }
            // 2. URL route match (e.g. /products/9 or /product/9)
            elseif ($link !== '' && preg_match('#/products?/(' . preg_quote((string)$productId, '#') . ')(?:/|\?|\#|$)#i', $link)) {
                $isMatch = true;
            }
            // 3. Exact Title match if product name is provided
            elseif ($productName !== '' && $title !== '' && strcasecmp(trim($productName), $title) === 0) {
                $isMatch = true;
            }

            if ($isMatch) {
                $priceRaw = trim((string)($card['price'] ?? ''));
                $mrpRaw = trim((string)($card['mrp'] ?? ''));
                $saveRaw = trim((string)($card['save'] ?? ''));

                $cleanPrice = trim(preg_replace('/[^\d,.]/', '', $priceRaw));
                $cleanMrp = trim(preg_replace('/[^\d,.]/', '', $mrpRaw));

                $computedSave = $saveRaw;
                if ($computedSave === '' && $cleanMrp !== '' && $cleanPrice !== '') {
                    $numMrp = (float)str_replace(',', '', $cleanMrp);
                    $numPrice = (float)str_replace(',', '', $cleanPrice);
                    if ($numMrp > $numPrice) {
                        $diff = $numMrp - $numPrice;
                        $computedSave = 'Save ৳ ' . number_format($diff);
                    }
                }

                return [
                    'card_index' => $idx + 1,
                    'type' => $card['type'] ?? 'single',
                    'promo_discount_pct' => $card['promo_discount_pct'] ?? null,
                    'badge' => $customization['campaign_badge'] ?? 'SPECIAL CAMPAIGN & OFFERS',
                    'tag' => $card['tag'] ?: 'Special Offer',
                    'title' => $title,
                    'description' => $card['desc'] ?? '',
                    'price' => $cleanPrice !== '' ? $cleanPrice : $priceRaw,
                    'price_raw' => $priceRaw,
                    'mrp' => $cleanMrp !== '' ? $cleanMrp : $mrpRaw,
                    'mrp_raw' => $mrpRaw,
                    'save' => $computedSave ?: 'Special Discount',
                    'image' => $card['image'] ?? '',
                    'btn' => $card['btn'] ?? 'View Package',
                    'link' => $link ?: '/products/' . $productId,
                    'highlight' => filter_var($card['highlight'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
        }

        return null;
    }

    public static function getFeaturedCategories(): array
    {
        $customization = self::all();
        $raw = $customization['featured_categories_items'] ?? self::defaults()['featured_categories_items'];
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            $name = $parts[0] ?? '';
            $icon = $parts[1] ?? 'fa-tag';
            $link = $parts[2] ?? ('/products?category=' . urlencode($name));
            $badge = $parts[3] ?? '';
            if ($name !== '') {
                $isImage = (bool)preg_match('/\.(png|jpe?g|svg|webp|gif)(\?.*)?$/i', $icon)
                    || str_starts_with($icon, '/')
                    || str_starts_with($icon, 'http://')
                    || str_starts_with($icon, 'https://');

                $items[] = [
                    'name' => $name,
                    'is_image' => $isImage,
                    'image' => $isImage ? $icon : '',
                    'icon' => !$isImage ? ($icon ?: 'fa-tag') : 'fa-tag',
                    'link' => $link ?: ('/products?category=' . urlencode($name)),
                    'badge' => $badge,
                ];
            }
        }
        return $items;
    }

    public static function getConfiguredCategoryNames(): array
    {
        $featured = array_column(self::getFeaturedCategories(), 'name');
        $customization = self::all();
        $nav = is_array($customization['nav_categories'] ?? null)
            ? $customization['nav_categories']
            : array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)($customization['nav_categories'] ?? ''))));
        $existing = \App\Models\Product::orderBy('category')->distinct()->pluck('category')->toArray();
        $all = array_values(array_unique(array_filter(array_merge($featured, $nav, $existing))));
        natcasesort($all);
        return array_values($all);
    }

    public static function getFeaturedProducts(): array
    {
        $customization = self::all();
        $enabled = filter_var($customization['featured_products_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return [];
        }

        $mode = $customization['featured_products_mode'] ?? 'auto';
        $items = [];

        if ($mode === 'curated' && !empty($customization['featured_products_items'])) {
            $curatedConfigs = collect($customization['featured_products_items']);
            $productIds = $curatedConfigs->pluck('product_id')->filter()->unique()->toArray();

            if (!empty($productIds)) {
                $products = \App\Models\Product::where('active', true)
                    ->whereIn('id', $productIds)
                    ->get()
                    ->keyBy('id');

                foreach ($curatedConfigs as $cfg) {
                    $pid = $cfg['product_id'] ?? null;
                    if ($pid && isset($products[$pid])) {
                        $items[] = self::formatPublicProductCard($products[$pid], $cfg);
                    }
                }
            }
        }

        if (empty($items)) {
            $referencePreview = ReferenceCatalog::enabled() && !\App\Models\Product::where('active', true)->where('sku', 'not like', 'DEMO-%')->exists();
            if ($referencePreview) {
                $refItems = ReferenceCatalog::items();
                foreach ($refItems as $idx => $p) {
                    $cat = $p['category'] ?? 'General';
                    $priceRaw = (string)$p['price'];
                    $mrpRaw = (string)$p['mrp'];
                    $numPrice = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', $priceRaw));
                    $numMrp = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', $mrpRaw));
                    $discountPct = ($numMrp > $numPrice && $numMrp > 0) ? (int)round((($numMrp - $numPrice) / $numMrp) * 100) : 0;
                    $saveText = $discountPct > 0 ? ('Save ' . number_format($numMrp - $numPrice) . ' Tk') : '';

                    $items[] = [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'sku' => $p['sku'],
                        'category' => $cat,
                        'category_slug' => \Illuminate\Support\Str::slug($cat),
                        'description' => $p['description'],
                        'unit' => $p['unit'] ?? 'pack',
                        'stock' => $p['stock'] ?? 1,
                        'stock_status' => 'in_stock',
                        'stock_badge' => '',
                        'price' => $p['price'],
                        'mrp' => $p['mrp'],
                        'discount_pct' => $discountPct,
                        'savings_text' => $saveText,
                        'marketing_badge' => $idx === 0 ? '★ Best Seller' : ($idx === 1 ? '🔥 Hot Deal' : ($idx === 2 ? 'New Arrival' : '')),
                        'campaign' => null,
                        'url' => $p['url'],
                        'image_path' => $p['image_path'],
                        'preview' => true,
                    ];
                }
            } else {
                $products = \App\Models\Product::where('active', true)
                    ->orderByDesc('featured')
                    ->orderBy('name')
                    ->limit(20)
                    ->get();

                foreach ($products as $p) {
                    $items[] = self::formatPublicProductCard($p);
                }
            }
        }

        return $items;
    }

    public static function formatPublicProductCard(\App\Models\Product $p, array $config = []): array
    {
        $campaign = self::getCampaignForProduct($p->id, $p->name);
        $price = $campaign && !empty($campaign['price']) ? $campaign['price'] : ($p->dealer_price ?: $p->mrp);
        $mrp = $campaign && !empty($campaign['mrp']) ? $campaign['mrp'] : $p->mrp;

        $numPrice = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', (string)$price));
        $numMrp = (float)str_replace(',', '', preg_replace('/[^\d.]/', '', (string)$mrp));

        $discountPct = 0;
        $savingsText = '';
        if ($numMrp > $numPrice && $numMrp > 0) {
            $discountPct = (int)round((($numMrp - $numPrice) / $numMrp) * 100);
            $diff = $numMrp - $numPrice;
            $savingsText = 'Save ' . number_format($diff) . ' Tk';
        } elseif ($campaign && !empty($campaign['save'])) {
            $savingsText = $campaign['save'];
        }

        $stock = (int)($p->stock ?? 0);
        $stockStatus = 'in_stock';
        $stockBadge = '';
        if ($stock <= 0) {
            $stockStatus = 'out_of_stock';
            $stockBadge = 'Out of Stock';
        } elseif ($stock <= 5) {
            $stockStatus = 'low_stock';
            $stockBadge = "⚡ Only {$stock} Left!";
        }

        $marketingBadge = trim((string)($config['marketing_badge'] ?? ''));
        if ($marketingBadge === '') {
            if ($campaign) {
                $marketingBadge = $campaign['tag'] ?: '🔥 Deal';
            } elseif ($p->featured) {
                $marketingBadge = '★ Featured';
            }
        }

        $media = $p->media();
        $name = !empty($config['override_title']) ? $config['override_title'] : $p->name;

        return [
            'id' => $p->id,
            'name' => $name,
            'sku' => $p->sku,
            'category' => $p->category ?: 'General',
            'category_slug' => \Illuminate\Support\Str::slug($p->category ?: 'general'),
            'description' => $p->description,
            'unit' => $p->unit ?: 'pack',
            'stock' => $stock,
            'stock_status' => $stockStatus,
            'stock_badge' => $stockBadge,
            'price' => $price,
            'mrp' => $mrp,
            'discount_pct' => $discountPct,
            'savings_text' => $savingsText,
            'marketing_badge' => $marketingBadge,
            'campaign' => $campaign,
            'url' => route('products.show', $p->id),
            'image_path' => $media['image_url'] ?? '',
            'media' => $media,
            'preview' => false,
        ];
    }

    public static function getFeaturedCategoryTabs(array $products): array
    {
        $categories = [];
        foreach ($products as $p) {
            $cat = trim((string)($p['category'] ?? ''));
            if ($cat === '') continue;
            if (!isset($categories[$cat])) {
                $categories[$cat] = [
                    'name' => $cat,
                    'slug' => \Illuminate\Support\Str::slug($cat),
                    'count' => 0,
                ];
            }
            $categories[$cat]['count']++;
        }

        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);

        $tabs = [
            [
                'name' => 'All Products',
                'slug' => 'all',
                'count' => count($products),
            ],
            ...array_values($categories),
        ];

        return $tabs;
    }

    public static function reset(): void
    {
        DB::table('content_pages')->where('slug', self::SLUG)->delete();
    }
}

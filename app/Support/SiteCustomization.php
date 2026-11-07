<?php

namespace App\Support;

use App\Domain\Catalog\CatalogTaxonomy;
use App\Domain\Content\TestimonialLabels;
use App\Models\Product;
use App\Models\SiteFeedback;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteCustomization
{
    private const SLUG = 'frontend_customization';

    private const CacheKey = 'site_customization:v1';

    public const TestimonialsCacheKey = 'public:testimonials:v1';

    private const CacheTtlSeconds = 300;

    private const TestimonialsCacheTtlSeconds = 120;

    /** @var array<string, mixed>|null */
    private static ?array $requestMemo = null;

    /** @var array<string, mixed>|null */
    private static ?array $englishSiteCopy = null;

    /**
     * Factory text for the website editor and the public site.
     *
     * Text that also has a Bangla translation is read from lang/en/site.php,
     * so the English default and the Bangla translation always describe the
     * same thing. Old factory wording lives in SiteCustomizationRetiredDefaults.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $copy = static fn (string $siteKey): string => self::englishSiteCopy($siteKey);

        return [
            // Top Bar & Header Branding
            'top_bar_text' => 'Madina Natural Health Care',
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
            'header_catalog_title' => 'Products',
            'header_network_title' => 'Dealer Network',
            'company_intro_title' => 'Madina Natural Health Care',
            'company_intro_body' => $copy('company_intro_body'),
            // Home page intro: small line above the company name and the two link cards beside it.
            'company_intro_eyebrow' => $copy('company_intro_eyebrow'),
            'product_range_title' => $copy('product_range_title'),
            'product_range_sub' => $copy('product_range_sub'),
            'partner_with_us_title' => $copy('partner_with_us_title'),
            'partner_with_us_sub' => $copy('partner_with_us_sub'),
            'our_services_title' => $copy('our_services_title'),
            'our_services_sub' => $copy('our_services_sub'),
            'public_products_subtitle' => 'Discover our product range and connect with an authorized dealer.',
            'nav_categories' => '',
            // Mobile search overlay — Popular Categories chips (admin-managed)
            'mobile_search_categories_enabled' => '1',
            'mobile_search_categories_heading' => '',
            'mobile_search_categories_items' => '',
            'mobile_search_categories_limit' => '6',

            // Announcement & Ticker
            'announcement_text' => $copy('announcement_text'),

            // Carousel Slides (1 to 5)
            // Carousel Slides (3 Dedicated Hero Banners)
            'slides' => [
                ['image' => '/branding/madina-hero-banner-1.jpg', 'link' => '/products', 'alt' => $copy('hero_slide1_alt')],
                ['image' => '/branding/madina-hero-banner-2.jpg', 'link' => '/products', 'alt' => $copy('hero_slide2_alt')],
                ['image' => '/branding/madina-hero-banner-3.jpg', 'link' => '/about', 'alt' => $copy('hero_slide3_alt')],
            ],

            // Side Banners (Dedicated Mini-Carousels - 100% Unique to Side Sections)
            'side_banner_1_slides' => [
                ['image' => '/branding/madina-side-banner-1.jpg', 'link' => '/products', 'alt' => $copy('side_banner_1_slide1_alt')],
                ['image' => '/branding/madina-top-skincare.jpg', 'link' => '/products', 'alt' => $copy('side_banner_1_slide2_alt')],
                ['image' => '/branding/madina-top-immunity.jpg', 'link' => '/products', 'alt' => $copy('side_banner_1_slide3_alt')],
            ],
            'side_banner_2_slides' => [
                ['image' => '/branding/madina-side-banner-2.jpg', 'link' => '/dealers', 'alt' => $copy('side_banner_2_slide1_alt')],
                ['image' => '/branding/madina-promotion-popup.jpg', 'link' => '/about', 'alt' => $copy('side_banner_2_slide2_alt')],
                ['image' => '/branding/madina-bottom-pharmacy.jpg', 'link' => '/dealers', 'alt' => $copy('side_banner_2_slide3_alt')],
            ],
            'side_banner_1_image' => '/branding/madina-side-banner-1.jpg',
            'side_banner_1_link' => '/products',
            'side_banner_1_alt' => $copy('side_banner_1_alt'),
            'side_banner_2_image' => '/branding/madina-side-banner-2.jpg',
            'side_banner_2_link' => '/dealers',
            'side_banner_2_alt' => $copy('side_banner_2_alt'),

            // Quick Shortcuts
            'shortcuts_section_enabled' => '1',
            'shortcuts' => [
                ['title' => 'Our Products', 'subtitle' => 'browse wellness items', 'icon' => 'fa-seedling', 'link' => '/products'],
                ['title' => 'Become a Partner', 'subtitle' => 'Dealer & sub-dealer programme', 'icon' => 'fa-handshake', 'link' => '/partnership'],
                ['title' => 'Our Services', 'subtitle' => 'Natural Health Care and Physiotherapy Center', 'icon' => 'fa-hand-holding-heart', 'link' => '/services'],
                ['title' => 'Contact Us', 'subtitle' => 'Support & Inquiries', 'icon' => 'fa-headset', 'link' => '/contact'],
                ['title' => 'Our Network', 'subtitle' => 'Dealer Directory', 'icon' => 'fa-diagram-project', 'link' => '/dealers'],
            ],

            // Campaign & Special Offers
            'campaign_section_enabled' => '0',
            'campaign_badge' => 'SPECIAL CAMPAIGN & OFFERS',
            'campaign_title' => 'Special Discounts & Promotional Offers',
            'campaign_subtitle' => 'Health packages for a short time, bulk offers for shop owners and seasonal discounts.',
            'campaign_cards' => [],
            // Retain legacy flat keys for backward compatibility
            'campaign_card1_product_id' => '',
            'campaign_card1_tag' => '',
            'campaign_card1_title' => '',
            'campaign_card1_desc' => '',
            'campaign_card1_feat1' => '',
            'campaign_card1_feat2' => '',
            'campaign_card1_price' => '',
            'campaign_card1_mrp' => '',
            'campaign_card1_save' => '',
            'campaign_card1_btn' => 'View Package',
            'campaign_card1_link' => '/products',
            'campaign_card1_image' => '',
            'campaign_card2_product_id' => '',
            'campaign_card2_tag' => '',
            'campaign_card2_title' => '',
            'campaign_card2_desc' => '',
            'campaign_card2_feat1' => '',
            'campaign_card2_feat2' => '',
            'campaign_card2_price' => '',
            'campaign_card2_mrp' => '',
            'campaign_card2_save' => '',
            'campaign_card2_btn' => 'Explore Kit',
            'campaign_card2_link' => '/products',
            'campaign_card2_image' => '',

            // Featured Categories Section
            'featured_categories_enabled' => '1',
            'featured_categories_title' => 'Featured Categories',
            'featured_categories_subtitle' => 'Explore products by category.',
            'featured_categories_items' => '',
            'dealer_banner_title' => 'Authorized Dealer Network',
            'dealer_banner_subtitle' => $copy('dealer_banner_subtitle'),
            'dealer_banner_btn_text' => 'Find Nearest Dealer',

            // Featured Products Section
            'featured_products_enabled' => '1',
            'featured_products_badge' => $copy('featured_products_badge'),
            'featured_products_title' => 'Featured Products',
            'featured_products_subtitle' => $copy('featured_products_subtitle'),
            'featured_products_view_all' => 'View All Products',
            'featured_products_view_all_link' => '/products',
            'featured_products_tabs_enabled' => '1',
            'featured_products_mode' => 'auto',
            'featured_products_items' => [],
            'products_empty_title' => 'Our collection is being prepared.',

            // Brand promises. Each one states only what the public site itself
            // shows (trade licence, head office, dealer directory, phone line).
            'trust_pillars_enabled' => '1',
            'trust_pillars_title' => 'Our Promise to You',
            'trust_pillars' => [
                ['icon' => 'fa-leaf', 'title' => 'Herbal & Nutrition Products', 'subtitle' => 'Holistic Nutracare herbal and nutrition products for everyday care.'],
                ['icon' => 'fa-certificate', 'title' => 'Trade-Licensed Business', 'subtitle' => 'A licensed distributor with its head office in Pirganj, Rangpur.'],
                ['icon' => 'fa-truck-fast', 'title' => 'Company-Approved Dealers', 'subtitle' => 'Buy from dealers the company has approved, and find the one nearest you.'],
                ['icon' => 'fa-headset', 'title' => 'Help by Phone', 'subtitle' => 'Call our office for product and order information.'],
            ],

            // Reviews come only from customer feedback the owner has approved;
            // the section stays hidden until at least one is approved.
            'testimonials_section_enabled' => '1',
            'testimonials_title' => 'What Customers Say',
            'testimonials_subtitle' => 'Shared by customers and partners, and published after our team approves them.',
            'testimonials' => [],
            // Shown in place of the reviews until the first one is approved.
            'testimonials_invite_title' => $copy('testimonials_invite_title'),
            'testimonials_invite_body' => $copy('testimonials_invite_body'),
            // Review form (/feedback)
            'feedback_page_title' => $copy('feedback_page_title'),
            'feedback_page_lead' => $copy('feedback_page_lead'),

            // Brand Video Gallery Showcase (Toggleable by Admin)
            // Off by default until real company videos are configured — avoids placeholder embeds on a corporate site.
            'video_gallery_enabled' => '0',
            'video_gallery_title' => $copy('video_gallery_title'),
            'video_gallery_subtitle' => $copy('video_gallery_subtitle'),
            'video_gallery_items' => [
                ['title' => 'Herbal Care Guidance', 'subtitle' => 'Application guidance for Madina botanical care', 'video_url' => '', 'video_embed_url' => '', 'thumbnail' => '/branding/madina-top-skincare.jpg'],
                ['title' => 'Natural Formulation Story', 'subtitle' => 'How Madina prepares botanical formulations', 'video_url' => '', 'video_embed_url' => '', 'thumbnail' => '/branding/madina-side-banner-1.jpg'],
            ],

            // Homepage About
            'about_headline' => 'Natural Food, Medicine & Wellness Product Shop in Bangladesh',
            'about_lead' => 'Licensed distributor of Holistic Nutracare herbal and nutrition products. See our products, or contact Madina Natural Health Care about a product or about becoming a dealer.',
            'about_summary_title' => 'Read More',
            'about_summary_body' => 'Dealers order, pay, follow deliveries and get help from their own panel. Only company staff can open a dealer account.',

            // About Us Page (/about)
            'about_hero_title' => 'About Madina Natural Health Care',
            'about_hero_lead' => $copy('about_hero_lead'),
            'about_history_title' => 'Company History',
            'about_history' => $copy('about_history'),
            'about_mission_title' => 'Our Mission',
            'about_mission' => $copy('about_mission'),
            'about_vision_title' => 'Our Vision',
            'about_vision' => $copy('about_vision'),
            'about_objectives_title' => 'Core Objectives',
            'about_obj1_title' => $copy('about_obj1_title'),
            'about_obj1_desc' => $copy('about_obj1_desc'),
            'about_obj2_title' => $copy('about_obj2_title'),
            'about_obj2_desc' => $copy('about_obj2_desc'),
            'about_obj3_title' => $copy('about_obj3_title'),
            'about_obj3_desc' => $copy('about_obj3_desc'),
            'about_obj4_title' => $copy('about_obj4_title'),
            'about_obj4_desc' => $copy('about_obj4_desc'),
            'about_network_title' => 'Dealer & Sub-dealer Network',
            'about_network' => $copy('about_network'),
            'about_btn_products' => 'Explore Products',
            'about_btn_dealers' => 'Find a Dealer',
            'about_btn_contact' => 'Contact Head Office',

            // Managing Director's message (/about and the home page).
            // Each text has a Bangla box; empty = the built-in Bangla wording.
            'md_message_enabled' => '1',
            'md_message_heading' => $copy('md_message_heading'),
            'md_message_heading_bn' => '',
            'md_message_name' => $copy('md_message_name'),
            'md_message_name_bn' => '',
            'md_message_title' => $copy('md_message_title'),
            'md_message_title_bn' => '',
            'md_message_company' => $copy('md_message_company'),
            'md_message_company_bn' => '',
            'md_message_body' => $copy('md_message_body'),
            'md_message_body_bn' => '',
            'md_message_image' => '/branding/md-message/managing-director.webp',

            // Contact Page (/contact)
            'contact_title' => 'Contact Us',
            'contact_company_title' => 'Madina Natural Health Care',
            'contact_company_desc' => 'Madina Natural Health Care (Owner: Md Anowar Hossain). Head office: Thana Road, Pirganj 5470, Rangpur. Factory/warehouse: Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka. Hotline: 01730532966 · 01723731877 · 01727612379.',
            'contact_phone_title' => 'Customer Care & Hotline',
            'contact_phone' => '+8801730532966',
            'contact_phone_hours' => 'Hotline also: 01723731877 · 01727612379',
            'contact_email_title' => 'Official Email',
            'contact_email' => 'holisticnutracare@gmail.com',
            'contact_topic1_title' => 'Dealer Enquiries',
            'contact_topic1_desc' => $copy('contact_topic1_desc'),
            'contact_topic2_title' => 'Products & Support',
            'contact_topic2_desc' => $copy('contact_topic2_desc'),
            'contact_topic3_title' => 'Head Office',
            // Printed order invoice letterhead (starting values come from config/madina.php).
            'invoice_manufacturer' => (string) config('madina.invoice_manufacturer', 'Holistic Nutracare'),
            'invoice_marketed_by' => (string) config('madina.invoice_marketed_by', 'Madina Natural Health Care'),
            'invoice_marketed_phones' => (string) config('madina.invoice_marketed_phones', '01723-731877, 01730532966'),
            'contact_social_title' => 'Connect With Us Online',
            'office_address' => 'Thana Road, Pirganj 5470, Rangpur, Bangladesh',
            // Default pin: Pirganj Upazila, Rangpur District (admin can override in Website CMS).
            // Do not use the old 25.7701,88.8240 example — that resolved to Dinajpur, not Pirganj.
            'map_latitude' => '25.416572',
            'map_longitude' => '89.307493',
            'contact_form_title' => 'Send an Enquiry',
            'map_title' => 'Office & Healthcare Center Location',
            'map_subtitle' => 'Visit Madina Natural Health Care at our licensed Pirganj head office or navigate with map directions.',
            'map_facility' => 'Madina Natural Health Care — Thana Road, Pirganj',
            'map_directions_text' => 'Open Map Directions',

            // Dealer Directory Page (/dealers)
            'directory_title' => 'Dealer Directory',
            'directory_subtitle' => $copy('directory_subtitle'),
            'directory_gps_btn' => 'Find nearest dealers',
            'directory_empty_title' => $copy('directory_empty_title'),
            'directory_empty_desc' => 'Contact us for help finding a local dealer.',

            // Products Catalog Page (/products)
            'catalog_categories_title' => 'Categories',
            'catalog_all_products_label' => 'All Products',
            'catalog_price_title' => 'MRP Range',
            'catalog_instock_label' => 'In-stock products only',
            'catalog_apply_filters_btn' => $copy('apply_filters'),
            'catalog_clear_filters_btn' => 'Clear Filters',
            'catalog_sort_by_label' => 'Sort By',
            'catalog_empty_title' => 'No products found',
            'catalog_empty_desc' => $copy('catalog_empty_desc'),
            'catalog_empty_btn' => 'Browse All Products',

            // Product Cards & Product Details (/product/{slug})
            'product_in_stock_label' => 'In Stock',
            'product_out_of_stock_label' => $copy('out_of_stock'),
            'product_add_to_cart_btn' => 'Add to Cart',
            'product_buy_now_btn' => 'Buy Now',
            'product_share_label' => 'Share:',
            'product_save_btn' => 'Save',
            'product_compare_btn' => 'Add to Compare',
            'product_payment_mode_label' => 'bKash / Nagad / Rocket / bank',
            'product_purchase_note' => 'Dealers pay the full order amount and send a payment screenshot. Goods are sent after the payment is checked.',
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
            'footer_email' => 'holisticnutracare@gmail.com',
            'footer_address' => $copy('footer_address'),
            'footer_link_about' => 'About Us',
            'footer_link_about_url' => '/about',
            'footer_link_vendor' => 'Vendor Program',
            'footer_link_vendor_url' => '/partnership',
            'footer_link_orders' => 'Order Tracking',
            'footer_link_orders_url' => '/login',
            'footer_link_blog' => 'Blog & Tips',
            'footer_link_blog_url' => '/about',
            'footer_link_contact' => 'Contact Us',
            'footer_link_contact_url' => '/contact',
            'footer_link_dealer_apply' => 'Apply for Dealership',
            'footer_link_dealer_apply_url' => '/partnership',
            'footer_about_title' => 'Madina Natural Health Care',
            'footer_about_desc' => 'Licensed distributor (Trade License 2026-00563, Pirganj Municipality). Natural herbal & Holistic Nutracare products across Bangladesh.',
            'footer_logo_url' => '/madina-header-logo-white.png?v=9',

            // Floating Tools & Dialogs
            'floating_compare_label' => 'COMPARE',
            'floating_cart_label' => 'CART',
            'floating_chat_label' => 'CHAT',
            'cart_dialog_title' => 'Your Cart',
            'cart_dialog_btn' => 'Log in to order',
            'cart_dialog_notice' => 'Pay the full amount and send a payment screenshot. Goods are sent after the payment is checked.',
            'compare_dialog_title' => 'Product Comparison',
            'compare_dialog_desc' => 'Select products to compare.',

            // Social Media & Chat (empty = omit icon; never render href="#")
            'social_facebook' => '',
            'social_youtube' => '',
            'social_linkedin' => '',
            'social_instagram' => '',
            'social_whatsapp' => 'https://wa.me/8801730532966',
            'social_twitter' => '',
            'social_tiktok' => '',
            'social_telegram' => '',
            'whatsapp_link' => 'https://wa.me/8801730532966',

            // Mobile App Store Badges (empty = omit badge)
            'app_google_play_link' => '',
            'app_apple_store_link' => '',
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
            // Help box in the dealer and sub-dealer side menu (phone comes from contact_phone).
            'partner_help_title' => $copy('partner_help_title'),
            'partner_help_body' => $copy('partner_help_body'),

            // Partnership / Become a Dealer (/partnership)
            'partnership_hero_title' => 'Become an Authorized Madina Partner',
            'partnership_hero_lead' => $copy('partnership_hero_lead'),
            'partnership_why_title' => 'Why partner with Madina',
            'partnership_benefit1_title' => $copy('partnership_benefit1_title'),
            'partnership_benefit1_desc' => $copy('partnership_benefit1_desc'),
            'partnership_benefit2_title' => $copy('partnership_benefit2_title'),
            'partnership_benefit2_desc' => $copy('partnership_benefit2_desc'),
            'partnership_benefit3_title' => $copy('partnership_benefit3_title'),
            'partnership_benefit3_desc' => $copy('partnership_benefit3_desc'),
            'partnership_benefit4_title' => 'Local market support',
            'partnership_benefit4_desc' => $copy('partnership_benefit4_desc'),
            'partnership_steps_title' => 'How to apply',
            'partnership_step1_title' => 'Share your details',
            'partnership_step1_desc' => $copy('partnership_step1_desc'),
            'partnership_step2_title' => $copy('partnership_step2_title'),
            'partnership_step2_desc' => $copy('partnership_step2_desc'),
            'partnership_step3_title' => $copy('partnership_step3_title'),
            'partnership_step3_desc' => $copy('partnership_step3_desc'),
            'partnership_cta_title' => 'Ready to grow with Madina?',
            'partnership_cta_body' => $copy('partnership_cta_body'),
            'partnership_cta_btn' => 'Apply for Dealership',
            'partnership_cta_link' => '#partner-apply',

            // Services (/services)
            'services_hero_title' => 'Natural Health Care & Physiotherapy Services',
            'services_hero_lead' => $copy('services_hero_lead'),
            'services_card1_title' => 'Natural health consultation',
            'services_card1_desc' => $copy('services_card1_desc'),
            'services_card2_title' => 'Physiotherapy center',
            'services_card2_desc' => $copy('services_card2_desc'),
            'services_card3_title' => $copy('services_card3_title'),
            'services_card3_desc' => $copy('services_card3_desc'),
            'services_cta_btn' => 'Contact the Center',
            'services_cta_link' => '/contact',

            // Quality & Authenticity (/quality)
            'quality_hero_title' => $copy('quality_hero_title'),
            'quality_hero_lead' => $copy('quality_hero_lead'),
            'quality_pillar1_title' => $copy('quality_pillar1_title'),
            'quality_pillar1_desc' => $copy('quality_pillar1_desc'),
            'quality_pillar2_title' => 'Authorized partners only',
            'quality_pillar2_desc' => $copy('quality_pillar2_desc'),
            'quality_pillar3_title' => $copy('quality_pillar3_title'),
            'quality_pillar3_desc' => $copy('quality_pillar3_desc'),
            'quality_cta_btn' => 'Browse Products',
            'quality_cta_link' => '/products',

            // FAQ (/faq)
            'faq_hero_title' => 'Frequently Asked Questions',
            'faq_hero_lead' => 'Quick answers about products, dealers, partnership and company support.',
            'faq_items' => array_map(
                static fn (int $item): array => [
                    'question' => $copy('faq_item'.$item.'_question'),
                    'answer' => $copy('faq_item'.$item.'_answer'),
                ],
                range(1, 5),
            ),

            // Privacy (/privacy)
            'privacy_hero_title' => 'Privacy Policy',
            'privacy_hero_lead' => $copy('privacy_hero_lead'),
            'privacy_body' => $copy('privacy_body'),

            // Terms (/terms)
            'terms_hero_title' => 'Terms of Use',
            'terms_hero_lead' => $copy('terms_hero_lead'),
            'terms_body' => $copy('terms_body'),

            'legal_meta_effective' => 'Effective: October 2026',
            'legal_meta_governance' => 'Madina Natural Health Care · Operational policy',
            'legal_meta_location' => 'Pirganj, Rangpur, Bangladesh',

            // Footer legal links
            'footer_link_services' => 'Our Services',
            'footer_link_services_url' => '/services',
            'footer_link_quality' => 'Quality Promise',
            'footer_link_quality_url' => '/quality',
            'footer_link_faq' => 'FAQ',
            'footer_link_faq_url' => '/faq',
            'footer_link_privacy' => 'Privacy Policy',
            'footer_link_privacy_url' => '/privacy',
            'footer_link_terms' => 'Terms of Use',
            'footer_link_terms_url' => '/terms',
            'footer_link_partnership' => 'Become a Partner',
            'footer_link_partnership_url' => '/partnership',

            // Public page visibility (owner can turn pages off site-wide)
            'page_enabled_about' => '1',
            'page_enabled_products' => '1',
            'page_enabled_dealers' => '1',
            'page_enabled_contact' => '1',
            'page_enabled_partnership' => '1',
            'page_enabled_services' => '1',
            'page_enabled_quality' => '1',
            'page_enabled_faq' => '1',
            'page_enabled_privacy' => '1',
            'page_enabled_terms' => '1',

            // Copyright
            'copyright_text' => '© '.date('Y').' Madina Natural Health Care. All rights reserved.',
            // Bangla wording for Bangla visitors; empty = the built-in Bangla line.
            'copyright_text_bn' => '',

            // Text on the notice and circular pages while nothing is published.
            'notice_empty' => $copy('notice_empty'),
            'notice_empty_bn' => '',
        ];
    }

    /**
     * Website editor text the dealer and sub-dealer panels show: header
     * links and the help box in the side menu, in the partner's language.
     *
     * @return array<string, string>
     */
    public static function partnerPanelCopy(): array
    {
        $all = self::all();
        $copy = collect($all)->only([
            'header_offers_title', 'header_offers_subtitle',
            'header_projects_title', 'header_projects_subtitle',
        ])->map(fn (mixed $value): string => (string) $value)->all();
        $phone = trim((string) ($all['contact_phone'] ?? ''));

        return $copy + [
            'partner_help_title' => SiteChrome::text('partner_help_title', $all['partner_help_title'] ?? null),
            'partner_help_body' => SiteChrome::text('partner_help_body', $all['partner_help_body'] ?? null),
            'partner_help_phone' => $phone !== '' ? $phone : (string) self::defaults()['contact_phone'],
        ];
    }

    /**
     * Company name for browser tab titles: the "company name" on the
     * website editor's contact page (Bangla name for Bangla visitors until
     * the owner types their own).
     */
    public static function brandName(): string
    {
        return SiteChrome::text('contact_company_title', self::all()['contact_company_title'] ?? null);
    }

    /**
     * Name after the page title in dealer and staff panel tabs. Stays the
     * short "Madina" until the owner changes the company name.
     */
    public static function panelTabName(): string
    {
        $custom = trim((string) (self::all()['contact_company_title'] ?? ''));
        $factory = [trim((string) self::defaults()['contact_company_title']), (string) trans('site.contact_company_title', [], 'en')];

        return $custom === '' || in_array($custom, $factory, true) ? 'Madina' : $custom;
    }

    /**
     * Footer copyright line. A Bangla line typed in the editor wins for
     * Bangla visitors; an owner edit is never dropped; untouched factory
     * text (any year) uses the built-in line with the current year.
     */
    public static function copyrightLine(): string
    {
        $all = self::all();
        $bangla = trim((string) ($all['copyright_text_bn'] ?? ''));
        if (app()->getLocale() === 'bn' && $bangla !== '') {
            return $bangla;
        }
        $custom = trim((string) ($all['copyright_text'] ?? ''));
        $isFactory = (bool) preg_match('/^© \d{4} Madina Natural Health Care\. All rights reserved\.$/u', $custom);
        if ($custom === '' || $isFactory) {
            return (string) __('site.copyright', ['year' => LocalDigits::text(date('Y'))]);
        }

        return $custom;
    }

    /** @return list<string> */
    public static function toggleablePages(): array
    {
        return ['about', 'products', 'dealers', 'contact', 'partnership', 'services', 'quality', 'faq', 'privacy', 'terms'];
    }

    /** @return array<string, bool> */
    public static function enabledPagesMap(?array $customization = null): array
    {
        $all = $customization ?? self::all();
        $map = [];
        foreach (self::toggleablePages() as $page) {
            $map[$page] = filter_var($all['page_enabled_'.$page] ?? '1', FILTER_VALIDATE_BOOLEAN);
        }

        return $map;
    }

    public static function isPageEnabled(string $page): bool
    {
        if ($page === 'home' || $page === '') {
            return true;
        }
        if (! in_array($page, self::toggleablePages(), true)) {
            return true;
        }

        return self::enabledPagesMap()[$page] ?? true;
    }

    public static function pageFromPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'mailto:') || str_starts_with($path, 'tel:') || str_starts_with($path, '#')) {
            $host = parse_url($path, PHP_URL_HOST);
            if ($host !== null && $host !== '' && $host !== PortalHost::domain() && ! str_ends_with((string) $host, '.'.PortalHost::domain())) {
                return null;
            }
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        }
        $path = '/'.ltrim(explode('?', explode('#', $path)[0])[0], '/');
        if ($path === '/' || $path === '') {
            return 'home';
        }
        $segment = explode('/', trim($path, '/'))[0] ?? '';

        return in_array($segment, self::toggleablePages(), true) ? $segment : null;
    }

    public static function publicHref(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return null;
        }
        $page = self::pageFromPath($url);
        if ($page !== null && $page !== 'home' && ! self::isPageEnabled($page)) {
            return null;
        }

        return $url;
    }

    /**
     * English website copy that has a Bangla twin in lang/bn/site.php.
     */
    private static function englishSiteCopy(string $siteKey): string
    {
        self::$englishSiteCopy ??= (array) trans('site', [], 'en');

        return (string) (self::$englishSiteCopy[$siteKey] ?? '');
    }

    /**
     * Saved editor values, without old factory text the owner never changed,
     * so the editor shows today's default as the placeholder instead.
     *
     * @return array<string, mixed>
     */
    public static function raw(): array
    {
        try {
            $record = DB::table('content_pages')->where('slug', self::SLUG)->first();
            if (! $record || empty($record->body)) {
                return [];
            }
            $stored = json_decode($record->body, true);

            return is_array($stored) ? self::withoutRetiredDefaults($stored) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Saving the editor stores every field, including factory text the owner
     * never touched. When that stored text is an old factory default, drop it
     * so the current default (and its Bangla translation) is used. Text the
     * owner wrote is never touched.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function withoutRetiredDefaults(array $stored): array
    {
        foreach (SiteCustomizationRetiredDefaults::all() as $key => $retiredValues) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }
            $value = $stored[$key];
            if (is_array($value)) {
                $value = self::normalizedFaqItems($value);
            } elseif (is_scalar($value)) {
                $value = trim((string) $value);
            } else {
                continue;
            }
            if (in_array($value, $retiredValues, true)) {
                unset($stored[$key]);
            }
        }

        return $stored;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array{question: string, answer: string}>
     */
    private static function normalizedFaqItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized[] = [
                'question' => trim((string) ($item['question'] ?? '')),
                'answer' => trim((string) ($item['answer'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * Prefer translated copy when CMS still holds the English factory default.
     * Custom CMS text is returned unchanged.
     */
    public static function localizedCopy(array $customization, string $key, string $translationKey): string
    {
        $value = trim((string) ($customization[$key] ?? ''));
        $default = trim((string) (self::defaults()[$key] ?? ''));
        if ($value === '' || $value === $default) {
            return (string) __($translationKey);
        }

        return $value;
    }

    /**
     * FAQ items for the public /faq page. Uses Website CMS entries when staff
     * customized them; Bangla falls back to translated defaults until then.
     *
     * @param  array<string, mixed>|null  $customization
     * @return list<array{question: string, answer: string}>
     */
    public static function faqItemsForDisplay(?array $customization = null): array
    {
        $all = $customization ?? self::all();
        $defaults = self::defaults()['faq_items'];
        $items = $all['faq_items'] ?? [];
        if (! is_array($items) || $items === []) {
            $items = $defaults;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' && $answer === '') {
                continue;
            }
            $normalized[] = ['question' => $question, 'answer' => $answer];
        }
        if ($normalized === []) {
            $normalized = array_map(
                static fn (array $item): array => [
                    'question' => trim((string) ($item['question'] ?? '')),
                    'answer' => trim((string) ($item['answer'] ?? '')),
                ],
                $defaults,
            );
        }

        $matchesDefault = count($normalized) === count($defaults);
        if ($matchesDefault) {
            foreach ($normalized as $idx => $item) {
                $defaultItem = $defaults[$idx] ?? ['question' => '', 'answer' => ''];
                if ($item['question'] !== trim((string) ($defaultItem['question'] ?? ''))
                    || $item['answer'] !== trim((string) ($defaultItem['answer'] ?? ''))) {
                    $matchesDefault = false;
                    break;
                }
            }
        }

        if (app()->getLocale() === 'bn' && $matchesDefault) {
            $localized = [];
            foreach (range(1, count($defaults)) as $i) {
                $localized[] = [
                    'question' => (string) __('site.faq_item'.$i.'_question'),
                    'answer' => (string) __('site.faq_item'.$i.'_answer'),
                ];
            }

            return $localized;
        }

        return $normalized;
    }

    public static function forgetCache(): void
    {
        self::$requestMemo = null;
        Cache::forget(self::CacheKey);
        Cache::forget('public:content_pages:v1');
        Cache::forget('public:content_pages:v2');
        Cache::forget('public:product_categories:v1');
        Cache::forget('public:product_categories:v2');
        Cache::forget('public:featured_products:v1');
        Cache::forget('public:featured_products:v2');
        Cache::forget(self::TestimonialsCacheKey);
        ProductVideo::forgetHomepageCache();
    }

    public static function all(): array
    {
        return self::$requestMemo ??= Cache::remember(self::CacheKey, self::CacheTtlSeconds, function (): array {
            return self::loadAll();
        });
    }

    /** @return array<string, mixed> */
    private static function loadAll(): array
    {
        $defaults = self::defaults();
        try {
            $record = DB::table('content_pages')->where('slug', self::SLUG)->first();
            if (! $record || empty($record->body)) {
                return $defaults;
            }
            $stored = json_decode($record->body, true);
            if (! is_array($stored)) {
                return $defaults;
            }
            $stored = self::withoutRetiredDefaults($stored);

            $merged = $defaults;
            foreach ($defaults as $key => $defaultVal) {
                if (isset($stored[$key])) {
                    if (is_array($defaultVal)) {
                        if (is_array($stored[$key])) {
                            // Dynamic arrays: shortcuts, campaign_cards & featured_products_items support variable length!
                            if ($key === 'shortcuts' || $key === 'campaign_cards' || $key === 'featured_products_items' || $key === 'faq_items') {
                                $items = [];
                                foreach ($stored[$key] as $subIdx => $subStored) {
                                    if (! is_array($subStored)) {
                                        continue;
                                    }
                                    $subDefault = $defaultVal[$subIdx] ?? ($defaultVal[0] ?? []);
                                    $item = [];
                                    foreach ($subDefault as $k => $v) {
                                        if (isset($subStored[$k])) {
                                            $item[$k] = is_array($subStored[$k]) ? $subStored[$k] : trim((string) $subStored[$k]);
                                        } else {
                                            $item[$k] = $v;
                                        }
                                    }
                                    foreach ($subStored as $k => $v) {
                                        if (! array_key_exists($k, $item)) {
                                            $item[$k] = is_array($v) ? $v : (is_scalar($v) ? (string) $v : $v);
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
                                            if (isset($subStored[$k]) && trim((string) $subStored[$k]) !== '') {
                                                $item[$k] = (string) $subStored[$k];
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
                        $trimmed = trim((string) $stored[$key]);
                        if ($trimmed !== '') {
                            $merged[$key] = $trimmed;
                        }
                    }
                }
            }

            // Fallback for campaign_cards if missing from stored but stored has legacy flat keys
            if (! array_key_exists('campaign_cards', $stored)) {
                $hasLegacy = isset($stored['campaign_card1_title']) || isset($stored['campaign_card2_title']) || isset($stored['campaign_card1_price']);
                if ($hasLegacy) {
                    $c1 = [
                        'id' => 'card-1',
                        'product_id' => (string) ($stored['campaign_card1_product_id'] ?? $defaults['campaign_card1_product_id']),
                        'tag' => (string) ($stored['campaign_card1_tag'] ?? $defaults['campaign_card1_tag']),
                        'title' => (string) ($stored['campaign_card1_title'] ?? $defaults['campaign_card1_title']),
                        'desc' => (string) ($stored['campaign_card1_desc'] ?? $defaults['campaign_card1_desc']),
                        'feat1' => (string) ($stored['campaign_card1_feat1'] ?? $defaults['campaign_card1_feat1']),
                        'feat2' => (string) ($stored['campaign_card1_feat2'] ?? $defaults['campaign_card1_feat2']),
                        'price' => (string) ($stored['campaign_card1_price'] ?? $defaults['campaign_card1_price']),
                        'mrp' => (string) ($stored['campaign_card1_mrp'] ?? $defaults['campaign_card1_mrp']),
                        'save' => (string) ($stored['campaign_card1_save'] ?? $defaults['campaign_card1_save']),
                        'btn' => (string) ($stored['campaign_card1_btn'] ?? $defaults['campaign_card1_btn']),
                        'link' => (string) ($stored['campaign_card1_link'] ?? $defaults['campaign_card1_link']),
                        'image' => (string) ($stored['campaign_card1_image'] ?? $defaults['campaign_card1_image']),
                        'highlight' => '1',
                    ];
                    $c2 = [
                        'id' => 'card-2',
                        'product_id' => (string) ($stored['campaign_card2_product_id'] ?? $defaults['campaign_card2_product_id']),
                        'tag' => (string) ($stored['campaign_card2_tag'] ?? $defaults['campaign_card2_tag']),
                        'title' => (string) ($stored['campaign_card2_title'] ?? $defaults['campaign_card2_title']),
                        'desc' => (string) ($stored['campaign_card2_desc'] ?? $defaults['campaign_card2_desc']),
                        'feat1' => (string) ($stored['campaign_card2_feat1'] ?? $defaults['campaign_card2_feat1']),
                        'feat2' => (string) ($stored['campaign_card2_feat2'] ?? $defaults['campaign_card2_feat2']),
                        'price' => (string) ($stored['campaign_card2_price'] ?? $defaults['campaign_card2_price']),
                        'mrp' => (string) ($stored['campaign_card2_mrp'] ?? $defaults['campaign_card2_mrp']),
                        'save' => (string) ($stored['campaign_card2_save'] ?? $defaults['campaign_card2_save']),
                        'btn' => (string) ($stored['campaign_card2_btn'] ?? $defaults['campaign_card2_btn']),
                        'link' => (string) ($stored['campaign_card2_link'] ?? $defaults['campaign_card2_link']),
                        'image' => (string) ($stored['campaign_card2_image'] ?? $defaults['campaign_card2_image']),
                        'highlight' => '0',
                    ];
                    $merged['campaign_cards'] = [$c1, $c2];
                }
            }

            return SafeSiteUrl::clean($merged);
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    public static function save(array $payload): void
    {
        self::validateShape(self::defaults(), $payload);
        SafeSiteUrl::clean($payload, true);
        DB::transaction(function () use ($payload): void {
            DB::select('SELECT pg_advisory_xact_lock(?)', [81416002]);
            $payload = array_replace(self::all(), $payload);
            $defaults = self::defaults();
            $clean = [];

            foreach ($defaults as $key => $defaultVal) {
                if ($key === 'nav_categories') {
                    if (is_array($payload[$key] ?? null)) {
                        $clean[$key] = implode("\n", array_map('trim', array_filter($payload[$key])));
                    } else {
                        $clean[$key] = trim((string) ($payload[$key] ?? ''));
                    }

                    continue;
                }
                if (is_array($defaultVal)) {
                    $subItems = [];
                    $inputArray = $payload[$key] ?? null;
                    if (is_array($inputArray)) {
                        if ($key === 'shortcuts' || $key === 'campaign_cards' || $key === 'featured_products_items' || $key === 'faq_items') {
                            // Dynamic list: preserve all items sent by client
                            $template = $defaultVal[0] ?? [];
                            foreach ($inputArray as $subInput) {
                                if (! is_array($subInput)) {
                                    continue;
                                }
                                $cleanSub = [];
                                foreach ($template as $k => $v) {
                                    if (isset($subInput[$k])) {
                                        $cleanSub[$k] = is_array($subInput[$k]) ? $subInput[$k] : trim((string) $subInput[$k]);
                                    } else {
                                        $cleanSub[$k] = is_array($v) ? [] : '';
                                    }
                                }
                                foreach ($subInput as $k => $v) {
                                    if (! array_key_exists($k, $cleanSub)) {
                                        $cleanSub[$k] = is_array($v) ? $v : (is_scalar($v) ? trim((string) $v) : $v);
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
                                    $val = isset($subInput[$k]) ? trim((string) $subInput[$k]) : '';
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
                    $val = isset($payload[$key]) ? trim((string) $payload[$key]) : '';
                    $clean[$key] = $val;
                }
            }

            // Backward compatibility sync for legacy flat keys if campaign_cards are saved
            if (! empty($clean['campaign_cards']) && is_array($clean['campaign_cards'])) {
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
        }, 3);
        self::forgetCache();
    }

    /** @param array<string, mixed> $defaults @param array<string, mixed> $payload */
    private static function validateShape(array $defaults, array $payload): void
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            $valid = true;
            if ($key === 'nav_categories' && is_array($value)) {
                $valid = count($value) <= 100 && count(array_filter($value, is_scalar(...))) === count($value);
            } elseif (is_array($default)) {
                $valid = is_array($value) && count($value) <= 100;
                if ($valid) {
                    foreach ($value as $item) {
                        if (! is_array($item)) {
                            $valid = false;
                            break;
                        }
                        foreach ($default[0] ?? [] as $field => $example) {
                            if (isset($item[$field]) && is_array($item[$field]) !== is_array($example)) {
                                $valid = false;
                            }
                        }
                    }
                }
            } else {
                $valid = $value === null || is_scalar($value);
            }
            if (! $valid) {
                throw ValidationException::withMessages(['customization.'.$key => __('portal.err_cms_format')]);
            }
        }
    }

    public static function getShortcuts(): array
    {
        $all = self::all();
        $enabled = filter_var($all['shortcuts_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return [];
        }
        $raw = $all['shortcuts'] ?? self::defaults()['shortcuts'];
        if (! is_array($raw) || empty($raw)) {
            $raw = self::defaults()['shortcuts'];
        }
        $defaultsList = self::defaults()['shortcuts'];
        $items = [];
        foreach ($raw as $idx => $s) {
            if (! is_array($s)) {
                continue;
            }
            $defS = $defaultsList[$idx] ?? [
                'title' => 'Quick Shortcut #'.($idx + 1),
                'subtitle' => 'Explore section',
                'icon' => 'fa-circle-chevron-right',
                'link' => '/products',
            ];
            $title = trim((string) ($s['title'] ?? ''));
            $subtitle = trim((string) ($s['subtitle'] ?? ''));
            $icon = trim((string) ($s['icon'] ?? ''));
            $link = trim((string) ($s['link'] ?? ''));

            $resolvedLink = $link !== '' ? $link : ($defS['link'] ?? '/products');
            if (self::publicHref($resolvedLink) === null) {
                continue;
            }
            $items[] = [
                'title' => $title !== '' ? $title : ($defS['title'] ?? 'Quick Link'),
                'subtitle' => $subtitle !== '' ? $subtitle : ($defS['subtitle'] ?? ''),
                'icon' => $icon !== '' ? $icon : ($defS['icon'] ?? 'fa-circle-chevron-right'),
                'link' => $resolvedLink,
            ];
        }

        return $items;
    }

    public static function getCampaignCards(): array
    {
        $customization = self::all();
        $cards = $customization['campaign_cards'] ?? [];
        if (! is_array($cards) || empty($cards)) {
            $cards = self::defaults()['campaign_cards'];
        }

        $defaultCards = self::defaults()['campaign_cards'];
        $items = [];
        foreach ($cards as $idx => $card) {
            if (! is_array($card)) {
                continue;
            }

            $defCard = $defaultCards[$idx] ?? [
                'type' => 'bundle',
                'tag' => 'Special Offer',
                'title' => 'Promotional Package #'.($idx + 1),
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

            $type = trim((string) ($card['type'] ?? ($defCard['type'] ?? 'bundle')));
            if ($type !== 'single' && $type !== 'bundle') {
                $type = ! empty($card['product_id']) ? 'single' : 'bundle';
            }

            $title = trim((string) ($card['title'] ?? ''));
            $price = trim((string) ($card['price'] ?? ''));
            $mrp = trim((string) ($card['mrp'] ?? ''));
            $save = trim((string) ($card['save'] ?? ''));
            $tag = trim((string) ($card['tag'] ?? ''));
            $desc = trim((string) ($card['desc'] ?? ''));
            $feat1 = trim((string) ($card['feat1'] ?? ''));
            $feat2 = trim((string) ($card['feat2'] ?? ''));
            $btn = trim((string) ($card['btn'] ?? ''));
            $link = trim((string) ($card['link'] ?? ''));
            $image = trim((string) ($card['image'] ?? ''));
            $productId = trim((string) ($card['product_id'] ?? ''));
            $highlight = filter_var($card['highlight'] ?? ($idx === 0), FILTER_VALIDATE_BOOLEAN);

            $promoDiscountPct = isset($card['promo_discount_pct']) && $card['promo_discount_pct'] !== ''
                ? (float) $card['promo_discount_pct']
                : null;
            $bundleItems = is_array($card['bundle_items'] ?? null) ? $card['bundle_items'] : [];
            $bundleDiscountMode = trim((string) ($card['bundle_discount_mode'] ?? 'total'));
            $bundleDiscountPct = isset($card['bundle_discount_pct']) && $card['bundle_discount_pct'] !== ''
                ? (float) $card['bundle_discount_pct']
                : null;

            // Mode A: Single Product Logic
            if ($type === 'single' && $productId !== '') {
                $product = Product::find($productId);
                if ($product) {
                    $numMrp = (float) $product->mrp;
                    if ($title === '') {
                        $title = $product->name;
                    }
                    if ($desc === '') {
                        $desc = $product->description ?: '';
                    }
                    if ($image === '') {
                        $image = $product->media()['image_url'] ?? '';
                    }
                    if ($link === '') {
                        $link = route('products.show', $product->id);
                    }
                    if ($btn === '') {
                        $btn = 'Order Special Offer';
                    }

                    if ($promoDiscountPct !== null && $promoDiscountPct > 0) {
                        $calcPrice = round($numMrp * (1 - ($promoDiscountPct / 100)), 2);
                        $calcSave = round($numMrp - $calcPrice, 2);
                        if ($price === '') {
                            $price = number_format($calcPrice).' Tk';
                        }
                        if ($mrp === '') {
                            $mrp = number_format($numMrp).' Tk';
                        }
                        if ($save === '') {
                            $save = 'Save '.number_format($calcSave)." Tk ({$promoDiscountPct}% OFF)";
                        }
                        if ($tag === '') {
                            $tag = "⚡ {$promoDiscountPct}% Special Offer";
                        }
                    } else {
                        if ($mrp === '') {
                            $mrp = number_format($numMrp).' Tk';
                        }
                    }
                }
            }

            // Mode B: Group Package Bundle Logic
            if ($type === 'bundle' && ! empty($bundleItems)) {
                $totalBundleMrp = 0;
                $resolvedItems = [];
                foreach ($bundleItems as $bItem) {
                    $bProdId = $bItem['product_id'] ?? null;
                    $bProd = $bProdId ? Product::find($bProdId) : null;
                    $itemMrp = $bProd ? (float) $bProd->mrp : (float) ($bItem['mrp'] ?? 0);
                    $itemName = $bProd ? $bProd->name : ($bItem['name'] ?? 'Bundle Item');
                    $itemSku = $bProd ? $bProd->sku : ($bItem['sku'] ?? '');
                    $itemCustomPct = (float) ($bItem['custom_discount_pct'] ?? 0);
                    $itemPrice = $itemCustomPct > 0
                        ? round($itemMrp * (1 - ($itemCustomPct / 100)), 2)
                        : (float) ($bItem['item_price'] ?? $itemMrp);

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
                    if ($mrp === '') {
                        $mrp = number_format($totalBundleMrp).' Tk';
                    }
                    if ($price === '') {
                        $price = number_format($totalBundlePrice).' Tk';
                    }
                    if ($save === '') {
                        $save = $totalBundleSavings > 0
                            ? ('Save '.number_format($totalBundleSavings).' Tk'.($effPct > 0 ? " ({$effPct}% OFF)" : ''))
                            : '';
                    }
                }
                if ($btn === '') {
                    $btn = 'View Package';
                }
                if ($tag === '') {
                    $tag = 'Featured Package';
                }
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
                $numMrp = (float) str_replace(',', '', $cleanMrp);
                $numPrice = (float) str_replace(',', '', $cleanPrice);
                if ($numMrp > $numPrice) {
                    $save = 'Save ৳ '.number_format($numMrp - $numPrice);
                }
            }
            if ($save === '') {
                $save = $defCard['save'] ?? '';
            }

            $items[] = [
                'id' => $card['id'] ?? ('card-'.($idx + 1)),
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
        if (! $enabled) {
            return null;
        }

        $cards = self::getCampaignCards();

        foreach ($cards as $idx => $card) {
            $configuredId = trim((string) ($card['product_id'] ?? ''));
            $link = trim((string) ($card['link'] ?? ''));
            $title = trim((string) ($card['title'] ?? ''));

            $isMatch = false;

            // 1. Exact product ID match
            if ($configuredId !== '' && $configuredId === (string) $productId) {
                $isMatch = true;
            }
            // 2. URL route match (e.g. /products/9 or /product/9)
            elseif ($link !== '' && preg_match('#/products?/('.preg_quote((string) $productId, '#').')(?:/|\?|\#|$)#i', $link)) {
                $isMatch = true;
            }
            // 3. Exact Title match if product name is provided
            elseif ($productName !== '' && $title !== '' && strcasecmp(trim($productName), $title) === 0) {
                $isMatch = true;
            }

            if ($isMatch) {
                $priceRaw = trim((string) ($card['price'] ?? ''));
                $mrpRaw = trim((string) ($card['mrp'] ?? ''));
                $saveRaw = trim((string) ($card['save'] ?? ''));

                $cleanPrice = trim(preg_replace('/[^\d,.]/', '', $priceRaw));
                $cleanMrp = trim(preg_replace('/[^\d,.]/', '', $mrpRaw));

                $computedSave = $saveRaw;
                if ($computedSave === '' && $cleanMrp !== '' && $cleanPrice !== '') {
                    $numMrp = (float) str_replace(',', '', $cleanMrp);
                    $numPrice = (float) str_replace(',', '', $cleanPrice);
                    if ($numMrp > $numPrice) {
                        $diff = $numMrp - $numPrice;
                        $computedSave = 'Save ৳ '.number_format($diff);
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
                    'link' => $link ?: '/products/'.$productId,
                    'highlight' => filter_var($card['highlight'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
        }

        return null;
    }

    public static function getTrustPillars(): array
    {
        $all = self::all();
        $enabled = filter_var($all['trust_pillars_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return [];
        }
        $raw = $all['trust_pillars'] ?? self::defaults()['trust_pillars'];
        if (! is_array($raw) || empty($raw)) {
            $raw = self::defaults()['trust_pillars'];
        }

        $items = [];
        foreach ($raw as $pillar) {
            if (! is_array($pillar)) {
                continue;
            }
            $items[] = [
                'icon' => trim((string) ($pillar['icon'] ?? 'fa-leaf')),
                'title' => trim((string) ($pillar['title'] ?? '')),
                'subtitle' => trim((string) ($pillar['subtitle'] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * Approved reviews for the public site, with the role and place words in
     * the visitor's language (the cache holds the saved words only).
     *
     * @return list<array<string, mixed>>
     */
    public static function getTestimonials(): array
    {
        $rows = Cache::remember(self::TestimonialsCacheKey, self::TestimonialsCacheTtlSeconds, function (): array {
            return self::buildTestimonials();
        });

        return array_map(static fn (array $row): array => [
            ...$row,
            'role' => TestimonialLabels::role($row['role'] ?? null, $row['source'] ?? null),
            'location' => TestimonialLabels::location($row['location'] ?? null),
        ], $rows);
    }

    /**
     * Whether the owner keeps the review section switched on.
     */
    public static function testimonialsEnabled(?array $customization = null): bool
    {
        $all = $customization ?? self::all();

        return filter_var($all['testimonials_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The Managing Director's message in the visitor's language, or null when
     * the owner has switched it off. Bangla visitors see the Bangla boxes when
     * they are filled; otherwise the same rule as other website text applies.
     *
     * @param  array<string, mixed>|null  $customization
     * @return array{heading: string, name: string, title: string, company: string, paragraphs: list<string>, image: string, image_alt: string}|null
     */
    public static function managingDirectorMessage(?array $customization = null): ?array
    {
        $all = $customization ?? self::all();
        if (! filter_var($all['md_message_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $text = static fn (string $key): string => SiteChrome::ownerText(
            $key,
            isset($all[$key]) ? (string) $all[$key] : null,
            isset($all[$key.'_bn']) ? (string) $all[$key.'_bn'] : null,
        );

        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/u', $text('md_message_body')) ?: []),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));
        if ($paragraphs === []) {
            return null;
        }

        $image = trim((string) ($all['md_message_image'] ?? ''));
        $name = $text('md_message_name');
        $title = $text('md_message_title');
        $company = $text('md_message_company');

        return [
            'heading' => $text('md_message_heading'),
            'name' => $name,
            'title' => $title,
            'company' => $company,
            'paragraphs' => $paragraphs,
            'image' => $image !== '' ? $image : (string) self::defaults()['md_message_image'],
            'image_alt' => (string) __('site.md_message_photo_alt', ['name' => $name, 'title' => $title, 'company' => $company]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function buildTestimonials(): array
    {
        if (! self::testimonialsEnabled()) {
            return [];
        }

        return SiteFeedback::query()
            ->where('status', 'approved')
            ->orderByDesc('moderated_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->map(fn (SiteFeedback $row): array => [
                'name' => $row->name,
                'role' => $row->role,
                'location' => $row->location,
                'rating' => max(1, min(5, (int) $row->rating)),
                'text' => $row->message,
                'avatar' => '',
                'source' => $row->source,
            ])
            ->all();
    }

    public static function getVideoGallery(): array
    {
        $all = self::all();
        $enabled = filter_var($all['video_gallery_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return [];
        }
        $raw = $all['video_gallery_items'] ?? self::defaults()['video_gallery_items'];
        if (! is_array($raw) || empty($raw)) {
            $raw = self::defaults()['video_gallery_items'];
        }

        $items = [];
        foreach ($raw as $v) {
            if (! is_array($v)) {
                continue;
            }
            $videoUrl = trim((string) ($v['video_url'] ?? ''));
            $embedUrl = trim((string) ($v['video_embed_url'] ?? ''));
            // Corporate site: only publish gallery cards that have a real media source.
            if ($videoUrl === '' && $embedUrl === '') {
                continue;
            }
            $items[] = [
                'title' => trim((string) ($v['title'] ?? '')),
                'subtitle' => trim((string) ($v['subtitle'] ?? '')),
                'video_url' => $videoUrl,
                'video_embed_url' => $embedUrl,
                'thumbnail' => trim((string) ($v['thumbnail'] ?? '')),
            ];
        }

        return $items;
    }

    public static function getFeaturedCategories(): array
    {
        $customization = self::all();
        $raw = $customization['featured_categories_items'] ?? self::defaults()['featured_categories_items'];
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $name = $parts[0] ?? '';
            $icon = $parts[1] ?? 'fa-tag';
            $link = $parts[2] ?? '';
            if (! SafeSiteUrl::isSafeLink($link)) {
                $link = '/products?category='.urlencode($name);
            }
            $badge = $parts[3] ?? '';
            if ($name !== '') {
                $isImage = SafeSiteUrl::isSafeImage($icon) && ((bool) preg_match('/\.(png|jpe?g|svg|webp|gif)(\?.*)?$/i', $icon)
                    || str_starts_with($icon, '/')
                    || str_starts_with($icon, 'http://')
                    || str_starts_with($icon, 'https://'));

                $resolvedIcon = ! $isImage ? ($icon ?: 'fa-tag') : 'fa-tag';

                $items[] = [
                    'name' => $name,
                    'is_image' => $isImage,
                    'image' => $isImage ? $icon : '',
                    'icon' => $resolvedIcon,
                    // Public homepage always renders CategoryAutoIcon when no image is uploaded.
                    'use_auto_icon' => ! $isImage,
                    'auto_icon' => CategoryAutoIcon::forCategory($name),
                    'link' => $link ?: ('/products?category='.urlencode($name)),
                    'badge' => $badge,
                ];
            }
        }

        if ($items === []) {
            return array_map(
                static fn (string $name): array => [
                    'name' => $name,
                    'is_image' => false,
                    'image' => '',
                    'icon' => 'fa-tag',
                    'use_auto_icon' => true,
                    'auto_icon' => CategoryAutoIcon::forCategory($name),
                    'link' => '/products?category='.urlencode($name),
                    'badge' => '',
                ],
                self::catalogCategoryNames()
            );
        }

        return $items;
    }

    /**
     * Popular category chips in the mobile search overlay.
     * Prefer the dedicated admin list; otherwise Featured Categories; otherwise active catalog categories.
     *
     * @return list<array{name: string, link: string}>
     */
    public static function getMobileSearchCategories(): array
    {
        $customization = self::all();

        if (($customization['mobile_search_categories_enabled'] ?? '1') === '0') {
            return [];
        }

        $limit = (int) ($customization['mobile_search_categories_limit'] ?? 6);
        if ($limit < 1) {
            $limit = 6;
        }
        if ($limit > 24) {
            $limit = 24;
        }

        $raw = trim((string) ($customization['mobile_search_categories_items'] ?? ''));
        if ($raw !== '') {
            $items = [];
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line));
                $name = $parts[0] ?? '';
                if ($name === '') {
                    continue;
                }
                $link = $parts[1] ?? '';
                if (! str_starts_with($link, '/') || str_starts_with($link, '//') || ! SafeSiteUrl::isSafeLink($link)) {
                    $link = '/products?category='.urlencode($name);
                }
                $items[] = [
                    'name' => $name,
                    'link' => $link,
                ];
            }

            return array_slice($items, 0, $limit);
        }

        $featured = self::getFeaturedCategories();
        if ($featured !== []) {
            return array_slice(array_map(
                static fn (array $cat): array => [
                    'name' => (string) ($cat['name'] ?? ''),
                    'link' => (string) ($cat['link'] ?? ('/products?category='.urlencode((string) ($cat['name'] ?? '')))),
                ],
                $featured
            ), 0, $limit);
        }

        return array_map(
            static fn (string $name): array => [
                'name' => $name,
                'link' => '/products?category='.urlencode($name),
            ],
            array_slice(self::catalogCategoryNames(), 0, $limit)
        );
    }

    public static function mobileSearchCategoriesHeading(): string
    {
        $custom = trim((string) (self::all()['mobile_search_categories_heading'] ?? ''));

        return $custom !== '' ? $custom : (string) __('site.search_popular_categories');
    }

    /**
     * Active catalog category names from CMS tables (source of truth).
     * Falls back to the public products cache / product strings only when CMS has no rows
     * (migrate-friendly fallback for public nav chips).
     *
     * @return list<string>
     */
    private static function catalogCategoryNames(): array
    {
        $cms = app(CatalogTaxonomy::class)->activeCategoryNames();
        if ($cms !== []) {
            return $cms;
        }

        $cached = Cache::get('public:product_categories:v2');
        if (is_array($cached)) {
            return array_values(array_filter(array_map(
                static fn (mixed $name): string => trim((string) $name),
                $cached
            )));
        }

        return Product::where('active', true)->where('category', '!=', '')
            ->orderBy('category')->distinct()->pluck('category')
            ->all();
    }

    public static function getNavCategories(): array
    {
        $customization = self::all();
        $raw = $customization['nav_categories'] ?? null;
        $cats = is_array($raw)
            ? array_filter(array_map('trim', $raw))
            : array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $raw)));
        if (empty($cats)) {
            return self::catalogCategoryNames();
        }

        return array_values($cats);
    }

    /**
     * Category names for product editors and filters.
     * CMS `product_categories` is the source of truth; empty CMS yields an empty list
     * (no hardcoded defaults).
     *
     * @return list<string>
     */
    public static function getConfiguredCategoryNames(): array
    {
        $cms = app(CatalogTaxonomy::class)->activeCategoryNames();
        if ($cms === []) {
            return [];
        }

        $featured = array_column(self::getFeaturedCategories(), 'name');
        $nav = self::getNavCategories();
        $all = array_values(array_unique(array_filter(array_merge($cms, $featured, $nav))));
        natcasesort($all);

        return array_values($all);
    }

    /**
     * Subcategory names for product editors.
     * CMS `product_subcategories` is the source of truth. Hardcoded dosage-form defaults
     * are no longer used. Empty CMS yields an empty list.
     *
     * @return list<string>
     */
    public static function getConfiguredSubCategories(?string $category = null): array
    {
        return app(CatalogTaxonomy::class)->activeSubcategoryNames($category);
    }

    public static function getFeaturedProducts(): array
    {
        $customization = self::all();
        $enabled = filter_var($customization['featured_products_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return [];
        }

        $mode = $customization['featured_products_mode'] ?? 'auto';
        $items = [];

        if ($mode === 'curated' && ! empty($customization['featured_products_items'])) {
            $curatedConfigs = collect($customization['featured_products_items']);
            $productIds = $curatedConfigs->pluck('product_id')->filter()->unique()->toArray();

            if (! empty($productIds)) {
                $products = Product::where('active', true)
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
            $products = Product::where('active', true)
                ->orderByDesc('featured')
                ->orderBy('name')
                ->limit(20)
                ->get();
            foreach ($products as $product) {
                $items[] = self::formatPublicProductCard($product);
            }
        }

        return $items;
    }

    public static function formatPublicProductCard(Product $p, array $config = []): array
    {
        $stockStatus = $p->stockStatus();
        $stockBadge = match ($stockStatus) {
            'out_of_stock' => 'Out of Stock',
            'low_stock' => 'Low Stock',
            default => '',
        };

        $marketingBadge = trim((string) ($config['marketing_badge'] ?? ''));
        if ($marketingBadge === '' && $p->featured) {
            $marketingBadge = 'Featured';
        }

        $media = $p->media();
        $name = ! empty($config['override_title']) ? $config['override_title'] : $p->name;

        return [
            'id' => $p->id,
            'name' => $name,
            'sku' => $p->sku,
            'category' => $p->category ?: 'General',
            'category_slug' => Str::slug($p->category ?: 'general'),
            'description' => $p->description,
            'unit' => $p->unit ?: 'pack',
            'stock_status' => $stockStatus,
            'stock_badge' => $stockBadge,
            'price' => $p->mrp,
            'mrp' => $p->mrp,
            'discount_pct' => 0,
            'savings_text' => '',
            'marketing_badge' => $marketingBadge,
            'campaign' => null,
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
            $cat = trim((string) ($p['category'] ?? ''));
            if ($cat === '') {
                continue;
            }
            if (! isset($categories[$cat])) {
                $categories[$cat] = [
                    'name' => $cat,
                    'slug' => Str::slug($cat),
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
        self::forgetCache();
    }
}

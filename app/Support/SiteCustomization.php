<?php

namespace App\Support;

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

    private const CacheTtlSeconds = 300;

    /** @var array<string, mixed>|null */
    private static ?array $requestMemo = null;

    public static function defaults(): array
    {
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
            'company_intro_body' => 'Explore our company, our product range and the people connecting our products with communities across Bangladesh.',
            'public_products_subtitle' => 'Discover our product range and connect with an authorized dealer.',
            'nav_categories' => '',
            // Mobile search overlay — Popular Categories chips (admin-managed)
            'mobile_search_categories_enabled' => '1',
            'mobile_search_categories_heading' => '',
            'mobile_search_categories_items' => '',
            'mobile_search_categories_limit' => '6',

            // Announcement & Ticker
            'announcement_text' => 'For product availability and dealership enquiries, please contact the company.',

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
                ['title' => 'Become a Partner', 'subtitle' => 'Dealer & sub-dealer programme', 'icon' => 'fa-handshake', 'link' => '/partnership'],
                ['title' => 'Our Services', 'subtitle' => 'Natural Health Care and Physiotherapy Center', 'icon' => 'fa-hand-holding-heart', 'link' => '/services'],
                ['title' => 'Contact Us', 'subtitle' => 'Support & Inquiries', 'icon' => 'fa-headset', 'link' => '/contact'],
                ['title' => 'Our Network', 'subtitle' => 'Dealer Directory', 'icon' => 'fa-diagram-project', 'link' => '/dealers'],
            ],

            // Campaign & Special Offers
            'campaign_section_enabled' => '0',
            'campaign_badge' => 'SPECIAL CAMPAIGN & OFFERS',
            'campaign_title' => 'Special Discounts & Promotional Offers',
            'campaign_subtitle' => 'Explore limited-time wellness packages, bulk entrepreneur offers and seasonal healthcare discounts.',
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
            'dealer_banner_subtitle' => 'Connect with an authorized local dealer for product information and purchase enquiries.',
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

            // Core Brand Promises & Trust Pillars (Toggleable by Admin)
            'trust_pillars_enabled' => '1',
            'trust_pillars_title' => 'Our Core Brand Commitments',
            'trust_pillars' => [
                ['icon' => 'fa-leaf', 'title' => '100% Authentic Botanical Herbs', 'subtitle' => 'Pure natural unani formulations certified for potency and safety.'],
                ['icon' => 'fa-certificate', 'title' => 'GMP Standard Quality', 'subtitle' => 'Manufactured under modern hygienic pharmaceutical guidelines.'],
                ['icon' => 'fa-truck-fast', 'title' => 'Nationwide Partner Network', 'subtitle' => 'Distributed through verified authorized dealers across 64 districts.'],
                ['icon' => 'fa-user-doctor', 'title' => 'Dedicated Health Support', 'subtitle' => 'Expert consultation and product guidance for authentic holistic care.'],
            ],

            // Customer & Partner Testimonials (Toggleable by Admin)
            'testimonials_section_enabled' => '1',
            'testimonials_title' => 'Community Trust & Feedback',
            'testimonials_subtitle' => 'Real clinical feedback and experiences from verified partners, dealers and families across Bangladesh.',
            'testimonials' => [
                ['name' => 'Tasmin Ahmed', 'role' => 'Wellness Client', 'location' => 'Dhaka', 'rating' => 5, 'text' => 'Madina botanical products have become an indispensable part of my family routine. 100% natural and remarkably effective!'],
                ['name' => 'Md. Farhad Hossain', 'role' => 'Authorized Dealer Partner', 'location' => 'Chittagong', 'rating' => 5, 'text' => 'The product consistency and genuine herbal composition make it widely respected and trusted among our local clients.'],
                ['name' => 'Nusrat Jahan', 'role' => 'Verified Client', 'location' => 'Sylhet', 'rating' => 5, 'text' => 'The natural hair vitality shampoo and botanical care lotion gave noticeable results without any chemical irritation.'],
            ],

            // Brand Video Gallery Showcase (Toggleable by Admin)
            // Off by default until real company videos are configured — avoids placeholder embeds on a corporate site.
            'video_gallery_enabled' => '0',
            'video_gallery_title' => 'Brand & Product Stories',
            'video_gallery_subtitle' => 'Company stories, herbal process highlights and authentic product demonstrations.',
            'video_gallery_items' => [
                ['title' => 'Herbal Care Guidance', 'subtitle' => 'Application guidance for Madina botanical care', 'video_url' => '', 'video_embed_url' => '', 'thumbnail' => '/branding/madina-top-skincare.jpg'],
                ['title' => 'Natural Formulation Story', 'subtitle' => 'How Madina prepares botanical formulations', 'video_url' => '', 'video_embed_url' => '', 'thumbnail' => '/branding/madina-side-banner-1.jpg'],
            ],

            // Homepage About
            'about_headline' => 'Natural Food, Medicine & Wellness Product Shop in Bangladesh',
            'about_lead' => 'Licensed distributor of Holistic Nutracare herbal and nutrition products. Explore the collection and contact Madina Natural Health Care for product information and partnership enquiries.',
            'about_summary_title' => 'Read More',
            'about_summary_body' => 'Our partner workspace brings product requests, deliveries, verified manual payments and company support together. Dealer accounts are created by authorized company staff.',

            // About Us Page (/about)
            'about_hero_title' => 'About Madina Natural Health Care',
            'about_hero_lead' => 'Madina Natural Health Care is a licensed single-owner distributor (Trade License 2026-00563, Pirganj Municipality) supplying chemical-free herbal and Holistic Nutracare nutrition products through an authorized dealer network across Bangladesh.',
            'about_history_title' => 'Company History',
            'about_history' => 'Madina Natural Health Care (Owner: Md Anowar Hossain) is a licensed distributor based on Thana Road, Pirganj, Rangpur. We market Holistic Nutracare nutrition and herbal products made from natural raw materials with modern process standards, connecting the company with authorized dealers and sub-dealers nationwide.',
            'about_mission_title' => 'Our Mission',
            'about_mission' => 'To protect public health by ensuring adulterant-free food and popularizing the correct use of herbal products, while empowering local entrepreneurship through a transparent, staff-verified dealer network.',
            'about_vision_title' => 'Our Vision',
            'about_vision' => 'To deliver chemical-free natural herbal and food products to every home in Bangladesh, fostering healthier families and resilient partner livelihoods in every district.',
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
            'contact_company_desc' => 'Madina Natural Health Care (Owner: Md Anowar Hossain). Head office: Thana Road, Pirganj 5470, Rangpur. Factory/warehouse: Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka. Hotline: 01730532966 · 01723731877 · 01727612379.',
            'contact_phone_title' => 'Customer Care & Hotline',
            'contact_phone' => '+8801730532966',
            'contact_phone_hours' => 'Hotline also: 01723731877 · 01727612379',
            'contact_email_title' => 'Official Email',
            'contact_email' => 'holisticnutracare@gmail.com',
            'contact_topic1_title' => 'Dealer Enquiries',
            'contact_topic1_desc' => 'Dealer accounts are created by company staff after review.',
            'contact_topic2_title' => 'Products & Support',
            'contact_topic2_desc' => 'Ask about availability, orders and your local dealer.',
            'contact_topic3_title' => 'Head Office',
            'contact_social_title' => 'Connect With Us Online',
            'office_address' => 'Thana Road, Pirganj 5470, Rangpur, Bangladesh',
            'contact_form_title' => 'Send an Enquiry',
            'map_title' => 'Office & Healthcare Center Location',
            'map_subtitle' => 'Visit Madina Natural Health Care at our licensed Pirganj head office or navigate with map directions.',
            'map_facility' => 'Madina Natural Health Care — Thana Road, Pirganj',
            'map_directions_text' => 'Open Map Directions',

            // Dealer Directory Page (/dealers)
            'directory_title' => 'Dealer Directory',
            'directory_subtitle' => 'Find a company-approved dealer point or search nearest dealers in your area.',
            'directory_gps_btn' => 'Find nearest dealers',
            'directory_empty_title' => 'No published dealers in this area yet.',
            'directory_empty_desc' => 'Contact us for help finding a local dealer.',

            // Products Catalog Page (/products)
            'catalog_categories_title' => 'Categories',
            'catalog_all_products_label' => 'All Products',
            'catalog_price_title' => 'MRP Range',
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
            'footer_email' => 'holisticnutracare@gmail.com',
            'footer_address' => 'Thana Road, Pirganj 5470, Rangpur',
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
            'cart_dialog_btn' => 'Sign in to request products',
            'cart_dialog_notice' => 'Product requests and payments are reviewed by company staff.',
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

            // Partnership / Become a Dealer (/partnership)
            'partnership_hero_title' => 'Become an Authorized Madina Partner',
            'partnership_hero_lead' => 'Join our nationwide dealer and sub-dealer network. Grow a trusted wellness business with company-backed products, training and territory support across Bangladesh.',
            'partnership_why_title' => 'Why partner with Madina',
            'partnership_benefit1_title' => 'Trusted product range',
            'partnership_benefit1_desc' => 'Offer authentic natural health, unani and wellness products with clear quality standards.',
            'partnership_benefit2_title' => 'Structured onboarding',
            'partnership_benefit2_desc' => 'Company staff review applications, create accounts and guide you through territory setup.',
            'partnership_benefit3_title' => 'Partner workspace',
            'partnership_benefit3_desc' => 'Use dedicated dealer and sub-dealer portals for orders, deliveries, dues and earnings visibility.',
            'partnership_benefit4_title' => 'Local market support',
            'partnership_benefit4_desc' => 'Connect communities with nearby authorized points while representing a recognized wellness brand.',
            'partnership_steps_title' => 'How to apply',
            'partnership_step1_title' => 'Share your details',
            'partnership_step1_desc' => 'Send a dealer enquiry with your district, experience and preferred partnership type.',
            'partnership_step2_title' => 'Company review',
            'partnership_step2_desc' => 'Our team reviews coverage, documents and fit for the Madina network.',
            'partnership_step3_title' => 'Account activation',
            'partnership_step3_desc' => 'Approved partners receive secure portal access from company staff — no self-registration passwords.',
            'partnership_cta_title' => 'Ready to grow with Madina?',
            'partnership_cta_body' => 'Tell us about your location and goals. Approved partners are invited by the company after review.',
            'partnership_cta_btn' => 'Apply for Dealership',
            'partnership_cta_link' => '#partner-apply',

            // Services (/services)
            'services_hero_title' => 'Natural Health Care & Physiotherapy Services',
            'services_hero_lead' => 'Madina supports community wellness through authentic products and care-focused services at our Natural Health Care and Physiotherapy Center.',
            'services_card1_title' => 'Natural health consultation',
            'services_card1_desc' => 'Guidance on suitable wellness products and lifestyle support for everyday family health needs.',
            'services_card2_title' => 'Physiotherapy center',
            'services_card2_desc' => 'Care-oriented physiotherapy support at our registered facility for mobility and recovery needs.',
            'services_card3_title' => 'Partner product access',
            'services_card3_desc' => 'Find authorized dealers for product availability, local advice and purchase enquiries nationwide.',
            'services_cta_btn' => 'Contact the Center',
            'services_cta_link' => '/contact',

            // Quality & Authenticity (/quality)
            'quality_hero_title' => 'Quality & Authenticity Promise',
            'quality_hero_lead' => 'We focus on reliable sourcing, clear product information and an authorized distribution network so customers receive genuine Madina wellness products.',
            'quality_pillar1_title' => 'Verified product standards',
            'quality_pillar1_desc' => 'Products are selected and presented with attention to safety, labeling clarity and consistent quality expectations.',
            'quality_pillar2_title' => 'Authorized partners only',
            'quality_pillar2_desc' => 'Published dealers are company-approved points — helping communities avoid unofficial or counterfeit channels.',
            'quality_pillar3_title' => 'Audited partner operations',
            'quality_pillar3_desc' => 'Orders, deliveries and manual payment claims follow staff-reviewed workflows with activity trails.',
            'quality_cta_btn' => 'Browse Products',
            'quality_cta_link' => '/products',

            // FAQ (/faq)
            'faq_hero_title' => 'Frequently Asked Questions',
            'faq_hero_lead' => 'Quick answers about products, dealers, partnership and company support.',
            'faq_items' => [
                ['question' => 'Where can I buy Madina products?', 'answer' => 'Purchase through an authorized dealer listed in our Dealer Directory, or contact the company for help finding a nearby partner.'],
                ['question' => 'How do I become a dealer or sub-dealer?', 'answer' => 'Submit a dealer enquiry on the Contact page. Company staff review applications and create approved partner accounts — there is no public self-registration with a shared password.'],
                ['question' => 'Do you offer home delivery from this website?', 'answer' => 'The public website is informational. Partner product requests and deliveries are handled through authorized dealer and sub-dealer workspaces after company review.'],
                ['question' => 'How can I verify an authorized dealer?', 'answer' => 'Use the Dealer Directory to find company-published dealers by division and district, or contact head office for confirmation.'],
                ['question' => 'What should I do for product support or complaints?', 'answer' => 'Use the Contact form with the Support or Complaint topic, or call the published customer care number during service hours.'],
            ],

            // Privacy (/privacy)
            'privacy_hero_title' => 'Privacy Policy & Data Protection',
            'privacy_hero_lead' => 'How Madina Natural Health Care protects information shared through our public website, partner network and digital operations.',
            'privacy_body' => "1. Scope & Commitment\nMadina Natural Health Care is committed to protecting the privacy, confidentiality and integrity of personal and business information. This policy governs how we collect, process, store and protect information collected through our public website, dealer portals, mobile interfaces and communication channels in compliance with applicable laws and Bangladesh Personal Data Protection Ordinance (PDPO) guidelines.\n\n2. Information We Collect\nWe collect only the information necessary to fulfill our business and operational objectives:\n• Public Enquiries: Name, contact phone number, email address, district/division and message content when submitting customer care, product or partnership inquiries.\n• Dealer & Partner Onboarding: Full legal name, business/pharmacy trade name, national identity (NID) or birth registration number, contact phone numbers, email address, physical shop/trade license address, geo-location coordinates and payment credentials.\n• Financial & Operational Transactions: Orders placed, payment claim references, bKash/Nagad/Rocket/bank transaction receipts, credit ledgers, commission records and delivery confirmations.\n• Technical & Audit Logs: Timestamps, IP addresses, browser user-agents and security audit trails recorded strictly for authentication integrity and fraud prevention.\n\n3. Lawful Purpose & Processing\nWe process collected data exclusively for legitimate operational purposes:\n• Verifying, approving and maintaining authorized dealer and sub-dealer accounts.\n• Processing product orders, warehouse picking, dispatching and courier delivery coordination.\n• Reconciling manual payment claims, managing partner ledgers, commissions and authorized withdrawal payouts.\n• Providing customer care, product safety advisories, promotional notices and official corporate circulars.\n• Maintaining tamper-evident audit logs and statutory financial accounting records.\n\n4. Data Sharing & Third Parties\nWe do not sell personal information under any circumstance. We never rent, monetize or trade partner or customer data. Data is shared strictly on a confidential need-to-know basis with:\n• Logistics & Courier Partners: Recipient name, delivery address and contact phone for physical order fulfillment.\n• Authorized Payment Channels: Transaction references and amounts for financial verification with banks and mobile financial services (MFS).\n• Regulatory Authorities: When required by law, court order or legal governmental directives.\nAccess inside company systems is strictly governed by role-based access controls (RBAC) and audited.\n\n5. Data Security & Storage\nWe employ enterprise-grade technical and organizational safeguards:\n• Transport Layer Security (TLS 1.3) encryption for all web and API data transmissions.\n• Bcrypt password hashing and optional Time-based One-Time Password (TOTP) two-factor authentication for partner accounts.\n• Role-based permission segregation ensuring field staff, accountants and managers access only requisite records.\n• Continuous fraud anomaly detection and automated audit logging for sensitive actions.\n\n6. Data Retention & Privacy Export Rights\nPartner account records and financial ledgers are retained in accordance with statutory accounting requirements and company retention schedules (configured retention period for closed records).\nPartners may exercise their Subject Access Rights at any time by downloading a complete JSON copy of their profile and operational metadata via the 'Download My Data' feature in their secure portal settings. Identity credentials (NID) are omitted from exported files to prevent credential leakage.\n\n7. Cookies & Tracking\nOur website uses essential session cookies, security tokens (CSRF) and user interface preference cookies (such as theme and language selection). We do not deploy third-party advertising trackers or behavioral profiling pixels.\n\n8. Policy Updates & Contact\nWe may update this Privacy Policy to reflect operational or regulatory changes. The latest version will always be posted with an updated effective date.\nFor any privacy questions, data access requests or security concerns, contact our Head Office:\n• Office: Thana Road, Pirganj 5470, Rangpur, Bangladesh\n• Customer Care Hotline: +8801730532966 / 01723731877\n• Official Email: holisticnutracare@gmail.com",

            // Terms (/terms)
            'terms_hero_title' => 'Terms of Use & Operating Standards',
            'terms_hero_lead' => 'The legally binding terms and operating principles governing access to the Madina Natural Health Care platform, catalog and partner services.',
            'terms_body' => "1. Acceptance of Terms\nBy accessing or using the Madina Natural Health Care public website, product catalogs, dealer directory or authorized partner portals, you agree to be bound by these Terms of Use and all operating policies referenced herein. If you do not agree to these terms, please discontinue using this website and related services immediately.\n\n2. Permitted Use & Public Catalog\nThis website provides authenticated company information, natural wellness product showcases, educational resources and an authorized dealer directory. While product information, suggested retail prices (MRP) and specifications are presented for public guidance, all commercial purchases, dealer discounts and wholesale orders are conducted strictly through authorized company channels and verified dealer accounts.\n\n3. Partner Account Governance & Review\nDealer and sub-dealer accounts cannot be registered automatically. All partner accounts are created only by company staff after thorough territory review, documentation verification and administrative approval:\n• Account Credentials: Login access (via phone, email or dealer code) is personal and confidential. Partners are strictly responsible for maintaining credential secrecy and using strong passwords or two-factor authentication (TOTP).\n• Integrity & Representation: Partners may not misrepresent their territory tier, impersonate corporate officers or use unauthorized brand variations.\n• Account Suspension: The company reserves the right to suspend or terminate portal access for violation of operational policies, fraudulent claims or abusive conduct.\n\n4. Commerce, Orders & Payment Claims\nAll commercial ordering across the platform adheres to our structured multi-step verification model:\n• Requests & Order Lines: Orders submitted through dealer portals constitute commercial requests subject to inventory availability and company approval.\n• Payment Claims: Manual payment claims (bKash, Nagad, Rocket, Bank transfers) must reflect genuine transactions with authentic transaction IDs and collection account matching. Submitting false, duplicate or collusive payment claims constitutes financial fraud and triggers immediate account termination and legal remedies.\n• Credit & Ledger: Institutional credit terms and wallet payouts require explicit company approval and adhere strictly to published policy drafts.\n\n5. Intellectual Property & Brand Protection\nAll trademarks, product branding (including Holistic Nutracare distribution marks), logos, graphics, text, photography and website design elements are the exclusive intellectual property of Madina Natural Health Care or its licensors. Unauthorized reproduction, scraping, alteration or commercial reuse without prior written authorization is strictly prohibited.\n\n6. Medical & Wellness Disclaimer\nProducts featured across our catalog include unani medicines, nutritional supplements, dietary aids and herbal wellness items. Information provided on this site is for educational and informational purposes only and does not constitute medical advice, clinical diagnosis or prescription. Consumers should consult qualified medical practitioners or visit our Natural Health Care and Physiotherapy Center for personalized therapeutic guidance.\n\n7. Platform Availability & Disclaimers\nWe endeavor to ensure uninterrupted availability of our web services, catalogs and dealer systems. However, services are provided on an 'as is' and 'as available' basis. The company does not warrant that website features will be entirely error-free or uninterrupted during scheduled maintenance, network updates or telecommunication outages.\n\n8. Governing Law & Dispute Resolution\nThese Terms of Use are governed by and construed in accordance with the laws of the People's Republic of Bangladesh. Any dispute arising out of or relating to website usage, dealership agreements or platform operations shall be resolved through good-faith negotiation, failing which jurisdiction shall lie exclusively with the competent courts in Rangpur, Bangladesh.\n\n9. Contact & Inquiries\nFor questions regarding these Terms of Use, operational policies or partnership agreements, please contact:\n• Head Office: Thana Road, Pirganj 5470, Rangpur, Bangladesh\n• Customer Care & Support: +8801730532966 / 01723731877\n• Official Email: holisticnutracare@gmail.com",

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
        ];
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

    public static function raw(): array
    {
        try {
            $record = DB::table('content_pages')->where('slug', self::SLUG)->first();
            if (! $record || empty($record->body)) {
                return [];
            }
            $stored = json_decode($record->body, true);

            return is_array($stored) ? $stored : [];
        } catch (\Throwable $e) {
            return [];
        }
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

    public static function getTestimonials(): array
    {
        $all = self::all();
        $enabled = filter_var($all['testimonials_section_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return [];
        }

        $approved = SiteFeedback::query()
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

        if ($approved !== []) {
            return $approved;
        }

        $raw = $all['testimonials'] ?? self::defaults()['testimonials'];
        if (! is_array($raw) || empty($raw)) {
            $raw = self::defaults()['testimonials'];
        }

        $items = [];
        foreach ($raw as $t) {
            if (! is_array($t)) {
                continue;
            }
            $items[] = [
                'name' => trim((string) ($t['name'] ?? '')),
                'role' => trim((string) ($t['role'] ?? 'Verified Client')),
                'location' => trim((string) ($t['location'] ?? 'Bangladesh')),
                'rating' => max(1, min(5, (int) ($t['rating'] ?? 5))),
                'text' => trim((string) ($t['text'] ?? '')),
                'avatar' => trim((string) ($t['avatar'] ?? '')),
                'source' => 'default',
            ];
        }

        return $items;
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
            $link = $parts[2] ?? ('/products?category='.urlencode($name));
            $badge = $parts[3] ?? '';
            if ($name !== '') {
                $isImage = (bool) preg_match('/\.(png|jpe?g|svg|webp|gif)(\?.*)?$/i', $icon)
                    || str_starts_with($icon, '/')
                    || str_starts_with($icon, 'http://')
                    || str_starts_with($icon, 'https://');

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
            return Product::where('active', true)->where('category', '!=', '')
                ->orderBy('category')->distinct()->pluck('category')
                ->map(fn (string $name): array => [
                    'name' => $name,
                    'is_image' => false,
                    'image' => '',
                    'icon' => 'fa-tag',
                    'use_auto_icon' => true,
                    'auto_icon' => CategoryAutoIcon::forCategory($name),
                    'link' => '/products?category='.urlencode($name),
                    'badge' => '',
                ])->all();
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
                if ($link === '' || ! str_starts_with($link, '/')) {
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

        return Product::where('active', true)->where('category', '!=', '')
            ->orderBy('category')->distinct()->pluck('category')
            ->take($limit)
            ->map(fn (string $name): array => [
                'name' => $name,
                'link' => '/products?category='.urlencode($name),
            ])->values()->all();
    }

    public static function mobileSearchCategoriesHeading(): string
    {
        $custom = trim((string) (self::all()['mobile_search_categories_heading'] ?? ''));

        return $custom !== '' ? $custom : (string) __('site.search_popular_categories');
    }

    public static function getNavCategories(): array
    {
        $customization = self::all();
        $raw = $customization['nav_categories'] ?? null;
        $cats = is_array($raw)
            ? array_filter(array_map('trim', $raw))
            : array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $raw)));
        if (empty($cats)) {
            return Product::where('active', true)->where('category', '!=', '')
                ->orderBy('category')->distinct()->pluck('category')->all();
        }

        return array_values($cats);
    }

    public static function getConfiguredCategoryNames(): array
    {
        $featured = array_column(self::getFeaturedCategories(), 'name');
        $nav = self::getNavCategories();
        $existing = Product::orderBy('category')->distinct()->pluck('category')->toArray();
        $all = array_values(array_unique(array_filter(array_merge($featured, $nav, $existing))));
        natcasesort($all);

        return array_values($all);
    }

    public static function getConfiguredSubCategories(?string $category = null): array
    {
        $defaults = ['Tablet', 'Capsule', 'Syrup', 'Drop', 'Ointment', 'Oil', 'Powder', 'Cream', 'Tonic', 'Gel'];
        $query = Product::query()->whereNotNull('sub_category')->where('sub_category', '!=', '');
        if ($category) {
            $query->where('category', $category);
        }
        $existing = $query->distinct()->pluck('sub_category')->toArray();
        $all = array_values(array_unique(array_filter(array_merge($defaults, $existing))));
        natcasesort($all);

        return array_values($all);
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

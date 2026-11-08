/** Shapes of the website settings. The keys are the stored setting names, so never rename them. */

export interface Slide {
    image: string;
    link: string;
    alt: string;
}

export interface Shortcut {
    id?: string;
    title: string;
    subtitle: string;
    icon: string;
    link: string;
}

export interface FeaturedProductItem {
    id?: string;
    product_id: string;
    marketing_badge?: string;
    override_title?: string;
}

export interface CampaignBundleItem {
    product_id: number | string;
    name: string;
    sku?: string;
    mrp: number | string;
    custom_discount_pct?: number | string;
    item_price?: number | string;
}

export interface CampaignCardItem {
    id?: string;
    type?: 'single' | 'bundle';
    product_id?: string;
    tag?: string;
    title?: string;
    desc?: string;
    feat1?: string;
    feat2?: string;
    price?: string;
    mrp?: string;
    save?: string;
    btn?: string;
    link?: string;
    image?: string;
    highlight?: string | boolean;
    promo_discount_pct?: number | string;
    bundle_discount_mode?: 'total' | 'itemized';
    bundle_discount_pct?: number | string;
    bundle_items?: CampaignBundleItem[];
}

export interface CustomizationData {
    // Top Bar & Header
    top_bar_text?: string;
    search_placeholder?: string;
    header_about_title?: string;
    header_about_subtitle?: string;
    header_contact_title?: string;
    header_contact_subtitle?: string;
    header_offers_title?: string;
    header_offers_subtitle?: string;
    header_network_title?: string;
    header_projects_subtitle?: string;
    header_account_title?: string;
    header_account_subtitle?: string;
    header_catalog_title?: string;
    nav_categories?: string;
    mobile_search_categories_enabled?: string;
    mobile_search_categories_heading?: string;
    mobile_search_categories_items?: string;
    mobile_search_categories_limit?: string;

    // Announcement
    announcement_text?: string;

    // Slides & Banners
    slides?: Slide[];
    side_banner_1_slides?: Slide[];
    side_banner_2_slides?: Slide[];
    side_banner_1_image?: string;
    side_banner_1_link?: string;
    side_banner_1_alt?: string;
    side_banner_2_image?: string;
    side_banner_2_link?: string;
    side_banner_2_alt?: string;

    // Shortcuts
    shortcuts_section_enabled?: string;
    shortcuts?: Shortcut[];

    // Campaign & Special Offers
    campaign_section_enabled?: string;
    campaign_badge?: string;
    campaign_title?: string;
    campaign_subtitle?: string;
    campaign_cards?: CampaignCardItem[];
    campaign_card1_product_id?: string;
    campaign_card1_tag?: string;
    campaign_card1_title?: string;
    campaign_card1_desc?: string;
    campaign_card1_feat1?: string;
    campaign_card1_feat2?: string;
    campaign_card1_price?: string;
    campaign_card1_mrp?: string;
    campaign_card1_save?: string;
    campaign_card1_btn?: string;
    campaign_card1_link?: string;
    campaign_card1_image?: string;
    campaign_card2_product_id?: string;
    campaign_card2_tag?: string;
    campaign_card2_title?: string;
    campaign_card2_desc?: string;
    campaign_card2_feat1?: string;
    campaign_card2_feat2?: string;
    campaign_card2_price?: string;
    campaign_card2_mrp?: string;
    campaign_card2_save?: string;
    campaign_card2_btn?: string;
    campaign_card2_link?: string;
    campaign_card2_image?: string;

    // Featured Categories & Catalog
    featured_categories_enabled?: string;
    featured_categories_title?: string;
    featured_categories_subtitle?: string;
    featured_categories_items?: string;
    dealer_banner_title?: string;
    dealer_banner_subtitle?: string;
    dealer_banner_btn_text?: string;
    featured_products_enabled?: string;
    featured_products_badge?: string;
    featured_products_title?: string;
    featured_products_subtitle?: string;
    featured_products_view_all?: string;
    featured_products_view_all_link?: string;
    featured_products_tabs_enabled?: string;
    featured_products_mode?: string;
    featured_products_items?: FeaturedProductItem[];
    products_empty_title?: string;
    catalog_categories_title?: string;
    catalog_all_products_label?: string;
    catalog_price_title?: string;
    catalog_instock_label?: string;
    catalog_apply_filters_btn?: string;
    catalog_clear_filters_btn?: string;
    catalog_sort_by_label?: string;
    catalog_empty_title?: string;
    catalog_empty_desc?: string;
    catalog_empty_btn?: string;

    // Product Card & Detail
    product_in_stock_label?: string;
    product_out_of_stock_label?: string;
    product_add_to_cart_btn?: string;
    product_buy_now_btn?: string;
    product_share_label?: string;
    product_save_btn?: string;
    product_compare_btn?: string;
    product_payment_mode_label?: string;
    product_purchase_note?: string;
    product_video_title?: string;
    product_tab_description?: string;
    product_tab_specification?: string;
    product_medical_note?: string;
    catalog_concern_title?: string;

    // Brand Trust & Showcase (Category খ)
    trust_pillars_enabled?: string;
    trust_pillars_title?: string;
    video_gallery_enabled?: string;
    video_gallery_title?: string;
    video_gallery_subtitle?: string;
    testimonials_section_enabled?: string;
    testimonials_title?: string;
    testimonials_subtitle?: string;
    testimonials_invite_title?: string;
    testimonials_invite_body?: string;
    feedback_page_title?: string;
    feedback_page_lead?: string;

    // Homepage Story
    company_intro_title?: string;
    company_intro_body?: string;
    company_intro_eyebrow?: string;
    home_bismillah_enabled?: string;
    home_bismillah_text?: string;
    product_range_title?: string;
    product_range_sub?: string;
    partner_with_us_title?: string;
    partner_with_us_sub?: string;
    our_services_title?: string;
    our_services_sub?: string;
    invoice_manufacturer?: string;
    invoice_marketed_by?: string;
    invoice_marketed_phones?: string;
    public_products_subtitle?: string;
    about_headline?: string;
    about_lead?: string;
    about_summary_title?: string;
    about_summary_body?: string;

    // About Us Page (/about)
    about_hero_title?: string;
    about_hero_lead?: string;
    about_history_title?: string;
    about_history?: string;
    about_mission_title?: string;
    about_mission?: string;
    about_vision_title?: string;
    about_vision?: string;
    about_objectives_title?: string;
    about_obj1_title?: string;
    about_obj1_desc?: string;
    about_obj2_title?: string;
    about_obj2_desc?: string;
    about_obj3_title?: string;
    about_obj3_desc?: string;
    about_obj4_title?: string;
    about_obj4_desc?: string;
    about_network_title?: string;
    about_network?: string;
    about_btn_products?: string;
    about_btn_dealers?: string;
    about_btn_contact?: string;

    partnership_hero_title?: string;
    partnership_hero_lead?: string;
    partnership_why_title?: string;
    partnership_benefit1_title?: string;
    partnership_benefit1_desc?: string;
    partnership_benefit2_title?: string;
    partnership_benefit2_desc?: string;
    partnership_benefit3_title?: string;
    partnership_benefit3_desc?: string;
    partnership_benefit4_title?: string;
    partnership_benefit4_desc?: string;
    partnership_steps_title?: string;
    partnership_step1_title?: string;
    partnership_step1_desc?: string;
    partnership_step2_title?: string;
    partnership_step2_desc?: string;
    partnership_step3_title?: string;
    partnership_step3_desc?: string;
    partnership_cta_title?: string;
    partnership_cta_body?: string;
    partnership_cta_btn?: string;
    partnership_cta_link?: string;

    services_hero_title?: string;
    services_hero_lead?: string;
    services_card1_title?: string;
    services_card1_desc?: string;
    services_card2_title?: string;
    services_card2_desc?: string;
    services_card3_title?: string;
    services_card3_desc?: string;
    services_cta_btn?: string;
    services_cta_link?: string;

    quality_hero_title?: string;
    quality_hero_lead?: string;
    quality_pillar1_title?: string;
    quality_pillar1_desc?: string;
    quality_pillar2_title?: string;
    quality_pillar2_desc?: string;
    quality_pillar3_title?: string;
    quality_pillar3_desc?: string;
    quality_cta_btn?: string;
    quality_cta_link?: string;

    faq_hero_title?: string;
    faq_hero_lead?: string;
    faq_items?: { question?: string; answer?: string }[];

    privacy_hero_title?: string;
    privacy_hero_lead?: string;
    privacy_body?: string;
    terms_hero_title?: string;
    terms_hero_lead?: string;
    terms_body?: string;
    legal_meta_effective?: string;
    legal_meta_governance?: string;
    legal_meta_location?: string;

    footer_link_services?: string;
    footer_link_services_url?: string;
    footer_link_quality?: string;
    footer_link_quality_url?: string;
    footer_link_faq?: string;
    footer_link_faq_url?: string;
    footer_link_privacy?: string;
    footer_link_privacy_url?: string;
    footer_link_terms?: string;
    footer_link_terms_url?: string;
    footer_link_partnership?: string;
    footer_link_partnership_url?: string;

    page_enabled_about?: string;
    page_enabled_products?: string;
    page_enabled_dealers?: string;
    page_enabled_contact?: string;
    page_enabled_partnership?: string;
    page_enabled_services?: string;
    page_enabled_quality?: string;
    page_enabled_faq?: string;
    page_enabled_privacy?: string;
    page_enabled_terms?: string;
    page_enabled_prescribers?: string;
    // Registered practitioners page (/prescribers)
    prescribers_title?: string;
    prescribers_lead?: string;
    prescribers_emergency_note?: string;
    prescribers_consent_line?: string;

    // Contact Page (/contact)
    contact_title?: string;
    contact_company_title?: string;
    contact_company_desc?: string;
    contact_phone_title?: string;
    contact_phone?: string;
    contact_phone_hours?: string;
    contact_email_title?: string;
    contact_email?: string;
    contact_social_title?: string;
    contact_topic1_title?: string;
    contact_topic1_desc?: string;
    contact_topic2_title?: string;
    contact_topic2_desc?: string;
    contact_topic3_title?: string;
    office_address?: string;
    map_latitude?: string;
    map_longitude?: string;
    contact_form_title?: string;
    map_title?: string;
    map_subtitle?: string;
    map_facility?: string;
    map_directions_text?: string;

    // Dealer Directory Page (/dealers)
    directory_title?: string;
    directory_subtitle?: string;
    directory_gps_btn?: string;
    directory_empty_title?: string;
    directory_empty_desc?: string;

    // Footer & Support
    footer_support_heading?: string;
    footer_about_heading?: string;
    footer_stay_connected_heading?: string;
    footer_support_subtitle?: string;
    footer_support_title?: string;
    footer_support_link?: string;
    footer_support_icon?: string;
    footer_directory_subtitle?: string;
    footer_directory_title?: string;
    footer_directory_link?: string;
    footer_directory_icon?: string;
    footer_phone?: string;
    footer_email?: string;
    footer_address?: string;
    footer_location?: string;

    footer_link_about?: string;
    footer_link_about_url?: string;
    footer_link_vendor?: string;
    footer_link_vendor_url?: string;
    footer_link_orders?: string;
    footer_link_orders_url?: string;
    footer_link_blog?: string;
    footer_link_blog_url?: string;
    footer_link_contact?: string;
    footer_link_contact_url?: string;
    footer_link_dealer_apply?: string;
    footer_link_dealer_apply_url?: string;

    footer_about_title?: string;
    footer_about_desc?: string;
    footer_logo_url?: string;

    // Floating Tools & Dialogs
    floating_compare_label?: string;
    floating_cart_label?: string;
    floating_chat_label?: string;
    cart_dialog_title?: string;
    cart_dialog_btn?: string;
    cart_dialog_notice?: string;
    compare_dialog_title?: string;
    compare_dialog_desc?: string;

    // Social Media & Chat
    social_facebook?: string;
    social_youtube?: string;
    social_linkedin?: string;
    social_instagram?: string;
    social_whatsapp?: string;
    social_twitter?: string;
    social_tiktok?: string;
    social_telegram?: string;
    whatsapp_link?: string;

    // Mobile App Store Badges
    app_google_play_link?: string;
    app_apple_store_link?: string;
    app_badges_enabled?: string;

    // Payment Methods
    payment_methods_enabled?: string;
    payment_methods_image?: string;
    payment_methods_alt?: string;

    // Auth & Partner Portal Login
    auth_login_title?: string;
    auth_login_subtitle?: string;
    auth_dealer_help_text?: string;
    auth_dealer_contact_text?: string;
    partner_help_title?: string;
    partner_help_body?: string;

    // Copyright
    copyright_text?: string;
    copyright_text_bn?: string;
    notice_empty?: string;
    notice_empty_bn?: string;

    // Managing Director's message (/about and the home page)
    md_message_enabled?: string;
    md_message_heading?: string;
    md_message_heading_bn?: string;
    md_message_name?: string;
    md_message_name_bn?: string;
    md_message_title?: string;
    md_message_title_bn?: string;
    md_message_company?: string;
    md_message_company_bn?: string;
    md_message_body?: string;
    md_message_body_bn?: string;
    md_message_image?: string;
}

export type MdMessageBanglaCopy = {
    md_message_heading?: string;
    md_message_name?: string;
    md_message_title?: string;
    md_message_company?: string;
    md_message_body?: string;
};

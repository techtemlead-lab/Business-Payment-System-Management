import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
    SlidersHorizontal,
    Layout,
    Image,
    Sparkles,
    Tag,
    Package,
    BookOpen,
    Share2,
    RotateCcw,
    Save,
    ExternalLink
} from 'lucide-react';

interface Slide {
    image: string;
    link: string;
    alt: string;
}

interface Shortcut {
    title: string;
    subtitle: string;
    icon: string;
    link: string;
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
    header_projects_title?: string;
    header_projects_subtitle?: string;
    header_account_title?: string;
    header_account_subtitle?: string;
    header_shop_title?: string;
    nav_categories?: string;

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
    shortcuts?: Shortcut[];

    // Campaign & Special Offers
    campaign_badge?: string;
    campaign_title?: string;
    campaign_subtitle?: string;
    campaign_card1_tag?: string;
    campaign_card1_title?: string;
    campaign_card1_desc?: string;
    campaign_card1_price?: string;
    campaign_card1_mrp?: string;
    campaign_card1_save?: string;
    campaign_card1_btn?: string;
    campaign_card2_tag?: string;
    campaign_card2_title?: string;
    campaign_card2_desc?: string;
    campaign_card2_price?: string;
    campaign_card2_mrp?: string;
    campaign_card2_save?: string;
    campaign_card2_btn?: string;

    // Headings, Dealer Banner & Catalog
    featured_categories_title?: string;
    featured_categories_subtitle?: string;
    dealer_banner_title?: string;
    dealer_banner_subtitle?: string;
    dealer_banner_btn_text?: string;
    featured_products_title?: string;
    featured_products_subtitle?: string;
    featured_products_view_all?: string;
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

    // Homepage Story
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
    about_btn_products?: string;
    about_btn_dealers?: string;
    about_btn_contact?: string;

    // Contact Page (/contact)
    contact_title?: string;
    contact_company_title?: string;
    contact_company_desc?: string;
    contact_topic1_title?: string;
    contact_topic1_desc?: string;
    contact_topic2_title?: string;
    contact_topic2_desc?: string;
    contact_topic3_title?: string;
    office_address?: string;
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
    footer_directory_subtitle?: string;
    footer_directory_title?: string;
    footer_directory_link?: string;
    footer_link_about?: string;
    footer_link_vendor?: string;
    footer_link_orders?: string;
    footer_link_blog?: string;
    footer_link_contact?: string;
    footer_link_dealer_apply?: string;
    footer_about_title?: string;
    footer_about_desc?: string;

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
    whatsapp_link?: string;

    // Auth & Partner Portal Login
    auth_login_title?: string;
    auth_login_subtitle?: string;
    auth_dealer_help_text?: string;
    auth_dealer_contact_text?: string;

    // Copyright
    copyright_text?: string;
}

function Field({
    label,
    fieldKey,
    value,
    placeholder,
    onChange,
    multiline = false,
    rows = 3,
    span2 = false
}: {
    label: string;
    fieldKey: keyof CustomizationData;
    value?: string;
    placeholder?: string;
    onChange: (key: keyof CustomizationData, val: string) => void;
    multiline?: boolean;
    rows?: number;
    span2?: boolean;
}) {
    return (
        <label style={{ display: 'block', gridColumn: span2 ? '1 / -1' : undefined }}>
            <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>{label}</span>
            {multiline ? (
                <textarea
                    rows={rows}
                    value={value || ''}
                    placeholder={placeholder}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6, fontFamily: 'inherit', fontSize: 13 }}
                />
            ) : (
                <input
                    type="text"
                    value={value || ''}
                    placeholder={placeholder}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                />
            )}
            <small style={{ color: '#94a3b8', display: 'block', marginTop: 4, fontSize: 11 }}>
                Default placeholder: {placeholder}
            </small>
        </label>
    );
}

export default function SiteCustomizationEditor({
    customization,
    defaults
}: {
    customization: CustomizationData;
    defaults: CustomizationData;
}) {
    const [tab, setTab] = useState<'header' | 'banners' | 'shortcuts' | 'campaign' | 'catalog' | 'pages' | 'footer'>('header');

    const form = useForm<{ customization: CustomizationData }>({
        customization: {
            // Header
            top_bar_text: customization.top_bar_text || '',
            search_placeholder: customization.search_placeholder || '',
            header_about_title: customization.header_about_title || '',
            header_about_subtitle: customization.header_about_subtitle || '',
            header_contact_title: customization.header_contact_title || '',
            header_contact_subtitle: customization.header_contact_subtitle || '',
            header_offers_title: customization.header_offers_title || '',
            header_offers_subtitle: customization.header_offers_subtitle || '',
            header_projects_title: customization.header_projects_title || '',
            header_projects_subtitle: customization.header_projects_subtitle || '',
            header_account_title: customization.header_account_title || '',
            header_account_subtitle: customization.header_account_subtitle || '',
            header_shop_title: customization.header_shop_title || '',
            nav_categories: customization.nav_categories || '',

            announcement_text: customization.announcement_text || '',

            // Slides & Banners
            slides: (defaults.slides || []).map((defSlide, i) => {
                const s = customization.slides?.[i];
                return {
                    image: s?.image || '',
                    link: s?.link || '',
                    alt: s?.alt || ''
                };
            }),
            side_banner_1_slides: (defaults.side_banner_1_slides || []).map((defSlide, i) => {
                const s = customization.side_banner_1_slides?.[i];
                return {
                    image: s?.image || '',
                    link: s?.link || '',
                    alt: s?.alt || ''
                };
            }),
            side_banner_2_slides: (defaults.side_banner_2_slides || []).map((defSlide, i) => {
                const s = customization.side_banner_2_slides?.[i];
                return {
                    image: s?.image || '',
                    link: s?.link || '',
                    alt: s?.alt || ''
                };
            }),
            side_banner_1_image: customization.side_banner_1_image || '',
            side_banner_1_link: customization.side_banner_1_link || '',
            side_banner_1_alt: customization.side_banner_1_alt || '',
            side_banner_2_image: customization.side_banner_2_image || '',
            side_banner_2_link: customization.side_banner_2_link || '',
            side_banner_2_alt: customization.side_banner_2_alt || '',

            // Shortcuts
            shortcuts: (defaults.shortcuts || []).map((defSc, i) => {
                const sc = customization.shortcuts?.[i];
                return {
                    title: sc?.title || '',
                    subtitle: sc?.subtitle || '',
                    icon: sc?.icon || '',
                    link: sc?.link || ''
                };
            }),

            // Campaign
            campaign_badge: customization.campaign_badge || '',
            campaign_title: customization.campaign_title || '',
            campaign_subtitle: customization.campaign_subtitle || '',
            campaign_card1_tag: customization.campaign_card1_tag || '',
            campaign_card1_title: customization.campaign_card1_title || '',
            campaign_card1_desc: customization.campaign_card1_desc || '',
            campaign_card1_price: customization.campaign_card1_price || '',
            campaign_card1_mrp: customization.campaign_card1_mrp || '',
            campaign_card1_save: customization.campaign_card1_save || '',
            campaign_card1_btn: customization.campaign_card1_btn || '',
            campaign_card2_tag: customization.campaign_card2_tag || '',
            campaign_card2_title: customization.campaign_card2_title || '',
            campaign_card2_desc: customization.campaign_card2_desc || '',
            campaign_card2_price: customization.campaign_card2_price || '',
            campaign_card2_mrp: customization.campaign_card2_mrp || '',
            campaign_card2_save: customization.campaign_card2_save || '',
            campaign_card2_btn: customization.campaign_card2_btn || '',

            // Headings & Catalog
            featured_categories_title: customization.featured_categories_title || '',
            featured_categories_subtitle: customization.featured_categories_subtitle || '',
            dealer_banner_title: customization.dealer_banner_title || '',
            dealer_banner_subtitle: customization.dealer_banner_subtitle || '',
            dealer_banner_btn_text: customization.dealer_banner_btn_text || '',
            featured_products_title: customization.featured_products_title || '',
            featured_products_subtitle: customization.featured_products_subtitle || '',
            featured_products_view_all: customization.featured_products_view_all || '',
            products_empty_title: customization.products_empty_title || '',
            catalog_categories_title: customization.catalog_categories_title || '',
            catalog_all_products_label: customization.catalog_all_products_label || '',
            catalog_price_title: customization.catalog_price_title || '',
            catalog_instock_label: customization.catalog_instock_label || '',
            catalog_apply_filters_btn: customization.catalog_apply_filters_btn || '',
            catalog_clear_filters_btn: customization.catalog_clear_filters_btn || '',
            catalog_sort_by_label: customization.catalog_sort_by_label || '',
            catalog_empty_title: customization.catalog_empty_title || '',
            catalog_empty_desc: customization.catalog_empty_desc || '',
            catalog_empty_btn: customization.catalog_empty_btn || '',

            // Product Cards & Details
            product_in_stock_label: customization.product_in_stock_label || '',
            product_out_of_stock_label: customization.product_out_of_stock_label || '',
            product_add_to_cart_btn: customization.product_add_to_cart_btn || '',
            product_buy_now_btn: customization.product_buy_now_btn || '',
            product_share_label: customization.product_share_label || '',
            product_save_btn: customization.product_save_btn || '',
            product_compare_btn: customization.product_compare_btn || '',
            product_payment_mode_label: customization.product_payment_mode_label || '',
            product_purchase_note: customization.product_purchase_note || '',
            product_video_title: customization.product_video_title || '',
            product_tab_description: customization.product_tab_description || '',
            product_tab_specification: customization.product_tab_specification || '',

            // Homepage Story
            about_headline: customization.about_headline || '',
            about_lead: customization.about_lead || '',
            about_summary_title: customization.about_summary_title || '',
            about_summary_body: customization.about_summary_body || '',

            // About Us Page
            about_hero_title: customization.about_hero_title || '',
            about_hero_lead: customization.about_hero_lead || '',
            about_history_title: customization.about_history_title || '',
            about_history: customization.about_history || '',
            about_mission_title: customization.about_mission_title || '',
            about_mission: customization.about_mission || '',
            about_vision_title: customization.about_vision_title || '',
            about_vision: customization.about_vision || '',
            about_objectives_title: customization.about_objectives_title || '',
            about_obj1_title: customization.about_obj1_title || '',
            about_obj1_desc: customization.about_obj1_desc || '',
            about_obj2_title: customization.about_obj2_title || '',
            about_obj2_desc: customization.about_obj2_desc || '',
            about_obj3_title: customization.about_obj3_title || '',
            about_obj3_desc: customization.about_obj3_desc || '',
            about_obj4_title: customization.about_obj4_title || '',
            about_obj4_desc: customization.about_obj4_desc || '',
            about_btn_products: customization.about_btn_products || '',
            about_btn_dealers: customization.about_btn_dealers || '',
            about_btn_contact: customization.about_btn_contact || '',

            // Contact Page
            contact_title: customization.contact_title || '',
            contact_company_title: customization.contact_company_title || '',
            contact_company_desc: customization.contact_company_desc || '',
            contact_topic1_title: customization.contact_topic1_title || '',
            contact_topic1_desc: customization.contact_topic1_desc || '',
            contact_topic2_title: customization.contact_topic2_title || '',
            contact_topic2_desc: customization.contact_topic2_desc || '',
            contact_topic3_title: customization.contact_topic3_title || '',
            office_address: customization.office_address || '',
            contact_form_title: customization.contact_form_title || '',
            map_title: customization.map_title || '',
            map_subtitle: customization.map_subtitle || '',
            map_facility: customization.map_facility || '',
            map_directions_text: customization.map_directions_text || '',

            // Dealer Directory
            directory_title: customization.directory_title || '',
            directory_subtitle: customization.directory_subtitle || '',
            directory_gps_btn: customization.directory_gps_btn || '',
            directory_empty_title: customization.directory_empty_title || '',
            directory_empty_desc: customization.directory_empty_desc || '',

            // Footer & Support
            footer_support_heading: customization.footer_support_heading || '',
            footer_about_heading: customization.footer_about_heading || '',
            footer_stay_connected_heading: customization.footer_stay_connected_heading || '',
            footer_support_subtitle: customization.footer_support_subtitle || '',
            footer_support_title: customization.footer_support_title || '',
            footer_support_link: customization.footer_support_link || '',
            footer_directory_subtitle: customization.footer_directory_subtitle || '',
            footer_directory_title: customization.footer_directory_title || '',
            footer_directory_link: customization.footer_directory_link || '',
            footer_link_about: customization.footer_link_about || '',
            footer_link_vendor: customization.footer_link_vendor || '',
            footer_link_orders: customization.footer_link_orders || '',
            footer_link_blog: customization.footer_link_blog || '',
            footer_link_contact: customization.footer_link_contact || '',
            footer_link_dealer_apply: customization.footer_link_dealer_apply || '',
            footer_about_title: customization.footer_about_title || '',
            footer_about_desc: customization.footer_about_desc || '',

            // Floating Tools & Dialogs
            floating_compare_label: customization.floating_compare_label || '',
            floating_cart_label: customization.floating_cart_label || '',
            floating_chat_label: customization.floating_chat_label || '',
            cart_dialog_title: customization.cart_dialog_title || '',
            cart_dialog_btn: customization.cart_dialog_btn || '',
            cart_dialog_notice: customization.cart_dialog_notice || '',
            compare_dialog_title: customization.compare_dialog_title || '',
            compare_dialog_desc: customization.compare_dialog_desc || '',

            // Social Media & Chat
            social_facebook: customization.social_facebook || '',
            social_youtube: customization.social_youtube || '',
            social_linkedin: customization.social_linkedin || '',
            social_instagram: customization.social_instagram || '',
            social_whatsapp: customization.social_whatsapp || '',
            whatsapp_link: customization.whatsapp_link || '',

            // Auth
            auth_login_title: customization.auth_login_title || '',
            auth_login_subtitle: customization.auth_login_subtitle || '',
            auth_dealer_help_text: customization.auth_dealer_help_text || '',
            auth_dealer_contact_text: customization.auth_dealer_contact_text || '',

            // Copyright
            copyright_text: customization.copyright_text || ''
        }
    });

    const resetForm = useForm({});

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/commands/customization');
    };

    const handleResetAll = () => {
        if (confirm('Reset all frontend customizations back to the default placeholders?')) {
            resetForm.post('/commands/customization/reset');
        }
    };

    const updateField = (field: keyof CustomizationData, value: string) => {
        form.setData('customization', {
            ...form.data.customization,
            [field]: value
        });
    };

    const updateSlide = (index: number, key: keyof Slide, value: string) => {
        const slides = [...(form.data.customization.slides || [])];
        if (!slides[index]) slides[index] = { image: '', link: '', alt: '' };
        slides[index] = { ...slides[index], [key]: value };
        form.setData('customization', { ...form.data.customization, slides });
    };

    const updateSideSlide = (banner: 1 | 2, index: number, key: keyof Slide, value: string) => {
        const fieldKey = banner === 1 ? 'side_banner_1_slides' : 'side_banner_2_slides';
        const list = [...((form.data.customization[fieldKey] as Slide[] | undefined) || defaults[fieldKey] || [])];
        if (!list[index]) list[index] = { image: '', link: '', alt: '' };
        list[index] = { ...list[index], [key]: value };
        form.setData('customization', { ...form.data.customization, [fieldKey]: list });
    };

    const updateShortcut = (index: number, key: keyof Shortcut, value: string) => {
        const shortcuts = [...(form.data.customization.shortcuts || [])];
        if (!shortcuts[index]) shortcuts[index] = { title: '', subtitle: '', icon: '', link: '' };
        shortcuts[index] = { ...shortcuts[index], [key]: value };
        form.setData('customization', { ...form.data.customization, shortcuts });
    };

    return (
        <div className="site-customization-container">
            <div className="panel" style={{ marginBottom: 20 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 15 }}>
                    <div>
                        <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0 }}>
                            <SlidersHorizontal size={22} color="#2e3192" /> Frontend Personal Customization
                        </h2>
                        <p style={{ margin: '6px 0 0', color: '#64748b', fontSize: 13 }}>
                            Full client freedom to customize every public frontend element. Every input includes a fallback default placeholder—leave any field empty to use its standard placeholder.
                        </p>
                    </div>
                    <div style={{ display: 'flex', gap: 10 }}>
                        <a href="/" target="_blank" rel="noopener noreferrer" className="button small" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                            <ExternalLink size={14} /> Preview Live Website
                        </a>
                        <button type="button" onClick={handleResetAll} className="button small" style={{ color: '#ef4444', display: 'flex', alignItems: 'center', gap: 6 }}>
                            <RotateCcw size={14} /> Reset All to Placeholders
                        </button>
                    </div>
                </div>

                <div style={{ display: 'flex', gap: 8, marginTop: 22, borderBottom: '1px solid #e2e8f0', overflowX: 'auto', paddingBottom: 8 }}>
                    {[
                        { id: 'header', label: 'Top Bar & Header', icon: Layout },
                        { id: 'banners', label: 'Slider & Banners', icon: Image },
                        { id: 'shortcuts', label: 'Feature Shortcuts', icon: Sparkles },
                        { id: 'campaign', label: 'Campaign & Offers', icon: Tag },
                        { id: 'catalog', label: 'Headings & Catalog', icon: Package },
                        { id: 'pages', label: 'About, Contact & Dealers', icon: BookOpen },
                        { id: 'footer', label: 'Footer, Dialogs & Auth', icon: Share2 },
                    ].map(t => {
                        const Icon = t.icon;
                        const active = tab === t.id;
                        return (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => setTab(t.id as any)}
                                className={`button small ${active ? 'dark' : ''}`}
                                style={{ display: 'flex', alignItems: 'center', gap: 7, borderRadius: 6, whiteSpace: 'nowrap' }}
                            >
                                <Icon size={15} />
                                <span>{t.label}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <form onSubmit={handleSubmit}>
                <div className="panel" style={{ padding: 28 }}>
                    {/* TAB 1: HEADER & TOP BAR */}
                    {tab === 'header' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Top Bar & Header Navigation</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="Top Company Bar Text" fieldKey="top_bar_text" value={form.data.customization.top_bar_text} placeholder={defaults.top_bar_text} onChange={updateField} />
                                <Field label="Search Input Placeholder" fieldKey="search_placeholder" value={form.data.customization.search_placeholder} placeholder={defaults.search_placeholder} onChange={updateField} />
                                <Field label="Shop Button Label" fieldKey="header_shop_title" value={form.data.customization.header_shop_title} placeholder={defaults.header_shop_title} onChange={updateField} />
                                
                                <Field label="About Us Link Title" fieldKey="header_about_title" value={form.data.customization.header_about_title} placeholder={defaults.header_about_title} onChange={updateField} />
                                <Field label="About Us Link Subtitle" fieldKey="header_about_subtitle" value={form.data.customization.header_about_subtitle} placeholder={defaults.header_about_subtitle} onChange={updateField} />
                                
                                <Field label="Contact Link Title" fieldKey="header_contact_title" value={form.data.customization.header_contact_title} placeholder={defaults.header_contact_title} onChange={updateField} />
                                <Field label="Contact Link Subtitle" fieldKey="header_contact_subtitle" value={form.data.customization.header_contact_subtitle} placeholder={defaults.header_contact_subtitle} onChange={updateField} />

                                <Field label="Offers Link Title" fieldKey="header_offers_title" value={form.data.customization.header_offers_title} placeholder={defaults.header_offers_title} onChange={updateField} />
                                <Field label="Offers Link Subtitle" fieldKey="header_offers_subtitle" value={form.data.customization.header_offers_subtitle} placeholder={defaults.header_offers_subtitle} onChange={updateField} />

                                <Field label="Projects Link Title" fieldKey="header_projects_title" value={form.data.customization.header_projects_title} placeholder={defaults.header_projects_title} onChange={updateField} />
                                <Field label="Projects Link Subtitle" fieldKey="header_projects_subtitle" value={form.data.customization.header_projects_subtitle} placeholder={defaults.header_projects_subtitle} onChange={updateField} />

                                <Field label="Partner Account Link Title" fieldKey="header_account_title" value={form.data.customization.header_account_title} placeholder={defaults.header_account_title} onChange={updateField} />
                                <Field label="Partner Account Link Subtitle" fieldKey="header_account_subtitle" value={form.data.customization.header_account_subtitle} placeholder={defaults.header_account_subtitle} onChange={updateField} />

                                <Field label="Category Navigation Bar (One per line)" fieldKey="nav_categories" value={form.data.customization.nav_categories} placeholder={defaults.nav_categories} onChange={updateField} multiline rows={4} span2 />
                            </div>
                        </div>
                    )}

                    {/* TAB 2: SLIDER & BANNERS */}
                    {tab === 'banners' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Hero Carousel Slides (5 Slides)</h3>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 20, marginBottom: 30 }}>
                                {(form.data.customization.slides || []).map((slide, idx) => {
                                    const defSlide = defaults.slides?.[idx] || { image: '', link: '', alt: '' };
                                    return (
                                        <div key={idx} style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                            <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Slide #{idx + 1}</h4>
                                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))', gap: 14 }}>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Image URL</span>
                                                    <input
                                                        type="text"
                                                        value={slide.image}
                                                        placeholder={defSlide.image}
                                                        onChange={e => updateSlide(idx, 'image', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSlide.image}</small>
                                                </label>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Target Link</span>
                                                    <input
                                                        type="text"
                                                        value={slide.link}
                                                        placeholder={defSlide.link}
                                                        onChange={e => updateSlide(idx, 'link', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSlide.link}</small>
                                                </label>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Alt / Caption</span>
                                                    <input
                                                        type="text"
                                                        value={slide.alt}
                                                        placeholder={defSlide.alt}
                                                        onChange={e => updateSlide(idx, 'alt', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSlide.alt}</small>
                                                </label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Side Promotional Banners (Mini-Carousels)</h3>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: 24 }}>
                                {/* Side Banner 1 (Top) */}
                                <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                    <h4 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 700, color: '#2e3192' }}>Top Side Card (Mini-Carousel Slides)</h4>
                                    <p style={{ margin: '0 0 14px', fontSize: 12, color: '#64748b' }}>Configure multiple rotating slides for the top side promotion card.</p>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                                        {((form.data.customization.side_banner_1_slides || defaults.side_banner_1_slides) || []).map((slide, idx) => {
                                            const defSlide = defaults.side_banner_1_slides?.[idx] || { image: '', link: '', alt: '' };
                                            return (
                                                <div key={idx} style={{ padding: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff' }}>
                                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 8 }}>Top Card Slide #{idx + 1}</span>
                                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
                                                        <label>
                                                            <span style={{ fontSize: 11, fontWeight: 600, display: 'block', marginBottom: 2 }}>Image URL</span>
                                                            <input
                                                                type="text"
                                                                value={slide.image}
                                                                placeholder={defSlide.image}
                                                                onChange={e => updateSideSlide(1, idx, 'image', e.target.value)}
                                                                style={{ width: '100%', padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                            />
                                                            <small style={{ color: '#94a3b8', display: 'block', fontSize: 10, marginTop: 2 }}>{defSlide.image}</small>
                                                        </label>
                                                        <label>
                                                            <span style={{ fontSize: 11, fontWeight: 600, display: 'block', marginBottom: 2 }}>Target Link</span>
                                                            <input
                                                                type="text"
                                                                value={slide.link}
                                                                placeholder={defSlide.link}
                                                                onChange={e => updateSideSlide(1, idx, 'link', e.target.value)}
                                                                style={{ width: '100%', padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                            />
                                                            <small style={{ color: '#94a3b8', display: 'block', fontSize: 10, marginTop: 2 }}>{defSlide.link}</small>
                                                        </label>
                                                        <label>
                                                            <span style={{ fontSize: 11, fontWeight: 600, display: 'block', marginBottom: 2 }}>Alt Text</span>
                                                            <input
                                                                type="text"
                                                                value={slide.alt}
                                                                placeholder={defSlide.alt}
                                                                onChange={e => updateSideSlide(1, idx, 'alt', e.target.value)}
                                                                style={{ width: '100%', padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                            />
                                                            <small style={{ color: '#94a3b8', display: 'block', fontSize: 10, marginTop: 2 }}>{defSlide.alt}</small>
                                                        </label>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Side Banner 2 (Bottom) */}
                                <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                    <h4 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 700, color: '#2e3192' }}>Bottom Side Card (Mini-Carousel Slides)</h4>
                                    <p style={{ margin: '0 0 14px', fontSize: 12, color: '#64748b' }}>Configure multiple rotating slides for the bottom side promotion card.</p>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                                        {((form.data.customization.side_banner_2_slides || defaults.side_banner_2_slides) || []).map((slide, idx) => {
                                            const defSlide = defaults.side_banner_2_slides?.[idx] || { image: '', link: '', alt: '' };
                                            return (
                                                <div key={idx} style={{ padding: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff' }}>
                                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#475569', display: 'block', marginBottom: 8 }}>Bottom Card Slide #{idx + 1}</span>
                                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
                                                        <label>
                                                            <span style={{ fontSize: 11, fontWeight: 600, display: 'block', marginBottom: 2 }}>Image URL</span>
                                                            <input
                                                                type="text"
                                                                value={slide.image}
                                                                placeholder={defSlide.image}
                                                                onChange={e => updateSideSlide(2, idx, 'image', e.target.value)}
                                                                style={{ width: '100%', padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                            />
                                                            <small style={{ color: '#94a3b8', display: 'block', fontSize: 10, marginTop: 2 }}>{defSlide.image}</small>
                                                        </label>
                                                        <label>
                                                            <span style={{ fontSize: 11, fontWeight: 600, display: 'block', marginBottom: 2 }}>Target Link</span>
                                                            <input
                                                                type="text"
                                                                value={slide.link}
                                                                placeholder={defSlide.link}
                                                                onChange={e => updateSideSlide(2, idx, 'link', e.target.value)}
                                                                style={{ width: '100%', padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                            />
                                                            <small style={{ color: '#94a3b8', display: 'block', fontSize: 10, marginTop: 2 }}>{defSlide.link}</small>
                                                        </label>
                                                        <label>
                                                            <span style={{ fontSize: 11, fontWeight: 600, display: 'block', marginBottom: 2 }}>Alt Text</span>
                                                            <input
                                                                type="text"
                                                                value={slide.alt}
                                                                placeholder={defSlide.alt}
                                                                onChange={e => updateSideSlide(2, idx, 'alt', e.target.value)}
                                                                style={{ width: '100%', padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                            />
                                                            <small style={{ color: '#94a3b8', display: 'block', fontSize: 10, marginTop: 2 }}>{defSlide.alt}</small>
                                                        </label>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TAB 3: FEATURE SHORTCUTS */}
                    {tab === 'shortcuts' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Quick Feature Shortcuts (5 Items)</h3>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                {(form.data.customization.shortcuts || []).map((sc, idx) => {
                                    const defSc = defaults.shortcuts?.[idx] || { title: '', subtitle: '', icon: '', link: '' };
                                    return (
                                        <div key={idx} style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                            <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Shortcut #{idx + 1}</h4>
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Title</span>
                                                    <input
                                                        type="text"
                                                        value={sc.title}
                                                        placeholder={defSc.title}
                                                        onChange={e => updateShortcut(idx, 'title', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defSc.title}</small>
                                                </label>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Subtitle</span>
                                                    <input
                                                        type="text"
                                                        value={sc.subtitle}
                                                        placeholder={defSc.subtitle}
                                                        onChange={e => updateShortcut(idx, 'subtitle', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defSc.subtitle}</small>
                                                </label>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>FontAwesome Icon (e.g. fa-seedling)</span>
                                                    <input
                                                        type="text"
                                                        value={sc.icon}
                                                        placeholder={defSc.icon}
                                                        onChange={e => updateShortcut(idx, 'icon', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defSc.icon}</small>
                                                </label>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Target Link</span>
                                                    <input
                                                        type="text"
                                                        value={sc.link}
                                                        placeholder={defSc.link}
                                                        onChange={e => updateShortcut(idx, 'link', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defSc.link}</small>
                                                </label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* TAB 4: CAMPAIGN & SPECIAL OFFERS */}
                    {tab === 'campaign' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Special Campaign & Promotional Offers Section</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Campaign Section Badge" fieldKey="campaign_badge" value={form.data.customization.campaign_badge} placeholder={defaults.campaign_badge} onChange={updateField} />
                                <Field label="Campaign Section Title" fieldKey="campaign_title" value={form.data.customization.campaign_title} placeholder={defaults.campaign_title} onChange={updateField} />
                                <Field label="Campaign Section Subtitle" fieldKey="campaign_subtitle" value={form.data.customization.campaign_subtitle} placeholder={defaults.campaign_subtitle} onChange={updateField} span2 />
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                    <h4 style={{ margin: '0 0 14px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Campaign Offer Card 1</h4>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        <Field label="Card 1 Tag / Badge" fieldKey="campaign_card1_tag" value={form.data.customization.campaign_card1_tag} placeholder={defaults.campaign_card1_tag} onChange={updateField} />
                                        <Field label="Card 1 Title" fieldKey="campaign_card1_title" value={form.data.customization.campaign_card1_title} placeholder={defaults.campaign_card1_title} onChange={updateField} />
                                        <Field label="Card 1 Description" fieldKey="campaign_card1_desc" value={form.data.customization.campaign_card1_desc} placeholder={defaults.campaign_card1_desc} onChange={updateField} multiline rows={2} />
                                        <Field label="Card 1 Offer Price (e.g. 20,000 Tk)" fieldKey="campaign_card1_price" value={form.data.customization.campaign_card1_price} placeholder={defaults.campaign_card1_price} onChange={updateField} />
                                        <Field label="Card 1 Regular MRP (e.g. 30,000 Tk)" fieldKey="campaign_card1_mrp" value={form.data.customization.campaign_card1_mrp} placeholder={defaults.campaign_card1_mrp} onChange={updateField} />
                                        <Field label="Card 1 Save Badge (e.g. Save 10,000 Tk)" fieldKey="campaign_card1_save" value={form.data.customization.campaign_card1_save} placeholder={defaults.campaign_card1_save} onChange={updateField} />
                                        <Field label="Card 1 Action Button Text" fieldKey="campaign_card1_btn" value={form.data.customization.campaign_card1_btn} placeholder={defaults.campaign_card1_btn} onChange={updateField} />
                                    </div>
                                </div>

                                <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                    <h4 style={{ margin: '0 0 14px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Campaign Offer Card 2</h4>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                        <Field label="Card 2 Tag / Badge" fieldKey="campaign_card2_tag" value={form.data.customization.campaign_card2_tag} placeholder={defaults.campaign_card2_tag} onChange={updateField} />
                                        <Field label="Card 2 Title" fieldKey="campaign_card2_title" value={form.data.customization.campaign_card2_title} placeholder={defaults.campaign_card2_title} onChange={updateField} />
                                        <Field label="Card 2 Description" fieldKey="campaign_card2_desc" value={form.data.customization.campaign_card2_desc} placeholder={defaults.campaign_card2_desc} onChange={updateField} multiline rows={2} />
                                        <Field label="Card 2 Offer Price (e.g. 6,100 Tk)" fieldKey="campaign_card2_price" value={form.data.customization.campaign_card2_price} placeholder={defaults.campaign_card2_price} onChange={updateField} />
                                        <Field label="Card 2 Regular MRP (e.g. 7,000 Tk)" fieldKey="campaign_card2_mrp" value={form.data.customization.campaign_card2_mrp} placeholder={defaults.campaign_card2_mrp} onChange={updateField} />
                                        <Field label="Card 2 Save Badge (e.g. Save 900 Tk)" fieldKey="campaign_card2_save" value={form.data.customization.campaign_card2_save} placeholder={defaults.campaign_card2_save} onChange={updateField} />
                                        <Field label="Card 2 Action Button Text" fieldKey="campaign_card2_btn" value={form.data.customization.campaign_card2_btn} placeholder={defaults.campaign_card2_btn} onChange={updateField} />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TAB 5: HEADINGS & CATALOG */}
                    {tab === 'catalog' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Headings, Ticker & Dealer Banner</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Homepage Scrolling Ticker Text" fieldKey="announcement_text" value={form.data.customization.announcement_text} placeholder={defaults.announcement_text} onChange={updateField} span2 />
                                <Field label="Featured Categories Section Title" fieldKey="featured_categories_title" value={form.data.customization.featured_categories_title} placeholder={defaults.featured_categories_title} onChange={updateField} />
                                <Field label="Featured Categories Subtitle" fieldKey="featured_categories_subtitle" value={form.data.customization.featured_categories_subtitle} placeholder={defaults.featured_categories_subtitle} onChange={updateField} />
                                
                                <Field label="Authorized Dealer Network Banner Title" fieldKey="dealer_banner_title" value={form.data.customization.dealer_banner_title} placeholder={defaults.dealer_banner_title} onChange={updateField} />
                                <Field label="Dealer Banner Subtitle" fieldKey="dealer_banner_subtitle" value={form.data.customization.dealer_banner_subtitle} placeholder={defaults.dealer_banner_subtitle} onChange={updateField} />
                                <Field label="Dealer Banner Button Label" fieldKey="dealer_banner_btn_text" value={form.data.customization.dealer_banner_btn_text} placeholder={defaults.dealer_banner_btn_text} onChange={updateField} />

                                <Field label="Featured Products Section Title" fieldKey="featured_products_title" value={form.data.customization.featured_products_title} placeholder={defaults.featured_products_title} onChange={updateField} />
                                <Field label="Featured Products Subtitle" fieldKey="featured_products_subtitle" value={form.data.customization.featured_products_subtitle} placeholder={defaults.featured_products_subtitle} onChange={updateField} />
                                <Field label="View All Products Button Text" fieldKey="featured_products_view_all" value={form.data.customization.featured_products_view_all} placeholder={defaults.featured_products_view_all} onChange={updateField} />
                                <Field label="Homepage Products Empty State Text" fieldKey="products_empty_title" value={form.data.customization.products_empty_title} placeholder={defaults.products_empty_title} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Product Catalog Page (/products) Labels</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Categories Filter Title" fieldKey="catalog_categories_title" value={form.data.customization.catalog_categories_title} placeholder={defaults.catalog_categories_title} onChange={updateField} />
                                <Field label="All Products Radio Label" fieldKey="catalog_all_products_label" value={form.data.customization.catalog_all_products_label} placeholder={defaults.catalog_all_products_label} onChange={updateField} />
                                <Field label="Price Range Filter Title" fieldKey="catalog_price_title" value={form.data.customization.catalog_price_title} placeholder={defaults.catalog_price_title} onChange={updateField} />
                                <Field label="In-Stock Filter Label" fieldKey="catalog_instock_label" value={form.data.customization.catalog_instock_label} placeholder={defaults.catalog_instock_label} onChange={updateField} />
                                <Field label="Apply Filters Button Text" fieldKey="catalog_apply_filters_btn" value={form.data.customization.catalog_apply_filters_btn} placeholder={defaults.catalog_apply_filters_btn} onChange={updateField} />
                                <Field label="Clear Filters Button Text" fieldKey="catalog_clear_filters_btn" value={form.data.customization.catalog_clear_filters_btn} placeholder={defaults.catalog_clear_filters_btn} onChange={updateField} />
                                <Field label="Sort By Dropdown Label" fieldKey="catalog_sort_by_label" value={form.data.customization.catalog_sort_by_label} placeholder={defaults.catalog_sort_by_label} onChange={updateField} />
                                <Field label="Catalog Empty State Title" fieldKey="catalog_empty_title" value={form.data.customization.catalog_empty_title} placeholder={defaults.catalog_empty_title} onChange={updateField} />
                                <Field label="Catalog Empty State Description" fieldKey="catalog_empty_desc" value={form.data.customization.catalog_empty_desc} placeholder={defaults.catalog_empty_desc} onChange={updateField} />
                                <Field label="Catalog Empty State Button Text" fieldKey="catalog_empty_btn" value={form.data.customization.catalog_empty_btn} placeholder={defaults.catalog_empty_btn} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Product Card & Product Details Page</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="In Stock Badge Label" fieldKey="product_in_stock_label" value={form.data.customization.product_in_stock_label} placeholder={defaults.product_in_stock_label} onChange={updateField} />
                                <Field label="Awaiting Stock Badge Label" fieldKey="product_out_of_stock_label" value={form.data.customization.product_out_of_stock_label} placeholder={defaults.product_out_of_stock_label} onChange={updateField} />
                                <Field label="Add to Cart Button Text" fieldKey="product_add_to_cart_btn" value={form.data.customization.product_add_to_cart_btn} placeholder={defaults.product_add_to_cart_btn} onChange={updateField} />
                                <Field label="Buy Now Button Text" fieldKey="product_buy_now_btn" value={form.data.customization.product_buy_now_btn} placeholder={defaults.product_buy_now_btn} onChange={updateField} />
                                <Field label="Share Label" fieldKey="product_share_label" value={form.data.customization.product_share_label} placeholder={defaults.product_share_label} onChange={updateField} />
                                <Field label="Save / Bookmark Button Text" fieldKey="product_save_btn" value={form.data.customization.product_save_btn} placeholder={defaults.product_save_btn} onChange={updateField} />
                                <Field label="Add to Compare Button Text" fieldKey="product_compare_btn" value={form.data.customization.product_compare_btn} placeholder={defaults.product_compare_btn} onChange={updateField} />
                                <Field label="Payment Mode Label (e.g. Cash / Manual Payment)" fieldKey="product_payment_mode_label" value={form.data.customization.product_payment_mode_label} placeholder={defaults.product_payment_mode_label} onChange={updateField} />
                                <Field label="Product Video Heading" fieldKey="product_video_title" value={form.data.customization.product_video_title} placeholder={defaults.product_video_title} onChange={updateField} />
                                <Field label="Description Tab Title" fieldKey="product_tab_description" value={form.data.customization.product_tab_description} placeholder={defaults.product_tab_description} onChange={updateField} />
                                <Field label="Specification Tab Title" fieldKey="product_tab_specification" value={form.data.customization.product_tab_specification} placeholder={defaults.product_tab_specification} onChange={updateField} />
                                <Field label="Product Purchase Policy Note" fieldKey="product_purchase_note" value={form.data.customization.product_purchase_note} placeholder={defaults.product_purchase_note} onChange={updateField} span2 />
                            </div>
                        </div>
                    )}

                    {/* TAB 6: ABOUT, CONTACT & DEALERS PAGES */}
                    {tab === 'pages' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Homepage Story Section</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="About Main Headline" fieldKey="about_headline" value={form.data.customization.about_headline} placeholder={defaults.about_headline} onChange={updateField} span2 />
                                <Field label="About Lead Paragraph" fieldKey="about_lead" value={form.data.customization.about_lead} placeholder={defaults.about_lead} onChange={updateField} multiline rows={3} span2 />
                                <Field label="Read More Summary Title" fieldKey="about_summary_title" value={form.data.customization.about_summary_title} placeholder={defaults.about_summary_title} onChange={updateField} />
                                <Field label="Read More Summary Body" fieldKey="about_summary_body" value={form.data.customization.about_summary_body} placeholder={defaults.about_summary_body} onChange={updateField} multiline rows={3} span2 />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>About Us Page (/about)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="About Page Hero Title" fieldKey="about_hero_title" value={form.data.customization.about_hero_title} placeholder={defaults.about_hero_title} onChange={updateField} />
                                <Field label="About Page Hero Lead" fieldKey="about_hero_lead" value={form.data.customization.about_hero_lead} placeholder={defaults.about_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Company History Title" fieldKey="about_history_title" value={form.data.customization.about_history_title} placeholder={defaults.about_history_title} onChange={updateField} />
                                <Field label="Company History Text" fieldKey="about_history" value={form.data.customization.about_history} placeholder={defaults.about_history} onChange={updateField} multiline rows={3} span2 />
                                <Field label="Mission Title" fieldKey="about_mission_title" value={form.data.customization.about_mission_title} placeholder={defaults.about_mission_title} onChange={updateField} />
                                <Field label="Mission Statement" fieldKey="about_mission" value={form.data.customization.about_mission} placeholder={defaults.about_mission} onChange={updateField} multiline rows={2} />
                                <Field label="Vision Title" fieldKey="about_vision_title" value={form.data.customization.about_vision_title} placeholder={defaults.about_vision_title} onChange={updateField} />
                                <Field label="Vision Statement" fieldKey="about_vision" value={form.data.customization.about_vision} placeholder={defaults.about_vision} onChange={updateField} multiline rows={2} />
                                
                                <Field label="Core Objectives Title" fieldKey="about_objectives_title" value={form.data.customization.about_objectives_title} placeholder={defaults.about_objectives_title} onChange={updateField} span2 />
                                <Field label="Objective 1 Title" fieldKey="about_obj1_title" value={form.data.customization.about_obj1_title} placeholder={defaults.about_obj1_title} onChange={updateField} />
                                <Field label="Objective 1 Description" fieldKey="about_obj1_desc" value={form.data.customization.about_obj1_desc} placeholder={defaults.about_obj1_desc} onChange={updateField} />
                                <Field label="Objective 2 Title" fieldKey="about_obj2_title" value={form.data.customization.about_obj2_title} placeholder={defaults.about_obj2_title} onChange={updateField} />
                                <Field label="Objective 2 Description" fieldKey="about_obj2_desc" value={form.data.customization.about_obj2_desc} placeholder={defaults.about_obj2_desc} onChange={updateField} />
                                <Field label="Objective 3 Title" fieldKey="about_obj3_title" value={form.data.customization.about_obj3_title} placeholder={defaults.about_obj3_title} onChange={updateField} />
                                <Field label="Objective 3 Description" fieldKey="about_obj3_desc" value={form.data.customization.about_obj3_desc} placeholder={defaults.about_obj3_desc} onChange={updateField} />
                                <Field label="Objective 4 Title" fieldKey="about_obj4_title" value={form.data.customization.about_obj4_title} placeholder={defaults.about_obj4_title} onChange={updateField} />
                                <Field label="Objective 4 Description" fieldKey="about_obj4_desc" value={form.data.customization.about_obj4_desc} placeholder={defaults.about_obj4_desc} onChange={updateField} />

                                <Field label="Button 1 (Explore Products)" fieldKey="about_btn_products" value={form.data.customization.about_btn_products} placeholder={defaults.about_btn_products} onChange={updateField} />
                                <Field label="Button 2 (Find a Dealer)" fieldKey="about_btn_dealers" value={form.data.customization.about_btn_dealers} placeholder={defaults.about_btn_dealers} onChange={updateField} />
                                <Field label="Button 3 (Contact Head Office)" fieldKey="about_btn_contact" value={form.data.customization.about_btn_contact} placeholder={defaults.about_btn_contact} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Contact Us Page (/contact) & Map Location</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Contact Page Heading" fieldKey="contact_title" value={form.data.customization.contact_title} placeholder={defaults.contact_title} onChange={updateField} />
                                <Field label="Contact Company Title" fieldKey="contact_company_title" value={form.data.customization.contact_company_title} placeholder={defaults.contact_company_title} onChange={updateField} />
                                <Field label="Contact Intro Description" fieldKey="contact_company_desc" value={form.data.customization.contact_company_desc} placeholder={defaults.contact_company_desc} onChange={updateField} span2 />
                                
                                <Field label="Inquiry Topic 1 Title" fieldKey="contact_topic1_title" value={form.data.customization.contact_topic1_title} placeholder={defaults.contact_topic1_title} onChange={updateField} />
                                <Field label="Inquiry Topic 1 Description" fieldKey="contact_topic1_desc" value={form.data.customization.contact_topic1_desc} placeholder={defaults.contact_topic1_desc} onChange={updateField} />
                                <Field label="Inquiry Topic 2 Title" fieldKey="contact_topic2_title" value={form.data.customization.contact_topic2_title} placeholder={defaults.contact_topic2_title} onChange={updateField} />
                                <Field label="Inquiry Topic 2 Description" fieldKey="contact_topic2_desc" value={form.data.customization.contact_topic2_desc} placeholder={defaults.contact_topic2_desc} onChange={updateField} />
                                <Field label="Head Office Card Title" fieldKey="contact_topic3_title" value={form.data.customization.contact_topic3_title} placeholder={defaults.contact_topic3_title} onChange={updateField} />
                                <Field label="Head Office Physical Address" fieldKey="office_address" value={form.data.customization.office_address} placeholder={defaults.office_address} onChange={updateField} />
                                <Field label="Contact Form Header" fieldKey="contact_form_title" value={form.data.customization.contact_form_title} placeholder={defaults.contact_form_title} onChange={updateField} />
                                
                                <Field label="Map Location Title" fieldKey="map_title" value={form.data.customization.map_title} placeholder={defaults.map_title} onChange={updateField} />
                                <Field label="Map Subtitle" fieldKey="map_subtitle" value={form.data.customization.map_subtitle} placeholder={defaults.map_subtitle} onChange={updateField} />
                                <Field label="Map Facility Name" fieldKey="map_facility" value={form.data.customization.map_facility} placeholder={defaults.map_facility} onChange={updateField} />
                                <Field label="Map Directions Button Text" fieldKey="map_directions_text" value={form.data.customization.map_directions_text} placeholder={defaults.map_directions_text} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Dealer Directory Page (/dealers)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="Directory Main Title" fieldKey="directory_title" value={form.data.customization.directory_title} placeholder={defaults.directory_title} onChange={updateField} />
                                <Field label="Directory Subtitle" fieldKey="directory_subtitle" value={form.data.customization.directory_subtitle} placeholder={defaults.directory_subtitle} onChange={updateField} />
                                <Field label="GPS Current Location Button Text" fieldKey="directory_gps_btn" value={form.data.customization.directory_gps_btn} placeholder={defaults.directory_gps_btn} onChange={updateField} span2 />
                                <Field label="Directory Empty Results Title" fieldKey="directory_empty_title" value={form.data.customization.directory_empty_title} placeholder={defaults.directory_empty_title} onChange={updateField} />
                                <Field label="Directory Empty Results Description" fieldKey="directory_empty_desc" value={form.data.customization.directory_empty_desc} placeholder={defaults.directory_empty_desc} onChange={updateField} />
                            </div>
                        </div>
                    )}

                    {/* TAB 7: FOOTER, DIALOGS & AUTH */}
                    {tab === 'footer' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Footer Headings & Support Boxes</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Column 1 Heading" fieldKey="footer_support_heading" value={form.data.customization.footer_support_heading} placeholder={defaults.footer_support_heading} onChange={updateField} />
                                <Field label="Column 2 Heading" fieldKey="footer_about_heading" value={form.data.customization.footer_about_heading} placeholder={defaults.footer_about_heading} onChange={updateField} />
                                <Field label="Column 3 Heading" fieldKey="footer_stay_connected_heading" value={form.data.customization.footer_stay_connected_heading} placeholder={defaults.footer_stay_connected_heading} onChange={updateField} />

                                <Field label="Support Box 1 Title" fieldKey="footer_support_title" value={form.data.customization.footer_support_title} placeholder={defaults.footer_support_title} onChange={updateField} />
                                <Field label="Support Box 1 Subtitle" fieldKey="footer_support_subtitle" value={form.data.customization.footer_support_subtitle} placeholder={defaults.footer_support_subtitle} onChange={updateField} />
                                <Field label="Support Box 1 Link" fieldKey="footer_support_link" value={form.data.customization.footer_support_link} placeholder={defaults.footer_support_link} onChange={updateField} />

                                <Field label="Directory Box 2 Title" fieldKey="footer_directory_title" value={form.data.customization.footer_directory_title} placeholder={defaults.footer_directory_title} onChange={updateField} />
                                <Field label="Directory Box 2 Subtitle" fieldKey="footer_directory_subtitle" value={form.data.customization.footer_directory_subtitle} placeholder={defaults.footer_directory_subtitle} onChange={updateField} />
                                <Field label="Directory Box 2 Link" fieldKey="footer_directory_link" value={form.data.customization.footer_directory_link} placeholder={defaults.footer_directory_link} onChange={updateField} />

                                <Field label="Footer Link 1" fieldKey="footer_link_about" value={form.data.customization.footer_link_about} placeholder={defaults.footer_link_about} onChange={updateField} />
                                <Field label="Footer Link 2" fieldKey="footer_link_vendor" value={form.data.customization.footer_link_vendor} placeholder={defaults.footer_link_vendor} onChange={updateField} />
                                <Field label="Footer Link 3 (Orders)" fieldKey="footer_link_orders" value={form.data.customization.footer_link_orders} placeholder={defaults.footer_link_orders} onChange={updateField} />
                                <Field label="Footer Link 4" fieldKey="footer_link_blog" value={form.data.customization.footer_link_blog} placeholder={defaults.footer_link_blog} onChange={updateField} />
                                <Field label="Footer Link 5" fieldKey="footer_link_contact" value={form.data.customization.footer_link_contact} placeholder={defaults.footer_link_contact} onChange={updateField} />
                                <Field label="Footer Link 6 (Dealership Apply)" fieldKey="footer_link_dealer_apply" value={form.data.customization.footer_link_dealer_apply} placeholder={defaults.footer_link_dealer_apply} onChange={updateField} />

                                <Field label="Footer Brand Title" fieldKey="footer_about_title" value={form.data.customization.footer_about_title} placeholder={defaults.footer_about_title} onChange={updateField} />
                                <Field label="Footer Brand Description" fieldKey="footer_about_desc" value={form.data.customization.footer_about_desc} placeholder={defaults.footer_about_desc} onChange={updateField} />
                                <Field label="Copyright Notice" fieldKey="copyright_text" value={form.data.customization.copyright_text} placeholder={defaults.copyright_text} onChange={updateField} span2 />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Social Links & Floating Tools</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Facebook Page URL" fieldKey="social_facebook" value={form.data.customization.social_facebook} placeholder={defaults.social_facebook} onChange={updateField} />
                                <Field label="YouTube Channel URL" fieldKey="social_youtube" value={form.data.customization.social_youtube} placeholder={defaults.social_youtube} onChange={updateField} />
                                <Field label="LinkedIn Page URL" fieldKey="social_linkedin" value={form.data.customization.social_linkedin} placeholder={defaults.social_linkedin} onChange={updateField} />
                                <Field label="Instagram Profile URL" fieldKey="social_instagram" value={form.data.customization.social_instagram} placeholder={defaults.social_instagram} onChange={updateField} />
                                <Field label="WhatsApp Chat URL / Number" fieldKey="social_whatsapp" value={form.data.customization.social_whatsapp} placeholder={defaults.social_whatsapp} onChange={updateField} />
                                <Field label="Floating WhatsApp Tool Link" fieldKey="whatsapp_link" value={form.data.customization.whatsapp_link} placeholder={defaults.whatsapp_link} onChange={updateField} />

                                <Field label="Floating Compare Button Label" fieldKey="floating_compare_label" value={form.data.customization.floating_compare_label} placeholder={defaults.floating_compare_label} onChange={updateField} />
                                <Field label="Floating Cart Button Label" fieldKey="floating_cart_label" value={form.data.customization.floating_cart_label} placeholder={defaults.floating_cart_label} onChange={updateField} />
                                <Field label="Floating Chat Button Label" fieldKey="floating_chat_label" value={form.data.customization.floating_chat_label} placeholder={defaults.floating_chat_label} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Slide-over Dialogs & Partner Auth Page</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="Cart Dialog Heading" fieldKey="cart_dialog_title" value={form.data.customization.cart_dialog_title} placeholder={defaults.cart_dialog_title} onChange={updateField} />
                                <Field label="Cart Dialog Login Button" fieldKey="cart_dialog_btn" value={form.data.customization.cart_dialog_btn} placeholder={defaults.cart_dialog_btn} onChange={updateField} />
                                <Field label="Cart Dialog Staff Review Notice" fieldKey="cart_dialog_notice" value={form.data.customization.cart_dialog_notice} placeholder={defaults.cart_dialog_notice} onChange={updateField} span2 />

                                <Field label="Comparison Dialog Heading" fieldKey="compare_dialog_title" value={form.data.customization.compare_dialog_title} placeholder={defaults.compare_dialog_title} onChange={updateField} />
                                <Field label="Comparison Dialog Description" fieldKey="compare_dialog_desc" value={form.data.customization.compare_dialog_desc} placeholder={defaults.compare_dialog_desc} onChange={updateField} />

                                <Field label="Partner Portal Login Heading" fieldKey="auth_login_title" value={form.data.customization.auth_login_title} placeholder={defaults.auth_login_title} onChange={updateField} />
                                <Field label="Partner Portal Login Subtitle" fieldKey="auth_login_subtitle" value={form.data.customization.auth_login_subtitle} placeholder={defaults.auth_login_subtitle} onChange={updateField} />
                                <Field label="Dealer Account Inquiry Text" fieldKey="auth_dealer_help_text" value={form.data.customization.auth_dealer_help_text} placeholder={defaults.auth_dealer_help_text} onChange={updateField} />
                                <Field label="Dealer Account Inquiry Link Text" fieldKey="auth_dealer_contact_text" value={form.data.customization.auth_dealer_contact_text} placeholder={defaults.auth_dealer_contact_text} onChange={updateField} />
                            </div>
                        </div>
                    )}

                    <div style={{ marginTop: 28, paddingTop: 20, borderTop: '1px solid #e2e8f0', display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="button dark"
                            style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '12px 24px', fontSize: 14 }}
                        >
                            <Save size={16} />
                            <span>{form.processing ? 'Saving Changes…' : 'Save Customization'}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
}

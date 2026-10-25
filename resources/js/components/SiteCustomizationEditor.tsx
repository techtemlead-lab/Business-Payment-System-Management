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
    ExternalLink,
    Plus,
    Trash2,
    ArrowUp,
    ArrowDown,
    Check,
    List
} from 'lucide-react';
import { useT } from '../shared/i18n/t';

interface Slide {
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

    // Homepage Story
    company_intro_title?: string;
    company_intro_body?: string;
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

interface CategoryItem {
    id: string;
    name: string;
    icon: string;
    link: string;
    badge?: string;
}

const POPULAR_CATEGORY_ICONS = [
    {
        group: '🏥 Health, Medicine & Diagnostics',
        icons: [
            { icon: 'fa-briefcase-medical', label: 'Medical Equipment' },
            { icon: 'fa-prescription-bottle-medical', label: 'Unani & Herbal Medicine' },
            { icon: 'fa-capsules', label: 'Capsules' },
            { icon: 'fa-tablets', label: 'Tablets / Medicine' },
            { icon: 'fa-pills', label: 'Pills & Supplements' },
            { icon: 'fa-stethoscope', label: 'Diagnostics / Doctor' },
            { icon: 'fa-kit-medical', label: 'First Aid / Kit' },
            { icon: 'fa-mortar-pestle', label: 'Herbal Compounding' },
            { icon: 'fa-heart-pulse', label: 'Cardio & Vitality' },
            { icon: 'fa-bandage', label: 'Bandages & Care' },
            { icon: 'fa-hand-holding-medical', label: 'Health Support' },
            { icon: 'fa-shield-halved', label: 'Health Protection' },
        ],
    },
    {
        group: '🌿 Herbal, Organic & Nutrition',
        icons: [
            { icon: 'fa-leaf', label: 'Pure Herbal' },
            { icon: 'fa-seedling', label: 'Agriculture & Botanicals' },
            { icon: 'fa-bowl-food', label: 'Food Supplement' },
            { icon: 'fa-bottle-droplet', label: 'Oils, Tinctures & Drops' },
            { icon: 'fa-apple-whole', label: 'Diet & Nutrition' },
            { icon: 'fa-spa', label: 'Wellness & Therapy' },
            { icon: 'fa-tree', label: 'Nature & Forest' },
            { icon: 'fa-cannabis', label: 'Botanical Extracts' },
            { icon: 'fa-wheat-awn', label: 'Organic Grains' },
            { icon: 'fa-fish', label: 'Meat & Fish' },
        ],
    },
    {
        group: '✨ Beauty, Personal Care & Home',
        icons: [
            { icon: 'fa-pump-medical', label: 'Personal Care & Sanitizer' },
            { icon: 'fa-gem', label: 'Cosmetics & Beauty' },
            { icon: 'fa-sparkles', label: 'Skin Glow' },
            { icon: 'fa-feather-pointed', label: 'Gentle Care' },
            { icon: 'fa-shirt', label: 'Fashion & Lifestyle' },
            { icon: 'fa-briefcase', label: 'Leather Items' },
            { icon: 'fa-water', label: 'Water Purifier' },
            { icon: 'fa-mug-hot', label: 'Electric Kettle' },
            { icon: 'fa-fire-burner', label: 'Cookeries Items' },
            { icon: 'fa-temperature-arrow-up', label: 'Room Heater' },
            { icon: 'fa-couch', label: 'Furniture' },
            { icon: 'fa-laptop', label: 'Electronic Devices' },
            { icon: 'fa-handshake-angle', label: 'Service' },
        ],
    },
    {
        group: '🏷️ General & Badges',
        icons: [
            { icon: 'fa-tag', label: 'Default Tag' },
            { icon: 'fa-bag-shopping', label: 'Shopping Bag' },
            { icon: 'fa-box-open', label: 'Product Box' },
            { icon: 'fa-star', label: 'Top Rated' },
            { icon: 'fa-gift', label: 'Special Offer' },
            { icon: 'fa-award', label: 'Certified / Award' },
        ],
    },
];

function parseItems(raw: string): CategoryItem[] {
    const lines = (raw || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    return lines.map((line, idx) => {
        const parts = line.split('|').map(p => p.trim());
        return {
            id: `cat-${idx}-${line.slice(0, 10).replace(/[^a-zA-Z0-9]/g, '_')}`,
            name: parts[0] || '',
            icon: parts[1] || 'fa-tag',
            link: parts[2] || '',
            badge: parts[3] || '',
        };
    });
}

function serializeItems(items: CategoryItem[]): string {
    return items
        .filter(it => it.name.trim() !== '')
        .map(it => {
            const name = it.name.trim();
            const icon = (it.icon || 'fa-tag').trim();
            const link = (it.link || '').trim();
            const badge = (it.badge || '').trim();
            if (badge) {
                return `${name} | ${icon} | ${link} | ${badge}`;
            }
            if (link) {
                return `${name} | ${icon} | ${link}`;
            }
            return `${name} | ${icon}`;
        })
        .join('\n');
}

function CategoryManager({
    value,
    defaultValue,
    onChange,
    products = []
}: {
    value?: string;
    defaultValue?: string;
    onChange: (val: string) => void;
    products?: { id: number; name: string; mrp: string; category: string }[];
}) {
    const translate = useT();
    const [mode, setMode] = useState<'visual' | 'text'>('visual');
    const [pickerOpenIdx, setPickerOpenIdx] = useState<number | null>(null);

    const rawValue = typeof value === 'string' ? value : (defaultValue || '');
    const items = parseItems(rawValue);

    const updateAndEmit = (newItems: CategoryItem[]) => {
        onChange(serializeItems(newItems));
    };

    const handleAddItem = () => {
        const newItem: CategoryItem = {
            id: `cat-new-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            name: 'New Category',
            icon: 'fa-tag',
            link: '',
            badge: '',
        };
        updateAndEmit([...items, newItem]);
    };

    const handleRemoveItem = (index: number) => {
        const target = items[index];
        if (!target) return;
        const msg = target.name.trim()
            ? translate('portal', 'cms_confirm_remove_category_named', { name: target.name })
            : translate('portal', 'cms_confirm_remove_category');
        if (window.confirm(msg)) {
            const next = items.filter((_, idx) => idx !== index);
            updateAndEmit(next);
            if (pickerOpenIdx === index) setPickerOpenIdx(null);
        }
    };

    const handleMoveUp = (index: number) => {
        if (index <= 0) return;
        const next = [...items];
        const temp = next[index - 1];
        next[index - 1] = next[index];
        next[index] = temp;
        updateAndEmit(next);
    };

    const handleMoveDown = (index: number) => {
        if (index >= items.length - 1) return;
        const next = [...items];
        const temp = next[index + 1];
        next[index + 1] = next[index];
        next[index] = temp;
        updateAndEmit(next);
    };

    const handleFieldChange = (index: number, field: keyof CategoryItem, val: string) => {
        const next = items.map((item, idx) => {
            if (idx === index) {
                return { ...item, [field]: val };
            }
            return item;
        });
        updateAndEmit(next);
    };

    const handleReset = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_categories'))) {
            onChange(defaultValue || '');
            setPickerOpenIdx(null);
        }
    };

    const handleClearAll = () => {
        if (window.confirm(translate('portal', 'cms_confirm_clear_categories'))) {
            onChange('');
            setPickerOpenIdx(null);
        }
    };

    const handleLoadFromProducts = () => {
        const catalogCats = Array.from(new Set(products.map(p => p.category).filter(Boolean))).sort();
        if (catalogCats.length === 0) {
            alert(translate('portal', 'cms_alert_no_product_categories'));
            return;
        }

        const newItems: CategoryItem[] = catalogCats.map((cat, idx) => {
            const lower = cat.toLowerCase();
            let icon = 'fa-tag';
            if (lower.includes('medicine') || lower.includes('unani') || lower.includes('syrup')) icon = 'fa-prescription-bottle-medical';
            else if (lower.includes('supplement') || lower.includes('food')) icon = 'fa-bowl-food';
            else if (lower.includes('care') || lower.includes('personal')) icon = 'fa-pump-medical';
            else if (lower.includes('cosmetic') || lower.includes('beauty')) icon = 'fa-gem';
            else if (lower.includes('equipment') || lower.includes('device') || lower.includes('diagnostic')) icon = 'fa-briefcase-medical';
            else if (lower.includes('herb') || lower.includes('natural') || lower.includes('plant')) icon = 'fa-leaf';
            else if (lower.includes('agri')) icon = 'fa-seedling';
            else if (lower.includes('water')) icon = 'fa-water';
            else if (lower.includes('service')) icon = 'fa-handshake-angle';
            else if (lower.includes('leather')) icon = 'fa-briefcase';
            else if (lower.includes('furniture')) icon = 'fa-couch';
            else if (lower.includes('meat') || lower.includes('fish')) icon = 'fa-fish';
            else if (lower.includes('kettle')) icon = 'fa-mug-hot';
            else if (lower.includes('cook')) icon = 'fa-fire-burner';
            else if (lower.includes('heater')) icon = 'fa-temperature-arrow-up';
            else if (lower.includes('electronic')) icon = 'fa-laptop';
            else if (lower.includes('fashion')) icon = 'fa-shirt';

            return {
                id: `cat-prod-${idx}-${Date.now()}`,
                name: cat,
                icon,
                link: `/products?category=${encodeURIComponent(cat)}`,
            };
        });

        updateAndEmit(newItems);
        setPickerOpenIdx(null);
    };

    return (
        <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
            {/* Header & Controls Toolbar */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <h4 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: '#2e3192' }}>
                            Featured Categories Manager
                        </h4>
                        <span style={{ fontSize: 11, fontWeight: 700, padding: '2px 8px', borderRadius: 12, background: '#e2e8f0', color: '#334155' }}>
                            {items.length} {items.length === 1 ? 'Category' : 'Categories'}
                        </span>
                    </div>
                    <small style={{ color: '#64748b', display: 'block', marginTop: 3 }}>
                        Add, remove, reorder, and configure individual category cards, icons, and target links.
                    </small>
                </div>

                {/* Mode Switcher */}
                <div style={{ display: 'flex', background: '#e2e8f0', borderRadius: 6, padding: 2 }}>
                    <button
                        type="button"
                        onClick={() => setMode('visual')}
                        style={{
                            padding: '5px 12px',
                            fontSize: 12,
                            fontWeight: 600,
                            borderRadius: 4,
                            border: 'none',
                            cursor: 'pointer',
                            background: mode === 'visual' ? '#ffffff' : 'transparent',
                            color: mode === 'visual' ? '#0f172a' : '#64748b',
                            boxShadow: mode === 'visual' ? '0 1px 2px rgba(0,0,0,0.06)' : 'none',
                        }}
                    >
                        🎨 Visual Builder
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('text')}
                        style={{
                            padding: '5px 12px',
                            fontSize: 12,
                            fontWeight: 600,
                            borderRadius: 4,
                            border: 'none',
                            cursor: 'pointer',
                            background: mode === 'text' ? '#ffffff' : 'transparent',
                            color: mode === 'text' ? '#0f172a' : '#64748b',
                            boxShadow: mode === 'text' ? '0 1px 2px rgba(0,0,0,0.06)' : 'none',
                        }}
                    >
                        📝 Raw Text Mode
                    </button>
                </div>
            </div>

            {/* Quick Action Freedom Bar */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8, padding: '10px 14px', background: '#ffffff', border: '1px solid #cbd5e1', borderRadius: 6, marginBottom: 16 }}>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={handleAddItem}
                        className="button small"
                        style={{ background: '#006837', color: '#ffffff', border: 'none', fontSize: 12, fontWeight: 700, padding: '7px 14px', borderRadius: 6, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}
                    >
                        <Plus size={15} /> Add New Category
                    </button>
                    {products.length > 0 && (
                        <button
                            type="button"
                            onClick={handleLoadFromProducts}
                            className="button small"
                            style={{ background: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0', fontSize: 12, fontWeight: 600, padding: '6px 12px', borderRadius: 6, cursor: 'pointer' }}
                        >
                            ⚡ Load from Active Products ({products.length})
                        </button>
                    )}
                </div>

                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small"
                        style={{ background: '#eff6ff', color: '#1e40af', border: '1px solid #bfdbfe', fontSize: 12, fontWeight: 600, padding: '6px 12px', borderRadius: 6, cursor: 'pointer' }}
                    >
                        ↺ Reset to reference defaults
                    </button>
                    <button
                        type="button"
                        onClick={handleClearAll}
                        className="button small"
                        style={{ background: '#fff1f2', color: '#be123c', border: '1px solid #fecdd3', fontSize: 12, fontWeight: 600, padding: '6px 12px', borderRadius: 6, cursor: 'pointer' }}
                    >
                        🗑️ Clear All
                    </button>
                </div>
            </div>

            {/* Visual Builder Mode */}
            {mode === 'visual' && (
                <div>
                    {items.length === 0 ? (
                        <div style={{ padding: '36px 20px', textAlign: 'center', background: '#fff', borderRadius: 8, border: '2px dashed #cbd5e1' }}>
                            <div style={{ fontSize: 24, marginBottom: 8 }}>🏷️</div>
                            <strong style={{ fontSize: 15, color: '#334155', display: 'block' }}>{translate('portal', 'cms_no_categories_yet')}</strong>
                            <p style={{ margin: '4px 0 16px', fontSize: 13, color: '#64748b' }}>
                                Add your first category using the button below, or reset to the 18 reference defaults.
                            </p>
                            <div style={{ display: 'flex', justifyContent: 'center', gap: 10 }}>
                                <button
                                    type="button"
                                    onClick={handleAddItem}
                                    style={{ background: '#006837', color: '#fff', border: 'none', padding: '8px 16px', borderRadius: 6, fontSize: 13, fontWeight: 600, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}
                                >
                                    <Plus size={16} /> Add First Category
                                </button>
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    style={{ background: '#f1f5f9', color: '#334155', border: '1px solid #cbd5e1', padding: '8px 16px', borderRadius: 6, fontSize: 13, fontWeight: 600, cursor: 'pointer' }}
                                >
                                    ↺ Restore Reference Defaults
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            {items.map((item, idx) => {
                                const isImage = (item.icon || '').startsWith('http') || (item.icon || '').startsWith('/') || /\.(png|jpe?g|svg|webp|gif)/i.test(item.icon || '');
                                const isPickerOpen = pickerOpenIdx === idx;

                                return (
                                    <div
                                        key={item.id || idx}
                                        style={{
                                            background: '#ffffff',
                                            border: '1px solid #cbd5e1',
                                            borderRadius: 8,
                                            padding: 14,
                                            boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                                            transition: 'border-color 0.2s ease',
                                        }}
                                    >
                                        {/* Card Top Bar */}
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10, marginBottom: 12, paddingBottom: 10, borderBottom: '1px solid #f1f5f9' }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                                {/* Reorder Buttons */}
                                                <div style={{ display: 'flex', flexDirection: 'row', gap: 2 }}>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMoveUp(idx)}
                                                        disabled={idx === 0}
                                                        title="Move category up"
                                                        style={{
                                                            padding: '3px 6px',
                                                            borderRadius: 4,
                                                            border: '1px solid #cbd5e1',
                                                            background: idx === 0 ? '#f8fafc' : '#fff',
                                                            color: idx === 0 ? '#94a3b8' : '#334155',
                                                            cursor: idx === 0 ? 'not-allowed' : 'pointer',
                                                            fontSize: 11,
                                                        }}
                                                    >
                                                        ▲
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMoveDown(idx)}
                                                        disabled={idx === items.length - 1}
                                                        title="Move category down"
                                                        style={{
                                                            padding: '3px 6px',
                                                            borderRadius: 4,
                                                            border: '1px solid #cbd5e1',
                                                            background: idx === items.length - 1 ? '#f8fafc' : '#fff',
                                                            color: idx === items.length - 1 ? '#94a3b8' : '#334155',
                                                            cursor: idx === items.length - 1 ? 'not-allowed' : 'pointer',
                                                            fontSize: 11,
                                                        }}
                                                    >
                                                        ▼
                                                    </button>
                                                </div>

                                                {/* Item Number */}
                                                <span style={{ fontSize: 12, fontWeight: 700, color: '#64748b', background: '#f1f5f9', padding: '2px 7px', borderRadius: 4 }}>
                                                    #{idx + 1}
                                                </span>

                                                {/* Live Icon / Image Preview */}
                                                <div
                                                    style={{
                                                        width: 32,
                                                        height: 32,
                                                        borderRadius: 6,
                                                        background: '#f0fdf4',
                                                        border: '1px solid #bbf7d0',
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                        fontSize: 16,
                                                        color: '#006837',
                                                        flexShrink: 0,
                                                    }}
                                                >
                                                    {isImage ? (
                                                        <img src={item.icon} alt={item.name} style={{ width: 22, height: 22, objectFit: 'contain' }} />
                                                    ) : (
                                                        <i className={`fa-solid ${item.icon || 'fa-tag'}`} aria-hidden="true" />
                                                    )}
                                                </div>

                                                <strong style={{ fontSize: 14, color: '#0f172a' }}>
                                                    {item.name || <span style={{ color: '#94a3b8', fontStyle: 'italic' }}>Untitled Category</span>}
                                                </strong>

                                                {/* Active Products Count & Status Pill */}
                                                {item.name.trim() !== '' && (
                                                    (() => {
                                                        const count = products.filter(p => (p.category || '').trim().toLowerCase() === item.name.trim().toLowerCase()).length;
                                                        return count > 0 ? (
                                                            <span style={{ fontSize: 11, fontWeight: 700, padding: '2px 8px', borderRadius: 10, background: '#dcfce7', color: '#166534' }}>
                                                                ✓ {count} active {count === 1 ? 'product' : 'products'}
                                                            </span>
                                                        ) : (
                                                            <span style={{ fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 10, background: '#fef3c7', color: '#92400e' }} title="No active products currently match this category">
                                                                ⚠️ 0 catalog products
                                                            </span>
                                                        );
                                                    })()
                                                )}

                                                {/* Badge Indicator Pill */}
                                                {item.badge && (
                                                    <span style={{ fontSize: 10, fontWeight: 800, padding: '2px 8px', borderRadius: 10, background: '#fee2e2', color: '#991b1b', textTransform: 'uppercase' }}>
                                                        ★ {item.badge}
                                                    </span>
                                                )}
                                            </div>

                                            {/* Remove / Delete Button */}
                                            <button
                                                type="button"
                                                onClick={() => handleRemoveItem(idx)}
                                                style={{
                                                    padding: '5px 10px',
                                                    borderRadius: 6,
                                                    background: '#fee2e2',
                                                    color: '#991b1b',
                                                    border: '1px solid #fecaca',
                                                    fontSize: 12,
                                                    fontWeight: 600,
                                                    cursor: 'pointer',
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: 4,
                                                }}
                                            >
                                                <Trash2 size={13} /> Remove
                                            </button>
                                        </div>

                                        {/* Card Input Fields */}
                                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
                                            {/* Category Name */}
                                            <label style={{ display: 'block' }}>
                                                <span style={{ fontSize: 11, fontWeight: 700, color: '#334155', display: 'block', marginBottom: 4 }}>
                                                    Category Name *
                                                </span>
                                                <input
                                                    type="text"
                                                    value={item.name}
                                                    placeholder="e.g. Food Supplement"
                                                    onChange={e => handleFieldChange(idx, 'name', e.target.value)}
                                                    style={{ width: '100%', padding: '7px 9px', border: '1px solid #cbd5e1', borderRadius: 5, fontSize: 12 }}
                                                />
                                            </label>

                                            {/* Icon Field & Quick Picker Toggle */}
                                            <label style={{ display: 'block' }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                                    <span style={{ fontSize: 11, fontWeight: 700, color: '#334155' }}>
                                                        Icon (FA class or image URL)
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => setPickerOpenIdx(isPickerOpen ? null : idx)}
                                                        style={{
                                                            border: 'none',
                                                            background: isPickerOpen ? '#006837' : '#e0f2fe',
                                                            color: isPickerOpen ? '#fff' : '#0369a1',
                                                            fontSize: 10,
                                                            fontWeight: 700,
                                                            padding: '2px 7px',
                                                            borderRadius: 4,
                                                            cursor: 'pointer',
                                                        }}
                                                    >
                                                        {isPickerOpen ? '✕ Close Picker' : '🎨 Pick Icon'}
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.icon}
                                                    placeholder="e.g. fa-capsules or /branding/icon.png"
                                                    onChange={e => handleFieldChange(idx, 'icon', e.target.value)}
                                                    style={{ width: '100%', padding: '7px 9px', border: '1px solid #cbd5e1', borderRadius: 5, fontSize: 12 }}
                                                />
                                            </label>

                                            {/* Destination Link */}
                                            <label style={{ display: 'block' }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                                    <span style={{ fontSize: 11, fontWeight: 700, color: '#334155' }}>
                                                        Target URL Link (Optional)
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            const autoLink = `/products?category=${encodeURIComponent(item.name.trim())}`;
                                                            handleFieldChange(idx, 'link', autoLink);
                                                        }}
                                                        style={{
                                                            border: 'none',
                                                            background: '#f1f5f9',
                                                            color: '#475569',
                                                            fontSize: 10,
                                                            fontWeight: 600,
                                                            padding: '2px 6px',
                                                            borderRadius: 4,
                                                            cursor: 'pointer',
                                                        }}
                                                    >
                                                        Auto-Catalog
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.link}
                                                    placeholder={`/products?category=${encodeURIComponent(item.name || 'Category')}`}
                                                    onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                                    style={{ width: '100%', padding: '7px 9px', border: '1px solid #cbd5e1', borderRadius: 5, fontSize: 12 }}
                                                />
                                            </label>

                                            {/* Promotional Badge */}
                                            <label style={{ display: 'block' }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                                    <span style={{ fontSize: 11, fontWeight: 700, color: '#334155' }}>
                                                        Promotional Badge (Optional)
                                                    </span>
                                                    <div style={{ display: 'flex', gap: 3 }}>
                                                        {['HOT', 'NEW', 'POPULAR', ''].map(tag => (
                                                            <button
                                                                key={tag || 'none'}
                                                                type="button"
                                                                onClick={() => handleFieldChange(idx, 'badge', tag)}
                                                                style={{
                                                                    border: 'none',
                                                                    background: item.badge === tag ? '#006837' : '#f1f5f9',
                                                                    color: item.badge === tag ? '#fff' : '#475569',
                                                                    fontSize: 9,
                                                                    fontWeight: 700,
                                                                    padding: '1px 5px',
                                                                    borderRadius: 3,
                                                                    cursor: 'pointer',
                                                                }}
                                                            >
                                                                {tag || 'Clear'}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.badge || ''}
                                                    placeholder="e.g. HOT, NEW, POPULAR, SALE"
                                                    onChange={e => handleFieldChange(idx, 'badge', e.target.value)}
                                                    style={{ width: '100%', padding: '7px 9px', border: '1px solid #cbd5e1', borderRadius: 5, fontSize: 12 }}
                                                />
                                            </label>
                                        </div>

                                        {/* Dropdown Icon Selector (Opens inline if toggled) */}
                                        {isPickerOpen && (
                                            <div
                                                style={{
                                                    marginTop: 12,
                                                    padding: 12,
                                                    background: '#f8fafc',
                                                    border: '1.5px solid #006837',
                                                    borderRadius: 8,
                                                }}
                                            >
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                                                    <strong style={{ fontSize: 12, color: '#006837' }}>
                                                        Select an Icon for "{item.name || 'Category'}"
                                                    </strong>
                                                    <span style={{ fontSize: 11, color: '#64748b' }}>
                                                        {translate('portal', 'cms_click_icon_apply')}
                                                    </span>
                                                </div>

                                                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                                    {POPULAR_CATEGORY_ICONS.map(group => (
                                                        <div key={group.group}>
                                                            <div style={{ fontSize: 11, fontWeight: 700, color: '#475569', marginBottom: 6, borderBottom: '1px solid #e2e8f0', paddingBottom: 2 }}>
                                                                {group.group}
                                                            </div>
                                                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(130px, 1fr))', gap: 6 }}>
                                                                {group.icons.map(ic => (
                                                                    <button
                                                                        key={ic.icon}
                                                                        type="button"
                                                                        onClick={() => {
                                                                            handleFieldChange(idx, 'icon', ic.icon);
                                                                            setPickerOpenIdx(null);
                                                                        }}
                                                                        style={{
                                                                            padding: '6px 8px',
                                                                            background: item.icon === ic.icon ? '#dcfce7' : '#ffffff',
                                                                            border: `1px solid ${item.icon === ic.icon ? '#15803d' : '#e2e8f0'}`,
                                                                            borderRadius: 6,
                                                                            display: 'flex',
                                                                            alignItems: 'center',
                                                                            gap: 8,
                                                                            fontSize: 11,
                                                                            color: item.icon === ic.icon ? '#15803d' : '#334155',
                                                                            cursor: 'pointer',
                                                                            textAlign: 'left',
                                                                        }}
                                                                    >
                                                                        <i className={`fa-solid ${ic.icon}`} style={{ fontSize: 14, width: 16, textAlign: 'center' }} />
                                                                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                            {ic.label}
                                                                        </span>
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}

                            {/* Bottom Add Category Button */}
                            <div style={{ display: 'flex', justifyContent: 'center', marginTop: 8 }}>
                                <button
                                    type="button"
                                    onClick={handleAddItem}
                                    style={{
                                        background: '#ffffff',
                                        color: '#006837',
                                        border: '1.5px dashed #006837',
                                        padding: '10px 24px',
                                        borderRadius: 8,
                                        fontSize: 13,
                                        fontWeight: 700,
                                        cursor: 'pointer',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 8,
                                        width: '100%',
                                        justifyContent: 'center',
                                    }}
                                >
                                    <Plus size={16} /> + Add Another Category
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Raw Text / Bulk Editor Mode */}
            {mode === 'text' && (
                <div>
                    <div style={{ marginBottom: 8, fontSize: 12, color: '#64748b' }}>
                        Type or paste categories, one per line. Format: <code>Category Name | FontAwesome Icon or Image URL | Optional Link</code>
                    </div>
                    <textarea
                        rows={12}
                        value={rawValue}
                        placeholder={defaultValue}
                        onChange={e => onChange(e.target.value)}
                        style={{
                            width: '100%',
                            fontFamily: 'monospace',
                            fontSize: 12,
                            lineHeight: 1.6,
                            padding: '10px 12px',
                            border: '1px solid #cbd5e1',
                            borderRadius: 6,
                            background: '#fff',
                        }}
                    />
                    <small style={{ display: 'block', marginTop: 6, color: '#64748b' }}>
                        Tip: You can switch back to "🎨 Visual Builder" anytime to visually arrange or pick icons for your categories.
                    </small>
                </div>
            )}
        </div>
    );
}

const POPULAR_SHORTCUT_ICONS = [
    { icon: 'fa-seedling', label: 'Seedling / Products' },
    { icon: 'fa-comments', label: 'Comments / Complaints' },
    { icon: 'fa-hand-holding-heart', label: 'Services / Care' },
    { icon: 'fa-headset', label: 'Support / Contact' },
    { icon: 'fa-diagram-project', label: 'Projects / Network' },
    { icon: 'fa-capsules', label: 'Medicine & Unani' },
    { icon: 'fa-kit-medical', label: 'Medical Kit' },
    { icon: 'fa-user-doctor', label: 'Doctor / Clinic' },
    { icon: 'fa-spa', label: 'Wellness & Spa' },
    { icon: 'fa-truck-fast', label: 'Fast Delivery' },
    { icon: 'fa-shield-halved', label: 'Quality Guarantee' },
    { icon: 'fa-location-dot', label: 'Store Locator' },
    { icon: 'fa-award', label: 'Certified / Award' },
    { icon: 'fa-store', label: 'Dealer Outlets' },
    { icon: 'fa-phone', label: 'Hotline / Phone' },
    { icon: 'fa-envelope', label: 'Email Support' },
    { icon: 'fa-circle-question', label: 'Help & FAQ' },
    { icon: 'fa-gift', label: 'Offers & Gifts' },
];

function ShortcutsManager({
    shortcuts,
    defaultShortcuts,
    enabled,
    onToggleEnabled,
    onChange,
}: {
    shortcuts: Shortcut[];
    defaultShortcuts: Shortcut[];
    enabled: boolean;
    onToggleEnabled: (enabled: boolean) => void;
    onChange: (items: Shortcut[]) => void;
}) {
    const translate = useT();
    const [pickerOpenIdx, setPickerOpenIdx] = useState<number | null>(null);

    const handleAdd = () => {
        const newItem: Shortcut = {
            id: `sc-new-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            title: 'New Shortcut',
            subtitle: 'Shortcut description',
            icon: 'fa-star',
            link: '/products',
        };
        onChange([...shortcuts, newItem]);
    };

    const handleRemove = (index: number) => {
        const target = shortcuts[index];
        const name = target?.title?.trim() || `Shortcut #${index + 1}`;
        if (window.confirm(translate('portal', 'cms_confirm_remove_named', { name }))) {
            const next = shortcuts.filter((_, idx) => idx !== index);
            onChange(next);
            if (pickerOpenIdx === index) setPickerOpenIdx(null);
        }
    };

    const handleMoveUp = (index: number) => {
        if (index <= 0) return;
        const next = [...shortcuts];
        const temp = next[index - 1];
        next[index - 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleMoveDown = (index: number) => {
        if (index >= shortcuts.length - 1) return;
        const next = [...shortcuts];
        const temp = next[index + 1];
        next[index + 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleFieldChange = (index: number, field: keyof Shortcut, val: string) => {
        const next = shortcuts.map((item, idx) => {
            if (idx === index) {
                return { ...item, [field]: val };
            }
            return item;
        });
        onChange(next);
    };

    const handleReset = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_shortcuts'))) {
            onChange(defaultShortcuts);
            setPickerOpenIdx(null);
        }
    };

    return (
        <div>
            {/* Section Visibility Switch */}
            <div style={{
                padding: '16px 20px',
                background: enabled ? '#ecfdf5' : '#f8fafc',
                border: `1.5px solid ${enabled ? '#10b981' : '#cbd5e1'}`,
                borderRadius: 10,
                marginBottom: 20,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 16,
                flexWrap: 'wrap'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                    <div style={{
                        width: 38,
                        height: 38,
                        borderRadius: '50%',
                        background: enabled ? '#d1fae5' : '#f1f5f9',
                        color: enabled ? '#006837' : '#64748b',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 18,
                        fontWeight: 700,
                        flexShrink: 0
                    }}>
                        {enabled ? '✓' : '✕'}
                    </div>
                    <div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <strong style={{ fontSize: 15, color: enabled ? '#065f46' : '#334155' }}>
                                Feature Shortcuts Section: {enabled ? 'Active & Visible on Homepage' : 'Hidden from Homepage'}
                            </strong>
                            <span style={{
                                fontSize: 11,
                                fontWeight: 700,
                                padding: '2px 8px',
                                borderRadius: 12,
                                background: enabled ? '#006837' : '#64748b',
                                color: '#fff'
                            }}>
                                {enabled ? 'ENABLED' : 'DISABLED'}
                            </span>
                        </div>
                        <p style={{ margin: '3px 0 0', fontSize: 13, color: '#475569' }}>
                            {enabled
                                ? 'The quick feature links ("Our Products", "Raise a Complaint", etc.) are currently displaying on the public homepage.'
                                : 'The feature shortcuts section is currently hidden. Click below to show it on the homepage.'}
                        </p>
                    </div>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={() => onToggleEnabled(!enabled)}
                        style={{
                            padding: '9px 18px',
                            borderRadius: 8,
                            fontSize: 13,
                            fontWeight: 700,
                            cursor: 'pointer',
                            border: 'none',
                            background: enabled ? '#fee2e2' : '#006837',
                            color: enabled ? '#991b1b' : '#ffffff',
                            boxShadow: '0 2px 4px rgba(0,0,0,0.06)',
                        }}
                    >
                        {enabled ? '✕ Disable & Hide Section' : '✓ Enable & Show Section'}
                    </button>
                </div>
            </div>

            {/* Action Bar */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#011627' }}>
                        Customizable Feature Shortcuts ({shortcuts.length} Items)
                    </h3>
                    <span style={{ fontSize: 12, color: '#64748b' }}>
                        You can add, edit, reorder or remove any shortcut below.
                    </span>
                </div>
                <div style={{ display: 'flex', gap: 8 }}>
                    <button
                        type="button"
                        onClick={handleAdd}
                        style={{
                            background: '#006837',
                            color: '#fff',
                            border: 'none',
                            padding: '8px 14px',
                            borderRadius: 6,
                            fontSize: 12,
                            fontWeight: 700,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <Plus size={14} /> + Add Shortcut
                    </button>
                    <button
                        type="button"
                        onClick={handleReset}
                        style={{
                            background: '#f1f5f9',
                            color: '#475569',
                            border: '1px solid #cbd5e1',
                            padding: '8px 12px',
                            borderRadius: 6,
                            fontSize: 12,
                            fontWeight: 600,
                            cursor: 'pointer',
                        }}
                    >
                        Reset to 5 Defaults
                    </button>
                </div>
            </div>

            {/* List of Shortcuts */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                {shortcuts.map((sc, idx) => {
                    const defSc = defaultShortcuts[idx] || { title: 'Quick Link', subtitle: 'Description', icon: 'fa-seedling', link: '/products' };
                    const isPickerOpen = pickerOpenIdx === idx;
                    const isImg = (sc.icon || '').startsWith('/') || (sc.icon || '').startsWith('http');
                    const cleanIconClass = (sc.icon || defSc.icon).startsWith('fa-') ? (sc.icon || defSc.icon) : `fa-${sc.icon || defSc.icon}`;

                    return (
                        <div
                            key={sc.id || idx}
                            style={{
                                padding: 16,
                                border: '1px solid #e2e8f0',
                                borderRadius: 10,
                                background: '#fff',
                                boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                            }}
                        >
                            {/* Card Header */}
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, paddingBottom: 8, borderBottom: '1px solid #f1f5f9' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <span style={{
                                        background: '#2e3192',
                                        color: '#fff',
                                        fontSize: 11,
                                        fontWeight: 800,
                                        width: 22,
                                        height: 22,
                                        borderRadius: '50%',
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center'
                                    }}>
                                        {idx + 1}
                                    </span>
                                    <strong style={{ fontSize: 14, color: '#0f172a' }}>
                                        {sc.title || defSc.title || `Shortcut #${idx + 1}`}
                                    </strong>
                                    {sc.subtitle && (
                                        <span style={{ fontSize: 12, color: '#64748b' }}>
                                            — {sc.subtitle}
                                        </span>
                                    )}
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                    <button
                                        type="button"
                                        disabled={idx === 0}
                                        onClick={() => handleMoveUp(idx)}
                                        title="Move Up"
                                        style={{
                                            border: '1px solid #cbd5e1',
                                            background: idx === 0 ? '#f8fafc' : '#fff',
                                            color: idx === 0 ? '#cbd5e1' : '#334155',
                                            padding: '4px 8px',
                                            borderRadius: 4,
                                            fontSize: 12,
                                            cursor: idx === 0 ? 'not-allowed' : 'pointer'
                                        }}
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={idx === shortcuts.length - 1}
                                        onClick={() => handleMoveDown(idx)}
                                        title="Move Down"
                                        style={{
                                            border: '1px solid #cbd5e1',
                                            background: idx === shortcuts.length - 1 ? '#f8fafc' : '#fff',
                                            color: idx === shortcuts.length - 1 ? '#cbd5e1' : '#334155',
                                            padding: '4px 8px',
                                            borderRadius: 4,
                                            fontSize: 12,
                                            cursor: idx === shortcuts.length - 1 ? 'not-allowed' : 'pointer'
                                        }}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(idx)}
                                        title="Delete Shortcut"
                                        style={{
                                            border: '1px solid #fecaca',
                                            background: '#fef2f2',
                                            color: '#dc2626',
                                            padding: '4px 8px',
                                            borderRadius: 4,
                                            fontSize: 12,
                                            cursor: 'pointer',
                                            fontWeight: 700
                                        }}
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>

                            {/* Card Body Grid */}
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                                {/* Title */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Title
                                    </label>
                                    <input
                                        type="text"
                                        value={sc.title}
                                        placeholder={defSc.title}
                                        onChange={e => handleFieldChange(idx, 'title', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Placeholder: {defSc.title}</small>
                                </div>

                                {/* Subtitle */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Subtitle / Caption
                                    </label>
                                    <input
                                        type="text"
                                        value={sc.subtitle}
                                        placeholder={defSc.subtitle}
                                        onChange={e => handleFieldChange(idx, 'subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Placeholder: {defSc.subtitle}</small>
                                </div>

                                {/* Icon */}
                                <div>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                        <label style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>
                                            Icon (FontAwesome or Image URL)
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => setPickerOpenIdx(isPickerOpen ? null : idx)}
                                            style={{
                                                border: 'none',
                                                background: isPickerOpen ? '#006837' : '#e0f2fe',
                                                color: isPickerOpen ? '#fff' : '#0369a1',
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 7px',
                                                borderRadius: 4,
                                                cursor: 'pointer',
                                            }}
                                        >
                                            {isPickerOpen ? '✕ Close' : '🎨 Pick Icon'}
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        value={sc.icon}
                                        placeholder={defSc.icon}
                                        onChange={e => handleFieldChange(idx, 'icon', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Placeholder: {defSc.icon}</small>
                                </div>

                                {/* Link */}
                                <div>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                        <label style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>
                                            Target Link
                                        </label>
                                        <div style={{ display: 'flex', gap: 3 }}>
                                            {[
                                                { label: 'Products', url: '/products' },
                                                { label: 'Dealers', url: '/dealers' },
                                                { label: 'About', url: '/about' },
                                                { label: 'Complaint', url: '/contact?topic=Complaint' },
                                            ].map(preset => (
                                                <button
                                                    key={preset.label}
                                                    type="button"
                                                    onClick={() => handleFieldChange(idx, 'link', preset.url)}
                                                    style={{
                                                        border: 'none',
                                                        background: '#f1f5f9',
                                                        color: '#475569',
                                                        fontSize: 9,
                                                        fontWeight: 600,
                                                        padding: '1px 5px',
                                                        borderRadius: 3,
                                                        cursor: 'pointer'
                                                    }}
                                                >
                                                    {preset.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <input
                                        type="text"
                                        value={sc.link}
                                        placeholder={defSc.link}
                                        onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Placeholder: {defSc.link}</small>
                                </div>
                            </div>

                            {/* Icon Picker Popdown */}
                            {isPickerOpen && (
                                <div style={{ marginTop: 12, padding: 12, background: '#f8fafc', border: '1.5px solid #006837', borderRadius: 8 }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                        <strong style={{ fontSize: 12, color: '#006837' }}>Choose an Icon:</strong>
                                        <span style={{ fontSize: 11, color: '#64748b' }}>{translate('portal', 'cms_click_icon_apply_short')}</span>
                                    </div>
                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(130px, 1fr))', gap: 6 }}>
                                        {POPULAR_SHORTCUT_ICONS.map(ic => (
                                            <button
                                                key={ic.icon}
                                                type="button"
                                                onClick={() => {
                                                    handleFieldChange(idx, 'icon', ic.icon);
                                                    setPickerOpenIdx(null);
                                                }}
                                                style={{
                                                    padding: '6px 8px',
                                                    background: sc.icon === ic.icon ? '#dcfce7' : '#ffffff',
                                                    border: `1px solid ${sc.icon === ic.icon ? '#15803d' : '#e2e8f0'}`,
                                                    borderRadius: 6,
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: 8,
                                                    fontSize: 11,
                                                    color: sc.icon === ic.icon ? '#15803d' : '#334155',
                                                    cursor: 'pointer',
                                                    textAlign: 'left',
                                                }}
                                            >
                                                <i className={`fa-solid ${ic.icon}`} style={{ fontSize: 14, width: 16, textAlign: 'center' }} />
                                                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {ic.label}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Live Card Preview Box */}
                            <div style={{ marginTop: 12, paddingTop: 10, borderTop: '1px dashed #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
                                <span style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.5 }}>
                                    Homepage Live Card Preview:
                                </span>
                                <div style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 12,
                                    padding: '10px 16px',
                                    background: '#ffffff',
                                    border: '1px solid #e2e8f0',
                                    borderRadius: 8,
                                    boxShadow: '0 2px 5px rgba(0,0,0,0.05)',
                                    minWidth: 220
                                }}>
                                    {isImg ? (
                                        <img src={sc.icon} alt={sc.title} style={{ width: 26, height: 26, objectFit: 'contain' }} />
                                    ) : (
                                        <i className={`fa-solid ${cleanIconClass}`} style={{ fontSize: 24, color: '#f34c20' }} />
                                    )}
                                    <div>
                                        <div style={{ fontSize: 13, fontWeight: 700, color: '#011627', lineHeight: 1.2 }}>
                                            {sc.title || defSc.title}
                                        </div>
                                        {(sc.subtitle || defSc.subtitle) && (
                                            <div style={{ fontSize: 10, color: '#64748b', marginTop: 2 }}>
                                                {sc.subtitle || defSc.subtitle}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Bottom Add Shortcut Button */}
            <div style={{ display: 'flex', justifyContent: 'center', marginTop: 14 }}>
                <button
                    type="button"
                    onClick={handleAdd}
                    style={{
                        background: '#ffffff',
                        color: '#006837',
                        border: '1.5px dashed #006837',
                        padding: '10px 24px',
                        borderRadius: 8,
                        fontSize: 13,
                        fontWeight: 700,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        width: '100%',
                        justifyContent: 'center',
                    }}
                >
                    <Plus size={16} /> + Add Another Feature Shortcut Card
                </button>
            </div>
        </div>
    );
}

export function CampaignOffersManager({
    cards,
    defaultCards,
    enabled,
    badge,
    title,
    subtitle,
    defaultBadge,
    defaultTitle,
    defaultSubtitle,
    onToggleEnabled,
    onUpdateHeaderField,
    onChange,
    products = [],
}: {
    cards: CampaignCardItem[];
    defaultCards: CampaignCardItem[];
    enabled: boolean;
    badge: string;
    title: string;
    subtitle: string;
    defaultBadge: string;
    defaultTitle: string;
    defaultSubtitle: string;
    onToggleEnabled: (enabled: boolean) => void;
    onUpdateHeaderField: (key: 'campaign_badge' | 'campaign_title' | 'campaign_subtitle', val: string) => void;
    onChange: (cards: CampaignCardItem[]) => void;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string }[];
}) {
    const translate = useT();
    const [bundleSelectedProds, setBundleSelectedProds] = useState<Record<number, string>>({});

    const parsePrice = (val: any): number => {
        if (typeof val === 'number') return val;
        if (!val) return 0;
        const clean = String(val).replace(/[^\d.]/g, '');
        const num = parseFloat(clean);
        return isNaN(num) ? 0 : num;
    };

    const handleAddSingle = () => {
        const newCard: CampaignCardItem = {
            id: `card-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            type: 'single',
            product_id: '',
            promo_discount_pct: 60,
            bundle_discount_mode: 'total',
            bundle_discount_pct: 35,
            bundle_items: [],
            tag: 'Special Offer',
            title: 'New Single Product Offer',
            desc: 'Exclusive promotional discount on natural wellness care.',
            feat1: '100% Pure Botanical Ingredients',
            feat2: 'Fast Nationwide Delivery & Support',
            price: '',
            mrp: '',
            save: '',
            btn: 'View Offer',
            link: '/products',
            image: '',
            highlight: '0',
        };
        onChange([...cards, newCard]);
    };

    const handleAddBundle = () => {
        const newCard: CampaignCardItem = {
            id: `card-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            type: 'bundle',
            product_id: '',
            promo_discount_pct: 0,
            bundle_discount_mode: 'total',
            bundle_discount_pct: 35,
            bundle_items: [],
            tag: 'Package Deal',
            title: 'New Promotional Wellness Package',
            desc: 'Exclusive curated package combining essential healthcare products at an extra discount.',
            feat1: 'Curated Complete Treatment Course',
            feat2: 'Free Priority Dealer Shipping Included',
            price: '',
            mrp: '',
            save: '',
            btn: 'View Package',
            link: '/products',
            image: '',
            highlight: '0',
        };
        onChange([...cards, newCard]);
    };

    const handleRemove = (index: number) => {
        const target = cards[index];
        const name = target?.title?.trim() || `Offer Card #${index + 1}`;
        if (window.confirm(translate('portal', 'cms_confirm_remove_promo', { name }))) {
            const next = cards.filter((_, idx) => idx !== index);
            onChange(next);
        }
    };

    const handleMoveUp = (index: number) => {
        if (index <= 0) return;
        const next = [...cards];
        const temp = next[index - 1];
        next[index - 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleMoveDown = (index: number) => {
        if (index >= cards.length - 1) return;
        const next = [...cards];
        const temp = next[index + 1];
        next[index + 1] = next[index];
        next[index] = temp;
        onChange(next);
    };

    const handleFieldChange = (index: number, field: keyof CampaignCardItem, val: any) => {
        const next = cards.map((c, idx) => {
            if (idx === index) {
                return { ...c, [field]: val };
            }
            return c;
        });
        onChange(next);
    };

    const handleSwitchCardType = (index: number, newType: 'single' | 'bundle') => {
        const target = cards[index];
        if (!target || target.type === newType) return;

        const next = cards.map((c, idx) => {
            if (idx === index) {
                if (newType === 'bundle') {
                    return {
                        ...c,
                        type: 'bundle' as const,
                        bundle_discount_mode: c.bundle_discount_mode || ('total' as const),
                        bundle_discount_pct: c.bundle_discount_pct ?? 35,
                        bundle_items: c.bundle_items || [],
                        tag: c.tag === 'Special Offer' ? 'Package Deal' : c.tag,
                    };
                } else {
                    return {
                        ...c,
                        type: 'single' as const,
                        promo_discount_pct: c.promo_discount_pct ?? 60,
                        tag: c.tag === 'Package Deal' ? 'Special Offer' : c.tag,
                    };
                }
            }
            return c;
        });
        onChange(next);
    };

    // Mode A: Single Product Select
    const handleProductSelect = (index: number, productId: string) => {
        const p = products.find(prod => String(prod.id) === productId);
        if (!p) {
            handleFieldChange(index, 'product_id', '');
            return;
        }

        const numMrp = parsePrice(p.mrp);
        const target = cards[index];
        const promoPct = parsePrice(target?.promo_discount_pct) || 60;
        const calcPrice = numMrp > 0 ? Math.round(numMrp * (1 - promoPct / 100)) : 0;
        const calcSave = numMrp > 0 ? Math.max(0, numMrp - calcPrice) : 0;

        const next = cards.map((c, idx) => {
            if (idx === index) {
                return {
                    ...c,
                    type: 'single' as const,
                    product_id: String(p.id),
                    promo_discount_pct: promoPct,
                    link: `/products/${p.id}`,
                    mrp: numMrp > 0 ? `${numMrp.toLocaleString()} Tk` : c.mrp,
                    price: numMrp > 0 ? `${calcPrice.toLocaleString()} Tk` : c.price,
                    save: numMrp > 0 ? `Save ${calcSave.toLocaleString()} Tk` : c.save,
                    title: (!c.title || c.title === 'New Promotional Offer' || c.title === 'New Single Product Offer' || c.title.includes('Package')) ? p.name : c.title,
                };
            }
            return c;
        });
        onChange(next);
    };

    // Mode A: Single Product Promo Discount % Change
    const handlePromoDiscountChange = (index: number, newPctVal: number | string) => {
        const pct = parsePrice(newPctVal);
        const target = cards[index];
        if (!target) return;

        let numMrp = parsePrice(target.mrp);
        if ((!numMrp || numMrp === 0) && target.product_id) {
            const p = products.find(prod => String(prod.id) === target.product_id);
            if (p) numMrp = parsePrice(p.mrp);
        }

        const calcPrice = numMrp > 0 ? Math.round(numMrp * (1 - pct / 100)) : parsePrice(target.price);
        const calcSave = numMrp > 0 ? Math.max(0, numMrp - calcPrice) : 0;

        const next = cards.map((c, idx) => {
            if (idx === index) {
                return {
                    ...c,
                    promo_discount_pct: pct,
                    price: numMrp > 0 ? `${calcPrice.toLocaleString()} Tk` : c.price,
                    save: numMrp > 0 ? `Save ${calcSave.toLocaleString()} Tk` : c.save,
                };
            }
            return c;
        });
        onChange(next);
    };

    // Mode B: Recalculate Bundle Card
    const recalculateBundleCard = (
        cardIndex: number,
        items: CampaignBundleItem[],
        mode: 'total' | 'itemized',
        bundleDiscountPct: number | string
    ) => {
        const pct = parsePrice(bundleDiscountPct);
        const totalMrp = items.reduce((acc, it) => acc + parsePrice(it.mrp), 0);

        let packagePrice = 0;
        if (mode === 'itemized') {
            packagePrice = items.reduce((acc, it) => {
                const itPrice = it.item_price !== undefined
                    ? parsePrice(it.item_price)
                    : Math.round(parsePrice(it.mrp) * (1 - (parsePrice(it.custom_discount_pct ?? pct) / 100)));
                return acc + itPrice;
            }, 0);
        } else {
            packagePrice = Math.round(totalMrp * (1 - pct / 100));
        }
        const saveAmt = Math.max(0, totalMrp - packagePrice);

        const next = cards.map((c, idx) => {
            if (idx === cardIndex) {
                return {
                    ...c,
                    type: 'bundle' as const,
                    bundle_discount_mode: mode,
                    bundle_discount_pct: pct,
                    bundle_items: items,
                    mrp: totalMrp > 0 ? `${totalMrp.toLocaleString()} Tk` : c.mrp,
                    price: totalMrp > 0 ? `${packagePrice.toLocaleString()} Tk` : c.price,
                    save: totalMrp > 0 ? `Save ${saveAmt.toLocaleString()} Tk` : c.save,
                };
            }
            return c;
        });
        onChange(next);
    };

    // Mode B: Add Product to Bundle
    const handleAddBundleItem = (cardIndex: number) => {
        const prodId = bundleSelectedProds[cardIndex];
        if (!prodId) {
            alert(translate('portal', 'cms_alert_select_product'));
            return;
        }
        const p = products.find(prod => String(prod.id) === prodId);
        if (!p) return;

        const target = cards[cardIndex];
        const existingItems = target.bundle_items || [];

        if (existingItems.some(item => String(item.product_id) === String(p.id))) {
            alert(translate('portal', 'cms_alert_already_in_package', { name: p.name }));
            return;
        }

        const numMrp = parsePrice(p.mrp);
        const mode = target.bundle_discount_mode || 'total';
        const pct = target.bundle_discount_pct ?? 35;
        const newItem: CampaignBundleItem = {
            product_id: p.id,
            name: p.name,
            sku: p.sku || '',
            mrp: numMrp,
            custom_discount_pct: pct,
            item_price: Math.round(numMrp * (1 - parsePrice(pct) / 100)),
        };

        const newItems = [...existingItems, newItem];
        recalculateBundleCard(cardIndex, newItems, mode, pct);
        setBundleSelectedProds(prev => ({ ...prev, [cardIndex]: '' }));
    };

    // Mode B: Remove Product from Bundle
    const handleRemoveBundleItem = (cardIndex: number, itemIndex: number) => {
        const target = cards[cardIndex];
        const newItems = (target.bundle_items || []).filter((_, idx) => idx !== itemIndex);
        recalculateBundleCard(
            cardIndex,
            newItems,
            target.bundle_discount_mode || 'total',
            target.bundle_discount_pct ?? 35
        );
    };

    // Mode B: Change Itemized Discount on specific item
    const handleItemizedDiscountChange = (cardIndex: number, itemIndex: number, newPct: number) => {
        const target = cards[cardIndex];
        const items = [...(target.bundle_items || [])];
        const item = items[itemIndex];
        if (!item) return;

        const numMrp = parsePrice(item.mrp);
        const itemPrice = Math.round(numMrp * (1 - newPct / 100));
        items[itemIndex] = {
            ...item,
            custom_discount_pct: newPct,
            item_price: itemPrice,
        };
        recalculateBundleCard(cardIndex, items, 'itemized', target.bundle_discount_pct ?? 35);
    };

    const handleAutoCalculateSave = (index: number) => {
        const target = cards[index];
        if (!target) return;
        const numP = parsePrice(target.price);
        const numM = parsePrice(target.mrp);
        if (numM > 0 && numP > 0) {
            if (numM > numP) {
                const diff = numM - numP;
                handleFieldChange(index, 'save', `Save ${diff.toLocaleString()} Tk`);
            } else {
                alert(translate('portal', 'cms_alert_mrp_gt_offer'));
            }
        } else {
            alert(translate('portal', 'cms_alert_enter_prices'));
        }
    };

    const handleReset = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_promos'))) {
            onChange(defaultCards);
        }
    };

    return (
        <div>
            {/* Master Visibility Switch */}
            <div style={{
                padding: '16px 20px',
                background: enabled ? '#ecfdf5' : '#f8fafc',
                border: `1.5px solid ${enabled ? '#10b981' : '#cbd5e1'}`,
                borderRadius: 10,
                marginBottom: 24,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 16,
                flexWrap: 'wrap'
            }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
                    <div style={{
                        width: 38,
                        height: 38,
                        borderRadius: '50%',
                        background: enabled ? '#d1fae5' : '#f1f5f9',
                        color: enabled ? '#006837' : '#64748b',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 18,
                        fontWeight: 700,
                        flexShrink: 0
                    }}>
                        {enabled ? '✓' : '✕'}
                    </div>
                    <div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <strong style={{ fontSize: 15, color: enabled ? '#065f46' : '#334155' }}>
                                Campaign & Special Offers Status: {enabled ? 'Active & Visible on Homepage' : 'Hidden from Homepage'}
                            </strong>
                            <span style={{
                                fontSize: 11,
                                fontWeight: 700,
                                padding: '2px 8px',
                                borderRadius: 12,
                                background: enabled ? '#006837' : '#64748b',
                                color: '#fff'
                            }}>
                                {enabled ? 'ENABLED' : 'DISABLED'}
                            </span>
                        </div>
                        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#475569' }}>
                            {enabled
                                ? `The promotional offer section is displaying on the public homepage with ${cards.length} active offer card(s).`
                                : 'The promotional offer section is currently hidden. Click the button to re-enable and display it on the public homepage.'}
                        </p>
                    </div>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={() => onToggleEnabled(!enabled)}
                        style={{
                            padding: '10px 20px',
                            borderRadius: 8,
                            fontSize: 13,
                            fontWeight: 700,
                            cursor: 'pointer',
                            border: 'none',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            background: enabled ? '#fee2e2' : '#006837',
                            color: enabled ? '#991b1b' : '#ffffff',
                            boxShadow: '0 2px 4px rgba(0,0,0,0.06)',
                            transition: 'all 0.2s ease'
                        }}
                    >
                        {enabled ? '✕ Disable & Hide Section' : '✓ Enable & Show Section'}
                    </button>
                </div>
            </div>

            {/* Header Titles */}
            <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc', marginBottom: 24 }}>
                <h4 style={{ margin: '0 0 14px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Section Headings & Banner Text</h4>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
                    <div>
                        <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                            Section Badge Label
                        </label>
                        <input
                            type="text"
                            value={badge}
                            placeholder={defaultBadge}
                            onChange={e => onUpdateHeaderField('campaign_badge', e.target.value)}
                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                        />
                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaultBadge}</small>
                    </div>
                    <div>
                        <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                            Section Main Title
                        </label>
                        <input
                            type="text"
                            value={title}
                            placeholder={defaultTitle}
                            onChange={e => onUpdateHeaderField('campaign_title', e.target.value)}
                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                        />
                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaultTitle}</small>
                    </div>
                    <div style={{ gridColumn: '1 / -1' }}>
                        <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                            Section Subtitle / Description
                        </label>
                        <input
                            type="text"
                            value={subtitle}
                            placeholder={defaultSubtitle}
                            onChange={e => onUpdateHeaderField('campaign_subtitle', e.target.value)}
                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                        />
                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaultSubtitle}</small>
                    </div>
                </div>
            </div>

            {/* Action Bar for Offers */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 }}>
                <div>
                    <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#011627' }}>
                        Promotional Offer Cards ({cards.length} Cards)
                    </h3>
                    <span style={{ fontSize: 12, color: '#64748b' }}>
                        Add unlimited single product offers or multi-product bundle packages from your catalog.
                    </span>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={handleAddSingle}
                        style={{
                            background: '#006837',
                            color: '#fff',
                            border: 'none',
                            padding: '8px 16px',
                            borderRadius: 6,
                            fontSize: 12,
                            fontWeight: 700,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <Plus size={15} /> + Add Single Product Offer
                    </button>
                    <button
                        type="button"
                        onClick={handleAddBundle}
                        style={{
                            background: '#0284c7',
                            color: '#fff',
                            border: 'none',
                            padding: '8px 16px',
                            borderRadius: 6,
                            fontSize: 12,
                            fontWeight: 700,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <Plus size={15} /> + Add Group / Package Bundle
                    </button>
                    <button
                        type="button"
                        onClick={handleReset}
                        style={{
                            background: '#f1f5f9',
                            color: '#475569',
                            border: '1px solid #cbd5e1',
                            padding: '8px 12px',
                            borderRadius: 6,
                            fontSize: 12,
                            fontWeight: 600,
                            cursor: 'pointer',
                        }}
                    >
                        Reset to Defaults
                    </button>
                </div>
            </div>

            {/* Offer Cards List */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
                {cards.map((card, idx) => {
                    const isHighlight = card.highlight === '1' || card.highlight === true;
                    const cardType: 'single' | 'bundle' = card.type || (card.bundle_items && card.bundle_items.length > 0 ? 'bundle' : 'single');
                    const defCard = defaultCards[idx] || {
                        tag: cardType === 'bundle' ? 'Package Deal' : 'Special Offer',
                        title: cardType === 'bundle' ? 'Promotional Wellness Package' : 'Single Product Special Offer',
                        desc: '',
                        feat1: '',
                        feat2: '',
                        price: '',
                        mrp: '',
                        save: '',
                        btn: cardType === 'bundle' ? 'View Package' : 'View Offer',
                        link: '/products',
                        image: '',
                        highlight: '0'
                    };

                    // For Single Product Offer: Find catalog product details
                    const linkedProduct = card.product_id ? products.find(p => String(p.id) === String(card.product_id)) : undefined;
                    const linkedMrp = linkedProduct ? parsePrice(linkedProduct.mrp) : parsePrice(card.mrp);
                    const linkedDealerPrice = linkedProduct?.dealer_price ? parsePrice(linkedProduct.dealer_price) : 0;
                    const regDealerDiscountPct = linkedMrp > 0 && linkedDealerPrice > 0 ? Math.round((1 - linkedDealerPrice / linkedMrp) * 100) : 0;
                    const currentPromoDiscountPct = parsePrice(card.promo_discount_pct) || (linkedMrp > 0 && parsePrice(card.price) > 0 ? Math.round((1 - parsePrice(card.price) / linkedMrp) * 100) : 60);

                    // For Bundle Offer: Calculate total MRP and items
                    const bundleItems = card.bundle_items || [];
                    const totalBundleMrp = bundleItems.reduce((acc, it) => acc + parsePrice(it.mrp), 0);
                    const bundleMode = card.bundle_discount_mode || 'total';
                    const bundlePct = parsePrice(card.bundle_discount_pct ?? 35);
                    const effectiveBundlePrice = parsePrice(card.price);
                    const effectiveBundleSave = parsePrice(card.save) || (totalBundleMrp > 0 ? Math.max(0, totalBundleMrp - effectiveBundlePrice) : 0);
                    const effectiveBundleDiscountPct = totalBundleMrp > 0 ? Math.round((effectiveBundleSave / totalBundleMrp) * 100) : bundlePct;

                    return (
                        <div
                            key={card.id || idx}
                            style={{
                                padding: 20,
                                border: isHighlight ? '2px solid #006837' : (cardType === 'bundle' ? '1.5px solid #bae6fd' : '1px solid #e2e8f0'),
                                borderRadius: 10,
                                background: isHighlight
                                    ? 'linear-gradient(180deg, #ffffff 0%, #f6fbf8 100%)'
                                    : (cardType === 'bundle' ? 'linear-gradient(180deg, #ffffff 0%, #f0f9ff 100%)' : '#ffffff'),
                                boxShadow: isHighlight ? '0 4px 14px rgba(0, 104, 55, 0.08)' : '0 2px 6px rgba(0,0,0,0.04)',
                            }}
                        >
                            {/* Card Top Control Bar */}
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, paddingBottom: 10, borderBottom: '1px solid #f1f5f9', flexWrap: 'wrap', gap: 10 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                                    <span style={{
                                        background: cardType === 'bundle' ? '#0284c7' : (isHighlight ? '#006837' : '#2e3192'),
                                        color: '#fff',
                                        fontSize: 12,
                                        fontWeight: 800,
                                        width: 26,
                                        height: 26,
                                        borderRadius: '50%',
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center'
                                    }}>
                                        {idx + 1}
                                    </span>
                                    <strong style={{ fontSize: 15, color: '#011627' }}>
                                        {card.title || defCard.title || `Offer Card #${idx + 1}`}
                                    </strong>

                                    {/* Mode Indicator & Switcher */}
                                    <div style={{ display: 'inline-flex', background: '#f1f5f9', borderRadius: 20, padding: 2, border: '1px solid #cbd5e1' }}>
                                        <button
                                            type="button"
                                            onClick={() => handleSwitchCardType(idx, 'single')}
                                            style={{
                                                padding: '2px 10px',
                                                borderRadius: 18,
                                                fontSize: 11,
                                                fontWeight: 700,
                                                border: 'none',
                                                cursor: 'pointer',
                                                background: cardType === 'single' ? '#006837' : 'transparent',
                                                color: cardType === 'single' ? '#fff' : '#64748b',
                                                transition: 'all 0.15s ease'
                                            }}
                                        >
                                            🏷️ Single Product
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleSwitchCardType(idx, 'bundle')}
                                            style={{
                                                padding: '2px 10px',
                                                borderRadius: 18,
                                                fontSize: 11,
                                                fontWeight: 700,
                                                border: 'none',
                                                cursor: 'pointer',
                                                background: cardType === 'bundle' ? '#0284c7' : 'transparent',
                                                color: cardType === 'bundle' ? '#fff' : '#64748b',
                                                transition: 'all 0.15s ease'
                                            }}
                                        >
                                            📦 Group Package Bundle
                                        </button>
                                    </div>

                                    {isHighlight && (
                                        <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 12, background: '#dcfce7', color: '#15803d' }}>
                                            ★ HIGHLIGHTED
                                        </span>
                                    )}
                                </div>

                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                    {/* Highlight Toggle Button */}
                                    <button
                                        type="button"
                                        onClick={() => handleFieldChange(idx, 'highlight', isHighlight ? '0' : '1')}
                                        style={{
                                            border: `1px solid ${isHighlight ? '#15803d' : '#cbd5e1'}`,
                                            background: isHighlight ? '#006837' : '#f8fafc',
                                            color: isHighlight ? '#ffffff' : '#475569',
                                            padding: '4px 10px',
                                            borderRadius: 5,
                                            fontSize: 11,
                                            fontWeight: 700,
                                            cursor: 'pointer',
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 4
                                        }}
                                    >
                                        ★ {isHighlight ? 'Highlighted (Active)' : 'Set as Highlighted'}
                                    </button>

                                    {/* Reorder Buttons */}
                                    <button
                                        type="button"
                                        disabled={idx === 0}
                                        onClick={() => handleMoveUp(idx)}
                                        title="Move Up"
                                        style={{
                                            border: '1px solid #cbd5e1',
                                            background: idx === 0 ? '#f8fafc' : '#fff',
                                            color: idx === 0 ? '#cbd5e1' : '#334155',
                                            padding: '4px 8px',
                                            borderRadius: 4,
                                            fontSize: 12,
                                            cursor: idx === 0 ? 'not-allowed' : 'pointer'
                                        }}
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={idx === cards.length - 1}
                                        onClick={() => handleMoveDown(idx)}
                                        title="Move Down"
                                        style={{
                                            border: '1px solid #cbd5e1',
                                            background: idx === cards.length - 1 ? '#f8fafc' : '#fff',
                                            color: idx === cards.length - 1 ? '#cbd5e1' : '#334155',
                                            padding: '4px 8px',
                                            borderRadius: 4,
                                            fontSize: 12,
                                            cursor: idx === cards.length - 1 ? 'not-allowed' : 'pointer'
                                        }}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(idx)}
                                        title="Delete Offer Card"
                                        style={{
                                            border: '1px solid #fecaca',
                                            background: '#fef2f2',
                                            color: '#dc2626',
                                            padding: '4px 8px',
                                            borderRadius: 4,
                                            fontSize: 12,
                                            cursor: 'pointer',
                                            fontWeight: 700
                                        }}
                                    >
                                        ✕ Delete
                                    </button>
                                </div>
                            </div>

                            {/* MODE A: SINGLE PRODUCT OFFER CONFIGURATION */}
                            {cardType === 'single' && (
                                <div style={{ marginBottom: 20 }}>
                                    {/* Catalog Product Selection Bar */}
                                    <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, padding: 14, marginBottom: 14 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6, flexWrap: 'wrap', gap: 8 }}>
                                            <span style={{ fontSize: 13, fontWeight: 700, color: '#006837' }}>
                                                🏷️ Select Existing Catalog Product to Feature:
                                            </span>
                                            {card.product_id && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleFieldChange(idx, 'product_id', '')}
                                                    style={{
                                                        border: 'none',
                                                        background: '#fee2e2',
                                                        color: '#991b1b',
                                                        fontSize: 11,
                                                        fontWeight: 700,
                                                        padding: '3px 8px',
                                                        borderRadius: 4,
                                                        cursor: 'pointer',
                                                    }}
                                                >
                                                    ✕ Disconnect Product
                                                </button>
                                            )}
                                        </div>
                                        <select
                                            value={card.product_id || ''}
                                            onChange={e => handleProductSelect(idx, e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13, background: '#fff' }}
                                        >
                                            <option value="">-- Choose Existing Product from Catalog --</option>
                                            {products.map(p => (
                                                <option key={p.id} value={p.id}>
                                                    #{p.id}: {p.name} (MRP: {p.mrp} Tk) {p.sku ? `[SKU: ${p.sku}]` : ''} — Category: {p.category}
                                                </option>
                                            ))}
                                        </select>

                                        {/* Product Base Details & Promotional Discount Rate Setting */}
                                        {linkedProduct ? (
                                            <div style={{ marginTop: 12, paddingTop: 10, borderTop: '1px dashed #bbf7d0' }}>
                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10, marginBottom: 12 }}>
                                                    <div style={{ background: '#fff', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}>
                                                        <span style={{ fontSize: 11, color: '#64748b', display: 'block' }}>Regular Retail MRP:</span>
                                                        <strong style={{ fontSize: 14, color: '#0f172a' }}>৳ {linkedMrp.toLocaleString()}</strong>
                                                    </div>
                                                    <div style={{ background: '#fff', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}>
                                                        <span style={{ fontSize: 11, color: '#64748b', display: 'block' }}>Base Dealer Catalog Price:</span>
                                                        <strong style={{ fontSize: 14, color: '#475569' }}>
                                                            {linkedDealerPrice > 0 ? `৳ ${linkedDealerPrice.toLocaleString()} (${regDealerDiscountPct}% off)` : 'Standard MRP'}
                                                        </strong>
                                                    </div>
                                                    <div style={{ background: '#fff', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}>
                                                        <span style={{ fontSize: 11, color: '#64748b', display: 'block' }}>Product SKU / Code:</span>
                                                        <strong style={{ fontSize: 13, color: '#334155' }}>{linkedProduct.sku || `#${linkedProduct.id}`}</strong>
                                                    </div>
                                                </div>

                                                {/* Promotional Discount % Input & Quick Presets */}
                                                <div style={{ background: '#ffffff', padding: '12px 14px', borderRadius: 8, border: '1.5px solid #10b981' }}>
                                                    <label style={{ fontSize: 13, fontWeight: 700, color: '#065f46', display: 'block', marginBottom: 6 }}>
                                                        ⚡ Set Special Promotional Discount Percentage for this Campaign Offer:
                                                    </label>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={99}
                                                                value={card.promo_discount_pct ?? 60}
                                                                onChange={e => handlePromoDiscountChange(idx, e.target.value)}
                                                                style={{ width: 80, padding: '7px 10px', border: '1.5px solid #006837', borderRadius: 6, fontSize: 14, fontWeight: 700, color: '#006837' }}
                                                            />
                                                            <strong style={{ fontSize: 15, color: '#006837' }}>%</strong>
                                                        </div>

                                                        {/* Quick Presets */}
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                                                            <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>Quick Presets:</span>
                                                            {[30, 40, 50, 60, 70].map(pct => (
                                                                <button
                                                                    key={pct}
                                                                    type="button"
                                                                    onClick={() => handlePromoDiscountChange(idx, pct)}
                                                                    style={{
                                                                        padding: '4px 10px',
                                                                        borderRadius: 4,
                                                                        fontSize: 11,
                                                                        fontWeight: 700,
                                                                        border: `1px solid ${currentPromoDiscountPct === pct ? '#006837' : '#cbd5e1'}`,
                                                                        background: currentPromoDiscountPct === pct ? '#006837' : '#f8fafc',
                                                                        color: currentPromoDiscountPct === pct ? '#ffffff' : '#334155',
                                                                        cursor: 'pointer'
                                                                    }}
                                                                >
                                                                    {pct}%
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <small style={{ color: '#64748b', display: 'block', marginTop: 6 }}>
                                                Select a catalog product to automatically sync real retail MRP, apply promotional dealer discount, and calculate system cash price.
                                            </small>
                                        )}
                                    </div>

                                    {/* Financial Breakdown & Accounting Rule Card */}
                                    {card.product_id && linkedMrp > 0 && (
                                        <div style={{ background: '#f0fdf4', border: '1.5px solid #86efac', borderRadius: 8, padding: '12px 16px', marginBottom: 14 }}>
                                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8, flexWrap: 'wrap', gap: 6 }}>
                                                <span style={{ fontSize: 13, fontWeight: 700, color: '#15803d', display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    💰 System Cash Inflow & Promotional Discount Accounting:
                                                </span>
                                                <span style={{ fontSize: 11, background: '#16a34a', color: '#fff', padding: '2px 8px', borderRadius: 10, fontWeight: 700 }}>
                                                    {currentPromoDiscountPct}% Promotional Discount Active
                                                </span>
                                            </div>
                                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 10, marginBottom: 8 }}>
                                                <div style={{ background: '#fff', padding: '8px 12px', borderRadius: 6, border: '1px solid #bbf7d0' }}>
                                                    <span style={{ fontSize: 11, color: '#64748b', display: 'block' }}>Regular Retail MRP</span>
                                                    <strong style={{ fontSize: 14, color: '#334155' }}>৳ {linkedMrp.toLocaleString()}</strong>
                                                </div>
                                                <div style={{ background: '#ecfdf5', padding: '8px 12px', borderRadius: 6, border: '1.5px solid #86efac' }}>
                                                    <span style={{ fontSize: 11, color: '#065f46', display: 'block' }}>Cash Inflow (Discounted Price)</span>
                                                    <strong style={{ fontSize: 15, color: '#006837' }}>৳ {parsePrice(card.price).toLocaleString()}</strong>
                                                </div>
                                                <div style={{ background: '#fef2f2', padding: '8px 12px', borderRadius: 6, border: '1px solid #fecaca' }}>
                                                    <span style={{ fontSize: 11, color: '#991b1b', display: 'block' }}>Dealer Margin Saved</span>
                                                    <strong style={{ fontSize: 14, color: '#dc2626' }}>৳ {parsePrice(card.save).toLocaleString()}</strong>
                                                </div>
                                            </div>
                                            <p style={{ margin: 0, fontSize: 11, color: '#166534', lineHeight: 1.4 }}>
                                                ℹ️ <strong>Precedence & Reversion Rule:</strong> This <strong>{currentPromoDiscountPct}%</strong> promotional discount overrides the product's regular catalog dealer price while listed here. The <strong>৳{parsePrice(card.price).toLocaleString()}</strong> collected from the dealer is recognized as cash inflow. If removed from this section, the product automatically reverts to its catalog price.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* MODE B: GROUP / PACKAGE BUNDLE CONFIGURATION */}
                            {cardType === 'bundle' && (
                                <div style={{ marginBottom: 20 }}>
                                    {/* Bundle Multi-Product Picker */}
                                    <div style={{ background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 8, padding: 14, marginBottom: 14 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10, flexWrap: 'wrap', gap: 8 }}>
                                            <div>
                                                <strong style={{ fontSize: 14, color: '#0369a1', display: 'block' }}>
                                                    📦 Curate Multi-Product Bundle Package ({bundleItems.length} Products Included)
                                                </strong>
                                                <span style={{ fontSize: 11, color: '#64748b' }}>
                                                    Select existing catalog products to combine into this promotional package.
                                                </span>
                                            </div>
                                        </div>

                                        {/* Product Add Bar */}
                                        <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
                                            <select
                                                value={bundleSelectedProds[idx] || ''}
                                                onChange={e => setBundleSelectedProds(prev => ({ ...prev, [idx]: e.target.value }))}
                                                style={{ flex: 1, minWidth: 260, padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13, background: '#fff' }}
                                            >
                                                <option value="">-- Choose Existing Product from Catalog to Add to Package --</option>
                                                {products.map(p => (
                                                    <option key={p.id} value={p.id}>
                                                        #{p.id}: {p.name} (MRP: {p.mrp} Tk) {p.sku ? `[SKU: ${p.sku}]` : ''} — {p.category}
                                                    </option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => handleAddBundleItem(idx)}
                                                style={{
                                                    background: '#0284c7',
                                                    color: '#fff',
                                                    border: 'none',
                                                    padding: '8px 14px',
                                                    borderRadius: 6,
                                                    fontSize: 12,
                                                    fontWeight: 700,
                                                    cursor: 'pointer',
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: 6,
                                                    whiteSpace: 'nowrap'
                                                }}
                                            >
                                                <Plus size={14} /> Add Product to Bundle
                                            </button>
                                        </div>

                                        {/* Table of Bundled Items */}
                                        {bundleItems.length === 0 ? (
                                            <div style={{ padding: 16, background: '#fff', border: '1px dashed #cbd5e1', borderRadius: 6, textAlign: 'center', color: '#64748b', fontSize: 13 }}>
                                                No products added to this bundle yet. Select products from the dropdown above to create this package.
                                            </div>
                                        ) : (
                                            <div style={{ border: '1px solid #cbd5e1', borderRadius: 6, overflow: 'hidden', background: '#fff', marginBottom: 12 }}>
                                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                                    <thead>
                                                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #cbd5e1', textAlign: 'left', color: '#475569' }}>
                                                            <th style={{ padding: '8px 10px', width: 30 }}>#</th>
                                                            <th style={{ padding: '8px 10px' }}>Product Name</th>
                                                            <th style={{ padding: '8px 10px', width: 120 }}>Catalog MRP</th>
                                                            {bundleMode === 'itemized' && (
                                                                <>
                                                                    <th style={{ padding: '8px 10px', width: 110 }}>Item Discount %</th>
                                                                    <th style={{ padding: '8px 10px', width: 120 }}>Effective Price</th>
                                                                </>
                                                            )}
                                                            <th style={{ padding: '8px 10px', textAlign: 'right', width: 90 }}>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {bundleItems.map((it, itemIdx) => (
                                                            <tr key={itemIdx} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                                                <td style={{ padding: '8px 10px', color: '#64748b' }}>{itemIdx + 1}</td>
                                                                <td style={{ padding: '8px 10px' }}>
                                                                    <strong style={{ color: '#0f172a', display: 'block' }}>{it.name}</strong>
                                                                    {it.sku && <span style={{ fontSize: 11, color: '#64748b' }}>SKU: {it.sku}</span>}
                                                                </td>
                                                                <td style={{ padding: '8px 10px', fontWeight: 600 }}>৳ {parsePrice(it.mrp).toLocaleString()}</td>
                                                                {bundleMode === 'itemized' && (
                                                                    <>
                                                                        <td style={{ padding: '8px 10px' }}>
                                                                            <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                                                                <input
                                                                                    type="number"
                                                                                    min={0}
                                                                                    max={99}
                                                                                    value={it.custom_discount_pct ?? bundlePct}
                                                                                    onChange={e => handleItemizedDiscountChange(idx, itemIdx, parseFloat(e.target.value) || 0)}
                                                                                    style={{ width: 55, padding: '4px 6px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 12 }}
                                                                                />
                                                                                <span>%</span>
                                                                            </div>
                                                                        </td>
                                                                        <td style={{ padding: '8px 10px', fontWeight: 700, color: '#0284c7' }}>
                                                                            ৳ {parsePrice(it.item_price).toLocaleString()}
                                                                        </td>
                                                                    </>
                                                                )}
                                                                <td style={{ padding: '8px 10px', textAlign: 'right' }}>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleRemoveBundleItem(idx, itemIdx)}
                                                                        style={{
                                                                            border: 'none',
                                                                            background: '#fee2e2',
                                                                            color: '#dc2626',
                                                                            padding: '4px 8px',
                                                                            borderRadius: 4,
                                                                            fontSize: 11,
                                                                            fontWeight: 700,
                                                                            cursor: 'pointer'
                                                                        }}
                                                                    >
                                                                        ✕ Remove
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}

                                        {/* Bundle Discount Mode & Controls */}
                                        {bundleItems.length > 0 && (
                                            <div style={{ background: '#ffffff', padding: 12, borderRadius: 6, border: '1px solid #bae6fd' }}>
                                                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, flexWrap: 'wrap', gap: 8 }}>
                                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0369a1' }}>
                                                        Package Discount Calculation Mode:
                                                    </span>
                                                    <div style={{ display: 'flex', gap: 12 }}>
                                                        <label style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer', fontWeight: bundleMode === 'total' ? 700 : 500 }}>
                                                            <input
                                                                type="radio"
                                                                name={`bundle_mode_${idx}`}
                                                                checked={bundleMode === 'total'}
                                                                onChange={() => recalculateBundleCard(idx, bundleItems, 'total', bundlePct)}
                                                            />
                                                            Flat Discount on Combined Total MRP
                                                        </label>
                                                        <label style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer', fontWeight: bundleMode === 'itemized' ? 700 : 500 }}>
                                                            <input
                                                                type="radio"
                                                                name={`bundle_mode_${idx}`}
                                                                checked={bundleMode === 'itemized'}
                                                                onChange={() => recalculateBundleCard(idx, bundleItems, 'itemized', bundlePct)}
                                                            />
                                                            Itemized Per-Product %
                                                        </label>
                                                    </div>
                                                </div>

                                                {bundleMode === 'total' && (
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                                                        <span style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>Flat Package Discount %:</span>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={99}
                                                                value={card.bundle_discount_pct ?? 35}
                                                                onChange={e => recalculateBundleCard(idx, bundleItems, 'total', e.target.value)}
                                                                style={{ width: 75, padding: '6px 8px', border: '1.5px solid #0284c7', borderRadius: 4, fontSize: 13, fontWeight: 700, color: '#0284c7' }}
                                                            />
                                                            <strong style={{ fontSize: 14, color: '#0284c7' }}>%</strong>
                                                        </div>

                                                        {/* Presets */}
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                                                            <span style={{ fontSize: 11, color: '#64748b' }}>Presets:</span>
                                                            {[20, 25, 30, 35, 40, 50].map(pct => (
                                                                <button
                                                                    key={pct}
                                                                    type="button"
                                                                    onClick={() => recalculateBundleCard(idx, bundleItems, 'total', pct)}
                                                                    style={{
                                                                        padding: '3px 8px',
                                                                        borderRadius: 4,
                                                                        fontSize: 11,
                                                                        fontWeight: 700,
                                                                        border: `1px solid ${bundlePct === pct ? '#0284c7' : '#cbd5e1'}`,
                                                                        background: bundlePct === pct ? '#0284c7' : '#f8fafc',
                                                                        color: bundlePct === pct ? '#ffffff' : '#334155',
                                                                        cursor: 'pointer'
                                                                    }}
                                                                >
                                                                    {pct}%
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    {/* Bundle Financial Transparency Card */}
                                    {bundleItems.length > 0 && totalBundleMrp > 0 && (
                                        <div style={{ background: '#f0f9ff', border: '1.5px solid #7dd3fc', borderRadius: 8, padding: '12px 16px', marginBottom: 14 }}>
                                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8, flexWrap: 'wrap', gap: 6 }}>
                                                <span style={{ fontSize: 13, fontWeight: 700, color: '#0369a1', display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    📦 Bundle Package Cash Inflow & Savings Breakdown:
                                                </span>
                                                <span style={{ fontSize: 11, background: '#0284c7', color: '#fff', padding: '2px 8px', borderRadius: 10, fontWeight: 700 }}>
                                                    {bundleItems.length} Products Included
                                                </span>
                                            </div>
                                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 10, marginBottom: 8 }}>
                                                <div style={{ background: '#fff', padding: '8px 12px', borderRadius: 6, border: '1px solid #bae6fd' }}>
                                                    <span style={{ fontSize: 11, color: '#64748b', display: 'block' }}>Total Combined MRP</span>
                                                    <strong style={{ fontSize: 14, color: '#334155' }}>৳ {totalBundleMrp.toLocaleString()}</strong>
                                                </div>
                                                <div style={{ background: '#e0f2fe', padding: '8px 12px', borderRadius: 6, border: '1.5px solid #7dd3fc' }}>
                                                    <span style={{ fontSize: 11, color: '#075985', display: 'block' }}>Package Cash Inflow Price</span>
                                                    <strong style={{ fontSize: 15, color: '#0369a1' }}>৳ {effectiveBundlePrice.toLocaleString()}</strong>
                                                </div>
                                                <div style={{ background: '#fef2f2', padding: '8px 12px', borderRadius: 6, border: '1px solid #fecaca' }}>
                                                    <span style={{ fontSize: 11, color: '#991b1b', display: 'block' }}>Total Package Savings</span>
                                                    <strong style={{ fontSize: 14, color: '#dc2626' }}>৳ {effectiveBundleSave.toLocaleString()} ({effectiveBundleDiscountPct}%)</strong>
                                                </div>
                                            </div>
                                            <p style={{ margin: 0, fontSize: 11, color: '#075985', lineHeight: 1.4 }}>
                                                ℹ️ <strong>Package Order Accounting:</strong> When ordered, this bundle collects <strong>৳{effectiveBundlePrice.toLocaleString()}</strong> as single unit cash inflow. The individual standalone catalog prices of these products remain untouched.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Card Content Grid (Common Fields) */}
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 14 }}>
                                {/* Tag */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Promotional Tag / Badge
                                    </label>
                                    <input
                                        type="text"
                                        value={card.tag}
                                        placeholder={defCard.tag}
                                        onChange={e => handleFieldChange(idx, 'tag', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.tag}</small>
                                </div>

                                {/* Title */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Offer Title
                                    </label>
                                    <input
                                        type="text"
                                        value={card.title}
                                        placeholder={defCard.title}
                                        onChange={e => handleFieldChange(idx, 'title', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.title}</small>
                                </div>

                                {/* Description */}
                                <div style={{ gridColumn: '1 / -1' }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Offer Description
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={card.desc}
                                        placeholder={defCard.desc}
                                        onChange={e => handleFieldChange(idx, 'desc', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.desc}</small>
                                </div>

                                {/* Bullet 1 */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Checklist Feature 1
                                    </label>
                                    <input
                                        type="text"
                                        value={card.feat1}
                                        placeholder={defCard.feat1}
                                        onChange={e => handleFieldChange(idx, 'feat1', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.feat1}</small>
                                </div>

                                {/* Bullet 2 */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Checklist Feature 2
                                    </label>
                                    <input
                                        type="text"
                                        value={card.feat2}
                                        placeholder={defCard.feat2}
                                        onChange={e => handleFieldChange(idx, 'feat2', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.feat2}</small>
                                </div>

                                {/* Offer Price */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Offer Price (e.g. 20,000 Tk)
                                    </label>
                                    <input
                                        type="text"
                                        value={card.price}
                                        placeholder={defCard.price}
                                        onChange={e => handleFieldChange(idx, 'price', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.price}</small>
                                </div>

                                {/* MRP */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Regular MRP (e.g. 30,000 Tk)
                                    </label>
                                    <input
                                        type="text"
                                        value={card.mrp}
                                        placeholder={defCard.mrp}
                                        onChange={e => handleFieldChange(idx, 'mrp', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.mrp}</small>
                                </div>

                                {/* Save Badge */}
                                <div>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                        <label style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>
                                            Save Badge (e.g. Save 10,000 Tk)
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => handleAutoCalculateSave(idx)}
                                            style={{
                                                border: 'none',
                                                background: '#ecfdf5',
                                                color: '#065f46',
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 7px',
                                                borderRadius: 4,
                                                cursor: 'pointer',
                                            }}
                                        >
                                            ⚡ Calculate
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        value={card.save}
                                        placeholder={defCard.save}
                                        onChange={e => handleFieldChange(idx, 'save', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.save}</small>
                                </div>

                                {/* Action Button */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Action Button Text
                                    </label>
                                    <input
                                        type="text"
                                        value={card.btn}
                                        placeholder={defCard.btn}
                                        onChange={e => handleFieldChange(idx, 'btn', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.btn}</small>
                                </div>

                                {/* Destination Link */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Destination Link
                                    </label>
                                    <input
                                        type="text"
                                        value={card.link}
                                        placeholder={defCard.link}
                                        onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Placeholder: {defCard.link}</small>
                                </div>

                                {/* Offer Image */}
                                <div>
                                    <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4, color: '#334155' }}>
                                        Offer Image URL (Optional)
                                    </label>
                                    <input
                                        type="text"
                                        value={card.image}
                                        placeholder="/branding/madina-side-banner-1.jpg"
                                        onChange={e => handleFieldChange(idx, 'image', e.target.value)}
                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8' }}>Leave blank if no thumbnail is needed.</small>
                                </div>
                            </div>

                            {/* Live Card Preview */}
                            <div style={{ marginTop: 16, paddingTop: 14, borderTop: '1px dashed #e2e8f0' }}>
                                <span style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.5, display: 'block', marginBottom: 10 }}>
                                    Card Live Homepage Preview:
                                </span>
                                <div style={{
                                    maxWidth: 380,
                                    border: isHighlight ? '1.5px solid #006837' : (cardType === 'bundle' ? '1.5px solid #0284c7' : '1px solid #e2e8f0'),
                                    borderRadius: 12,
                                    padding: 18,
                                    background: isHighlight
                                        ? 'linear-gradient(180deg, #fff, #f6fbf8)'
                                        : (cardType === 'bundle' ? 'linear-gradient(180deg, #fff, #f0f9ff)' : '#fff'),
                                    boxShadow: '0 2px 6px rgba(0,0,0,0.06)',
                                    position: 'relative'
                                }}>
                                    {card.image && (
                                        <div style={{ height: 110, borderRadius: 8, overflow: 'hidden', marginBottom: 12 }}>
                                            <img src={card.image} alt={card.title} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                        </div>
                                    )}
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 8 }}>
                                        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: '#011627' }}>
                                            {card.title || defCard.title}
                                        </h4>
                                        <span style={{
                                            fontSize: 10,
                                            fontWeight: 700,
                                            background: cardType === 'bundle' ? '#0284c7' : (isHighlight ? '#006837' : '#2e3192'),
                                            color: '#fff',
                                            padding: '2px 8px',
                                            borderRadius: 12,
                                            whiteSpace: 'nowrap'
                                        }}>
                                            {card.tag || defCard.tag}
                                        </span>
                                    </div>
                                    <p style={{ margin: '0 0 10px', fontSize: 11, color: '#64748b', lineHeight: 1.4 }}>
                                        {card.desc || defCard.desc}
                                    </p>

                                    {/* Bundle items preview pill in card */}
                                    {cardType === 'bundle' && bundleItems.length > 0 && (
                                        <div style={{
                                            padding: '6px 8px',
                                            background: '#e0f2fe',
                                            border: '1px dashed #7dd3fc',
                                            borderRadius: 6,
                                            fontSize: 10,
                                            color: '#075985',
                                            marginBottom: 10
                                        }}>
                                            <strong>📦 Package Includes ({bundleItems.length} Products):</strong>
                                            <div style={{ marginTop: 3 }}>
                                                {bundleItems.map(it => it.name).join(' • ')}
                                            </div>
                                        </div>
                                    )}

                                    <div style={{ fontSize: 11, color: '#475569', display: 'flex', flexDirection: 'column', gap: 4, marginBottom: 12 }}>
                                        {(card.feat1 || defCard.feat1) && (
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                <i className="fa-solid fa-circle-check" style={{ color: '#006837', fontSize: 11 }} /> {card.feat1 || defCard.feat1}
                                            </span>
                                        )}
                                        {(card.feat2 || defCard.feat2) && (
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                <i className="fa-solid fa-circle-check" style={{ color: '#006837', fontSize: 11 }} /> {card.feat2 || defCard.feat2}
                                            </span>
                                        )}
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
                                        <strong style={{ fontSize: 16, color: '#006837' }}>{card.price || defCard.price}</strong>
                                        {(card.mrp || defCard.mrp) && <del style={{ fontSize: 11, color: '#94a3b8' }}>{card.mrp || defCard.mrp}</del>}
                                        {(card.save || defCard.save) && (
                                            <span style={{ fontSize: 10, fontWeight: 700, background: '#fef2f2', color: '#ef4444', padding: '1px 6px', borderRadius: 4 }}>
                                                {card.save || defCard.save}
                                            </span>
                                        )}
                                    </div>
                                    <div style={{ background: '#f34c20', color: '#fff', textAlign: 'center', padding: '7px 12px', borderRadius: 6, fontSize: 12, fontWeight: 700 }}>
                                        {card.btn || defCard.btn} →
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Bottom Add Card Actions */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12, marginTop: 20 }}>
                <button
                    type="button"
                    onClick={handleAddSingle}
                    style={{
                        background: '#ffffff',
                        color: '#006837',
                        border: '1.5px dashed #006837',
                        padding: '12px 20px',
                        borderRadius: 8,
                        fontSize: 13,
                        fontWeight: 700,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        justifyContent: 'center',
                    }}
                >
                    <Plus size={16} /> + Add Another Single Product Offer
                </button>
                <button
                    type="button"
                    onClick={handleAddBundle}
                    style={{
                        background: '#ffffff',
                        color: '#0284c7',
                        border: '1.5px dashed #0284c7',
                        padding: '12px 20px',
                        borderRadius: 8,
                        fontSize: 13,
                        fontWeight: 700,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        justifyContent: 'center',
                    }}
                >
                    <Plus size={16} /> + Add Another Group / Package Bundle
                </button>
            </div>
        </div>
    );
}

export function FeaturedProductsManager({
    customization,
    defaults,
    products,
    updateField,
}: {
    customization: CustomizationData;
    defaults: CustomizationData;
    products: { id: number; name: string; mrp: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    updateField: (key: keyof CustomizationData, val: any) => void;
}) {
    const isEnabled = customization.featured_products_enabled !== '0';
    const isTabsEnabled = customization.featured_products_tabs_enabled !== '0';
    const mode = customization.featured_products_mode || 'auto';
    const items = customization.featured_products_items || [];

    const handleAddItem = () => {
        const firstUnselected = products.find(p => !items.some(it => String(it.product_id) === String(p.id)));
        const newItem: FeaturedProductItem = {
            id: `fp-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            product_id: firstUnselected ? String(firstUnselected.id) : (products[0] ? String(products[0].id) : ''),
            marketing_badge: '★ Best Seller',
            override_title: '',
        };
        updateField('featured_products_items', [...items, newItem]);
    };

    const handleUpdateItem = (index: number, updates: Partial<FeaturedProductItem>) => {
        const updated = items.map((it, idx) => (idx === index ? { ...it, ...updates } : it));
        updateField('featured_products_items', updated);
    };

    const handleRemoveItem = (index: number) => {
        const updated = items.filter((_, idx) => idx !== index);
        updateField('featured_products_items', updated);
    };

    const handleMoveItem = (index: number, direction: 'up' | 'down') => {
        const targetIndex = direction === 'up' ? index - 1 : index + 1;
        if (targetIndex < 0 || targetIndex >= items.length) return;
        const copy = [...items];
        const [moved] = copy.splice(index, 1);
        copy.splice(targetIndex, 0, moved);
        updateField('featured_products_items', copy);
    };

    const quickBadges = [
        { label: '★ Best Seller', bg: '#006837', text: '#fff' },
        { label: '🔥 Hot Deal', bg: '#ef4444', text: '#fff' },
        { label: 'New Arrival', bg: '#0284c7', text: '#fff' },
        { label: '⚡ Popular', bg: '#f59e0b', text: '#fff' },
        { label: 'Trending', bg: '#7c3aed', text: '#fff' },
    ];

    const catalogFeatured = products.filter(p => p.featured);

    return (
        <div style={{ background: '#f8fafc', border: '1.5px solid #e2e8f0', borderRadius: 12, padding: 24, marginBottom: 28 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
                <div>
                    <h3 style={{ fontSize: 17, fontWeight: 700, margin: 0, color: '#011627', display: 'flex', alignItems: 'center', gap: 8 }}>
                        <Package size={20} color="#006837" /> Featured Products Showcase Section
                    </h3>
                    <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
                        Configure product showcase on the public homepage with interactive category tabs and smart marketing badges.
                    </p>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_enabled', isEnabled ? '0' : '1')}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 8,
                            fontSize: 12.5,
                            fontWeight: 600,
                            cursor: 'pointer',
                            background: isEnabled ? '#ecfdf5' : '#f8fafc',
                            color: isEnabled ? '#065f46' : '#64748b',
                            padding: '7px 14px',
                            borderRadius: 20,
                            border: `1.5px solid ${isEnabled ? '#10b981' : '#cbd5e1'}`,
                            transition: 'all 0.15s ease'
                        }}
                    >
                        <span style={{
                            width: 8,
                            height: 8,
                            borderRadius: '50%',
                            background: isEnabled ? '#10b981' : '#94a3b8'
                        }} />
                        {isEnabled ? 'Section Active' : 'Section Hidden'}
                    </button>
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_tabs_enabled', isTabsEnabled ? '0' : '1')}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 8,
                            fontSize: 12.5,
                            fontWeight: 600,
                            cursor: 'pointer',
                            background: isTabsEnabled ? '#ecfdf5' : '#f8fafc',
                            color: isTabsEnabled ? '#065f46' : '#64748b',
                            padding: '7px 14px',
                            borderRadius: 20,
                            border: `1.5px solid ${isTabsEnabled ? '#10b981' : '#cbd5e1'}`,
                            transition: 'all 0.15s ease'
                        }}
                    >
                        <span style={{
                            width: 8,
                            height: 8,
                            borderRadius: '50%',
                            background: isTabsEnabled ? '#10b981' : '#94a3b8'
                        }} />
                        {isTabsEnabled ? 'Filter Tabs On' : 'Filter Tabs Off'}
                    </button>
                </div>
            </div>

            {/* General Section Labels */}
            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 20 }}>
                <Field
                    label="Section Badge (Pill)"
                    fieldKey="featured_products_badge"
                    value={customization.featured_products_badge}
                    placeholder={defaults.featured_products_badge || 'POPULAR SELECTION'}
                    onChange={updateField}
                />
                <Field
                    label="Section Main Title"
                    fieldKey="featured_products_title"
                    value={customization.featured_products_title}
                    placeholder={defaults.featured_products_title || 'Featured Products'}
                    onChange={updateField}
                />
                <Field
                    label="Section Subtitle"
                    fieldKey="public_products_subtitle"
                    value={customization.public_products_subtitle}
                    placeholder={defaults.public_products_subtitle || 'Discover our product range and connect with an authorized dealer.'}
                    onChange={updateField}
                />
                <Field
                    label="View All Button Text"
                    fieldKey="featured_products_view_all"
                    value={customization.featured_products_view_all}
                    placeholder={defaults.featured_products_view_all || 'View All Products'}
                    onChange={updateField}
                />
                <Field
                    label="View All Button Link"
                    fieldKey="featured_products_view_all_link"
                    value={customization.featured_products_view_all_link}
                    placeholder={defaults.featured_products_view_all_link || '/products'}
                    onChange={updateField}
                />
                <Field
                    label="Products Empty State Text"
                    fieldKey="products_empty_title"
                    value={customization.products_empty_title}
                    placeholder={defaults.products_empty_title || 'Our collection is being prepared.'}
                    onChange={updateField}
                />
            </div>

            {/* Showcase Selection Mode */}
            <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, marginBottom: 20 }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: '#1e293b', marginBottom: 10 }}>
                    Product Selection Mode:
                </div>
                <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_mode', 'auto')}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            padding: '10px 18px',
                            border: `1.5px solid ${mode === 'auto' ? '#006837' : '#cbd5e1'}`,
                            borderRadius: 8,
                            background: mode === 'auto' ? '#f0fdf4' : '#fff',
                            cursor: 'pointer',
                            fontSize: 13,
                            fontWeight: 600,
                            color: mode === 'auto' ? '#006837' : '#334155',
                            boxShadow: mode === 'auto' ? '0 1px 3px rgba(0,104,55,0.08)' : 'none',
                            textAlign: 'left'
                        }}
                    >
                        <span style={{
                            width: 16,
                            height: 16,
                            borderRadius: '50%',
                            border: `2px solid ${mode === 'auto' ? '#006837' : '#94a3b8'}`,
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            background: '#fff',
                            flexShrink: 0
                        }}>
                            {mode === 'auto' && <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#006837' }} />}
                        </span>
                        <span>⚡ Automatic (Display Active Featured Products from Catalog)</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => updateField('featured_products_mode', 'curated')}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            padding: '10px 18px',
                            border: `1.5px solid ${mode === 'curated' ? '#006837' : '#cbd5e1'}`,
                            borderRadius: 8,
                            background: mode === 'curated' ? '#f0fdf4' : '#fff',
                            cursor: 'pointer',
                            fontSize: 13,
                            fontWeight: 600,
                            color: mode === 'curated' ? '#006837' : '#334155',
                            boxShadow: mode === 'curated' ? '0 1px 3px rgba(0,104,55,0.08)' : 'none',
                            textAlign: 'left'
                        }}
                    >
                        <span style={{
                            width: 16,
                            height: 16,
                            borderRadius: '50%',
                            border: `2px solid ${mode === 'curated' ? '#006837' : '#94a3b8'}`,
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            background: '#fff',
                            flexShrink: 0
                        }}>
                            {mode === 'curated' && <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#006837' }} />}
                        </span>
                        <span>✨ Curated (Hand-Pick & Customize Specific Showcase Products)</span>
                    </button>
                </div>

                {mode === 'auto' && (
                    <div style={{ marginTop: 14, padding: '12px 16px', background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0', fontSize: 12, color: '#475569' }}>
                        <div style={{ fontWeight: 600, marginBottom: 4, color: '#0f172a' }}>
                            ℹ️ Current Catalog Featured Products ({catalogFeatured.length} active):
                        </div>
                        {catalogFeatured.length > 0 ? (
                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
                                {catalogFeatured.map(p => (
                                    <span key={p.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: '#ecfdf5', color: '#065f46', border: '1px solid #a7f3d0', padding: '4px 10px', borderRadius: 16, fontSize: 11, fontWeight: 600 }}>
                                        ★ {p.name} [{p.category}] — {p.mrp} Tk
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <div style={{ color: '#94a3b8', fontStyle: 'italic' }}>
                                No products are currently flagged as "Featured" in the catalog. All active products will be displayed by default. You can edit any product in the Catalog tab to mark it as Featured.
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Curated Products List */}
            {mode === 'curated' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
                        <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                            Curated Showcase Products ({items.length})
                        </span>
                        <button
                            type="button"
                            onClick={handleAddItem}
                            style={{
                                background: '#006837',
                                color: '#fff',
                                border: 'none',
                                padding: '8px 16px',
                                borderRadius: 6,
                                fontSize: 12,
                                fontWeight: 700,
                                cursor: 'pointer',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6
                            }}
                        >
                            <Plus size={14} /> Add Product to Showcase
                        </button>
                    </div>

                    {items.length === 0 ? (
                        <div style={{ textAlign: 'center', padding: '36px 20px', background: '#fff', borderRadius: 8, border: '1.5px dashed #cbd5e1' }}>
                            <Package size={32} color="#94a3b8" style={{ margin: '0 auto 8px' }} />
                            <p style={{ margin: '0 0 12px', fontSize: 13, color: '#64748b' }}>
                                No curated showcase products selected yet.
                            </p>
                            <button
                                type="button"
                                onClick={handleAddItem}
                                style={{ background: '#006837', color: '#fff', border: 'none', padding: '8px 18px', borderRadius: 6, fontSize: 13, fontWeight: 600, cursor: 'pointer' }}
                            >
                                + Add First Showcase Product
                            </button>
                        </div>
                    ) : (
                        items.map((item, idx) => {
                            const selectedProd = products.find(p => String(p.id) === String(item.product_id));
                            return (
                                <div
                                    key={item.id || idx}
                                    style={{
                                        background: '#fff',
                                        border: '1px solid #e2e8f0',
                                        borderRadius: 10,
                                        padding: 16,
                                        display: 'grid',
                                        gridTemplateColumns: 'minmax(0, 1fr) auto',
                                        gap: 16,
                                        alignItems: 'center'
                                    }}
                                >
                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                                        {/* Product Selector */}
                                        <label style={{ display: 'block' }}>
                                            <span style={{ fontSize: 12, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 4 }}>
                                                Select Product #{idx + 1}
                                            </span>
                                            <select
                                                value={item.product_id}
                                                onChange={e => handleUpdateItem(idx, { product_id: e.target.value })}
                                                style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 12, background: '#fff' }}
                                            >
                                                <option value="">-- Choose Product --</option>
                                                {products.map(p => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name} [{p.category}] — {p.mrp} Tk {p.stock !== undefined ? `(Stock: ${p.stock})` : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        {/* Marketing Badge */}
                                        <div>
                                            <span style={{ fontSize: 12, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 4 }}>
                                                Marketing Badge
                                            </span>
                                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 6 }}>
                                                {quickBadges.map(qb => (
                                                    <button
                                                        key={qb.label}
                                                        type="button"
                                                        onClick={() => handleUpdateItem(idx, { marketing_badge: qb.label })}
                                                        style={{
                                                            fontSize: 10,
                                                            fontWeight: 700,
                                                            padding: '2px 8px',
                                                            borderRadius: 12,
                                                            border: item.marketing_badge === qb.label ? `2px solid ${qb.bg}` : '1px solid #cbd5e1',
                                                            background: item.marketing_badge === qb.label ? qb.bg : '#f8fafc',
                                                            color: item.marketing_badge === qb.label ? qb.text : '#475569',
                                                            cursor: 'pointer'
                                                        }}
                                                    >
                                                        {qb.label}
                                                    </button>
                                                ))}
                                                {item.marketing_badge && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleUpdateItem(idx, { marketing_badge: '' })}
                                                        style={{ fontSize: 10, padding: '2px 6px', background: '#fee2e2', color: '#dc2626', border: '1px solid #fecaca', borderRadius: 12, cursor: 'pointer' }}
                                                    >
                                                        Clear
                                                    </button>
                                                )}
                                            </div>
                                            <input
                                                type="text"
                                                placeholder="Or type custom badge..."
                                                value={item.marketing_badge || ''}
                                                onChange={e => handleUpdateItem(idx, { marketing_badge: e.target.value })}
                                                style={{ width: '100%', padding: '6px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 12 }}
                                            />
                                        </div>

                                        {/* Optional Title Override */}
                                        <label style={{ display: 'block' }}>
                                            <span style={{ fontSize: 12, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 4 }}>
                                                Title Override (Optional)
                                            </span>
                                            <input
                                                type="text"
                                                placeholder={selectedProd ? selectedProd.name : 'Leave blank to use product name'}
                                                value={item.override_title || ''}
                                                onChange={e => handleUpdateItem(idx, { override_title: e.target.value })}
                                                style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 12 }}
                                            />
                                        </label>
                                    </div>

                                    {/* Action buttons (Move Up, Move Down, Delete) */}
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'center' }}>
                                        <div style={{ display: 'flex', gap: 4 }}>
                                            <button
                                                type="button"
                                                disabled={idx === 0}
                                                onClick={() => handleMoveItem(idx, 'up')}
                                                style={{ width: 28, height: 28, borderRadius: 4, border: '1px solid #cbd5e1', background: '#fff', cursor: idx === 0 ? 'not-allowed' : 'pointer', opacity: idx === 0 ? 0.4 : 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                                                title="Move Up"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                disabled={idx === items.length - 1}
                                                onClick={() => handleMoveItem(idx, 'down')}
                                                style={{ width: 28, height: 28, borderRadius: 4, border: '1px solid #cbd5e1', background: '#fff', cursor: idx === items.length - 1 ? 'not-allowed' : 'pointer', opacity: idx === items.length - 1 ? 0.4 : 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                                                title="Move Down"
                                            >
                                                ↓
                                            </button>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveItem(idx)}
                                            style={{ width: 28, height: 28, borderRadius: 4, border: '1px solid #fecaca', background: '#fef2f2', color: '#dc2626', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                                            title="Remove from showcase"
                                        >
                                            <Trash2 size={14} />
                                        </button>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}

const DEFAULT_NAV_CATEGORIES = [
    'Medical Equipment',
    'Leather Items',
    'Consumer Items',
    'Medicine',
    'Agriculture Product',
    'Cosmetics',
    'Furniture',
    'Service',
];

interface HeaderNavCategoriesManagerProps {
    value: string;
    defaultValue: string;
    onChange: (value: string) => void;
    featuredCategoriesRaw?: string;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
}

function HeaderNavCategoriesManager({
    value,
    defaultValue,
    onChange,
    featuredCategoriesRaw = '',
    products = [],
}: HeaderNavCategoriesManagerProps) {
    const translate = useT();
    const [newCategoryName, setNewCategoryName] = useState('');
    const [bulkMode, setBulkMode] = useState(false);
    const [bulkInput, setBulkInput] = useState('');

    const rawTrimmed = (value || '').trim();
    const isCustomized = rawTrimmed.length > 0;

    // Parse current categories (or fallback to defaults)
    const activeCategories: string[] = isCustomized
        ? value.split(/\r?\n/).map(s => s.trim()).filter(Boolean)
        : DEFAULT_NAV_CATEGORIES;

    const handleAdd = () => {
        const trimmed = newCategoryName.trim();
        if (!trimmed) return;
        const currentList = isCustomized ? activeCategories : [...DEFAULT_NAV_CATEGORIES];
        if (currentList.some(c => c.toLowerCase() === trimmed.toLowerCase())) {
            alert(translate('portal', 'cms_alert_nav_dup', { name: trimmed }));
            return;
        }
        const updated = [...currentList, trimmed];
        onChange(updated.join('\n'));
        setNewCategoryName('');
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAdd();
        }
    };

    const handleRemove = (index: number) => {
        const currentList = isCustomized ? activeCategories : [...DEFAULT_NAV_CATEGORIES];
        const target = currentList[index];
        if (window.confirm(translate('portal', 'cms_confirm_remove_nav', { name: target }))) {
            const updated = currentList.filter((_, i) => i !== index);
            onChange(updated.join('\n'));
        }
    };

    const handleEdit = (index: number, newName: string) => {
        const currentList = isCustomized ? [...activeCategories] : [...DEFAULT_NAV_CATEGORIES];
        currentList[index] = newName;
        onChange(currentList.join('\n'));
    };

    const handleMove = (index: number, direction: 'up' | 'down') => {
        const targetIdx = direction === 'up' ? index - 1 : index + 1;
        const currentList = isCustomized ? [...activeCategories] : [...DEFAULT_NAV_CATEGORIES];
        if (targetIdx < 0 || targetIdx >= currentList.length) return;
        const temp = currentList[targetIdx];
        currentList[targetIdx] = currentList[index];
        currentList[index] = temp;
        onChange(currentList.join('\n'));
    };

    const handleResetDefaults = () => {
        if (window.confirm(translate('portal', 'cms_confirm_reset_nav'))) {
            onChange(DEFAULT_NAV_CATEGORIES.join('\n'));
            setNewCategoryName('');
        }
    };

    const handleClearToPlaceholders = () => {
        if (window.confirm(translate('portal', 'cms_confirm_clear_nav'))) {
            onChange('');
            setNewCategoryName('');
        }
    };

    const handleSyncFeatured = () => {
        const names = featuredCategoriesRaw.split(/\r?\n/).map(l => l.split('|')[0].trim()).filter(Boolean);
        if (names.length === 0) {
            alert(translate('portal', 'cms_alert_no_featured'));
            return;
        }
        const unique = Array.from(new Set(names));
        onChange(unique.join('\n'));
    };

    const handleSyncProducts = () => {
        if (!products || products.length === 0) {
            alert(translate('portal', 'cms_alert_no_products'));
            return;
        }
        const cats = Array.from(new Set(products.map(p => p.category).filter(Boolean))).sort();
        if (cats.length === 0) {
            alert(translate('portal', 'cms_alert_no_categories'));
            return;
        }
        onChange(cats.join('\n'));
    };

    const handleToggleBulkMode = () => {
        if (!bulkMode) {
            setBulkInput(activeCategories.join('\n'));
        } else {
            const parsed = bulkInput.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
            onChange(parsed.join('\n'));
        }
        setBulkMode(!bulkMode);
    };

    return (
        <div style={{
            background: '#ffffff',
            border: '1.5px solid #e2e8f0',
            borderRadius: 12,
            padding: '24px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.03)',
            gridColumn: '1 / -1',
            marginTop: 10
        }}>
            {/* Header / Title */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16, marginBottom: 18 }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <div style={{
                            width: 32,
                            height: 32,
                            borderRadius: 8,
                            background: '#eff6ff',
                            color: '#2563eb',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center'
                        }}>
                            <Tag size={18} />
                        </div>
                        <h4 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#011627' }}>
                            Upper Category Navigation Bar
                        </h4>
                        <span style={{
                            fontSize: 11,
                            fontWeight: 700,
                            padding: '3px 10px',
                            borderRadius: 12,
                            background: isCustomized ? '#dcfce7' : '#f1f5f9',
                            color: isCustomized ? '#15803d' : '#64748b',
                            border: `1px solid ${isCustomized ? '#86efac' : '#cbd5e1'}`
                        }}>
                            {isCustomized ? `CUSTOM ACTIVE (${activeCategories.length} OPTIONS)` : 'DEFAULT PLACEHOLDERS (8 OPTIONS)'}
                        </span>
                    </div>
                    <p style={{ margin: '6px 0 0', fontSize: 13, color: '#64748b', lineHeight: 1.5 }}>
                        This horizontal category bar appears directly beneath the main header search area on desktop and within the mobile navigation drawer. You can add new category links, rename existing ones, change order, or remove items.
                    </p>
                </div>

                {/* Quick Action Buttons */}
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={handleResetDefaults}
                        style={{
                            background: '#f8fafc',
                            color: '#334155',
                            border: '1px solid #cbd5e1',
                            borderRadius: 6,
                            padding: '6px 12px',
                            fontSize: 12,
                            fontWeight: 600,
                            cursor: 'pointer',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                        title="Restore the 8 reference category placeholders"
                    >
                        <RotateCcw size={13} /> Reset 8 Placeholders
                    </button>
                    {isCustomized && (
                        <button
                            type="button"
                            onClick={handleClearToPlaceholders}
                            style={{
                                background: '#fff1f2',
                                color: '#e11d48',
                                border: '1px solid #fecdd3',
                                borderRadius: 6,
                                padding: '6px 12px',
                                fontSize: 12,
                                fontWeight: 600,
                                cursor: 'pointer',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6
                            }}
                            title="Clear custom list to revert to placeholders"
                        >
                            <Trash2 size={13} /> Clear Custom List
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={handleToggleBulkMode}
                        style={{
                            background: bulkMode ? '#2563eb' : '#eff6ff',
                            color: bulkMode ? '#ffffff' : '#1d4ed8',
                            border: `1px solid ${bulkMode ? '#1d4ed8' : '#bfdbfe'}`,
                            borderRadius: 6,
                            padding: '6px 12px',
                            fontSize: 12,
                            fontWeight: 600,
                            cursor: 'pointer',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <List size={13} /> {bulkMode ? 'Exit Bulk Editor' : 'Bulk Edit (Plain Text)'}
                    </button>
                </div>
            </div>

            {/* Notice / Status description */}
            {!isCustomized && (
                <div style={{
                    padding: '10px 14px',
                    borderRadius: 8,
                    background: '#f8fafc',
                    border: '1px dashed #cbd5e1',
                    marginBottom: 16,
                    fontSize: 12.5,
                    color: '#475569',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8
                }}>
                    <span style={{ fontSize: 15 }}>💡</span>
                    <span>
                        Currently displaying the <strong>8 default placeholders</strong>: <em>Medical Equipment, Leather Items, Consumer Items, Medicine, Agriculture Product, Cosmetics, Furniture, Service</em>. Type a new category below or click "Add Option" or remove any item to begin customizing.
                    </span>
                </div>
            )}

            {/* Add New Category Box */}
            <div style={{
                display: 'flex',
                gap: 10,
                marginBottom: 20,
                background: '#f8fafc',
                padding: '12px 14px',
                borderRadius: 8,
                border: '1px solid #e2e8f0',
                alignItems: 'center',
                flexWrap: 'wrap'
            }}>
                <div style={{ flex: 1, minWidth: 240, position: 'relative' }}>
                    <input
                        type="text"
                        value={newCategoryName}
                        onChange={e => setNewCategoryName(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Add new category option (e.g. Organic Honey, Unani Tonics, Herbal Skincare)..."
                        style={{
                            width: '100%',
                            padding: '9px 14px',
                            border: '1px solid #cbd5e1',
                            borderRadius: 6,
                            fontSize: 13,
                            color: '#011627',
                            outline: 'none',
                            background: '#ffffff',
                            boxSizing: 'border-box'
                        }}
                    />
                </div>
                <button
                    type="button"
                    onClick={handleAdd}
                    disabled={!newCategoryName.trim()}
                    style={{
                        background: newCategoryName.trim() ? '#006837' : '#94a3b8',
                        color: '#ffffff',
                        border: 'none',
                        borderRadius: 6,
                        padding: '9px 18px',
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: newCategoryName.trim() ? 'pointer' : 'not-allowed',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6,
                        transition: 'background 0.15s ease'
                    }}
                >
                    <Plus size={15} /> Add Option
                </button>
            </div>

            {/* Visual Cards vs Bulk Text Mode */}
            {bulkMode ? (
                <div style={{ marginBottom: 16 }}>
                    <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 6 }}>
                        Bulk Plain Text Editor (One category per line):
                    </label>
                    <textarea
                        rows={8}
                        value={bulkInput}
                        onChange={e => setBulkInput(e.target.value)}
                        style={{
                            width: '100%',
                            fontFamily: 'monospace',
                            fontSize: 13,
                            lineHeight: 1.5,
                            padding: '12px',
                            borderRadius: 6,
                            border: '1px solid #cbd5e1',
                            boxSizing: 'border-box'
                        }}
                    />
                    <div style={{ marginTop: 8, display: 'flex', gap: 10 }}>
                        <button
                            type="button"
                            onClick={() => {
                                const parsed = bulkInput.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                                onChange(parsed.join('\n'));
                                setBulkMode(false);
                            }}
                            style={{
                                background: '#2563eb',
                                color: '#fff',
                                padding: '8px 16px',
                                borderRadius: 6,
                                fontSize: 12,
                                fontWeight: 600,
                                cursor: 'pointer',
                                border: 'none'
                            }}
                        >
                            Apply & Return to Cards
                        </button>
                        <button
                            type="button"
                            onClick={() => setBulkMode(false)}
                            style={{
                                background: '#f1f5f9',
                                color: '#475569',
                                padding: '8px 14px',
                                borderRadius: 6,
                                fontSize: 12,
                                fontWeight: 600,
                                cursor: 'pointer',
                                border: '1px solid #cbd5e1'
                            }}
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            ) : (
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
                    gap: 10,
                    marginBottom: 18
                }}>
                    {activeCategories.map((category, idx) => (
                        <div
                            key={idx}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                                padding: '8px 10px',
                                background: '#f8fafc',
                                border: '1px solid #e2e8f0',
                                borderRadius: 8,
                                transition: 'border-color 0.15s ease'
                            }}
                        >
                            {/* Position Index Badge */}
                            <span style={{
                                fontSize: 11,
                                fontWeight: 800,
                                color: '#64748b',
                                background: '#ffffff',
                                border: '1px solid #cbd5e1',
                                borderRadius: 4,
                                width: 26,
                                height: 26,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                flexShrink: 0
                            }}>
                                #{idx + 1}
                            </span>

                            {/* Editable Input */}
                            <input
                                type="text"
                                value={category}
                                onChange={e => handleEdit(idx, e.target.value)}
                                style={{
                                    flex: 1,
                                    minWidth: 0,
                                    fontSize: 12.5,
                                    fontWeight: 600,
                                    color: '#011627',
                                    border: '1px solid transparent',
                                    borderRadius: 4,
                                    padding: '4px 6px',
                                    background: 'transparent',
                                    outline: 'none',
                                    transition: 'border-color 0.15s, background 0.15s'
                                }}
                                onFocus={e => {
                                    e.currentTarget.style.border = '1px solid #3b82f6';
                                    e.currentTarget.style.background = '#ffffff';
                                }}
                                onBlur={e => {
                                    e.currentTarget.style.border = '1px solid transparent';
                                    e.currentTarget.style.background = 'transparent';
                                }}
                            />

                            {/* Move Up / Move Down buttons */}
                            <div style={{ display: 'flex', gap: 2, flexShrink: 0 }}>
                                <button
                                    type="button"
                                    onClick={() => handleMove(idx, 'up')}
                                    disabled={idx === 0}
                                    style={{
                                        width: 24,
                                        height: 24,
                                        borderRadius: 4,
                                        border: '1px solid #e2e8f0',
                                        background: idx === 0 ? '#f1f5f9' : '#ffffff',
                                        color: idx === 0 ? '#cbd5e1' : '#475569',
                                        cursor: idx === 0 ? 'not-allowed' : 'pointer',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        padding: 0
                                    }}
                                    title="Move left / up"
                                >
                                    <ArrowUp size={12} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleMove(idx, 'down')}
                                    disabled={idx === activeCategories.length - 1}
                                    style={{
                                        width: 24,
                                        height: 24,
                                        borderRadius: 4,
                                        border: '1px solid #e2e8f0',
                                        background: idx === activeCategories.length - 1 ? '#f1f5f9' : '#ffffff',
                                        color: idx === activeCategories.length - 1 ? '#cbd5e1' : '#475569',
                                        cursor: idx === activeCategories.length - 1 ? 'not-allowed' : 'pointer',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        padding: 0
                                    }}
                                    title="Move right / down"
                                >
                                    <ArrowDown size={12} />
                                </button>
                            </div>

                            {/* Remove button */}
                            <button
                                type="button"
                                onClick={() => handleRemove(idx)}
                                style={{
                                    width: 26,
                                    height: 26,
                                    borderRadius: 4,
                                    border: '1px solid #fee2e2',
                                    background: '#fff1f2',
                                    color: '#e11d48',
                                    cursor: 'pointer',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    flexShrink: 0,
                                    padding: 0,
                                    transition: 'background 0.15s, color 0.15s'
                                }}
                                title={`Remove "${category}"`}
                            >
                                <Trash2 size={13} />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {/* Sync Helpers */}
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 20 }}>
                <button
                    type="button"
                    onClick={handleSyncFeatured}
                    style={{
                        background: '#f0fdf4',
                        color: '#166534',
                        border: '1px solid #bbf7d0',
                        fontSize: 12,
                        fontWeight: 600,
                        padding: '6px 12px',
                        borderRadius: 6,
                        cursor: 'pointer',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6
                    }}
                >
                    <Sparkles size={13} /> Sync from Featured Categories
                </button>
                {products && products.length > 0 && (
                    <button
                        type="button"
                        onClick={handleSyncProducts}
                        style={{
                            background: '#eff6ff',
                            color: '#1e40af',
                            border: '1px solid #bfdbfe',
                            fontSize: 12,
                            fontWeight: 600,
                            padding: '6px 12px',
                            borderRadius: 6,
                            cursor: 'pointer',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6
                        }}
                    >
                        <Package size={13} /> Load from Active Products ({products.length} catalog items)
                    </button>
                )}
            </div>

            {/* Live Storefront Bar Preview */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 8,
                padding: '14px 18px',
                boxShadow: '0 2px 5px rgba(0,0,0,0.03)'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#22c55e', display: 'inline-block' }}></span>
                        <strong style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.6px', color: '#475569' }}>
                            Storefront Navigation Bar Live Preview
                        </strong>
                    </div>
                    <span style={{ fontSize: 11, color: '#64748b' }}>
                        Shows how it renders directly beneath the search bar
                    </span>
                </div>

                <div style={{
                    background: '#ffffff',
                    border: '1px solid #dce1e8',
                    borderRadius: 6,
                    padding: '0 16px',
                    minHeight: 46,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 16,
                    overflowX: 'auto',
                    scrollbarWidth: 'thin'
                }}>
                    {activeCategories.length === 0 ? (
                        <span style={{ fontSize: 12, color: '#94a3b8', fontStyle: 'italic' }}>
                            {translate('portal', 'cms_no_categories_selected')}
                        </span>
                    ) : (
                        activeCategories.map((cat, i) => (
                            <span
                                key={i}
                                style={{
                                    fontSize: 12,
                                    fontWeight: 700,
                                    color: '#011627',
                                    whiteSpace: 'nowrap',
                                    cursor: 'default',
                                    padding: '8px 0'
                                }}
                            >
                                {cat}
                            </span>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}

export default function SiteCustomizationEditor({
    customization,
    defaults,
    products = [],
    websitePages = []
}: {
    customization: CustomizationData;
    defaults: CustomizationData;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    websitePages?: { key: string; slug: string; label: string; toggleable?: boolean; enabled?: boolean; flag?: string | null }[];
}) {
    const translate = useT();
    const [tab, setTab] = useState<'header' | 'banners' | 'shortcuts' | 'catalog' | 'pages' | 'footer'>('pages');
    const pageFlags = [
        'page_enabled_about', 'page_enabled_products', 'page_enabled_dealers', 'page_enabled_contact',
        'page_enabled_partnership', 'page_enabled_services', 'page_enabled_quality', 'page_enabled_faq',
        'page_enabled_privacy', 'page_enabled_terms',
    ] as const;

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
            header_network_title: customization.header_network_title || '',
            header_projects_subtitle: customization.header_projects_subtitle || '',
            header_account_title: customization.header_account_title || '',
            header_account_subtitle: customization.header_account_subtitle || '',
            header_catalog_title: customization.header_catalog_title || '',
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
            shortcuts_section_enabled: customization.shortcuts_section_enabled !== undefined ? customization.shortcuts_section_enabled : '1',
            shortcuts: (Array.isArray(customization.shortcuts) && customization.shortcuts.length > 0)
                ? customization.shortcuts.map((sc, i) => ({
                    id: sc.id || `sc-${i}-${Math.random().toString(36).substring(2, 6)}`,
                    title: sc.title || '',
                    subtitle: sc.subtitle || '',
                    icon: sc.icon || '',
                    link: sc.link || ''
                }))
                : (defaults.shortcuts || []).map((defSc, i) => ({
                    id: `sc-def-${i}`,
                    title: defSc.title || '',
                    subtitle: defSc.subtitle || '',
                    icon: defSc.icon || '',
                    link: defSc.link || ''
                })),

            // Campaign
            campaign_section_enabled: customization.campaign_section_enabled !== undefined ? customization.campaign_section_enabled : '1',
            campaign_badge: customization.campaign_badge || '',
            campaign_title: customization.campaign_title || '',
            campaign_subtitle: customization.campaign_subtitle || '',
            campaign_cards: (Array.isArray(customization.campaign_cards) && customization.campaign_cards.length > 0)
                ? customization.campaign_cards.map((c, i) => ({
                    id: c.id || `card-${i + 1}-${Math.random().toString(36).substring(2, 6)}`,
                    type: c.type || (c.product_id ? 'single' : 'bundle'),
                    product_id: c.product_id || '',
                    tag: c.tag || '',
                    title: c.title || '',
                    desc: c.desc || '',
                    feat1: c.feat1 || '',
                    feat2: c.feat2 || '',
                    price: c.price || '',
                    mrp: c.mrp || '',
                    save: c.save || '',
                    btn: c.btn || '',
                    link: c.link || '',
                    image: c.image || '',
                    highlight: c.highlight !== undefined ? c.highlight : (i === 0 ? '1' : '0'),
                    promo_discount_pct: c.promo_discount_pct !== undefined ? c.promo_discount_pct : '',
                    bundle_discount_mode: c.bundle_discount_mode || 'total',
                    bundle_discount_pct: c.bundle_discount_pct !== undefined ? c.bundle_discount_pct : '',
                    bundle_items: Array.isArray(c.bundle_items) ? c.bundle_items : [],
                }))
                : (defaults.campaign_cards || []).map((defCard, i) => ({
                    id: defCard.id || `card-${i + 1}`,
                    type: defCard.type || 'bundle',
                    product_id: defCard.product_id || '',
                    tag: defCard.tag || '',
                    title: defCard.title || '',
                    desc: defCard.desc || '',
                    feat1: defCard.feat1 || '',
                    feat2: defCard.feat2 || '',
                    price: defCard.price || '',
                    mrp: defCard.mrp || '',
                    save: defCard.save || '',
                    btn: defCard.btn || '',
                    link: defCard.link || '',
                    image: defCard.image || '',
                    highlight: defCard.highlight !== undefined ? defCard.highlight : (i === 0 ? '1' : '0'),
                    promo_discount_pct: defCard.promo_discount_pct !== undefined ? defCard.promo_discount_pct : '',
                    bundle_discount_mode: defCard.bundle_discount_mode || 'total',
                    bundle_discount_pct: defCard.bundle_discount_pct !== undefined ? defCard.bundle_discount_pct : '',
                    bundle_items: Array.isArray(defCard.bundle_items) ? defCard.bundle_items : [],
                })),
            campaign_card1_product_id: customization.campaign_card1_product_id || '',
            campaign_card1_tag: customization.campaign_card1_tag || '',
            campaign_card1_title: customization.campaign_card1_title || '',
            campaign_card1_desc: customization.campaign_card1_desc || '',
            campaign_card1_feat1: customization.campaign_card1_feat1 || '',
            campaign_card1_feat2: customization.campaign_card1_feat2 || '',
            campaign_card1_price: customization.campaign_card1_price || '',
            campaign_card1_mrp: customization.campaign_card1_mrp || '',
            campaign_card1_save: customization.campaign_card1_save || '',
            campaign_card1_btn: customization.campaign_card1_btn || '',
            campaign_card1_link: customization.campaign_card1_link || '',
            campaign_card1_image: customization.campaign_card1_image || '',
            campaign_card2_product_id: customization.campaign_card2_product_id || '',
            campaign_card2_tag: customization.campaign_card2_tag || '',
            campaign_card2_title: customization.campaign_card2_title || '',
            campaign_card2_desc: customization.campaign_card2_desc || '',
            campaign_card2_feat1: customization.campaign_card2_feat1 || '',
            campaign_card2_feat2: customization.campaign_card2_feat2 || '',
            campaign_card2_price: customization.campaign_card2_price || '',
            campaign_card2_mrp: customization.campaign_card2_mrp || '',
            campaign_card2_save: customization.campaign_card2_save || '',
            campaign_card2_btn: customization.campaign_card2_btn || '',
            campaign_card2_link: customization.campaign_card2_link || '',
            campaign_card2_image: customization.campaign_card2_image || '',

            // Featured Categories & Catalog
            featured_categories_enabled: customization.featured_categories_enabled !== undefined ? customization.featured_categories_enabled : '1',
            featured_categories_title: customization.featured_categories_title || '',
            featured_categories_subtitle: customization.featured_categories_subtitle || '',
            featured_categories_items: customization.featured_categories_items !== undefined ? customization.featured_categories_items : defaults.featured_categories_items || '',
            dealer_banner_title: customization.dealer_banner_title || '',
            dealer_banner_subtitle: customization.dealer_banner_subtitle || '',
            dealer_banner_btn_text: customization.dealer_banner_btn_text || '',
            featured_products_enabled: customization.featured_products_enabled !== undefined ? customization.featured_products_enabled : '1',
            featured_products_badge: customization.featured_products_badge || '',
            featured_products_title: customization.featured_products_title || '',
            featured_products_subtitle: customization.featured_products_subtitle || '',
            featured_products_view_all: customization.featured_products_view_all || '',
            featured_products_view_all_link: customization.featured_products_view_all_link || '',
            featured_products_tabs_enabled: customization.featured_products_tabs_enabled !== undefined ? customization.featured_products_tabs_enabled : '1',
            featured_products_mode: customization.featured_products_mode || 'auto',
            featured_products_items: Array.isArray(customization.featured_products_items)
                ? customization.featured_products_items.map((item, idx) => ({
                    id: item.id || `fp-${idx}-${Math.random().toString(36).substring(2, 6)}`,
                    product_id: String(item.product_id || ''),
                    marketing_badge: item.marketing_badge || '',
                    override_title: item.override_title || '',
                }))
                : [],
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
            company_intro_title: customization.company_intro_title || '',
            company_intro_body: customization.company_intro_body || '',
            public_products_subtitle: customization.public_products_subtitle || '',
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

            partnership_hero_title: customization.partnership_hero_title || '',
            partnership_hero_lead: customization.partnership_hero_lead || '',
            partnership_why_title: customization.partnership_why_title || '',
            partnership_benefit1_title: customization.partnership_benefit1_title || '',
            partnership_benefit1_desc: customization.partnership_benefit1_desc || '',
            partnership_benefit2_title: customization.partnership_benefit2_title || '',
            partnership_benefit2_desc: customization.partnership_benefit2_desc || '',
            partnership_benefit3_title: customization.partnership_benefit3_title || '',
            partnership_benefit3_desc: customization.partnership_benefit3_desc || '',
            partnership_benefit4_title: customization.partnership_benefit4_title || '',
            partnership_benefit4_desc: customization.partnership_benefit4_desc || '',
            partnership_steps_title: customization.partnership_steps_title || '',
            partnership_step1_title: customization.partnership_step1_title || '',
            partnership_step1_desc: customization.partnership_step1_desc || '',
            partnership_step2_title: customization.partnership_step2_title || '',
            partnership_step2_desc: customization.partnership_step2_desc || '',
            partnership_step3_title: customization.partnership_step3_title || '',
            partnership_step3_desc: customization.partnership_step3_desc || '',
            partnership_cta_title: customization.partnership_cta_title || '',
            partnership_cta_body: customization.partnership_cta_body || '',
            partnership_cta_btn: customization.partnership_cta_btn || '',
            partnership_cta_link: customization.partnership_cta_link || '',

            services_hero_title: customization.services_hero_title || '',
            services_hero_lead: customization.services_hero_lead || '',
            services_card1_title: customization.services_card1_title || '',
            services_card1_desc: customization.services_card1_desc || '',
            services_card2_title: customization.services_card2_title || '',
            services_card2_desc: customization.services_card2_desc || '',
            services_card3_title: customization.services_card3_title || '',
            services_card3_desc: customization.services_card3_desc || '',
            services_cta_btn: customization.services_cta_btn || '',
            services_cta_link: customization.services_cta_link || '',

            quality_hero_title: customization.quality_hero_title || '',
            quality_hero_lead: customization.quality_hero_lead || '',
            quality_pillar1_title: customization.quality_pillar1_title || '',
            quality_pillar1_desc: customization.quality_pillar1_desc || '',
            quality_pillar2_title: customization.quality_pillar2_title || '',
            quality_pillar2_desc: customization.quality_pillar2_desc || '',
            quality_pillar3_title: customization.quality_pillar3_title || '',
            quality_pillar3_desc: customization.quality_pillar3_desc || '',
            quality_cta_btn: customization.quality_cta_btn || '',
            quality_cta_link: customization.quality_cta_link || '',

            faq_hero_title: customization.faq_hero_title || '',
            faq_hero_lead: customization.faq_hero_lead || '',
            faq_items: (Array.isArray(customization.faq_items) && customization.faq_items.length > 0)
                ? customization.faq_items.map((item) => ({ question: item.question || '', answer: item.answer || '' }))
                : (defaults.faq_items || []).map((item) => ({ question: item.question || '', answer: item.answer || '' })),

            privacy_hero_title: customization.privacy_hero_title || '',
            privacy_hero_lead: customization.privacy_hero_lead || '',
            privacy_body: customization.privacy_body || '',
            terms_hero_title: customization.terms_hero_title || '',
            terms_hero_lead: customization.terms_hero_lead || '',
            terms_body: customization.terms_body || '',

            footer_link_services: customization.footer_link_services || '',
            footer_link_services_url: customization.footer_link_services_url || '',
            footer_link_quality: customization.footer_link_quality || '',
            footer_link_quality_url: customization.footer_link_quality_url || '',
            footer_link_faq: customization.footer_link_faq || '',
            footer_link_faq_url: customization.footer_link_faq_url || '',
            footer_link_privacy: customization.footer_link_privacy || '',
            footer_link_privacy_url: customization.footer_link_privacy_url || '',
            footer_link_terms: customization.footer_link_terms || '',
            footer_link_terms_url: customization.footer_link_terms_url || '',
            footer_link_partnership: customization.footer_link_partnership || '',
            footer_link_partnership_url: customization.footer_link_partnership_url || '',

            page_enabled_about: customization.page_enabled_about !== undefined ? customization.page_enabled_about : '1',
            page_enabled_products: customization.page_enabled_products !== undefined ? customization.page_enabled_products : '1',
            page_enabled_dealers: customization.page_enabled_dealers !== undefined ? customization.page_enabled_dealers : '1',
            page_enabled_contact: customization.page_enabled_contact !== undefined ? customization.page_enabled_contact : '1',
            page_enabled_partnership: customization.page_enabled_partnership !== undefined ? customization.page_enabled_partnership : '1',
            page_enabled_services: customization.page_enabled_services !== undefined ? customization.page_enabled_services : '1',
            page_enabled_quality: customization.page_enabled_quality !== undefined ? customization.page_enabled_quality : '1',
            page_enabled_faq: customization.page_enabled_faq !== undefined ? customization.page_enabled_faq : '1',
            page_enabled_privacy: customization.page_enabled_privacy !== undefined ? customization.page_enabled_privacy : '1',
            page_enabled_terms: customization.page_enabled_terms !== undefined ? customization.page_enabled_terms : '1',

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
        if (confirm(translate('portal', 'cms_confirm_reset_all_frontend'))) {
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

    const updateShortcuts = (shortcuts: Shortcut[]) => {
        form.setData('customization', { ...form.data.customization, shortcuts });
    };

    const updateCampaignCards = (campaign_cards: CampaignCardItem[]) => {
        form.setData('customization', { ...form.data.customization, campaign_cards });
    };

    return (
        <div className="site-customization-container">
            <div className="panel" style={{ marginBottom: 20 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 15 }}>
                    <div>
                        <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0 }}>
                            <SlidersHorizontal size={22} color="#2e3192" /> {translate('portal', 'cms_heading')}
                        </h2>
                        <p style={{ margin: '6px 0 0', color: '#64748b', fontSize: 13 }}>
                            {translate('portal', 'cms_heading_hint')}
                        </p>
                    </div>
                    <div style={{ display: 'flex', gap: 10 }}>
                        <a href={typeof window !== 'undefined' ? `${window.location.protocol}//${window.location.hostname.replace(/^(manage|sadmin|dealer|subdealer)\./, '')}${window.location.port ? ':' + window.location.port : ''}/` : '/'} target="_blank" rel="noopener noreferrer" className="button small" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                            <ExternalLink size={14} /> {translate('portal', 'cms_preview_live')}
                        </a>
                        <button type="button" onClick={handleResetAll} className="button small" style={{ color: '#ef4444', display: 'flex', alignItems: 'center', gap: 6 }}>
                            <RotateCcw size={14} /> {translate('portal', 'cms_reset_all')}
                        </button>
                    </div>
                </div>

                {/* Relocation guidance banner */}
                <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, marginTop: 18 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <Package size={20} color="#15803d" />
                        <span style={{ fontSize: 13, color: '#166534', fontWeight: 500 }}>
                            {translate('portal', 'cms_product_banner')}
                        </span>
                    </div>
                    <a href="/catalog?tab=offers" className="button small dark" style={{ whiteSpace: 'nowrap', display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12 }}>
                        {translate('portal', 'cms_product_mgmt')}
                    </a>
                </div>

                <div style={{ marginTop: 18, padding: 16, borderRadius: 12, border: '1px solid #dbe4f0', background: 'linear-gradient(180deg,#f8fafc,#fff)' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
                        <div>
                            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>{translate('portal', 'cms_visibility_title')}</h3>
                            <p style={{ margin: '6px 0 0', fontSize: 12, color: '#64748b' }}>
                                {translate('portal', 'cms_visibility_hint')}
                            </p>
                        </div>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
                        {(websitePages.length > 0 ? websitePages : [
                            { key: 'home', slug: '/', label: 'Landing page', toggleable: false, enabled: true, flag: null },
                            ...pageFlags.map((flag) => ({
                                key: flag.replace('page_enabled_', ''),
                                slug: '/' + flag.replace('page_enabled_', ''),
                                label: flag.replace('page_enabled_', ''),
                                toggleable: true,
                                enabled: form.data.customization[flag] !== '0',
                                flag,
                            })),
                        ]).map((page) => {
                            const flag = page.flag as typeof pageFlags[number] | null | undefined;
                            const on = page.toggleable === false ? true : (flag ? form.data.customization[flag] !== '0' : page.enabled !== false);
                            return (
                                <div key={page.key} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, padding: '10px 12px', borderRadius: 10, border: '1px solid #e2e8f0', background: on ? '#fff' : '#f8fafc' }}>
                                    <div style={{ minWidth: 0 }}>
                                        <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'capitalize' }}>{page.label}</div>
                                        <div style={{ fontSize: 10, color: '#94a3b8' }}>{page.slug}</div>
                                    </div>
                                    {page.toggleable === false || !flag ? (
                                        <span style={{ fontSize: 10, fontWeight: 700, color: '#15803d' }}>{translate('portal', 'cms_always_on')}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            className={`button small ${on ? 'dark' : ''}`}
                                            aria-pressed={on}
                                            onClick={() => updateField(flag, on ? '0' : '1')}
                                            style={{ fontSize: 11, minWidth: 72 }}
                                        >
                                            {on ? translate('portal', 'cms_on') : translate('portal', 'cms_off')}
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
                        {websitePages.filter((page) => page.toggleable === false || (page.flag ? form.data.customization[page.flag as typeof pageFlags[number]] !== '0' : page.enabled)).map((page) => (
                            <a
                                key={`preview-${page.slug}`}
                                href={page.slug}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="button small"
                                style={{ fontSize: 11 }}
                            >
                                {translate('portal', 'cms_preview_page', { label: page.label })}
                            </a>
                        ))}
                    </div>
                </div>

                <div style={{ display: 'flex', gap: 8, marginTop: 18, borderBottom: '1px solid #e2e8f0', overflowX: 'auto', paddingBottom: 8 }}>
                    {[
                        { id: 'header', labelKey: 'cms_tab_header', icon: Layout },
                        { id: 'banners', labelKey: 'cms_tab_banners', icon: Image },
                        { id: 'shortcuts', labelKey: 'cms_tab_shortcuts', icon: Sparkles },
                        { id: 'catalog', labelKey: 'cms_tab_catalog', icon: Package },
                        { id: 'pages', labelKey: 'cms_tab_pages', icon: BookOpen },
                        { id: 'footer', labelKey: 'cms_tab_footer', icon: Share2 },
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
                                <span>{translate('portal', t.labelKey)}</span>
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
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>{translate('portal', 'cms_header_title')}</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="Top Company Bar Text" fieldKey="top_bar_text" value={form.data.customization.top_bar_text} placeholder={defaults.top_bar_text} onChange={updateField} />
                                <Field label="Search Input Placeholder" fieldKey="search_placeholder" value={form.data.customization.search_placeholder} placeholder={defaults.search_placeholder} onChange={updateField} />
                                <Field label="Product Catalogue Link Label" fieldKey="header_catalog_title" value={form.data.customization.header_catalog_title} placeholder={defaults.header_catalog_title} onChange={updateField} />
                                
                                <Field label="About Us Link Title" fieldKey="header_about_title" value={form.data.customization.header_about_title} placeholder={defaults.header_about_title} onChange={updateField} />
                                <Field label="About Us Link Subtitle" fieldKey="header_about_subtitle" value={form.data.customization.header_about_subtitle} placeholder={defaults.header_about_subtitle} onChange={updateField} />
                                
                                <Field label="Contact Link Title" fieldKey="header_contact_title" value={form.data.customization.header_contact_title} placeholder={defaults.header_contact_title} onChange={updateField} />
                                <Field label="Contact Link Subtitle" fieldKey="header_contact_subtitle" value={form.data.customization.header_contact_subtitle} placeholder={defaults.header_contact_subtitle} onChange={updateField} />


                                <Field label="Dealer Network Link Title" fieldKey="header_network_title" value={form.data.customization.header_network_title} placeholder={defaults.header_network_title} onChange={updateField} />

                                <Field label="Partner Account Link Title" fieldKey="header_account_title" value={form.data.customization.header_account_title} placeholder={defaults.header_account_title} onChange={updateField} />
                                <Field label="Partner Account Link Subtitle" fieldKey="header_account_subtitle" value={form.data.customization.header_account_subtitle} placeholder={defaults.header_account_subtitle} onChange={updateField} />

                                <HeaderNavCategoriesManager
                                    value={form.data.customization.nav_categories || ''}
                                    defaultValue={defaults.nav_categories || ''}
                                    onChange={newVal => updateField('nav_categories', newVal)}
                                    featuredCategoriesRaw={form.data.customization.featured_categories_items || defaults.featured_categories_items || ''}
                                    products={products}
                                />
                            </div>
                        </div>
                    )}

                    {/* TAB 2: SLIDER & BANNERS */}
                    {tab === 'banners' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>{translate('portal', 'cms_banners_title')}</h3>
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
                        <ShortcutsManager
                            shortcuts={form.data.customization.shortcuts || []}
                            defaultShortcuts={defaults.shortcuts || []}
                            enabled={form.data.customization.shortcuts_section_enabled !== '0'}
                            onToggleEnabled={val => updateField('shortcuts_section_enabled', val ? '1' : '0')}
                            onChange={updateShortcuts}
                        />
                    )}

                    {/* TAB 4: HEADINGS & CATALOG */}
                    {tab === 'catalog' && (
                        <div>
                            {/* SECTION: FEATURED CATEGORIES SECTION */}
                            <div style={{ marginBottom: 32, paddingBottom: 28, borderBottom: '1px solid #e2e8f0' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
                                    <div>
                                        <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#011627' }}>Featured Categories Grid Section</h3>
                                        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
                                            Configure category cards, icons, links, and homepage visibility.
                                        </p>
                                    </div>
                                </div>

                                {/* Section Visibility Master Control */}
                                <div style={{
                                    padding: '16px 20px',
                                    background: form.data.customization.featured_categories_enabled !== '0' ? '#ecfdf5' : '#f8fafc',
                                    border: `1.5px solid ${form.data.customization.featured_categories_enabled !== '0' ? '#10b981' : '#cbd5e1'}`,
                                    borderRadius: 10,
                                    marginBottom: 20,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'space-between',
                                    gap: 16,
                                    flexWrap: 'wrap'
                                }}>
                                    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
                                        <div style={{
                                            width: 38,
                                            height: 38,
                                            borderRadius: '50%',
                                            background: form.data.customization.featured_categories_enabled !== '0' ? '#d1fae5' : '#f1f5f9',
                                            color: form.data.customization.featured_categories_enabled !== '0' ? '#006837' : '#64748b',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            fontSize: 18,
                                            fontWeight: 700,
                                            flexShrink: 0
                                        }}>
                                            {form.data.customization.featured_categories_enabled !== '0' ? '✓' : '✕'}
                                        </div>
                                        <div>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                                <strong style={{ fontSize: 15, color: form.data.customization.featured_categories_enabled !== '0' ? '#065f46' : '#334155' }}>
                                                    Section Status: {form.data.customization.featured_categories_enabled !== '0' ? 'Active & Visible on Homepage' : 'Hidden from Homepage'}
                                                </strong>
                                                <span style={{
                                                    fontSize: 11,
                                                    fontWeight: 700,
                                                    padding: '2px 8px',
                                                    borderRadius: 12,
                                                    background: form.data.customization.featured_categories_enabled !== '0' ? '#006837' : '#64748b',
                                                    color: '#fff'
                                                }}>
                                                    {form.data.customization.featured_categories_enabled !== '0' ? 'ENABLED' : 'DISABLED'}
                                                </span>
                                            </div>
                                            <p style={{ margin: '4px 0 0', fontSize: 13, color: '#475569' }}>
                                                {form.data.customization.featured_categories_enabled !== '0'
                                                    ? 'The Featured Categories grid is currently showing on the public homepage. Click the button to hide it.'
                                                    : 'The Featured Categories section is currently hidden. Click the button to re-enable and show it.'}
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <button
                                            type="button"
                                            onClick={() => updateField('featured_categories_enabled', form.data.customization.featured_categories_enabled !== '0' ? '0' : '1')}
                                            style={{
                                                padding: '10px 20px',
                                                borderRadius: 8,
                                                fontSize: 13,
                                                fontWeight: 700,
                                                cursor: 'pointer',
                                                border: 'none',
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                background: form.data.customization.featured_categories_enabled !== '0' ? '#fee2e2' : '#006837',
                                                color: form.data.customization.featured_categories_enabled !== '0' ? '#991b1b' : '#ffffff',
                                                boxShadow: '0 2px 4px rgba(0,0,0,0.06)',
                                                transition: 'all 0.2s ease'
                                            }}
                                        >
                                            {form.data.customization.featured_categories_enabled !== '0' ? '✕ Disable & Hide Section' : '✓ Enable & Show Section'}
                                        </button>
                                    </div>
                                </div>

                                <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 20 }}>
                                    <Field label="Section Heading Title" fieldKey="featured_categories_title" value={form.data.customization.featured_categories_title} placeholder={defaults.featured_categories_title} onChange={updateField} />
                                    <Field label="Section Subtitle" fieldKey="featured_categories_subtitle" value={form.data.customization.featured_categories_subtitle} placeholder={defaults.featured_categories_subtitle} onChange={updateField} />
                                </div>

                                <CategoryManager
                                    value={form.data.customization.featured_categories_items}
                                    defaultValue={defaults.featured_categories_items}
                                    onChange={val => updateField('featured_categories_items', val)}
                                    products={products}
                                />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Dealer Network Banner & Scrolling Ticker</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Homepage Scrolling Ticker Text" fieldKey="announcement_text" value={form.data.customization.announcement_text} placeholder={defaults.announcement_text} onChange={updateField} span2 />
                                <Field label="Authorized Dealer Network Banner Title" fieldKey="dealer_banner_title" value={form.data.customization.dealer_banner_title} placeholder={defaults.dealer_banner_title} onChange={updateField} />
                                <Field label="Dealer Banner Subtitle" fieldKey="dealer_banner_subtitle" value={form.data.customization.dealer_banner_subtitle} placeholder={defaults.dealer_banner_subtitle} onChange={updateField} />
                                <Field label="Dealer Banner Button Label" fieldKey="dealer_banner_btn_text" value={form.data.customization.dealer_banner_btn_text} placeholder={defaults.dealer_banner_btn_text} onChange={updateField} />
                            </div>

                            <FeaturedProductsManager
                                customization={form.data.customization}
                                defaults={defaults}
                                products={products}
                                updateField={updateField}
                            />

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
                                <Field label="Share Label" fieldKey="product_share_label" value={form.data.customization.product_share_label} placeholder={defaults.product_share_label} onChange={updateField} />
                                <Field label="Product Video Heading" fieldKey="product_video_title" value={form.data.customization.product_video_title} placeholder={defaults.product_video_title} onChange={updateField} />
                                <Field label="Description Tab Title" fieldKey="product_tab_description" value={form.data.customization.product_tab_description} placeholder={defaults.product_tab_description} onChange={updateField} />
                                <Field label="Specification Tab Title" fieldKey="product_tab_specification" value={form.data.customization.product_tab_specification} placeholder={defaults.product_tab_specification} onChange={updateField} />
                            </div>
                        </div>
                    )}

                    {/* TAB 6: ABOUT, CONTACT & DEALERS PAGES */}
                    {tab === 'pages' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Homepage Company Introduction</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Company Introduction Title" fieldKey="company_intro_title" value={form.data.customization.company_intro_title} placeholder={defaults.company_intro_title} onChange={updateField} />
                                <Field label="Company Introduction Text" fieldKey="company_intro_body" value={form.data.customization.company_intro_body} placeholder={defaults.company_intro_body} onChange={updateField} multiline rows={3} span2 />
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

                                <Field label="Customer Care / Hotline Title" fieldKey="contact_phone_title" value={form.data.customization.contact_phone_title} placeholder={defaults.contact_phone_title} onChange={updateField} />
                                <Field label="Customer Care Phone Number" fieldKey="contact_phone" value={form.data.customization.contact_phone} placeholder={defaults.contact_phone} onChange={updateField} />
                                <Field label="Customer Care Service Hours" fieldKey="contact_phone_hours" value={form.data.customization.contact_phone_hours} placeholder={defaults.contact_phone_hours} onChange={updateField} />
                                <Field label="Official Email Title" fieldKey="contact_email_title" value={form.data.customization.contact_email_title} placeholder={defaults.contact_email_title} onChange={updateField} />
                                <Field label="Official Email Address" fieldKey="contact_email" value={form.data.customization.contact_email} placeholder={defaults.contact_email} onChange={updateField} />
                                <Field label="Social Links Section Title" fieldKey="contact_social_title" value={form.data.customization.contact_social_title} placeholder={defaults.contact_social_title} onChange={updateField} />
                                
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
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Directory Main Title" fieldKey="directory_title" value={form.data.customization.directory_title} placeholder={defaults.directory_title} onChange={updateField} />
                                <Field label="Directory Subtitle" fieldKey="directory_subtitle" value={form.data.customization.directory_subtitle} placeholder={defaults.directory_subtitle} onChange={updateField} />
                                <Field label="GPS Current Location Button Text" fieldKey="directory_gps_btn" value={form.data.customization.directory_gps_btn} placeholder={defaults.directory_gps_btn} onChange={updateField} span2 />
                                <Field label="Directory Empty Results Title" fieldKey="directory_empty_title" value={form.data.customization.directory_empty_title} placeholder={defaults.directory_empty_title} onChange={updateField} />
                                <Field label="Directory Empty Results Description" fieldKey="directory_empty_desc" value={form.data.customization.directory_empty_desc} placeholder={defaults.directory_empty_desc} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Partnership Page (/partnership)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Hero Title" fieldKey="partnership_hero_title" value={form.data.customization.partnership_hero_title} placeholder={defaults.partnership_hero_title} onChange={updateField} />
                                <Field label="Hero Lead" fieldKey="partnership_hero_lead" value={form.data.customization.partnership_hero_lead} placeholder={defaults.partnership_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Why Partner Heading" fieldKey="partnership_why_title" value={form.data.customization.partnership_why_title} placeholder={defaults.partnership_why_title} onChange={updateField} span2 />
                                {[1, 2, 3, 4].map((i) => (
                                    <React.Fragment key={`pb-${i}`}>
                                        <Field label={`Benefit ${i} Title`} fieldKey={`partnership_benefit${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_benefit${i}_title`]} placeholder={(defaults as any)[`partnership_benefit${i}_title`]} onChange={updateField} />
                                        <Field label={`Benefit ${i} Description`} fieldKey={`partnership_benefit${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_benefit${i}_desc`]} placeholder={(defaults as any)[`partnership_benefit${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="Steps Heading" fieldKey="partnership_steps_title" value={form.data.customization.partnership_steps_title} placeholder={defaults.partnership_steps_title} onChange={updateField} span2 />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`ps-${i}`}>
                                        <Field label={`Step ${i} Title`} fieldKey={`partnership_step${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_step${i}_title`]} placeholder={(defaults as any)[`partnership_step${i}_title`]} onChange={updateField} />
                                        <Field label={`Step ${i} Description`} fieldKey={`partnership_step${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_step${i}_desc`]} placeholder={(defaults as any)[`partnership_step${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="CTA Title" fieldKey="partnership_cta_title" value={form.data.customization.partnership_cta_title} placeholder={defaults.partnership_cta_title} onChange={updateField} />
                                <Field label="CTA Button Text" fieldKey="partnership_cta_btn" value={form.data.customization.partnership_cta_btn} placeholder={defaults.partnership_cta_btn} onChange={updateField} />
                                <Field label="CTA Body" fieldKey="partnership_cta_body" value={form.data.customization.partnership_cta_body} placeholder={defaults.partnership_cta_body} onChange={updateField} multiline rows={2} span2 />
                                <Field label="CTA Link" fieldKey="partnership_cta_link" value={form.data.customization.partnership_cta_link} placeholder={defaults.partnership_cta_link} onChange={updateField} span2 />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Services Page (/services)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Hero Title" fieldKey="services_hero_title" value={form.data.customization.services_hero_title} placeholder={defaults.services_hero_title} onChange={updateField} />
                                <Field label="Hero Lead" fieldKey="services_hero_lead" value={form.data.customization.services_hero_lead} placeholder={defaults.services_hero_lead} onChange={updateField} multiline rows={2} />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`svc-${i}`}>
                                        <Field label={`Service ${i} Title`} fieldKey={`services_card${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`services_card${i}_title`]} placeholder={(defaults as any)[`services_card${i}_title`]} onChange={updateField} />
                                        <Field label={`Service ${i} Description`} fieldKey={`services_card${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`services_card${i}_desc`]} placeholder={(defaults as any)[`services_card${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="CTA Button" fieldKey="services_cta_btn" value={form.data.customization.services_cta_btn} placeholder={defaults.services_cta_btn} onChange={updateField} />
                                <Field label="CTA Link" fieldKey="services_cta_link" value={form.data.customization.services_cta_link} placeholder={defaults.services_cta_link} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Quality Page (/quality)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Hero Title" fieldKey="quality_hero_title" value={form.data.customization.quality_hero_title} placeholder={defaults.quality_hero_title} onChange={updateField} />
                                <Field label="Hero Lead" fieldKey="quality_hero_lead" value={form.data.customization.quality_hero_lead} placeholder={defaults.quality_hero_lead} onChange={updateField} multiline rows={2} />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`q-${i}`}>
                                        <Field label={`Pillar ${i} Title`} fieldKey={`quality_pillar${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`quality_pillar${i}_title`]} placeholder={(defaults as any)[`quality_pillar${i}_title`]} onChange={updateField} />
                                        <Field label={`Pillar ${i} Description`} fieldKey={`quality_pillar${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`quality_pillar${i}_desc`]} placeholder={(defaults as any)[`quality_pillar${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="CTA Button" fieldKey="quality_cta_btn" value={form.data.customization.quality_cta_btn} placeholder={defaults.quality_cta_btn} onChange={updateField} />
                                <Field label="CTA Link" fieldKey="quality_cta_link" value={form.data.customization.quality_cta_link} placeholder={defaults.quality_cta_link} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>FAQ Page (/faq)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 16 }}>
                                <Field label="FAQ Hero Title" fieldKey="faq_hero_title" value={form.data.customization.faq_hero_title} placeholder={defaults.faq_hero_title} onChange={updateField} />
                                <Field label="FAQ Hero Lead" fieldKey="faq_hero_lead" value={form.data.customization.faq_hero_lead} placeholder={defaults.faq_hero_lead} onChange={updateField} multiline rows={2} />
                            </div>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 14, marginBottom: 28 }}>
                                {(form.data.customization.faq_items || []).map((item, idx) => (
                                    <div key={`faq-${idx}`} style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 16, background: '#f8fafc' }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
                                            <strong style={{ fontSize: 13 }}>Question #{idx + 1}</strong>
                                            <button
                                                type="button"
                                                className="button small"
                                                style={{ color: '#ef4444' }}
                                                onClick={() => {
                                                    const next = [...(form.data.customization.faq_items || [])];
                                                    next.splice(idx, 1);
                                                    form.setData('customization', { ...form.data.customization, faq_items: next });
                                                }}
                                            >
                                                <Trash2 size={14} /> Remove
                                            </button>
                                        </div>
                                        <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 12 }}>
                                            <label style={{ fontSize: 12, fontWeight: 600 }}>
                                                Question
                                                <input
                                                    value={item.question || ''}
                                                    placeholder={defaults.faq_items?.[idx]?.question || 'Question'}
                                                    onChange={(e) => {
                                                        const next = [...(form.data.customization.faq_items || [])];
                                                        next[idx] = { ...next[idx], question: e.target.value };
                                                        form.setData('customization', { ...form.data.customization, faq_items: next });
                                                    }}
                                                />
                                            </label>
                                            <label style={{ fontSize: 12, fontWeight: 600 }}>
                                                Answer
                                                <textarea
                                                    rows={3}
                                                    value={item.answer || ''}
                                                    placeholder={defaults.faq_items?.[idx]?.answer || 'Answer'}
                                                    onChange={(e) => {
                                                        const next = [...(form.data.customization.faq_items || [])];
                                                        next[idx] = { ...next[idx], answer: e.target.value };
                                                        form.setData('customization', { ...form.data.customization, faq_items: next });
                                                    }}
                                                />
                                            </label>
                                        </div>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className="button small dark"
                                    onClick={() => form.setData('customization', {
                                        ...form.data.customization,
                                        faq_items: [...(form.data.customization.faq_items || []), { question: '', answer: '' }],
                                    })}
                                >
                                    <Plus size={14} /> Add FAQ Item
                                </button>
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Privacy & Terms (/privacy, /terms)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="Privacy Title" fieldKey="privacy_hero_title" value={form.data.customization.privacy_hero_title} placeholder={defaults.privacy_hero_title} onChange={updateField} />
                                <Field label="Privacy Lead" fieldKey="privacy_hero_lead" value={form.data.customization.privacy_hero_lead} placeholder={defaults.privacy_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Privacy Body" fieldKey="privacy_body" value={form.data.customization.privacy_body} placeholder={defaults.privacy_body} onChange={updateField} multiline rows={6} span2 />
                                <Field label="Terms Title" fieldKey="terms_hero_title" value={form.data.customization.terms_hero_title} placeholder={defaults.terms_hero_title} onChange={updateField} />
                                <Field label="Terms Lead" fieldKey="terms_hero_lead" value={form.data.customization.terms_hero_lead} placeholder={defaults.terms_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Terms Body" fieldKey="terms_body" value={form.data.customization.terms_body} placeholder={defaults.terms_body} onChange={updateField} multiline rows={6} span2 />
                            </div>
                        </div>
                    )}

                    {/* TAB 7: FOOTER, DIALOGS & AUTH */}
                    {tab === 'footer' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Column 1: Support Boxes & Direct Contact</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Column 1 Heading" fieldKey="footer_support_heading" value={form.data.customization.footer_support_heading} placeholder={defaults.footer_support_heading} onChange={updateField} />
                                <Field label="Support Box 1 Title" fieldKey="footer_support_title" value={form.data.customization.footer_support_title} placeholder={defaults.footer_support_title} onChange={updateField} />
                                <Field label="Support Box 1 Subtitle" fieldKey="footer_support_subtitle" value={form.data.customization.footer_support_subtitle} placeholder={defaults.footer_support_subtitle} onChange={updateField} />
                                <Field label="Support Box 1 Link (tel: or URL)" fieldKey="footer_support_link" value={form.data.customization.footer_support_link} placeholder={defaults.footer_support_link} onChange={updateField} />
                                <Field label="Support Box 1 FontAwesome Icon" fieldKey="footer_support_icon" value={form.data.customization.footer_support_icon} placeholder={defaults.footer_support_icon} onChange={updateField} />

                                <Field label="Directory Box 2 Title" fieldKey="footer_directory_title" value={form.data.customization.footer_directory_title} placeholder={defaults.footer_directory_title} onChange={updateField} />
                                <Field label="Directory Box 2 Subtitle" fieldKey="footer_directory_subtitle" value={form.data.customization.footer_directory_subtitle} placeholder={defaults.footer_directory_subtitle} onChange={updateField} />
                                <Field label="Directory Box 2 Link" fieldKey="footer_directory_link" value={form.data.customization.footer_directory_link} placeholder={defaults.footer_directory_link} onChange={updateField} />
                                <Field label="Directory Box 2 FontAwesome Icon" fieldKey="footer_directory_icon" value={form.data.customization.footer_directory_icon} placeholder={defaults.footer_directory_icon} onChange={updateField} />

                                <Field label="Footer Hotline Phone" fieldKey="footer_phone" value={form.data.customization.footer_phone} placeholder={defaults.footer_phone} onChange={updateField} />
                                <Field label="Footer Official Email" fieldKey="footer_email" value={form.data.customization.footer_email} placeholder={defaults.footer_email} onChange={updateField} />
                                <Field label="Footer Physical Address" fieldKey="footer_address" value={form.data.customization.footer_address} placeholder={defaults.footer_address} onChange={updateField} span2 />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Column 2: Navigation Links (About Us Column)</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Column 2 Heading" fieldKey="footer_about_heading" value={form.data.customization.footer_about_heading} placeholder={defaults.footer_about_heading} onChange={updateField} span2 />

                                <Field label="Footer Link 1 Text" fieldKey="footer_link_about" value={form.data.customization.footer_link_about} placeholder={defaults.footer_link_about} onChange={updateField} />
                                <Field label="Footer Link 1 Target URL" fieldKey="footer_link_about_url" value={form.data.customization.footer_link_about_url} placeholder={defaults.footer_link_about_url} onChange={updateField} />




                                <Field label="Footer Link 5 Text (Contact)" fieldKey="footer_link_contact" value={form.data.customization.footer_link_contact} placeholder={defaults.footer_link_contact} onChange={updateField} />
                                <Field label="Footer Link 5 Target URL" fieldKey="footer_link_contact_url" value={form.data.customization.footer_link_contact_url} placeholder={defaults.footer_link_contact_url} onChange={updateField} />

                                <Field label="Footer Link 6 Text (Dealership)" fieldKey="footer_link_dealer_apply" value={form.data.customization.footer_link_dealer_apply} placeholder={defaults.footer_link_dealer_apply} onChange={updateField} />
                                <Field label="Footer Link 6 Target URL" fieldKey="footer_link_dealer_apply_url" value={form.data.customization.footer_link_dealer_apply_url} placeholder={defaults.footer_link_dealer_apply_url} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Column 3: Stay Connected & Brand Identity</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Column 3 Heading" fieldKey="footer_stay_connected_heading" value={form.data.customization.footer_stay_connected_heading} placeholder={defaults.footer_stay_connected_heading} onChange={updateField} />
                                <Field label="Footer Logo Image URL" fieldKey="footer_logo_url" value={form.data.customization.footer_logo_url} placeholder={defaults.footer_logo_url} onChange={updateField} />
                                <Field label="Footer Brand Title" fieldKey="footer_about_title" value={form.data.customization.footer_about_title} placeholder={defaults.footer_about_title} onChange={updateField} />
                                <Field label="Footer Brand Description" fieldKey="footer_about_desc" value={form.data.customization.footer_about_desc} placeholder={defaults.footer_about_desc} onChange={updateField} />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Social Media Channels & WhatsApp Chat</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20, marginBottom: 28 }}>
                                <Field label="Facebook Page URL" fieldKey="social_facebook" value={form.data.customization.social_facebook} placeholder={defaults.social_facebook} onChange={updateField} />
                                <Field label="YouTube Channel URL" fieldKey="social_youtube" value={form.data.customization.social_youtube} placeholder={defaults.social_youtube} onChange={updateField} />
                                <Field label="LinkedIn Page URL" fieldKey="social_linkedin" value={form.data.customization.social_linkedin} placeholder={defaults.social_linkedin} onChange={updateField} />
                                <Field label="Instagram Profile URL" fieldKey="social_instagram" value={form.data.customization.social_instagram} placeholder={defaults.social_instagram} onChange={updateField} />
                                <Field label="WhatsApp Chat URL / Phone Number" fieldKey="social_whatsapp" value={form.data.customization.social_whatsapp} placeholder={defaults.social_whatsapp} onChange={updateField} />
                                <Field label="Floating WhatsApp Tool Link" fieldKey="whatsapp_link" value={form.data.customization.whatsapp_link} placeholder={defaults.whatsapp_link} onChange={updateField} />
                                <Field label="X / Twitter Profile URL (Optional)" fieldKey="social_twitter" value={form.data.customization.social_twitter} placeholder={defaults.social_twitter || 'https://x.com/...'} onChange={updateField} />
                                <Field label="TikTok Profile URL (Optional)" fieldKey="social_tiktok" value={form.data.customization.social_tiktok} placeholder={defaults.social_tiktok || 'https://tiktok.com/@...'} onChange={updateField} />
                                <Field label="Telegram Channel / Link (Optional)" fieldKey="social_telegram" value={form.data.customization.social_telegram} placeholder={defaults.social_telegram || 'https://t.me/...'} onChange={updateField} />
                            </div>

                            <div className="form-grid" style={{display:'grid',gridTemplateColumns:'repeat(auto-fit,minmax(320px,1fr))',gap:20,marginBottom:28}}>
                                <Field label="Google Play App Link (optional)" fieldKey="app_google_play_link" value={form.data.customization.app_google_play_link} placeholder={defaults.app_google_play_link} onChange={updateField} />
                                <Field label="Apple App Store Link (optional)" fieldKey="app_apple_store_link" value={form.data.customization.app_apple_store_link} placeholder={defaults.app_apple_store_link} onChange={updateField} />
                                <Field label="Copyright Notice" fieldKey="copyright_text" value={form.data.customization.copyright_text} placeholder={defaults.copyright_text} onChange={updateField} span2 />
                            </div>

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Support & Partner Access</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <Field label="Floating Chat Button Label" fieldKey="floating_chat_label" value={form.data.customization.floating_chat_label} placeholder={defaults.floating_chat_label} onChange={updateField} />



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
                            <span>{form.processing ? translate('portal', 'cms_saving') : translate('portal', 'cms_save')}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
}

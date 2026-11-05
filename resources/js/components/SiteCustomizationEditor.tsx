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
import { notify } from '../shared/notify';

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

    // Brand Trust & Showcase (Category খ)
    trust_pillars_enabled?: string;
    trust_pillars_title?: string;
    video_gallery_enabled?: string;
    video_gallery_title?: string;
    video_gallery_subtitle?: string;
    testimonials_section_enabled?: string;
    testimonials_title?: string;
    testimonials_subtitle?: string;

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
    const translate = useT();
    const fieldLabelKey = `sce_fld_${String(fieldKey)}`;
    const localizedLabel = translate('portal', fieldLabelKey);
    const displayLabel = localizedLabel === fieldLabelKey ? label : localizedLabel;

    return (
        <label className={span2 ? 'ed-block hub-span-all' : 'ed-block'}>
            <span className="ed-label">{displayLabel}</span>
            {multiline ? (
                <textarea
                    rows={rows}
                    value={value || ''}
                    placeholder={placeholder}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    className="ed-input"
                />
            ) : (
                <input
                    type="text"
                    value={value || ''}
                    placeholder={placeholder}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    className="ed-input"
                />
            )}
            {placeholder ? (
                <small className="ed-hint ed-mt-xs">
                    {translate('portal', 'sce_default_placeholder', { value: placeholder })}
                </small>
            ) : null}
        </label>
    );
}

/**
 * Splits a translated sentence on its :placeholders and shows each given value in bold.
 */
function withBoldValues(sentence: string, values: Record<string, string>): React.ReactNode[] {
    return sentence.split(/(:[a-z_]+)/).map((part, index) => {
        const name = part.startsWith(':') ? part.slice(1) : '';
        return name && name in values ? <strong key={index}>{values[name]}</strong> : part;
    });
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
        groupKey: 'sce_icon_group_health',
        icons: [
            { icon: 'fa-briefcase-medical', labelKey: 'sce_icon_medical_equipment' },
            { icon: 'fa-prescription-bottle-medical', labelKey: 'sce_icon_unani_herbal' },
            { icon: 'fa-capsules', labelKey: 'sce_icon_capsules' },
            { icon: 'fa-tablets', labelKey: 'sce_icon_tablets' },
            { icon: 'fa-pills', labelKey: 'sce_icon_pills' },
            { icon: 'fa-stethoscope', labelKey: 'sce_icon_diagnostics' },
            { icon: 'fa-kit-medical', labelKey: 'sce_icon_first_aid' },
            { icon: 'fa-mortar-pestle', labelKey: 'sce_icon_herbal_compounding' },
            { icon: 'fa-heart-pulse', labelKey: 'sce_icon_cardio' },
            { icon: 'fa-bandage', labelKey: 'sce_icon_bandages' },
            { icon: 'fa-hand-holding-medical', labelKey: 'sce_icon_health_support' },
            { icon: 'fa-shield-halved', labelKey: 'sce_icon_health_protection' },
        ],
    },
    {
        groupKey: 'sce_icon_group_herbal',
        icons: [
            { icon: 'fa-leaf', labelKey: 'sce_icon_pure_herbal' },
            { icon: 'fa-seedling', labelKey: 'sce_icon_agriculture' },
            { icon: 'fa-bowl-food', labelKey: 'sce_icon_food_supplement' },
            { icon: 'fa-bottle-droplet', labelKey: 'sce_icon_oils' },
            { icon: 'fa-apple-whole', labelKey: 'sce_icon_diet' },
            { icon: 'fa-spa', labelKey: 'sce_icon_wellness' },
            { icon: 'fa-tree', labelKey: 'sce_icon_nature' },
            { icon: 'fa-cannabis', labelKey: 'sce_icon_botanical' },
            { icon: 'fa-wheat-awn', labelKey: 'sce_icon_grains' },
            { icon: 'fa-fish', labelKey: 'sce_icon_meat_fish' },
        ],
    },
    {
        groupKey: 'sce_icon_group_beauty',
        icons: [
            { icon: 'fa-pump-medical', labelKey: 'sce_icon_personal_care' },
            { icon: 'fa-gem', labelKey: 'sce_icon_cosmetics' },
            { icon: 'fa-sparkles', labelKey: 'sce_icon_skin_glow' },
            { icon: 'fa-feather-pointed', labelKey: 'sce_icon_gentle_care' },
            { icon: 'fa-shirt', labelKey: 'sce_icon_fashion' },
            { icon: 'fa-briefcase', labelKey: 'sce_icon_leather' },
            { icon: 'fa-water', labelKey: 'sce_icon_water' },
            { icon: 'fa-mug-hot', labelKey: 'sce_icon_kettle' },
            { icon: 'fa-fire-burner', labelKey: 'sce_icon_cookeries' },
            { icon: 'fa-temperature-arrow-up', labelKey: 'sce_icon_heater' },
            { icon: 'fa-couch', labelKey: 'sce_icon_furniture' },
            { icon: 'fa-laptop', labelKey: 'sce_icon_electronics' },
            { icon: 'fa-handshake-angle', labelKey: 'sce_icon_service' },
        ],
    },
    {
        groupKey: 'sce_icon_group_general',
        icons: [
            { icon: 'fa-tag', labelKey: 'sce_icon_default_tag' },
            { icon: 'fa-bag-shopping', labelKey: 'sce_icon_shopping_bag' },
            { icon: 'fa-box-open', labelKey: 'sce_icon_product_box' },
            { icon: 'fa-star', labelKey: 'sce_icon_top_rated' },
            { icon: 'fa-gift', labelKey: 'sce_icon_special_offer' },
            { icon: 'fa-award', labelKey: 'sce_icon_certified' },
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
    const [uploadingIdx, setUploadingIdx] = useState<number | null>(null);

    const uploadCategoryImage = async (index: number, file: File | null) => {
        if (!file) {
            return;
        }

        setUploadingIdx(index);
        try {
            const body = new FormData();
            body.append('image', file);
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                || (document.querySelector('input[name="_token"]') as HTMLInputElement | null)?.value
                || '';
            if (token) {
                body.append('_token', token);
            }

            const response = await fetch('/commands/customization/category-icon', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
            });

            const payload = await response.json().catch(() => null) as { ok?: boolean; url?: string; message?: string } | null;
            if (!response.ok || !payload?.ok || !payload.url) {
                throw new Error(payload?.message || translate('portal', 'sce_category_image_upload_failed'));
            }

            handleFieldChange(index, 'icon', payload.url);
        } catch (error) {
            notify(error instanceof Error ? error.message : translate('portal', 'sce_category_image_upload_failed'), 'error');
        } finally {
            setUploadingIdx(null);
        }
    };

    const rawValue = typeof value === 'string' ? value : (defaultValue || '');
    const items = parseItems(rawValue);

    const updateAndEmit = (newItems: CategoryItem[]) => {
        onChange(serializeItems(newItems));
    };

    const handleAddItem = () => {
        const newItem: CategoryItem = {
            id: `cat-new-${Date.now()}-${Math.random().toString(36).substring(2, 6)}`,
            name: translate('portal', 'sce_new_category'),
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
            notify(translate('portal', 'cms_alert_no_product_categories'), 'error');
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
        <div className="ed-card is-soft">
            {/* Header & Controls Toolbar */}
            <div className="ed-head">
                <div>
                    <div className="ed-row is-loose">
                        <h4 className="ed-subtitle is-flush">
                            {translate('portal', 'sce_featured_categories_manager')}
                        </h4>
                        <span className="hub-pill">
                            {translate('portal', items.length === 1 ? 'sce_category_count_one' : 'sce_category_count_many', { count: String(items.length) })}
                        </span>
                    </div>
                    <small className="ed-meta is-block">
                        {translate('portal', 'sce_featured_categories_manager_hint')}
                    </small>
                </div>

                {/* Mode Switcher */}
                <div className="hub-seg">
                    <button
                        type="button"
                        onClick={() => setMode('visual')}
                        className={mode === 'visual' ? 'is-active' : undefined}
                    >
                        🎨 {translate('portal', 'sce_visual_builder')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('text')}
                        className={mode === 'text' ? 'is-active' : undefined}
                    >
                        📝 {translate('portal', 'sce_raw_text_mode')}
                    </button>
                </div>
            </div>

            {/* Quick Action Freedom Bar */}
            <div className="ed-toolbar">
                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleAddItem}
                        className="button small dark"
                    >
                        <Plus size={15} /> {translate('portal', 'sce_add_new_category')}
                    </button>
                    {products.length > 0 && (
                        <button
                            type="button"
                            onClick={handleLoadFromProducts}
                            className="button small light"
                        >
                            ⚡ {translate('portal', 'sce_load_from_products', { count: String(products.length) })}
                        </button>
                    )}
                </div>

                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small tone-info"
                    >
                        ↺ {translate('portal', 'sce_reset_reference_defaults')}
                    </button>
                    <button
                        type="button"
                        onClick={handleClearAll}
                        className="button small danger"
                    >
                        🗑️ {translate('portal', 'sce_clear_all')}
                    </button>
                </div>
            </div>

            {/* Visual Builder Mode */}
            {mode === 'visual' && (
                <div>
                    {items.length === 0 ? (
                        <div className="ed-empty">
                            <div className="ed-empty-icon">🏷️</div>
                            <strong className="ed-strong is-lg is-block">{translate('portal', 'cms_no_categories_yet')}</strong>
                            <p className="ed-desc ed-mb">
                                {translate('portal', 'sce_no_categories_hint')}
                            </p>
                            <div className="ed-row is-loose is-center">
                                <button
                                    type="button"
                                    onClick={handleAddItem}
                                    className="button small dark"
                                >
                                    <Plus size={16} /> {translate('portal', 'sce_add_first_category')}
                                </button>
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    className="button small"
                                >
                                    ↺ {translate('portal', 'sce_restore_reference_defaults')}
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="ed-stack">
                            {items.map((item, idx) => {
                                const isImage = (item.icon || '').startsWith('http') || (item.icon || '').startsWith('/') || /\.(png|jpe?g|svg|webp|gif)/i.test(item.icon || '');
                                const isPickerOpen = pickerOpenIdx === idx;

                                return (
                                    <div
                                        key={item.id || idx}
                                        className="ed-card"
                                    >
                                        {/* Card Top Bar */}
                                        <div className="ed-head is-ruled">
                                            <div className="ed-row is-loose">
                                                {/* Reorder Buttons */}
                                                <div className="ed-row is-tight">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMoveUp(idx)}
                                                        disabled={idx === 0}
                                                        title={translate('portal', 'sce_move_category_up')}
                                                        className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                                    >
                                                        ▲
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMoveDown(idx)}
                                                        disabled={idx === items.length - 1}
                                                        title={translate('portal', 'sce_move_category_down')}
                                                        className={`ed-icon-btn is-sm${idx === items.length - 1 ? ' is-disabled' : ''}`}
                                                    >
                                                        ▼
                                                    </button>
                                                </div>

                                                {/* Item Number */}
                                                <span className="hub-pill">
                                                    #{idx + 1}
                                                </span>

                                                {/* Live Icon / Image Preview */}
                                                <div
                                                    className="ed-icon tone-ok"
                                                >
                                                    {isImage ? (
                                                        <img src={item.icon} alt={item.name} className="ed-icon-img" />
                                                    ) : (
                                                        <i className={`fa-solid ${item.icon || 'fa-tag'}`} aria-hidden="true" />
                                                    )}
                                                </div>

                                                <strong className="ed-strong">
                                                    {item.name || <span className="ed-placeholder">{translate('portal', 'sce_untitled_category')}</span>}
                                                </strong>

                                                {/* Active Products Count & Status Pill */}
                                                {item.name.trim() !== '' && (
                                                    (() => {
                                                        const count = products.filter(p => (p.category || '').trim().toLowerCase() === item.name.trim().toLowerCase()).length;
                                                        return count > 0 ? (
                                                            <span className="hub-pill tone-ok">
                                                                ✓ {translate('portal', count === 1 ? 'sce_active_product_count_one' : 'sce_active_product_count_many', { count: String(count) })}
                                                            </span>
                                                        ) : (
                                                            <span className="hub-pill tone-warn" title={translate('portal', 'sce_no_active_products_in_category')}>
                                                                ⚠️ {translate('portal', 'sce_zero_catalog_products')}
                                                            </span>
                                                        );
                                                    })()
                                                )}

                                                {/* Badge Indicator Pill */}
                                                {item.badge && (
                                                    <span className="hub-pill tone-danger">
                                                        ★ {item.badge}
                                                    </span>
                                                )}
                                            </div>

                                            {/* Remove / Delete Button */}
                                            <button
                                                type="button"
                                                onClick={() => handleRemoveItem(idx)}
                                                className="button small danger"
                                            >
                                                <Trash2 size={13} /> {translate('portal', 'sce_remove')}
                                            </button>
                                        </div>

                                        {/* Card Input Fields */}
                                        <div className="ed-grid is-sm">
                                            {/* Category Name */}
                                            <label className="ed-block">
                                                <span className="ed-label">
                                                    {translate('portal', 'sce_category_name')}
                                                </span>
                                                <input
                                                    type="text"
                                                    value={item.name}
                                                    placeholder={translate('portal', 'sce_category_name_ph')}
                                                    onChange={e => handleFieldChange(idx, 'name', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                            </label>

                                            {/* Icon Field & Quick Picker Toggle */}
                                            <label className="ed-block">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline">
                                                        {translate('portal', 'sce_icon_label')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => setPickerOpenIdx(isPickerOpen ? null : idx)}
                                                        className={`ed-mini ${isPickerOpen ? 'is-active' : 'tone-info'}`}
                                                    >
                                                        {isPickerOpen ? translate('portal', 'sce_close_picker') : translate('portal', 'sce_pick_icon')}
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.icon}
                                                    placeholder={translate('portal', 'sce_icon_ph')}
                                                    onChange={e => handleFieldChange(idx, 'icon', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                                <div className="ed-row is-wrap ed-mt-sm">
                                                    <label
                                                        className={`button small light${uploadingIdx === idx ? ' ed-busy' : ''}`}
                                                    >
                                                        <Image size={13} />
                                                        {uploadingIdx === idx
                                                            ? translate('portal', 'sce_uploading_category_image')
                                                            : translate('portal', 'sce_upload_category_image')}
                                                        <input
                                                            type="file"
                                                            accept="image/png,image/jpeg,image/webp"
                                                            disabled={uploadingIdx === idx}
                                                            className="ed-hidden"
                                                            onChange={e => {
                                                                const file = e.target.files?.[0] ?? null;
                                                                e.target.value = '';
                                                                void uploadCategoryImage(idx, file);
                                                            }}
                                                        />
                                                    </label>
                                                    <small className="ed-meta">
                                                        {translate('portal', 'sce_category_image_hint')}
                                                    </small>
                                                </div>
                                            </label>

                                            {/* Destination Link */}
                                            <label className="ed-block">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline">
                                                        {translate('portal', 'sce_target_url_optional')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            const autoLink = `/products?category=${encodeURIComponent(item.name.trim())}`;
                                                            handleFieldChange(idx, 'link', autoLink);
                                                        }}
                                                        className="ed-mini"
                                                    >
                                                        {translate('portal', 'sce_auto_catalog')}
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.link}
                                                    placeholder={`/products?category=${encodeURIComponent(item.name || translate('portal', 'sce_category_fallback'))}`}
                                                    onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                            </label>

                                            {/* Promotional Badge */}
                                            <label className="ed-block">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline">
                                                        {translate('portal', 'sce_promotional_badge_optional')}
                                                    </span>
                                                    <div className="ed-row is-tight">
                                                        {['HOT', 'NEW', 'POPULAR', ''].map(tag => (
                                                            <button
                                                                key={tag || 'none'}
                                                                type="button"
                                                                onClick={() => handleFieldChange(idx, 'badge', tag)}
                                                                className={`ed-mini${item.badge === tag ? ' is-active' : ''}`}
                                                            >
                                                                {tag || translate('portal', 'sce_clear')}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={item.badge || ''}
                                                    placeholder={translate('portal', 'sce_badge_ph')}
                                                    onChange={e => handleFieldChange(idx, 'badge', e.target.value)}
                                                    className="ed-input is-sm"
                                                />
                                            </label>
                                        </div>

                                        {/* Dropdown Icon Selector (Opens inline if toggled) */}
                                        {isPickerOpen && (
                                            <div
                                                className="ed-picker"
                                            >
                                                <div className="ed-head is-sm">
                                                    <strong className="ed-strong is-sm tone-ok">
                                                        {translate('portal', 'sce_select_icon_for', { name: item.name || translate('portal', 'sce_category_fallback') })}
                                                    </strong>
                                                    <span className="ed-meta">
                                                        {translate('portal', 'cms_click_icon_apply')}
                                                    </span>
                                                </div>

                                                <div className="ed-stack">
                                                    {POPULAR_CATEGORY_ICONS.map(group => (
                                                        <div key={group.groupKey}>
                                                            <div className="ed-group-label">
                                                                {translate('portal', group.groupKey)}
                                                            </div>
                                                            <div className="ed-grid is-xs">
                                                                {group.icons.map(ic => (
                                                                    <button
                                                                        key={ic.icon}
                                                                        type="button"
                                                                        onClick={() => {
                                                                            handleFieldChange(idx, 'icon', ic.icon);
                                                                            setPickerOpenIdx(null);
                                                                        }}
                                                                        className={`ed-option${item.icon === ic.icon ? ' is-active' : ''}`}
                                                                    >
                                                                        <i className={`fa-solid ${ic.icon} ed-picker-icon`} />
                                                                        <span className="ed-truncate">
                                                                            {translate('portal', ic.labelKey)}
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
                            <div className="ed-row is-center ed-mt-sm">
                                <button
                                    type="button"
                                    onClick={handleAddItem}
                                    className="ed-add tone-ok"
                                >
                                    <Plus size={16} /> {translate('portal', 'sce_add_another_category')}
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Raw Text / Bulk Editor Mode */}
            {mode === 'text' && (
                <div>
                    <div className="ed-desc is-sm ed-mb-sm">
                        {translate('portal', 'sce_raw_categories_format_prefix')}{' '}
                        <code>{translate('portal', 'sce_raw_categories_format_code')}</code>
                    </div>
                    <textarea
                        rows={12}
                        value={rawValue}
                        placeholder={defaultValue}
                        onChange={e => onChange(e.target.value)}
                        className="ed-input is-sm is-mono"
                    />
                    <small className="ed-meta is-block">
                        {translate('portal', 'sce_raw_categories_tip')}
                    </small>
                </div>
            )}
        </div>
    );
}

const POPULAR_SHORTCUT_ICONS = [
    { icon: 'fa-seedling', labelKey: 'sce_sc_icon_seedling' },
    { icon: 'fa-comments', labelKey: 'sce_sc_icon_comments' },
    { icon: 'fa-hand-holding-heart', labelKey: 'sce_sc_icon_care' },
    { icon: 'fa-headset', labelKey: 'sce_sc_icon_support' },
    { icon: 'fa-diagram-project', labelKey: 'sce_sc_icon_projects' },
    { icon: 'fa-capsules', labelKey: 'sce_sc_icon_medicine' },
    { icon: 'fa-kit-medical', labelKey: 'sce_sc_icon_kit' },
    { icon: 'fa-user-doctor', labelKey: 'sce_sc_icon_doctor' },
    { icon: 'fa-spa', labelKey: 'sce_sc_icon_spa' },
    { icon: 'fa-truck-fast', labelKey: 'sce_sc_icon_delivery' },
    { icon: 'fa-shield-halved', labelKey: 'sce_sc_icon_quality' },
    { icon: 'fa-location-dot', labelKey: 'sce_sc_icon_locator' },
    { icon: 'fa-award', labelKey: 'sce_sc_icon_award' },
    { icon: 'fa-store', labelKey: 'sce_sc_icon_outlets' },
    { icon: 'fa-phone', labelKey: 'sce_sc_icon_hotline' },
    { icon: 'fa-envelope', labelKey: 'sce_sc_icon_email' },
    { icon: 'fa-circle-question', labelKey: 'sce_sc_icon_faq' },
    { icon: 'fa-gift', labelKey: 'sce_sc_icon_gifts' },
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
            title: translate('portal', 'sce_new_shortcut_title'),
            subtitle: translate('portal', 'sce_new_shortcut_subtitle'),
            icon: 'fa-star',
            link: '/products',
        };
        onChange([...shortcuts, newItem]);
    };

    const handleRemove = (index: number) => {
        const target = shortcuts[index];
        const name = target?.title?.trim() || translate('portal', 'sce_shortcut_number', { number: String(index + 1) });
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
            <div className={`ed-switch-banner${enabled ? ' is-on tone-ok' : ''}`}>
                <div className="ed-row is-loose">
                    <div className="ed-switch-icon">
                        {enabled ? '✓' : '✕'}
                    </div>
                    <div>
                        <div className="ed-row">
                            <strong className="ed-strong is-lg">
                                {translate('portal', 'sce_shortcuts_section_status', {
                                    state: enabled
                                        ? translate('portal', 'sce_section_active_visible')
                                        : translate('portal', 'sce_section_hidden'),
                                })}
                            </strong>
                            <span className="ed-state-pill">
                                {enabled ? translate('portal', 'sce_enabled') : translate('portal', 'sce_disabled')}
                            </span>
                        </div>
                        <p className="ed-desc">
                            {enabled
                                ? translate('portal', 'sce_shortcuts_enabled_hint')
                                : translate('portal', 'sce_shortcuts_disabled_hint')}
                        </p>
                    </div>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={() => onToggleEnabled(!enabled)}
                        className={`button ${enabled ? 'danger' : 'dark'}`}
                    >
                        {enabled
                            ? translate('portal', 'sce_disable_hide_section')
                            : translate('portal', 'sce_enable_show_section')}
                    </button>
                </div>
            </div>

            {/* Action Bar */}
            <div className="ed-head">
                <div className="ed-row is-loose">
                    <h3 className="ed-title is-flush">
                        {translate('portal', 'sce_shortcuts_heading', { count: String(shortcuts.length) })}
                    </h3>
                    <span className="ed-desc is-sm">
                        {translate('portal', 'sce_shortcuts_manage_hint')}
                    </span>
                </div>
                <div className="ed-row">
                    <button
                        type="button"
                        onClick={handleAdd}
                        className="button small dark"
                    >
                        <Plus size={14} /> {translate('portal', 'sce_add_shortcut')}
                    </button>
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small"
                    >
                        {translate('portal', 'sce_reset_five_shortcuts')}
                    </button>
                </div>
            </div>

            {/* List of Shortcuts */}
            <div className="ed-stack">
                {shortcuts.map((sc, idx) => {
                    const defSc = defaultShortcuts[idx] || { title: translate('portal', 'sce_shortcut_fallback_title'), subtitle: translate('portal', 'sce_shortcut_fallback_subtitle'), icon: 'fa-seedling', link: '/products' };
                    const isPickerOpen = pickerOpenIdx === idx;
                    const isImg = (sc.icon || '').startsWith('/') || (sc.icon || '').startsWith('http');
                    const cleanIconClass = (sc.icon || defSc.icon).startsWith('fa-') ? (sc.icon || defSc.icon) : `fa-${sc.icon || defSc.icon}`;

                    return (
                        <div
                            key={sc.id || idx}
                            className="ed-card"
                        >
                            {/* Card Header */}
                            <div className="ed-head is-ruled">
                                <div className="ed-row is-loose">
                                    <span className="hub-step">
                                        {idx + 1}
                                    </span>
                                    <strong className="ed-strong">
                                        {sc.title || defSc.title || translate('portal', 'sce_shortcut_number', { number: String(idx + 1) })}
                                    </strong>
                                    {sc.subtitle && (
                                        <span className="ed-desc is-sm">
                                            — {sc.subtitle}
                                        </span>
                                    )}
                                </div>
                                <div className="ed-row is-tight">
                                    <button
                                        type="button"
                                        disabled={idx === 0}
                                        onClick={() => handleMoveUp(idx)}
                                        title={translate('portal', 'sce_move_up')}
                                        className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={idx === shortcuts.length - 1}
                                        onClick={() => handleMoveDown(idx)}
                                        title={translate('portal', 'sce_move_down')}
                                        className={`ed-icon-btn is-sm${idx === shortcuts.length - 1 ? ' is-disabled' : ''}`}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(idx)}
                                        title={translate('portal', 'sce_delete_shortcut')}
                                        className="button small danger"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>

                            {/* Card Body Grid */}
                            <div className="ed-grid is-md">
                                {/* Title */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_title')}
                                    </label>
                                    <input
                                        type="text"
                                        value={sc.title}
                                        placeholder={defSc.title}
                                        onChange={e => handleFieldChange(idx, 'title', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSc.title ?? '') })}</small>
                                </div>

                                {/* Subtitle */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_subtitle_caption')}
                                    </label>
                                    <input
                                        type="text"
                                        value={sc.subtitle}
                                        placeholder={defSc.subtitle}
                                        onChange={e => handleFieldChange(idx, 'subtitle', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSc.subtitle ?? '') })}</small>
                                </div>

                                {/* Icon */}
                                <div>
                                    <div className="ed-head is-sm">
                                        <label className="ed-label is-inline">
                                            {translate('portal', 'sce_icon_fa_or_image')}
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => setPickerOpenIdx(isPickerOpen ? null : idx)}
                                            className={`ed-mini ${isPickerOpen ? 'is-active' : 'tone-info'}`}
                                        >
                                            {isPickerOpen ? `✕ ${translate('portal', 'sce_close')}` : `🎨 ${translate('portal', 'sce_pick_icon')}`}
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        value={sc.icon}
                                        placeholder={defSc.icon}
                                        onChange={e => handleFieldChange(idx, 'icon', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSc.icon ?? '') })}</small>
                                </div>

                                {/* Link */}
                                <div>
                                    <div className="ed-head is-sm">
                                        <label className="ed-label is-inline">
                                            {translate('portal', 'sce_target_link')}
                                        </label>
                                        <div className="ed-row is-tight">
                                            {[
                                                { label: translate('portal', 'sce_link_preset_products'), url: '/products' },
                                                { label: translate('portal', 'sce_link_preset_dealers'), url: '/dealers' },
                                                { label: translate('portal', 'sce_link_preset_about'), url: '/about' },
                                                { label: translate('portal', 'sce_link_preset_complaint'), url: '/contact?topic=complaint' },
                                            ].map(preset => (
                                                <button
                                                    key={preset.url}
                                                    type="button"
                                                    onClick={() => handleFieldChange(idx, 'link', preset.url)}
                                                    className="ed-mini"
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
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">
                                        {translate('portal', 'sce_default_placeholder', { value: defSc.link })}
                                    </small>
                                </div>
                            </div>

                            {/* Icon Picker Popdown */}
                            {isPickerOpen && (
                                <div className="ed-picker">
                                    <div className="ed-head is-sm">
                                        <strong className="ed-strong is-sm tone-ok">{translate('portal', 'sce_choose_icon')}</strong>
                                        <span className="ed-meta">{translate('portal', 'cms_click_icon_apply_short')}</span>
                                    </div>
                                    <div className="ed-grid is-xs">
                                        {POPULAR_SHORTCUT_ICONS.map(ic => (
                                            <button
                                                key={ic.icon}
                                                type="button"
                                                onClick={() => {
                                                    handleFieldChange(idx, 'icon', ic.icon);
                                                    setPickerOpenIdx(null);
                                                }}
                                                className={`ed-option${sc.icon === ic.icon ? ' is-active' : ''}`}
                                            >
                                                <i className={`fa-solid ${ic.icon} ed-picker-icon`} />
                                                <span className="ed-truncate">
                                                    {translate('portal', ic.labelKey)}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Live Card Preview Box */}
                            <div className="ed-foot ed-head is-flush">
                                <span className="ed-kicker">
                                    {translate('portal', 'sce_homepage_card_preview')}
                                </span>
                                <div className="ed-preview-chip">
                                    {isImg ? (
                                        <img src={sc.icon} alt={sc.title} className="ed-icon-img is-lg" />
                                    ) : (
                                        <i className={`fa-solid ${cleanIconClass} ed-preview-icon`} />
                                    )}
                                    <div>
                                        <div className="ed-strong">
                                            {sc.title || defSc.title}
                                        </div>
                                        {(sc.subtitle || defSc.subtitle) && (
                                            <div className="ed-meta">
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
            <div className="ed-row is-center ed-mt">
                <button
                    type="button"
                    onClick={handleAdd}
                    className="ed-add tone-ok"
                >
                    <Plus size={16} /> {translate('portal', 'sce_add_another_shortcut_card')}
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
        const name = target?.title?.trim() || translate('portal', 'sce_offer_card_number', { number: String(index + 1) });
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
            notify(translate('portal', 'cms_alert_select_product'), 'error');
            return;
        }
        const p = products.find(prod => String(prod.id) === prodId);
        if (!p) return;

        const target = cards[cardIndex];
        const existingItems = target.bundle_items || [];

        if (existingItems.some(item => String(item.product_id) === String(p.id))) {
            notify(translate('portal', 'cms_alert_already_in_package', { name: p.name }), 'error');
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
                notify(translate('portal', 'cms_alert_mrp_gt_offer'), 'error');
            }
        } else {
            notify(translate('portal', 'cms_alert_enter_prices'), 'error');
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
            <div className={`ed-switch-banner ed-mb-lg${enabled ? ' is-on tone-ok' : ''}`}>
                <div className="ed-row is-loose is-top">
                    <div className="ed-switch-icon">
                        {enabled ? '✓' : '✕'}
                    </div>
                    <div>
                        <div className="ed-row">
                            <strong className="ed-strong is-lg">
                                {translate('portal', 'sce_campaign_section_status', {
                                    state: enabled
                                        ? translate('portal', 'sce_section_active_visible')
                                        : translate('portal', 'sce_section_hidden'),
                                })}
                            </strong>
                            <span className="ed-state-pill">
                                {enabled ? translate('portal', 'sce_enabled') : translate('portal', 'sce_disabled')}
                            </span>
                        </div>
                        <p className="ed-desc">
                            {enabled
                                ? translate('portal', 'sce_campaign_enabled_hint', { count: String(cards.length) })
                                : translate('portal', 'sce_campaign_disabled_hint')}
                        </p>
                    </div>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={() => onToggleEnabled(!enabled)}
                        className={`button ${enabled ? 'danger' : 'dark'}`}
                    >
                        {enabled
                            ? translate('portal', 'sce_disable_hide_section')
                            : translate('portal', 'sce_enable_show_section')}
                    </button>
                </div>
            </div>

            {/* Header Titles */}
            <div className="ed-card is-soft ed-mb-lg">
                <h4 className="ed-subtitle">
                    {translate('portal', 'sce_section_headings_banner')}
                </h4>
                <div className="ed-grid is-md">
                    <div>
                        <label className="ed-label">
                            {translate('portal', 'sce_section_badge_label')}
                        </label>
                        <input
                            type="text"
                            value={badge}
                            placeholder={defaultBadge}
                            onChange={e => onUpdateHeaderField('campaign_badge', e.target.value)}
                            className="ed-input"
                        />
                        <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defaultBadge ?? '') })}</small>
                    </div>
                    <div>
                        <label className="ed-label">
                            {translate('portal', 'sce_section_main_title')}
                        </label>
                        <input
                            type="text"
                            value={title}
                            placeholder={defaultTitle}
                            onChange={e => onUpdateHeaderField('campaign_title', e.target.value)}
                            className="ed-input"
                        />
                        <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defaultTitle ?? '') })}</small>
                    </div>
                    <div className="hub-span-all">
                        <label className="ed-label">
                            {translate('portal', 'sce_section_subtitle_description')}
                        </label>
                        <input
                            type="text"
                            value={subtitle}
                            placeholder={defaultSubtitle}
                            onChange={e => onUpdateHeaderField('campaign_subtitle', e.target.value)}
                            className="ed-input"
                        />
                        <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defaultSubtitle ?? '') })}</small>
                    </div>
                </div>
            </div>

            {/* Action Bar for Offers */}
            <div className="ed-head">
                <div>
                    <h3 className="ed-title is-flush">
                        {translate('portal', 'sce_offer_cards_heading', { count: String(cards.length) })}
                    </h3>
                    <span className="ed-desc is-sm">
                        {translate('portal', 'sce_offer_cards_hint')}
                    </span>
                </div>
                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleAddSingle}
                        className="button small dark"
                    >
                        <Plus size={15} /> {translate('portal', 'sce_add_single_offer')}
                    </button>
                    <button
                        type="button"
                        onClick={handleAddBundle}
                        className="button small dark"
                    >
                        <Plus size={15} /> {translate('portal', 'sce_add_package_offer')}
                    </button>
                    <button
                        type="button"
                        onClick={handleReset}
                        className="button small"
                    >
                        {translate('portal', 'sce_reset_to_defaults')}
                    </button>
                </div>
            </div>

            {/* Offer Cards List */}
            <div className="ed-stack is-loose">
                {cards.map((card, idx) => {
                    const isHighlight = card.highlight === '1' || card.highlight === true;
                    const cardType: 'single' | 'bundle' = card.type || (card.bundle_items && card.bundle_items.length > 0 ? 'bundle' : 'single');
                    const defCard = defaultCards[idx] || {
                        tag: cardType === 'bundle' ? translate('portal', 'sce_offer_fallback_tag_package') : translate('portal', 'sce_offer_fallback_tag_single'),
                        title: cardType === 'bundle' ? translate('portal', 'sce_offer_fallback_title_package') : translate('portal', 'sce_offer_fallback_title_single'),
                        desc: '',
                        feat1: '',
                        feat2: '',
                        price: '',
                        mrp: '',
                        save: '',
                        btn: cardType === 'bundle' ? translate('portal', 'sce_offer_fallback_btn_package') : translate('portal', 'sce_offer_fallback_btn_single'),
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
                            className={`ed-card is-lg${isHighlight ? ' is-brand' : cardType === 'bundle' ? ' is-info' : ''}`}
                        >
                            {/* Card Top Control Bar */}
                            <div className="ed-head is-ruled">
                                <div className="ed-row is-loose is-wrap">
                                    <span className={`hub-step ${cardType === 'bundle' ? 'tone-info' : isHighlight ? 'tone-ok' : 'tone-brand'}`}>
                                        {idx + 1}
                                    </span>
                                    <strong className="ed-strong is-lg">
                                        {card.title || defCard.title || translate('portal', 'sce_offer_card_number', { number: String(idx + 1) })}
                                    </strong>

                                    {/* Mode Indicator & Switcher */}
                                    <div className="hub-seg">
                                        <button
                                            type="button"
                                            onClick={() => handleSwitchCardType(idx, 'single')}
                                            className={cardType === 'single' ? 'is-active' : undefined}
                                        >
                                            🏷️ {translate('portal', 'sce_single_product')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleSwitchCardType(idx, 'bundle')}
                                            className={cardType === 'bundle' ? 'is-active' : undefined}
                                        >
                                            📦 {translate('portal', 'sce_package_bundle')}
                                        </button>
                                    </div>

                                    {isHighlight && (
                                        <span className="hub-pill tone-ok">
                                            ★ {translate('portal', 'sce_highlighted_badge')}
                                        </span>
                                    )}
                                </div>

                                <div className="ed-row">
                                    {/* Highlight Toggle Button */}
                                    <button
                                        type="button"
                                        onClick={() => handleFieldChange(idx, 'highlight', isHighlight ? '0' : '1')}
                                        className={`button small${isHighlight ? ' dark' : ''}`}
                                    >
                                        ★ {isHighlight ? translate('portal', 'sce_highlighted_active') : translate('portal', 'sce_set_highlighted')}
                                    </button>

                                    {/* Reorder Buttons */}
                                    <button
                                        type="button"
                                        disabled={idx === 0}
                                        onClick={() => handleMoveUp(idx)}
                                        title={translate('portal', 'sce_move_up')}
                                        className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={idx === cards.length - 1}
                                        onClick={() => handleMoveDown(idx)}
                                        title={translate('portal', 'sce_move_down')}
                                        className={`ed-icon-btn is-sm${idx === cards.length - 1 ? ' is-disabled' : ''}`}
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(idx)}
                                        title={translate('portal', 'sce_delete_offer_card')}
                                        className="button small danger"
                                    >
                                        ✕ {translate('portal', 'sce_delete')}
                                    </button>
                                </div>
                            </div>

                            {/* MODE A: SINGLE PRODUCT OFFER CONFIGURATION */}
                            {cardType === 'single' && (
                                <div>
                                    {/* Catalog Product Selection Bar */}
                                    <div className="ed-callout tone-ok ed-mb">
                                        <div className="ed-head is-sm">
                                            <span className="ed-strong tone-ok">
                                                🏷️ {translate('portal', 'sce_select_catalog_product_to_feature')}
                                            </span>
                                            {card.product_id && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleFieldChange(idx, 'product_id', '')}
                                                    className="ed-mini tone-danger"
                                                >
                                                    ✕ {translate('portal', 'sce_disconnect_product')}
                                                </button>
                                            )}
                                        </div>
                                        <select
                                            value={card.product_id || ''}
                                            onChange={e => handleProductSelect(idx, e.target.value)}
                                            className="ed-input"
                                        >
                                            <option value="">{translate('portal', 'sce_choose_catalog_product')}</option>
                                            {products.map(p => (
                                                <option key={p.id} value={p.id}>
                                                    #{p.id}: {p.name} {translate('portal', 'sce_option_mrp_tk', { mrp: String(p.mrp) })} {p.sku ? translate('portal', 'sce_option_sku', { sku: String(p.sku) }) : ''} — {translate('portal', 'sce_option_category', { category: String(p.category ?? '') })}
                                                </option>
                                            ))}
                                        </select>

                                        {/* Product Base Details & Promotional Discount Rate Setting */}
                                        {linkedProduct ? (
                                            <div className="ed-foot">
                                                <div className="ed-grid is-sm ed-mb">
                                                    <div className="ed-stat">
                                                        <span className="ed-meta is-block">{translate('portal', 'sce_regular_retail_mrp')}</span>
                                                        <strong className="ed-strong">৳ {linkedMrp.toLocaleString()}</strong>
                                                    </div>
                                                    <div className="ed-stat">
                                                        <span className="ed-meta is-block">{translate('portal', 'sce_base_dealer_catalog_price')}</span>
                                                        <strong className="ed-strong">
                                                            {linkedDealerPrice > 0
                                                                ? translate('portal', 'sce_dealer_price_with_off', {
                                                                    price: linkedDealerPrice.toLocaleString(),
                                                                    pct: String(regDealerDiscountPct),
                                                                })
                                                                : translate('portal', 'sce_standard_mrp')}
                                                        </strong>
                                                    </div>
                                                    <div className="ed-stat">
                                                        <span className="ed-meta is-block">{translate('portal', 'sce_product_sku_code')}</span>
                                                        <strong className="ed-strong">{linkedProduct.sku || `#${linkedProduct.id}`}</strong>
                                                    </div>
                                                </div>

                                                {/* Promotional Discount % Input & Quick Presets */}
                                                <div className="ed-card is-compact is-accent">
                                                    <label className="ed-label tone-ok">
                                                        ⚡ {translate('portal', 'sce_set_promo_discount_pct')}
                                                    </label>
                                                    <div className="ed-row is-loose is-wrap">
                                                        <div className="ed-row is-tight">
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={99}
                                                                value={card.promo_discount_pct ?? 60}
                                                                onChange={e => handlePromoDiscountChange(idx, e.target.value)}
                                                                className="ed-input ed-pct tone-ok"
                                                            />
                                                            <strong className="ed-strong is-lg tone-ok">%</strong>
                                                        </div>

                                                        {/* Quick Presets */}
                                                        <div className="ed-row is-wrap">
                                                            <span className="ed-label is-inline">{translate('portal', 'sce_quick_presets')}</span>
                                                            {[30, 40, 50, 60, 70].map(pct => (
                                                                <button
                                                                    key={pct}
                                                                    type="button"
                                                                    onClick={() => handlePromoDiscountChange(idx, pct)}
                                                                    className={`ed-chip${currentPromoDiscountPct === pct ? ' is-active' : ''}`}
                                                                >
                                                                    {pct}%
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <small className="ed-meta is-block">
                                                {translate('portal', 'sce_select_product_auto_price_hint')}
                                            </small>
                                        )}
                                    </div>

                                    {/* Financial Breakdown & Accounting Rule Card */}
                                    {card.product_id && linkedMrp > 0 && (
                                        <div className="ed-callout tone-ok ed-mb">
                                            <div className="ed-head is-sm">
                                                <span className="ed-strong ed-row">
                                                    💰 {translate('portal', 'sce_offer_price_summary')}
                                                </span>
                                                <span className="hub-pill tone-ok">
                                                    {translate('portal', 'sce_offer_discount_active', { pct: String(currentPromoDiscountPct) })}
                                                </span>
                                            </div>
                                            <div className="ed-grid is-xs ed-mb-sm">
                                                <div className="ed-stat">
                                                    <span className="ed-meta is-block">{translate('portal', 'sce_regular_mrp_heading')}</span>
                                                    <strong className="ed-strong">৳ {linkedMrp.toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted">
                                                    <span className="ed-meta is-block tone-ok">{translate('portal', 'sce_offer_price_payable')}</span>
                                                    <strong className="ed-strong is-lg tone-ok">৳ {parsePrice(card.price).toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted tone-danger">
                                                    <span className="ed-meta is-block tone-danger">{translate('portal', 'sce_dealer_profit_savings')}</span>
                                                    <strong className="ed-strong tone-danger">৳ {parsePrice(card.save).toLocaleString()}</strong>
                                                </div>
                                            </div>
                                            <p className="ed-desc is-sm is-flush">
                                                ℹ️ <strong>{translate('portal', 'sce_pricing_rule_label')}</strong>{' '}
                                                {withBoldValues(translate('portal', 'sce_pricing_rule_body'), {
                                                    pct: `${currentPromoDiscountPct}%`,
                                                    price: `৳${parsePrice(card.price).toLocaleString()}`,
                                                })}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* MODE B: GROUP / PACKAGE BUNDLE CONFIGURATION */}
                            {cardType === 'bundle' && (
                                <div>
                                    {/* Bundle Multi-Product Picker */}
                                    <div className="ed-callout tone-info ed-mb">
                                        <div className="ed-head is-sm">
                                            <div>
                                                <strong className="ed-strong tone-info is-block">
                                                    📦 {translate('portal', 'sce_build_package_heading', { count: String(bundleItems.length) })}
                                                </strong>
                                                <span className="ed-meta">
                                                    {translate('portal', 'sce_build_package_hint')}
                                                </span>
                                            </div>
                                        </div>

                                        {/* Product Add Bar */}
                                        <div className="ed-row is-wrap ed-mb">
                                            <select
                                                value={bundleSelectedProds[idx] || ''}
                                                onChange={e => setBundleSelectedProds(prev => ({ ...prev, [idx]: e.target.value }))}
                                                className="ed-input is-grow"
                                            >
                                                <option value="">{translate('portal', 'sce_choose_product_for_package')}</option>
                                                {products.map(p => (
                                                    <option key={p.id} value={p.id}>
                                                        #{p.id}: {p.name} {translate('portal', 'sce_option_mrp_tk', { mrp: String(p.mrp) })} {p.sku ? translate('portal', 'sce_option_sku', { sku: String(p.sku) }) : ''} — {p.category}
                                                    </option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => handleAddBundleItem(idx)}
                                                className="button small dark"
                                            >
                                                <Plus size={14} /> {translate('portal', 'sce_add_product_to_package')}
                                            </button>
                                        </div>

                                        {/* Table of Bundled Items */}
                                        {bundleItems.length === 0 ? (
                                            <div className="ed-empty is-sm">
                                                {translate('portal', 'sce_package_empty')}
                                            </div>
                                        ) : (
                                            <div className="ed-table-wrap">
                                                <table className="ed-table">
                                                    <thead>
                                                        <tr>
                                                            <th className="ed-col-xs">#</th>
                                                            <th>{translate('portal', 'sce_col_product_name')}</th>
                                                            <th className="ed-col-md">{translate('portal', 'sce_col_list_price')}</th>
                                                            {bundleMode === 'itemized' && (
                                                                <>
                                                                    <th className="ed-col-md">{translate('portal', 'sce_col_item_discount_pct')}</th>
                                                                    <th className="ed-col-md">{translate('portal', 'sce_col_final_price')}</th>
                                                                </>
                                                            )}
                                                            <th className="is-num ed-col-sm">{translate('portal', 'sce_col_action')}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {bundleItems.map((it, itemIdx) => (
                                                            <tr key={itemIdx}>
                                                                <td className="ed-meta">{itemIdx + 1}</td>
                                                                <td>
                                                                    <strong className="ed-strong is-block">{it.name}</strong>
                                                                    {it.sku && <span className="ed-meta">{translate('portal', 'sce_sku_value', { sku: String(it.sku) })}</span>}
                                                                </td>
                                                                <td className="is-strong">৳ {parsePrice(it.mrp).toLocaleString()}</td>
                                                                {bundleMode === 'itemized' && (
                                                                    <>
                                                                        <td>
                                                                            <div className="ed-row is-tight">
                                                                                <input
                                                                                    type="number"
                                                                                    min={0}
                                                                                    max={99}
                                                                                    value={it.custom_discount_pct ?? bundlePct}
                                                                                    onChange={e => handleItemizedDiscountChange(idx, itemIdx, parseFloat(e.target.value) || 0)}
                                                                                    className="ed-input ed-pct"
                                                                                />
                                                                                <span>%</span>
                                                                            </div>
                                                                        </td>
                                                                        <td className="is-strong tone-info">
                                                                            ৳ {parsePrice(it.item_price).toLocaleString()}
                                                                        </td>
                                                                    </>
                                                                )}
                                                                <td className="is-num">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleRemoveBundleItem(idx, itemIdx)}
                                                                        className="button small danger"
                                                                    >
                                                                        ✕ {translate('portal', 'sce_remove')}
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
                                            <div className="ed-card is-compact">
                                                <div className="ed-head is-sm">
                                                    <span className="ed-label is-inline tone-info">
                                                        {translate('portal', 'sce_package_discount_mode')}
                                                    </span>
                                                    <div className="ed-row is-loose">
                                                        <label className={`ed-choice${bundleMode === 'total' ? ' is-active' : ''}`}>
                                                            <input
                                                                type="radio"
                                                                name={`bundle_mode_${idx}`}
                                                                checked={bundleMode === 'total'}
                                                                onChange={() => recalculateBundleCard(idx, bundleItems, 'total', bundlePct)}
                                                            />
                                                            {translate('portal', 'sce_package_mode_total')}
                                                        </label>
                                                        <label className={`ed-choice${bundleMode === 'itemized' ? ' is-active' : ''}`}>
                                                            <input
                                                                type="radio"
                                                                name={`bundle_mode_${idx}`}
                                                                checked={bundleMode === 'itemized'}
                                                                onChange={() => recalculateBundleCard(idx, bundleItems, 'itemized', bundlePct)}
                                                            />
                                                            {translate('portal', 'sce_package_mode_itemized')}
                                                        </label>
                                                    </div>
                                                </div>

                                                {bundleMode === 'total' && (
                                                    <div className="ed-row is-loose is-wrap">
                                                        <span className="ed-label is-inline">{translate('portal', 'sce_package_flat_discount_pct')}</span>
                                                        <div className="ed-row is-tight">
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={99}
                                                                value={card.bundle_discount_pct ?? 35}
                                                                onChange={e => recalculateBundleCard(idx, bundleItems, 'total', e.target.value)}
                                                                className="ed-input ed-pct tone-info"
                                                            />
                                                            <strong className="ed-strong tone-info">%</strong>
                                                        </div>

                                                        {/* Presets */}
                                                        <div className="ed-row is-wrap">
                                                            <span className="ed-meta">{translate('portal', 'sce_quick_presets')}</span>
                                                            {[20, 25, 30, 35, 40, 50].map(pct => (
                                                                <button
                                                                    key={pct}
                                                                    type="button"
                                                                    onClick={() => recalculateBundleCard(idx, bundleItems, 'total', pct)}
                                                                    className={`ed-chip is-info${bundlePct === pct ? ' is-active' : ''}`}
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
                                        <div className="ed-callout tone-info ed-mb">
                                            <div className="ed-head is-sm">
                                                <span className="ed-strong ed-row">
                                                    📦 {translate('portal', 'sce_package_price_summary')}
                                                </span>
                                                <span className="hub-pill tone-info">
                                                    {translate('portal', 'sce_products_included_count', { count: String(bundleItems.length) })}
                                                </span>
                                            </div>
                                            <div className="ed-grid is-xs ed-mb-sm">
                                                <div className="ed-stat">
                                                    <span className="ed-meta is-block">{translate('portal', 'sce_total_combined_mrp')}</span>
                                                    <strong className="ed-strong">৳ {totalBundleMrp.toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted">
                                                    <span className="ed-meta is-block tone-info">{translate('portal', 'sce_package_offer_price')}</span>
                                                    <strong className="ed-strong is-lg tone-info">৳ {effectiveBundlePrice.toLocaleString()}</strong>
                                                </div>
                                                <div className="ed-stat is-tinted tone-danger">
                                                    <span className="ed-meta is-block tone-danger">{translate('portal', 'sce_total_package_savings')}</span>
                                                    <strong className="ed-strong tone-danger">৳ {effectiveBundleSave.toLocaleString()} ({effectiveBundleDiscountPct}%)</strong>
                                                </div>
                                            </div>
                                            <p className="ed-desc is-sm is-flush">
                                                ℹ️ <strong>{translate('portal', 'sce_package_pricing_label')}</strong>{' '}
                                                {withBoldValues(translate('portal', 'sce_package_pricing_body'), {
                                                    price: `৳${effectiveBundlePrice.toLocaleString()}`,
                                                })}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Card Content Grid (Common Fields) */}
                            <div className="ed-grid is-md">
                                {/* Tag */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_tag_badge')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.tag}
                                        placeholder={defCard.tag}
                                        onChange={e => handleFieldChange(idx, 'tag', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.tag ?? '') })}</small>
                                </div>

                                {/* Title */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_title')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.title}
                                        placeholder={defCard.title}
                                        onChange={e => handleFieldChange(idx, 'title', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.title ?? '') })}</small>
                                </div>

                                {/* Description */}
                                <div className="hub-span-all">
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_description')}
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={card.desc}
                                        placeholder={defCard.desc}
                                        onChange={e => handleFieldChange(idx, 'desc', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.desc ?? '') })}</small>
                                </div>

                                {/* Bullet 1 */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_feature_1')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.feat1}
                                        placeholder={defCard.feat1}
                                        onChange={e => handleFieldChange(idx, 'feat1', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.feat1 ?? '') })}</small>
                                </div>

                                {/* Bullet 2 */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_feature_2')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.feat2}
                                        placeholder={defCard.feat2}
                                        onChange={e => handleFieldChange(idx, 'feat2', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.feat2 ?? '') })}</small>
                                </div>

                                {/* Offer Price */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_price_example')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.price}
                                        placeholder={defCard.price}
                                        onChange={e => handleFieldChange(idx, 'price', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.price ?? '') })}</small>
                                </div>

                                {/* MRP */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_mrp_example')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.mrp}
                                        placeholder={defCard.mrp}
                                        onChange={e => handleFieldChange(idx, 'mrp', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.mrp ?? '') })}</small>
                                </div>

                                {/* Save Badge */}
                                <div>
                                    <div className="ed-head is-sm">
                                        <label className="ed-label is-inline">
                                            {translate('portal', 'sce_offer_save_badge_example')}
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => handleAutoCalculateSave(idx)}
                                            className="ed-mini tone-ok"
                                        >
                                            ⚡ {translate('portal', 'sce_calculate')}
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        value={card.save}
                                        placeholder={defCard.save}
                                        onChange={e => handleFieldChange(idx, 'save', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.save ?? '') })}</small>
                                </div>

                                {/* Action Button */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_button_text')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.btn}
                                        placeholder={defCard.btn}
                                        onChange={e => handleFieldChange(idx, 'btn', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.btn ?? '') })}</small>
                                </div>

                                {/* Destination Link */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_destination_link')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.link}
                                        placeholder={defCard.link}
                                        onChange={e => handleFieldChange(idx, 'link', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defCard.link ?? '') })}</small>
                                </div>

                                {/* Offer Image */}
                                <div>
                                    <label className="ed-label">
                                        {translate('portal', 'sce_offer_image_url_optional')}
                                    </label>
                                    <input
                                        type="text"
                                        value={card.image}
                                        placeholder="/branding/madina-side-banner-1.jpg"
                                        onChange={e => handleFieldChange(idx, 'image', e.target.value)}
                                        className="ed-input"
                                    />
                                    <small className="ed-hint">{translate('portal', 'sce_offer_image_blank_hint')}</small>
                                </div>
                            </div>

                            {/* Live Card Preview */}
                            <div className="ed-foot">
                                <span className="ed-kicker ed-mb-sm">
                                    {translate('portal', 'sce_homepage_card_preview')}
                                </span>
                                <div className={`ed-preview-card${isHighlight ? ' is-brand' : cardType === 'bundle' ? ' is-info' : ''}`}>
                                    {card.image && (
                                        <div className="ed-thumb">
                                            <img src={card.image} alt={card.title} />
                                        </div>
                                    )}
                                    <div className="ed-head is-top is-sm">
                                        <h4 className="ed-title is-flush is-sm">
                                            {card.title || defCard.title}
                                        </h4>
                                        <span className={`ed-state-pill ${cardType === 'bundle' ? 'tone-info' : isHighlight ? 'tone-ok' : 'tone-brand'}`}>
                                            {card.tag || defCard.tag}
                                        </span>
                                    </div>
                                    <p className="ed-desc is-sm is-flush ed-mb-sm">
                                        {card.desc || defCard.desc}
                                    </p>

                                    {/* Bundle items preview pill in card */}
                                    {cardType === 'bundle' && bundleItems.length > 0 && (
                                        <div className="ed-callout tone-info is-sm ed-mb-sm">
                                            <strong>📦 {translate('portal', 'sce_package_includes', { count: String(bundleItems.length) })}</strong>
                                            <div className="ed-mt-xs">
                                                {bundleItems.map(it => it.name).join(' • ')}
                                            </div>
                                        </div>
                                    )}

                                    <div className="ed-checklist">
                                        {(card.feat1 || defCard.feat1) && (
                                            <span className="ed-row">
                                                <i className="fa-solid fa-circle-check tone-ok" /> {card.feat1 || defCard.feat1}
                                            </span>
                                        )}
                                        {(card.feat2 || defCard.feat2) && (
                                            <span className="ed-row">
                                                <i className="fa-solid fa-circle-check tone-ok" /> {card.feat2 || defCard.feat2}
                                            </span>
                                        )}
                                    </div>
                                    <div className="ed-row ed-mb">
                                        <strong className="ed-strong is-lg tone-ok">{card.price || defCard.price}</strong>
                                        {(card.mrp || defCard.mrp) && <del className="ed-hint">{card.mrp || defCard.mrp}</del>}
                                        {(card.save || defCard.save) && (
                                            <span className="hub-pill tone-danger">
                                                {card.save || defCard.save}
                                            </span>
                                        )}
                                    </div>
                                    <div className="ed-preview-cta">
                                        {card.btn || defCard.btn} →
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Bottom Add Card Actions */}
            <div className="ed-grid is-md ed-mt">
                <button
                    type="button"
                    onClick={handleAddSingle}
                    className="ed-add tone-ok"
                >
                    <Plus size={16} /> {translate('portal', 'sce_add_another_single_offer')}
                </button>
                <button
                    type="button"
                    onClick={handleAddBundle}
                    className="ed-add tone-info"
                >
                    <Plus size={16} /> {translate('portal', 'sce_add_another_package_offer')}
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
    const translate = useT();
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
        <div className="ed-card is-soft is-lg ed-mb-lg">
            <div className="ed-head">
                <div>
                    <h3 className="ed-title is-flush ed-row">
                        <Package size={20} className="icon-ok" /> {translate('portal', 'sce_featured_products_section')}
                    </h3>
                    <p className="ed-desc">
                        {translate('portal', 'sce_featured_products_section_hint')}
                    </p>
                </div>
                <div className="ed-row is-loose">
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_enabled', isEnabled ? '0' : '1')}
                        className={`ed-toggle${isEnabled ? ' is-on' : ''}`}
                    >
                        <span className="ed-dot" />
                        {isEnabled
                            ? translate('portal', 'sce_section_active')
                            : translate('portal', 'sce_section_hidden_short')}
                    </button>
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_tabs_enabled', isTabsEnabled ? '0' : '1')}
                        className={`ed-toggle${isTabsEnabled ? ' is-on' : ''}`}
                    >
                        <span className="ed-dot" />
                        {isTabsEnabled
                            ? translate('portal', 'sce_filter_tabs_on')
                            : translate('portal', 'sce_filter_tabs_off')}
                    </button>
                </div>
            </div>

            {/* General Section Labels */}
            <div className="form-grid ed-grid is-md ed-mb">
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
            <div className="ed-card ed-mb">
                <div className="ed-strong ed-mb-sm">
                    {translate('portal', 'sce_product_mode')}
                </div>
                <div className="ed-row is-loose is-wrap">
                    <button
                        type="button"
                        onClick={() => updateField('featured_products_mode', 'auto')}
                        className={`ed-mode${mode === 'auto' ? ' is-active' : ''}`}
                    >
                        <span className="ed-radio">
                            {mode === 'auto' && <span className="ed-dot" />}
                        </span>
                        <span>⚡ {translate('portal', 'sce_featured_mode_auto')}</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => updateField('featured_products_mode', 'curated')}
                        className={`ed-mode${mode === 'curated' ? ' is-active' : ''}`}
                    >
                        <span className="ed-radio">
                            {mode === 'curated' && <span className="ed-dot" />}
                        </span>
                        <span>✨ {translate('portal', 'sce_featured_mode_curated')}</span>
                    </button>
                </div>

                {mode === 'auto' && (
                    <div className="ed-well ed-mt">
                        <div className="ed-strong ed-mb-xs">
                            ℹ️ {translate('portal', 'sce_catalog_featured_heading', { count: String(catalogFeatured.length) })}
                        </div>
                        {catalogFeatured.length > 0 ? (
                            <div className="ed-row is-wrap ed-mt-sm">
                                {catalogFeatured.map(p => (
                                    <span key={p.id} className="hub-pill tone-ok">
                                        ★ {p.name} [{p.category}] — {translate('portal', 'sce_amount_tk', { amount: String(p.mrp) })}
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <div className="ed-placeholder">
                                {translate('portal', 'sce_catalog_featured_empty')}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Curated Products List */}
            {mode === 'curated' && (
                <div className="ed-stack">
                    <div className="ed-head is-flush">
                        <span className="ed-strong">
                            {translate('portal', 'sce_curated_products_heading', { count: String(items.length) })}
                        </span>
                        <button
                            type="button"
                            onClick={handleAddItem}
                            className="button small dark"
                        >
                            <Plus size={14} /> {translate('portal', 'sce_add_product_to_showcase')}
                        </button>
                    </div>

                    {items.length === 0 ? (
                        <div className="ed-empty">
                            <Package size={32} className="ed-empty-icon icon-muted" />
                            <p className="ed-desc is-flush ed-mb">
                                {translate('portal', 'sce_curated_products_empty')}
                            </p>
                            <button
                                type="button"
                                onClick={handleAddItem}
                                className="button small dark"
                            >
                                {translate('portal', 'sce_add_first_showcase_product')}
                            </button>
                        </div>
                    ) : (
                        items.map((item, idx) => {
                            const selectedProd = products.find(p => String(p.id) === String(item.product_id));
                            return (
                                <div
                                    key={item.id || idx}
                                    className="ed-card ed-item-row"
                                >
                                    <div className="ed-grid is-md">
                                        {/* Product Selector */}
                                        <label className="ed-block">
                                            <span className="ed-label">
                                                {translate('portal', 'sce_select_product', { n: String(idx + 1) })}
                                            </span>
                                            <select
                                                value={item.product_id}
                                                onChange={e => handleUpdateItem(idx, { product_id: e.target.value })}
                                                className="ed-input is-sm"
                                            >
                                                <option value="">{translate('portal', 'sce_choose_product')}</option>
                                                {products.map(p => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name} [{p.category}] — {translate('portal', 'sce_amount_tk', { amount: String(p.mrp) })} {p.stock !== undefined ? translate('portal', 'sce_stock_label', { count: String(p.stock) }) : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>

                                        {/* Marketing Badge */}
                                        <div>
                                            <span className="ed-label">
                                                {translate('portal', 'sce_marketing_badge')}
                                            </span>
                                            <div className="ed-row is-wrap ed-mb-sm">
                                                {quickBadges.map(qb => (
                                                    <button
                                                        key={qb.label}
                                                        type="button"
                                                        onClick={() => handleUpdateItem(idx, { marketing_badge: qb.label })}
                                                        className="ed-chip"
                                                        style={item.marketing_badge === qb.label ? { borderColor: qb.bg, background: qb.bg, color: qb.text } : undefined}
                                                    >
                                                        {qb.label}
                                                    </button>
                                                ))}
                                                {item.marketing_badge && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleUpdateItem(idx, { marketing_badge: '' })}
                                                        className="ed-mini tone-danger"
                                                    >
                                                        {translate('portal', 'sce_clear')}
                                                    </button>
                                                )}
                                            </div>
                                            <input
                                                type="text"
                                                placeholder={translate('portal', 'sce_custom_badge_ph')}
                                                value={item.marketing_badge || ''}
                                                onChange={e => handleUpdateItem(idx, { marketing_badge: e.target.value })}
                                                className="ed-input is-sm"
                                            />
                                        </div>

                                        {/* Optional Title Override */}
                                        <label className="ed-block">
                                            <span className="ed-label">
                                                {translate('portal', 'sce_title_override_optional')}
                                            </span>
                                            <input
                                                type="text"
                                                placeholder={selectedProd ? selectedProd.name : translate('portal', 'sce_title_override_ph')}
                                                value={item.override_title || ''}
                                                onChange={e => handleUpdateItem(idx, { override_title: e.target.value })}
                                                className="ed-input is-sm"
                                            />
                                        </label>
                                    </div>

                                    {/* Action buttons (Move Up, Move Down, Delete) */}
                                    <div className="ed-stack is-tight is-center">
                                        <div className="ed-row is-tight">
                                            <button
                                                type="button"
                                                disabled={idx === 0}
                                                onClick={() => handleMoveItem(idx, 'up')}
                                                className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                                title={translate('portal', 'sce_move_up')}
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                disabled={idx === items.length - 1}
                                                onClick={() => handleMoveItem(idx, 'down')}
                                                className={`ed-icon-btn is-sm${idx === items.length - 1 ? ' is-disabled' : ''}`}
                                                title={translate('portal', 'sce_move_down')}
                                            >
                                                ↓
                                            </button>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveItem(idx)}
                                            className="ed-icon-btn tone-danger"
                                            title={translate('portal', 'sce_remove_from_showcase')}
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

interface MobileSearchCategoriesManagerProps {
    enabled: string;
    heading: string;
    items: string;
    limit: string;
    featuredCategoriesRaw?: string;
    products?: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    onChange: (field: keyof CustomizationData, value: string) => void;
}

function MobileSearchCategoriesManager({
    enabled,
    heading,
    items,
    limit,
    featuredCategoriesRaw = '',
    products = [],
    onChange,
}: MobileSearchCategoriesManagerProps) {
    const translate = useT();
    const [newName, setNewName] = useState('');
    const isOn = enabled !== '0';
    const lines = (items || '').split(/\r?\n/).map((s) => s.trim()).filter(Boolean);

    const emitLines = (next: string[]) => onChange('mobile_search_categories_items', next.join('\n'));

    const handleAdd = () => {
        const trimmed = newName.trim();
        if (!trimmed) return;
        if (lines.some((l) => l.split('|')[0].trim().toLowerCase() === trimmed.toLowerCase())) {
            notify(translate('portal', 'cms_alert_nav_dup', { name: trimmed }), 'error');
            return;
        }
        emitLines([...lines, trimmed]);
        setNewName('');
    };

    const handleRemove = (index: number) => {
        const target = lines[index]?.split('|')[0]?.trim() || '';
        if (!window.confirm(translate('portal', 'cms_confirm_remove_mobile_search_cat', { name: target }))) {
            return;
        }
        emitLines(lines.filter((_, i) => i !== index));
    };

    const handleMove = (index: number, direction: 'up' | 'down') => {
        const targetIdx = direction === 'up' ? index - 1 : index + 1;
        if (targetIdx < 0 || targetIdx >= lines.length) return;
        const next = [...lines];
        const tmp = next[targetIdx];
        next[targetIdx] = next[index];
        next[index] = tmp;
        emitLines(next);
    };

    const handleSyncFeatured = () => {
        const names = featuredCategoriesRaw.split(/\r?\n/).map((l) => l.split('|')[0].trim()).filter(Boolean);
        if (names.length === 0) {
            notify(translate('portal', 'cms_alert_no_featured'), 'error');
            return;
        }
        emitLines(Array.from(new Set(names)));
    };

    const handleSyncProducts = () => {
        const cats = Array.from(new Set((products || []).map((p) => p.category).filter(Boolean))).sort();
        if (cats.length === 0) {
            notify(translate('portal', 'cms_alert_no_categories'), 'error');
            return;
        }
        emitLines(cats);
    };

    return (
        <div className={`ed-card is-lg hub-span-all ed-mt-sm${isOn ? ' is-on' : ' is-soft'}`}>
            <div className="ed-head">
                <div>
                    <h4 className="ed-title is-flush">
                        {translate('portal', 'cms_mobile_search_categories_title')}
                    </h4>
                    <p className="ed-desc is-sm">
                        {translate('portal', 'cms_mobile_search_categories_hint')}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => onChange('mobile_search_categories_enabled', isOn ? '0' : '1')}
                    className={`button small ${isOn ? 'dark' : 'is-off'}`}
                >
                    {isOn ? translate('portal', 'cms_mobile_search_categories_on') : translate('portal', 'cms_mobile_search_categories_off')}
                </button>
            </div>

            <div className="ed-grid is-md ed-mb">
                <label className="ed-field">
                    {translate('portal', 'cms_mobile_search_categories_heading')}
                    <input
                        value={heading}
                        onChange={(e) => onChange('mobile_search_categories_heading', e.target.value)}
                        placeholder={translate('portal', 'cms_mobile_search_categories_heading_ph')}
                        className="ed-input"
                    />
                </label>
                <label className="ed-field">
                    {translate('portal', 'cms_mobile_search_categories_limit')}
                    <input
                        type="number"
                        min={1}
                        max={24}
                        value={limit || '6'}
                        onChange={(e) => onChange('mobile_search_categories_limit', e.target.value)}
                        className="ed-input is-narrow"
                    />
                </label>
            </div>

            <div className="ed-row is-wrap ed-mb">
                <button type="button" className="button small" onClick={handleSyncFeatured}>
                    {translate('portal', 'cms_mobile_search_sync_featured')}
                </button>
                <button type="button" className="button small" onClick={handleSyncProducts}>
                    {translate('portal', 'cms_mobile_search_sync_products')}
                </button>
                {lines.length > 0 && (
                    <button
                        type="button"
                        className="button small danger"
                        onClick={() => {
                            if (window.confirm(translate('portal', 'cms_mobile_search_clear_confirm'))) {
                                emitLines([]);
                            }
                        }}
                    >
                        {translate('portal', 'cms_mobile_search_clear')}
                    </button>
                )}
            </div>

            <div className="ed-row ed-mb">
                <input
                    value={newName}
                    onChange={(e) => setNewName(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            handleAdd();
                        }
                    }}
                    placeholder={translate('portal', 'cms_mobile_search_add_ph')}
                    className="ed-input is-grow"
                />
                <button type="button" className="button small dark" onClick={handleAdd}>
                    <Plus size={14} /> {translate('portal', 'cms_mobile_search_add')}
                </button>
            </div>

            {lines.length === 0 ? (
                <p className="ed-desc is-sm is-flush">
                    {translate('portal', 'cms_mobile_search_empty')}
                </p>
            ) : (
                <ul className="ed-list">
                    {lines.map((line, index) => {
                        const name = line.split('|')[0].trim();
                        return (
                            <li
                                key={`${name}-${index}`}
                                className="ed-list-item"
                            >
                                <strong className="ed-strong ed-grow">{name}</strong>
                                <button type="button" className="button small" onClick={() => handleMove(index, 'up')} disabled={index === 0} aria-label={translate('portal', 'sce_move_up')}>
                                    <ArrowUp size={14} />
                                </button>
                                <button type="button" className="button small" onClick={() => handleMove(index, 'down')} disabled={index === lines.length - 1} aria-label={translate('portal', 'sce_move_down')}>
                                    <ArrowDown size={14} />
                                </button>
                                <button type="button" className="button small" onClick={() => handleRemove(index)} aria-label={translate('portal', 'sce_remove')}>
                                    <Trash2 size={14} />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

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
            notify(translate('portal', 'cms_alert_nav_dup', { name: trimmed }), 'error');
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
            notify(translate('portal', 'cms_alert_no_featured'), 'error');
            return;
        }
        const unique = Array.from(new Set(names));
        onChange(unique.join('\n'));
    };

    const handleSyncProducts = () => {
        if (!products || products.length === 0) {
            notify(translate('portal', 'cms_alert_no_products'), 'error');
            return;
        }
        const cats = Array.from(new Set(products.map(p => p.category).filter(Boolean))).sort();
        if (cats.length === 0) {
            notify(translate('portal', 'cms_alert_no_categories'), 'error');
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
        <div className="ed-card is-lg hub-span-all ed-mt-sm">
            {/* Header / Title */}
            <div className="ed-head is-top">
                <div>
                    <div className="ed-row is-loose">
                        <div className="ed-icon tone-info">
                            <Tag size={18} />
                        </div>
                        <h4 className="ed-title is-flush">
                            {translate('portal', 'sce_nav_bar_heading')}
                        </h4>
                        <span className={`hub-pill${isCustomized ? ' tone-ok' : ''}`}>
                            {isCustomized
                                ? translate('portal', 'sce_nav_custom_active', { count: String(activeCategories.length) })
                                : translate('portal', 'sce_nav_default_placeholders')}
                        </span>
                    </div>
                    <p className="ed-desc">
                        {translate('portal', 'sce_nav_bar_hint')}
                    </p>
                </div>

                {/* Quick Action Buttons */}
                <div className="ed-row is-wrap">
                    <button
                        type="button"
                        onClick={handleResetDefaults}
                        className="button small"
                        title={translate('portal', 'sce_nav_reset_eight_title')}
                    >
                        <RotateCcw size={13} /> {translate('portal', 'sce_nav_reset_eight')}
                    </button>
                    {isCustomized && (
                        <button
                            type="button"
                            onClick={handleClearToPlaceholders}
                            className="button small danger"
                            title={translate('portal', 'sce_clear_custom_list_title')}
                        >
                            <Trash2 size={13} /> {translate('portal', 'sce_clear_custom_list')}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={handleToggleBulkMode}
                        className={`button small ${bulkMode ? 'dark' : 'tone-info'}`}
                    >
                        <List size={13} /> {bulkMode ? translate('portal', 'sce_exit_bulk') : translate('portal', 'sce_bulk_edit')}
                    </button>
                </div>
            </div>

            {/* Notice / Status description */}
            {!isCustomized && (
                <div className="ed-note">
                    <span>
                        {translate('portal', 'sce_nav_defaults_notice')}
                    </span>
                </div>
            )}

            {/* Add New Category Box */}
            <div className="ed-toolbar is-soft">
                <div className="ed-search">
                    <input
                        type="text"
                        value={newCategoryName}
                        onChange={e => setNewCategoryName(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder={translate('portal', 'sce_add_category_ph')}
                        className="ed-input"
                    />
                </div>
                <button
                    type="button"
                    onClick={handleAdd}
                    disabled={!newCategoryName.trim()}
                    className={`button dark${newCategoryName.trim() ? '' : ' is-disabled'}`}
                >
                    <Plus size={15} /> {translate('portal', 'sce_add_option')}
                </button>
            </div>

            {/* Visual Cards vs Bulk Text Mode */}
            {bulkMode ? (
                <div className="ed-mb">
                    <label className="ed-label">
                        {translate('portal', 'sce_bulk_edit')}
                    </label>
                    <textarea
                        rows={8}
                        value={bulkInput}
                        onChange={e => setBulkInput(e.target.value)}
                        className="ed-input is-mono"
                    />
                    <div className="ed-row is-loose ed-mt-sm">
                        <button
                            type="button"
                            onClick={() => {
                                const parsed = bulkInput.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                                onChange(parsed.join('\n'));
                                setBulkMode(false);
                            }}
                            className="button small dark"
                        >
                            {translate('portal', 'sce_apply_return_to_cards')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setBulkMode(false)}
                            className="button small"
                        >
                            {translate('portal', 'sce_cancel')}
                        </button>
                    </div>
                </div>
            ) : (
                <div className="ed-grid is-list ed-mb">
                    {activeCategories.map((category, idx) => (
                        <div
                            key={idx}
                            className="ed-chip-row"
                        >
                            {/* Position Index Badge */}
                            <span className="ed-index">
                                #{idx + 1}
                            </span>

                            {/* Editable Input */}
                            <input
                                type="text"
                                value={category}
                                onChange={e => handleEdit(idx, e.target.value)}
                                className="ed-inline-input"
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
                            <div className="ed-row is-tight">
                                <button
                                    type="button"
                                    onClick={() => handleMove(idx, 'up')}
                                    disabled={idx === 0}
                                    className={`ed-icon-btn is-sm${idx === 0 ? ' is-disabled' : ''}`}
                                    title={translate('portal', 'sce_move_left_up')}
                                >
                                    <ArrowUp size={12} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleMove(idx, 'down')}
                                    disabled={idx === activeCategories.length - 1}
                                    className={`ed-icon-btn is-sm${idx === activeCategories.length - 1 ? ' is-disabled' : ''}`}
                                    title={translate('portal', 'sce_move_right_down')}
                                >
                                    <ArrowDown size={12} />
                                </button>
                            </div>

                            {/* Remove button */}
                            <button
                                type="button"
                                onClick={() => handleRemove(idx)}
                                className="ed-icon-btn is-sm tone-danger"
                                title={translate('portal', 'sce_remove_named', { name: category })}
                            >
                                <Trash2 size={13} />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {/* Sync Helpers */}
            <div className="ed-row is-wrap ed-mb">
                <button
                    type="button"
                    onClick={handleSyncFeatured}
                    className="button small light"
                >
                    <Sparkles size={13} /> {translate('portal', 'sce_sync_from_featured_categories')}
                </button>
                {products && products.length > 0 && (
                    <button
                        type="button"
                        onClick={handleSyncProducts}
                        className="button small tone-info"
                    >
                        <Package size={13} /> {translate('portal', 'sce_nav_load_from_products', { count: String(products.length) })}
                    </button>
                )}
            </div>

            {/* Live Storefront Bar Preview */}
            <div className="ed-card">
                <div className="ed-head is-sm">
                    <div className="ed-row">
                        <span className="ed-dot tone-ok"></span>
                        <strong className="ed-kicker">
                            {translate('portal', 'sce_nav_preview_heading')}
                        </strong>
                    </div>
                    <span className="ed-meta">
                        {translate('portal', 'sce_nav_preview_hint')}
                    </span>
                </div>

                <div className="ed-nav-preview">
                    {activeCategories.length === 0 ? (
                        <span className="ed-placeholder">
                            {translate('portal', 'cms_no_categories_selected')}
                        </span>
                    ) : (
                        activeCategories.map((cat, i) => (
                            <span
                                key={i}
                                className="ed-nav-preview-item"
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
            mobile_search_categories_enabled: customization.mobile_search_categories_enabled !== undefined
                ? customization.mobile_search_categories_enabled
                : (defaults.mobile_search_categories_enabled || '1'),
            mobile_search_categories_heading: customization.mobile_search_categories_heading || '',
            mobile_search_categories_items: customization.mobile_search_categories_items || '',
            mobile_search_categories_limit: customization.mobile_search_categories_limit || defaults.mobile_search_categories_limit || '6',

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

            // Brand Trust & Engagement Controls
            trust_pillars_enabled: customization.trust_pillars_enabled !== undefined ? customization.trust_pillars_enabled : '1',
            trust_pillars_title: customization.trust_pillars_title || '',
            video_gallery_enabled: customization.video_gallery_enabled !== undefined ? customization.video_gallery_enabled : '1',
            video_gallery_title: customization.video_gallery_title || '',
            video_gallery_subtitle: customization.video_gallery_subtitle || '',
            testimonials_section_enabled: customization.testimonials_section_enabled !== undefined ? customization.testimonials_section_enabled : '1',
            testimonials_title: customization.testimonials_title || '',
            testimonials_subtitle: customization.testimonials_subtitle || '',

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
            about_network_title: customization.about_network_title || '',
            about_network: customization.about_network || '',
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
            legal_meta_effective: customization.legal_meta_effective || '',
            legal_meta_governance: customization.legal_meta_governance || '',
            legal_meta_location: customization.legal_meta_location || '',

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
            map_latitude: customization.map_latitude || '',
            map_longitude: customization.map_longitude || '',
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
            <div className="panel">
                <div className="ed-head is-flush">
                    <div>
                        <h2 className="ed-row is-loose">
                            <SlidersHorizontal size={22} className="icon-brand" /> {translate('portal', 'cms_heading')}
                        </h2>
                        <p className="ed-desc">
                            {translate('portal', 'cms_heading_hint')}
                        </p>
                    </div>
                    <div className="ed-row is-loose">
                        <a href={typeof window !== 'undefined' ? `${window.location.protocol}//${window.location.hostname.replace(/^(manage|sadmin|dealer|subdealer)\./, '')}${window.location.port ? ':' + window.location.port : ''}/` : '/'} target="_blank" rel="noopener noreferrer" className="button small">
                            <ExternalLink size={14} /> {translate('portal', 'cms_preview_live')}
                        </a>
                        <button type="button" onClick={handleResetAll} className="button small danger">
                            <RotateCcw size={14} /> {translate('portal', 'cms_reset_all')}
                        </button>
                    </div>
                </div>

                {/* Relocation guidance banner */}
                <div className="ed-callout tone-ok ed-head is-flush ed-mt">
                    <div className="ed-row is-loose">
                        <Package size={20} className="icon-ok" />
                        <span className="ed-body tone-ok">
                            {translate('portal', 'cms_product_banner')}
                        </span>
                    </div>
                    <a href="/catalog?tab=offers" className="button small dark">
                        {translate('portal', 'cms_product_mgmt')}
                    </a>
                </div>

                <div className="ed-card is-soft ed-mt">
                    <div className="ed-head">
                        <div>
                            <h3 className="ed-title is-flush">{translate('portal', 'cms_visibility_title')}</h3>
                            <p className="ed-desc is-sm">
                                {translate('portal', 'cms_visibility_hint')}
                            </p>
                        </div>
                    </div>
                    <div className="ed-grid is-sm">
                        {(websitePages.length > 0 ? websitePages : [
                            { key: 'home', slug: '/', label: translate('portal', 'website_page_home'), toggleable: false, enabled: true, flag: null },
                            ...pageFlags.map((flag) => ({
                                key: flag.replace('page_enabled_', ''),
                                slug: '/' + flag.replace('page_enabled_', ''),
                                label: translate('portal', `website_page_${flag.replace('page_enabled_', '')}`),
                                toggleable: true,
                                enabled: form.data.customization[flag] !== '0',
                                flag,
                            })),
                        ]).map((page) => {
                            const flag = page.flag as typeof pageFlags[number] | null | undefined;
                            const on = page.toggleable === false ? true : (flag ? form.data.customization[flag] !== '0' : page.enabled !== false);
                            return (
                                <div key={page.key} className={`ed-toggle-row${on ? ' is-on' : ''}`}>
                                    <div className="ed-shrink">
                                        <div className="ed-strong is-sm hub-capital">{page.label}</div>
                                        <div className="ed-hint">{page.slug}</div>
                                    </div>
                                    {page.toggleable === false || !flag ? (
                                        <span className="ed-label is-inline tone-ok">{translate('portal', 'cms_always_on')}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => updateField(flag, on ? '0' : '1')}
                                            className={`button small ed-toggle-btn ${on ? 'dark' : ''}`}
                                        >
                                            {on ? translate('portal', 'cms_on') : translate('portal', 'cms_off')}
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <div className="ed-row is-wrap ed-mt">
                        {websitePages.filter((page) => page.toggleable === false || (page.flag ? form.data.customization[page.flag as typeof pageFlags[number]] !== '0' : page.enabled)).map((page) => (
                            <a
                                key={`preview-${page.slug}`}
                                href={page.slug}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="button small"
                            >
                                {translate('portal', 'cms_preview_page', { label: page.label })}
                            </a>
                        ))}
                    </div>
                </div>

                <div className="ed-tabs">
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
                            >
                                <Icon size={15} />
                                <span>{translate('portal', t.labelKey)}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <form onSubmit={handleSubmit}>
                <div className="panel">
                    {/* TAB 1: HEADER & TOP BAR */}
                    {tab === 'header' && (
                        <div>
                            <h3 className="ed-title">{translate('portal', 'cms_header_title')}</h3>
                            <div className="form-grid ed-grid">
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

                                <MobileSearchCategoriesManager
                                    enabled={form.data.customization.mobile_search_categories_enabled || '1'}
                                    heading={form.data.customization.mobile_search_categories_heading || ''}
                                    items={form.data.customization.mobile_search_categories_items || ''}
                                    limit={form.data.customization.mobile_search_categories_limit || '6'}
                                    featuredCategoriesRaw={form.data.customization.featured_categories_items || defaults.featured_categories_items || ''}
                                    products={products}
                                    onChange={updateField}
                                />

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
                            <h3 className="ed-title">{translate('portal', 'cms_banners_title')}</h3>
                            <div className="ed-stack is-loose ed-mb-lg">
                                {(form.data.customization.slides || []).map((slide, idx) => {
                                    const defSlide = defaults.slides?.[idx] || { image: '', link: '', alt: '' };
                                    return (
                                        <div key={idx} className="ed-card is-soft">
                                            <h4 className="ed-subtitle">{translate('portal', 'sce_slide_number', { number: String(idx + 1) })}</h4>
                                            <div className="ed-grid is-md">
                                                <label>
                                                    <span className="ed-label">{translate('portal', 'sce_image_url')}</span>
                                                    <input
                                                        type="text"
                                                        value={slide.image}
                                                        placeholder={defSlide.image}
                                                        onChange={e => updateSlide(idx, 'image', e.target.value)}
                                                        className="ed-input"
                                                    />
                                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSlide.image ?? '') })}</small>
                                                </label>
                                                <label>
                                                    <span className="ed-label">{translate('portal', 'sce_target_link')}</span>
                                                    <input
                                                        type="text"
                                                        value={slide.link}
                                                        placeholder={defSlide.link}
                                                        onChange={e => updateSlide(idx, 'link', e.target.value)}
                                                        className="ed-input"
                                                    />
                                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSlide.link ?? '') })}</small>
                                                </label>
                                                <label>
                                                    <span className="ed-label">{translate('portal', 'sce_alt_caption')}</span>
                                                    <input
                                                        type="text"
                                                        value={slide.alt}
                                                        placeholder={defSlide.alt}
                                                        onChange={e => updateSlide(idx, 'alt', e.target.value)}
                                                        className="ed-input"
                                                    />
                                                    <small className="ed-hint">{translate('portal', 'sce_default_placeholder', { value: String(defSlide.alt ?? '') })}</small>
                                                </label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_side_banners_heading')}</h3>
                            <div className="ed-grid is-lg">
                                {/* Side Banner 1 (Top) */}
                                <div className="ed-card is-soft">
                                    <h4 className="ed-subtitle">{translate('portal', 'sce_side_top_card_heading')}</h4>
                                    <p className="ed-desc is-sm is-flush ed-mb">{translate('portal', 'sce_side_top_card_hint')}</p>
                                    <div className="ed-stack">
                                        {((form.data.customization.side_banner_1_slides || defaults.side_banner_1_slides) || []).map((slide, idx) => {
                                            const defSlide = defaults.side_banner_1_slides?.[idx] || { image: '', link: '', alt: '' };
                                            return (
                                                <div key={idx} className="ed-card is-compact">
                                                    <span className="ed-label">{translate('portal', 'sce_side_top_slide_number', { number: String(idx + 1) })}</span>
                                                    <div className="ed-grid is-sm">
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_image_url')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.image}
                                                                placeholder={defSlide.image}
                                                                onChange={e => updateSideSlide(1, idx, 'image', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.image}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_target_link')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.link}
                                                                placeholder={defSlide.link}
                                                                onChange={e => updateSideSlide(1, idx, 'link', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.link}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_alt_text')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.alt}
                                                                placeholder={defSlide.alt}
                                                                onChange={e => updateSideSlide(1, idx, 'alt', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.alt}</small>
                                                        </label>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Side Banner 2 (Bottom) */}
                                <div className="ed-card is-soft">
                                    <h4 className="ed-subtitle">{translate('portal', 'sce_side_bottom_card_heading')}</h4>
                                    <p className="ed-desc is-sm is-flush ed-mb">{translate('portal', 'sce_side_bottom_card_hint')}</p>
                                    <div className="ed-stack">
                                        {((form.data.customization.side_banner_2_slides || defaults.side_banner_2_slides) || []).map((slide, idx) => {
                                            const defSlide = defaults.side_banner_2_slides?.[idx] || { image: '', link: '', alt: '' };
                                            return (
                                                <div key={idx} className="ed-card is-compact">
                                                    <span className="ed-label">{translate('portal', 'sce_side_bottom_slide_number', { number: String(idx + 1) })}</span>
                                                    <div className="ed-grid is-sm">
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_image_url')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.image}
                                                                placeholder={defSlide.image}
                                                                onChange={e => updateSideSlide(2, idx, 'image', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.image}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_target_link')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.link}
                                                                placeholder={defSlide.link}
                                                                onChange={e => updateSideSlide(2, idx, 'link', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.link}</small>
                                                        </label>
                                                        <label>
                                                            <span className="ed-label">{translate('portal', 'sce_alt_text')}</span>
                                                            <input
                                                                type="text"
                                                                value={slide.alt}
                                                                placeholder={defSlide.alt}
                                                                onChange={e => updateSideSlide(2, idx, 'alt', e.target.value)}
                                                                className="ed-input is-sm"
                                                            />
                                                            <small className="ed-hint">{defSlide.alt}</small>
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
                            <div className="ed-divided">
                                <div className="ed-head">
                                    <div>
                                        <h3 className="ed-title is-flush">{translate('portal', 'sce_featured_categories_grid_heading')}</h3>
                                        <p className="ed-desc">
                                            {translate('portal', 'sce_featured_categories_grid_hint')}
                                        </p>
                                    </div>
                                </div>

                                {/* Section Visibility Master Control */}
                                <div className={`ed-switch-banner${form.data.customization.featured_categories_enabled !== '0' ? ' is-on tone-ok' : ''}`}>
                                    <div className="ed-row is-loose is-top">
                                        <div className="ed-switch-icon">
                                            {form.data.customization.featured_categories_enabled !== '0' ? '✓' : '✕'}
                                        </div>
                                        <div>
                                            <div className="ed-row">
                                                <strong className="ed-strong is-lg">
                                                    {translate('portal', 'sce_featured_categories_section_status', {
                                                        state: form.data.customization.featured_categories_enabled !== '0'
                                                            ? translate('portal', 'sce_section_active_visible')
                                                            : translate('portal', 'sce_section_hidden'),
                                                    })}
                                                </strong>
                                                <span className="ed-state-pill">
                                                    {form.data.customization.featured_categories_enabled !== '0'
                                                        ? translate('portal', 'sce_enabled')
                                                        : translate('portal', 'sce_disabled')}
                                                </span>
                                            </div>
                                            <p className="ed-desc">
                                                {form.data.customization.featured_categories_enabled !== '0'
                                                    ? translate('portal', 'sce_featured_categories_enabled_hint')
                                                    : translate('portal', 'sce_featured_categories_disabled_hint')}
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <button
                                            type="button"
                                            onClick={() => updateField('featured_categories_enabled', form.data.customization.featured_categories_enabled !== '0' ? '0' : '1')}
                                            className={`button ${form.data.customization.featured_categories_enabled !== '0' ? 'danger' : 'dark'}`}
                                        >
                                            {form.data.customization.featured_categories_enabled !== '0'
                                                ? translate('portal', 'sce_disable_hide_section')
                                                : translate('portal', 'sce_enable_show_section')}
                                        </button>
                                    </div>
                                </div>

                                <div className="form-grid ed-grid ed-mb">
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

                            <h3 className="ed-title">{translate('portal', 'sce_dealer_banner_ticker_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
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

                            <h3 className="ed-title">{translate('portal', 'sce_catalog_page_labels_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
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

                            <h3 className="ed-title">{translate('portal', 'sce_product_card_details_heading')}</h3>
                            <div className="form-grid ed-grid">
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
                            <h3 className="ed-title">{translate('portal', 'sce_company_intro_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Company Introduction Title" fieldKey="company_intro_title" value={form.data.customization.company_intro_title} placeholder={defaults.company_intro_title} onChange={updateField} />
                                <Field label="Company Introduction Text" fieldKey="company_intro_body" value={form.data.customization.company_intro_body} placeholder={defaults.company_intro_body} onChange={updateField} multiline rows={3} span2 />
                            </div>

                            {/* Brand Trust, Video Showcase & Testimonials Toggle Controls */}
                            <div className="ed-card is-soft is-lg ed-mb-lg">
                                <h3 className="ed-title is-tight">
                                    {translate('portal', 'sce_brand_trust_sections_heading')}
                                </h3>
                                <p className="ed-desc ed-mb">
                                    {translate('portal', 'sce_brand_trust_sections_hint')}
                                </p>

                                {/* 1. Core Brand Commitments & Trust Pillars */}
                                <div className="ed-card ed-mb">
                                    <div className="ed-head">
                                        <div>
                                            <strong className="ed-strong">{translate('portal', 'sce_trust_pillars_heading')}</strong>
                                            <p className="ed-desc is-sm">{translate('portal', 'sce_trust_pillars_hint')}</p>
                                        </div>
                                        <button
                                            type="button"
                                            className={`button small ${form.data.customization.trust_pillars_enabled !== '0' ? 'dark' : ''}`}
                                            onClick={() => updateField('trust_pillars_enabled', form.data.customization.trust_pillars_enabled !== '0' ? '0' : '1')}
                                        >
                                            {form.data.customization.trust_pillars_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                                        </button>
                                    </div>
                                    <Field label="Trust Pillars Section Title" fieldKey="trust_pillars_title" value={form.data.customization.trust_pillars_title} placeholder={defaults.trust_pillars_title} onChange={updateField} />
                                </div>

                                {/* 2. Video Gallery & Demonstrations */}
                                <div className="ed-card ed-mb">
                                    <div className="ed-head">
                                        <div>
                                            <strong className="ed-strong">{translate('portal', 'sce_video_showcase_heading')}</strong>
                                            <p className="ed-desc is-sm">{translate('portal', 'sce_video_showcase_hint')}</p>
                                        </div>
                                        <button
                                            type="button"
                                            className={`button small ${form.data.customization.video_gallery_enabled !== '0' ? 'dark' : ''}`}
                                            onClick={() => updateField('video_gallery_enabled', form.data.customization.video_gallery_enabled !== '0' ? '0' : '1')}
                                        >
                                            {form.data.customization.video_gallery_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                                        </button>
                                    </div>
                                    <div className="form-grid ed-grid is-md">
                                        <Field label="Video Showcase Title" fieldKey="video_gallery_title" value={form.data.customization.video_gallery_title} placeholder={defaults.video_gallery_title} onChange={updateField} />
                                        <Field label="Video Showcase Subtitle" fieldKey="video_gallery_subtitle" value={form.data.customization.video_gallery_subtitle} placeholder={defaults.video_gallery_subtitle} onChange={updateField} />
                                    </div>
                                </div>

                                {/* 3. Customer & Partner Testimonials */}
                                <div className="ed-card">
                                    <div className="ed-head">
                                        <div>
                                            <strong className="ed-strong">{translate('portal', 'sce_testimonials_heading')}</strong>
                                            <p className="ed-desc is-sm">{translate('portal', 'sce_testimonials_hint')}</p>
                                        </div>
                                        <button
                                            type="button"
                                            className={`button small ${form.data.customization.testimonials_section_enabled !== '0' ? 'dark' : ''}`}
                                            onClick={() => updateField('testimonials_section_enabled', form.data.customization.testimonials_section_enabled !== '0' ? '0' : '1')}
                                        >
                                            {form.data.customization.testimonials_section_enabled !== '0' ? `✓ ${translate('portal', 'sce_status_on')}` : `✕ ${translate('portal', 'sce_status_off')}`}
                                        </button>
                                    </div>
                                    <div className="form-grid ed-grid is-md">
                                        <Field label="Testimonials Section Title" fieldKey="testimonials_title" value={form.data.customization.testimonials_title} placeholder={defaults.testimonials_title} onChange={updateField} />
                                        <Field label="Testimonials Section Subtitle" fieldKey="testimonials_subtitle" value={form.data.customization.testimonials_subtitle} placeholder={defaults.testimonials_subtitle} onChange={updateField} />
                                    </div>
                                </div>
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_about_page_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="About Page Hero Title" fieldKey="about_hero_title" value={form.data.customization.about_hero_title} placeholder={defaults.about_hero_title} onChange={updateField} />
                                <Field label="About Page Hero Lead" fieldKey="about_hero_lead" value={form.data.customization.about_hero_lead} placeholder={defaults.about_hero_lead} onChange={updateField} multiline rows={3} span2 />
                                <Field label="Company History Title" fieldKey="about_history_title" value={form.data.customization.about_history_title} placeholder={defaults.about_history_title} onChange={updateField} />
                                <Field label="Company History Text" fieldKey="about_history" value={form.data.customization.about_history} placeholder={defaults.about_history} onChange={updateField} multiline rows={4} span2 />
                                <Field label="Mission Title" fieldKey="about_mission_title" value={form.data.customization.about_mission_title} placeholder={defaults.about_mission_title} onChange={updateField} />
                                <Field label="Mission Statement" fieldKey="about_mission" value={form.data.customization.about_mission} placeholder={defaults.about_mission} onChange={updateField} multiline rows={3} />
                                <Field label="Vision Title" fieldKey="about_vision_title" value={form.data.customization.about_vision_title} placeholder={defaults.about_vision_title} onChange={updateField} />
                                <Field label="Vision Statement" fieldKey="about_vision" value={form.data.customization.about_vision} placeholder={defaults.about_vision} onChange={updateField} multiline rows={3} />

                                <Field label="Core Objectives Title" fieldKey="about_objectives_title" value={form.data.customization.about_objectives_title} placeholder={defaults.about_objectives_title} onChange={updateField} span2 />
                                <Field label="Objective 1 Title" fieldKey="about_obj1_title" value={form.data.customization.about_obj1_title} placeholder={defaults.about_obj1_title} onChange={updateField} />
                                <Field label="Objective 1 Description" fieldKey="about_obj1_desc" value={form.data.customization.about_obj1_desc} placeholder={defaults.about_obj1_desc} onChange={updateField} multiline rows={2} />
                                <Field label="Objective 2 Title" fieldKey="about_obj2_title" value={form.data.customization.about_obj2_title} placeholder={defaults.about_obj2_title} onChange={updateField} />
                                <Field label="Objective 2 Description" fieldKey="about_obj2_desc" value={form.data.customization.about_obj2_desc} placeholder={defaults.about_obj2_desc} onChange={updateField} multiline rows={2} />
                                <Field label="Objective 3 Title" fieldKey="about_obj3_title" value={form.data.customization.about_obj3_title} placeholder={defaults.about_obj3_title} onChange={updateField} />
                                <Field label="Objective 3 Description" fieldKey="about_obj3_desc" value={form.data.customization.about_obj3_desc} placeholder={defaults.about_obj3_desc} onChange={updateField} multiline rows={2} />
                                <Field label="Objective 4 Title" fieldKey="about_obj4_title" value={form.data.customization.about_obj4_title} placeholder={defaults.about_obj4_title} onChange={updateField} />
                                <Field label="Objective 4 Description" fieldKey="about_obj4_desc" value={form.data.customization.about_obj4_desc} placeholder={defaults.about_obj4_desc} onChange={updateField} multiline rows={2} />
                                <Field label="Network Section Title" fieldKey="about_network_title" value={form.data.customization.about_network_title} placeholder={defaults.about_network_title} onChange={updateField} />
                                <Field label="Network Section Text" fieldKey="about_network" value={form.data.customization.about_network} placeholder={defaults.about_network} onChange={updateField} multiline rows={4} span2 />

                                <Field label="Button 1 (Explore Products)" fieldKey="about_btn_products" value={form.data.customization.about_btn_products} placeholder={defaults.about_btn_products} onChange={updateField} />
                                <Field label="Button 2 (Find a Dealer)" fieldKey="about_btn_dealers" value={form.data.customization.about_btn_dealers} placeholder={defaults.about_btn_dealers} onChange={updateField} />
                                <Field label="Button 3 (Contact Head Office)" fieldKey="about_btn_contact" value={form.data.customization.about_btn_contact} placeholder={defaults.about_btn_contact} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_contact_page_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
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
                                <Field label="Head Office Physical Address" fieldKey="office_address" value={form.data.customization.office_address} placeholder={defaults.office_address} onChange={updateField} span2 />
                                <Field label="Map Latitude (optional)" fieldKey="map_latitude" value={form.data.customization.map_latitude} placeholder={defaults.map_latitude || 'e.g. 25.7701'} onChange={updateField} />
                                <Field label="Map Longitude (optional)" fieldKey="map_longitude" value={form.data.customization.map_longitude} placeholder={defaults.map_longitude || 'e.g. 88.8240'} onChange={updateField} />
                                <Field label="Contact Form Header" fieldKey="contact_form_title" value={form.data.customization.contact_form_title} placeholder={defaults.contact_form_title} onChange={updateField} />
                                
                                <Field label="Map Location Title" fieldKey="map_title" value={form.data.customization.map_title} placeholder={defaults.map_title} onChange={updateField} />
                                <Field label="Map Subtitle" fieldKey="map_subtitle" value={form.data.customization.map_subtitle} placeholder={defaults.map_subtitle} onChange={updateField} />
                                <Field label="Map Facility Name" fieldKey="map_facility" value={form.data.customization.map_facility} placeholder={defaults.map_facility} onChange={updateField} />
                                <Field label="Map Directions Button Text" fieldKey="map_directions_text" value={form.data.customization.map_directions_text} placeholder={defaults.map_directions_text} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_dealer_directory_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Directory Main Title" fieldKey="directory_title" value={form.data.customization.directory_title} placeholder={defaults.directory_title} onChange={updateField} />
                                <Field label="Directory Subtitle" fieldKey="directory_subtitle" value={form.data.customization.directory_subtitle} placeholder={defaults.directory_subtitle} onChange={updateField} />
                                <Field label="GPS Current Location Button Text" fieldKey="directory_gps_btn" value={form.data.customization.directory_gps_btn} placeholder={defaults.directory_gps_btn} onChange={updateField} span2 />
                                <Field label="Directory Empty Results Title" fieldKey="directory_empty_title" value={form.data.customization.directory_empty_title} placeholder={defaults.directory_empty_title} onChange={updateField} />
                                <Field label="Directory Empty Results Description" fieldKey="directory_empty_desc" value={form.data.customization.directory_empty_desc} placeholder={defaults.directory_empty_desc} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_partnership_page_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Hero Title" fieldKey="partnership_hero_title" value={form.data.customization.partnership_hero_title} placeholder={defaults.partnership_hero_title} onChange={updateField} />
                                <Field label="Hero Lead" fieldKey="partnership_hero_lead" value={form.data.customization.partnership_hero_lead} placeholder={defaults.partnership_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Why Partner Heading" fieldKey="partnership_why_title" value={form.data.customization.partnership_why_title} placeholder={defaults.partnership_why_title} onChange={updateField} span2 />
                                {[1, 2, 3, 4].map((i) => (
                                    <React.Fragment key={`pb-${i}`}>
                                        <Field label={translate('portal', 'sce_benefit_title', { number: String(i) })} fieldKey={`partnership_benefit${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_benefit${i}_title`]} placeholder={(defaults as any)[`partnership_benefit${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_benefit_description', { number: String(i) })} fieldKey={`partnership_benefit${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_benefit${i}_desc`]} placeholder={(defaults as any)[`partnership_benefit${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="Steps Heading" fieldKey="partnership_steps_title" value={form.data.customization.partnership_steps_title} placeholder={defaults.partnership_steps_title} onChange={updateField} span2 />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`ps-${i}`}>
                                        <Field label={translate('portal', 'sce_step_title', { number: String(i) })} fieldKey={`partnership_step${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_step${i}_title`]} placeholder={(defaults as any)[`partnership_step${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_step_description', { number: String(i) })} fieldKey={`partnership_step${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`partnership_step${i}_desc`]} placeholder={(defaults as any)[`partnership_step${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="CTA Title" fieldKey="partnership_cta_title" value={form.data.customization.partnership_cta_title} placeholder={defaults.partnership_cta_title} onChange={updateField} />
                                <Field label="CTA Button Text" fieldKey="partnership_cta_btn" value={form.data.customization.partnership_cta_btn} placeholder={defaults.partnership_cta_btn} onChange={updateField} />
                                <Field label="CTA Body" fieldKey="partnership_cta_body" value={form.data.customization.partnership_cta_body} placeholder={defaults.partnership_cta_body} onChange={updateField} multiline rows={2} span2 />
                                <Field label="CTA Link" fieldKey="partnership_cta_link" value={form.data.customization.partnership_cta_link} placeholder={defaults.partnership_cta_link} onChange={updateField} span2 />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_services_page_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Hero Title" fieldKey="services_hero_title" value={form.data.customization.services_hero_title} placeholder={defaults.services_hero_title} onChange={updateField} />
                                <Field label="Hero Lead" fieldKey="services_hero_lead" value={form.data.customization.services_hero_lead} placeholder={defaults.services_hero_lead} onChange={updateField} multiline rows={2} />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`svc-${i}`}>
                                        <Field label={translate('portal', 'sce_service_title', { number: String(i) })} fieldKey={`services_card${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`services_card${i}_title`]} placeholder={(defaults as any)[`services_card${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_service_description', { number: String(i) })} fieldKey={`services_card${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`services_card${i}_desc`]} placeholder={(defaults as any)[`services_card${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="CTA Button" fieldKey="services_cta_btn" value={form.data.customization.services_cta_btn} placeholder={defaults.services_cta_btn} onChange={updateField} />
                                <Field label="CTA Link" fieldKey="services_cta_link" value={form.data.customization.services_cta_link} placeholder={defaults.services_cta_link} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_quality_page_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Hero Title" fieldKey="quality_hero_title" value={form.data.customization.quality_hero_title} placeholder={defaults.quality_hero_title} onChange={updateField} />
                                <Field label="Hero Lead" fieldKey="quality_hero_lead" value={form.data.customization.quality_hero_lead} placeholder={defaults.quality_hero_lead} onChange={updateField} multiline rows={2} />
                                {[1, 2, 3].map((i) => (
                                    <React.Fragment key={`q-${i}`}>
                                        <Field label={translate('portal', 'sce_pillar_title', { number: String(i) })} fieldKey={`quality_pillar${i}_title` as keyof CustomizationData} value={(form.data.customization as any)[`quality_pillar${i}_title`]} placeholder={(defaults as any)[`quality_pillar${i}_title`]} onChange={updateField} />
                                        <Field label={translate('portal', 'sce_pillar_description', { number: String(i) })} fieldKey={`quality_pillar${i}_desc` as keyof CustomizationData} value={(form.data.customization as any)[`quality_pillar${i}_desc`]} placeholder={(defaults as any)[`quality_pillar${i}_desc`]} onChange={updateField} />
                                    </React.Fragment>
                                ))}
                                <Field label="CTA Button" fieldKey="quality_cta_btn" value={form.data.customization.quality_cta_btn} placeholder={defaults.quality_cta_btn} onChange={updateField} />
                                <Field label="CTA Link" fieldKey="quality_cta_link" value={form.data.customization.quality_cta_link} placeholder={defaults.quality_cta_link} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_faq_page_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb">
                                <Field label="FAQ Hero Title" fieldKey="faq_hero_title" value={form.data.customization.faq_hero_title} placeholder={defaults.faq_hero_title} onChange={updateField} />
                                <Field label="FAQ Hero Lead" fieldKey="faq_hero_lead" value={form.data.customization.faq_hero_lead} placeholder={defaults.faq_hero_lead} onChange={updateField} multiline rows={2} />
                            </div>
                            <div className="ed-stack ed-mb-lg">
                                {(form.data.customization.faq_items || []).map((item, idx) => (
                                    <div key={`faq-${idx}`} className="ed-card is-soft">
                                        <div className="ed-head is-sm">
                                            <strong className="ed-strong">{translate('portal', 'sce_question_number', { number: String(idx + 1) })}</strong>
                                            <button
                                                type="button"
                                                className="button small danger"
                                                onClick={() => {
                                                    const next = [...(form.data.customization.faq_items || [])];
                                                    next.splice(idx, 1);
                                                    form.setData('customization', { ...form.data.customization, faq_items: next });
                                                }}
                                            >
                                                <Trash2 size={14} /> {translate('portal', 'sce_remove')}
                                            </button>
                                        </div>
                                        <div className="form-grid ed-grid is-single">
                                            <label className="ed-label is-inline">
                                                {translate('portal', 'sce_question')}
                                                <input
                                                    value={item.question || ''}
                                                    placeholder={defaults.faq_items?.[idx]?.question || translate('portal', 'sce_question')}
                                                    onChange={(e) => {
                                                        const next = [...(form.data.customization.faq_items || [])];
                                                        next[idx] = { ...next[idx], question: e.target.value };
                                                        form.setData('customization', { ...form.data.customization, faq_items: next });
                                                    }}
                                                />
                                            </label>
                                            <label className="ed-label is-inline">
                                                {translate('portal', 'sce_answer')}
                                                <textarea
                                                    rows={3}
                                                    value={item.answer || ''}
                                                    placeholder={defaults.faq_items?.[idx]?.answer || translate('portal', 'sce_answer')}
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
                                    <Plus size={14} /> {translate('portal', 'sce_add_faq_item')}
                                </button>
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_privacy_terms_heading')}</h3>
                            <div className="form-grid ed-grid">
                                <Field label="Privacy Title" fieldKey="privacy_hero_title" value={form.data.customization.privacy_hero_title} placeholder={defaults.privacy_hero_title} onChange={updateField} />
                                <Field label="Privacy Lead" fieldKey="privacy_hero_lead" value={form.data.customization.privacy_hero_lead} placeholder={defaults.privacy_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Privacy Body" fieldKey="privacy_body" value={form.data.customization.privacy_body} placeholder={defaults.privacy_body} onChange={updateField} multiline rows={6} span2 />
                                <Field label="Terms Title" fieldKey="terms_hero_title" value={form.data.customization.terms_hero_title} placeholder={defaults.terms_hero_title} onChange={updateField} />
                                <Field label="Terms Lead" fieldKey="terms_hero_lead" value={form.data.customization.terms_hero_lead} placeholder={defaults.terms_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label="Terms Body" fieldKey="terms_body" value={form.data.customization.terms_body} placeholder={defaults.terms_body} onChange={updateField} multiline rows={6} span2 />
                                <Field label="Legal Meta · Effective" fieldKey="legal_meta_effective" value={form.data.customization.legal_meta_effective} placeholder={defaults.legal_meta_effective} onChange={updateField} />
                                <Field label="Legal Meta · Governance" fieldKey="legal_meta_governance" value={form.data.customization.legal_meta_governance} placeholder={defaults.legal_meta_governance} onChange={updateField} />
                                <Field label="Legal Meta · Location" fieldKey="legal_meta_location" value={form.data.customization.legal_meta_location} placeholder={defaults.legal_meta_location} onChange={updateField} />
                            </div>
                        </div>
                    )}

                    {/* TAB 7: FOOTER, DIALOGS & AUTH */}
                    {tab === 'footer' && (
                        <div>
                            <h3 className="ed-title">{translate('portal', 'sce_footer_column_1_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
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

                            <h3 className="ed-title">{translate('portal', 'sce_footer_column_2_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Column 2 Heading" fieldKey="footer_about_heading" value={form.data.customization.footer_about_heading} placeholder={defaults.footer_about_heading} onChange={updateField} span2 />

                                <Field label="Footer Link 1 Text" fieldKey="footer_link_about" value={form.data.customization.footer_link_about} placeholder={defaults.footer_link_about} onChange={updateField} />
                                <Field label="Footer Link 1 Target URL" fieldKey="footer_link_about_url" value={form.data.customization.footer_link_about_url} placeholder={defaults.footer_link_about_url} onChange={updateField} />




                                <Field label="Footer Link 5 Text (Contact)" fieldKey="footer_link_contact" value={form.data.customization.footer_link_contact} placeholder={defaults.footer_link_contact} onChange={updateField} />
                                <Field label="Footer Link 5 Target URL" fieldKey="footer_link_contact_url" value={form.data.customization.footer_link_contact_url} placeholder={defaults.footer_link_contact_url} onChange={updateField} />

                                <Field label="Footer Link 6 Text (Dealership)" fieldKey="footer_link_dealer_apply" value={form.data.customization.footer_link_dealer_apply} placeholder={defaults.footer_link_dealer_apply} onChange={updateField} />
                                <Field label="Footer Link 6 Target URL" fieldKey="footer_link_dealer_apply_url" value={form.data.customization.footer_link_dealer_apply_url} placeholder={defaults.footer_link_dealer_apply_url} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_footer_column_3_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Column 3 Heading" fieldKey="footer_stay_connected_heading" value={form.data.customization.footer_stay_connected_heading} placeholder={defaults.footer_stay_connected_heading} onChange={updateField} />
                                <Field label="Footer Logo Image URL" fieldKey="footer_logo_url" value={form.data.customization.footer_logo_url} placeholder={defaults.footer_logo_url} onChange={updateField} />
                                <Field label="Footer Brand Title" fieldKey="footer_about_title" value={form.data.customization.footer_about_title} placeholder={defaults.footer_about_title} onChange={updateField} />
                                <Field label="Footer Brand Description" fieldKey="footer_about_desc" value={form.data.customization.footer_about_desc} placeholder={defaults.footer_about_desc} onChange={updateField} />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_social_media_heading')}</h3>
                            <div className="form-grid ed-grid ed-mb-lg">
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

                            <div className="form-grid ed-grid ed-mb-lg">
                                <Field label="Google Play App Link (optional)" fieldKey="app_google_play_link" value={form.data.customization.app_google_play_link} placeholder={defaults.app_google_play_link} onChange={updateField} />
                                <Field label="Apple App Store Link (optional)" fieldKey="app_apple_store_link" value={form.data.customization.app_apple_store_link} placeholder={defaults.app_apple_store_link} onChange={updateField} />
                                <Field label="Copyright Notice" fieldKey="copyright_text" value={form.data.customization.copyright_text} placeholder={defaults.copyright_text} onChange={updateField} span2 />
                            </div>

                            <h3 className="ed-title">{translate('portal', 'sce_support_partner_access_heading')}</h3>
                            <div className="form-grid ed-grid">
                                <Field label="Floating Chat Button Label" fieldKey="floating_chat_label" value={form.data.customization.floating_chat_label} placeholder={defaults.floating_chat_label} onChange={updateField} />



                                <Field label="Partner Portal Login Heading" fieldKey="auth_login_title" value={form.data.customization.auth_login_title} placeholder={defaults.auth_login_title} onChange={updateField} />
                                <Field label="Partner Portal Login Subtitle" fieldKey="auth_login_subtitle" value={form.data.customization.auth_login_subtitle} placeholder={defaults.auth_login_subtitle} onChange={updateField} />
                                <Field label="Dealer Account Inquiry Text" fieldKey="auth_dealer_help_text" value={form.data.customization.auth_dealer_help_text} placeholder={defaults.auth_dealer_help_text} onChange={updateField} />
                                <Field label="Dealer Account Inquiry Link Text" fieldKey="auth_dealer_contact_text" value={form.data.customization.auth_dealer_contact_text} placeholder={defaults.auth_dealer_contact_text} onChange={updateField} />
                            </div>
                        </div>
                    )}

                    <div className="ed-actions">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="button dark ed-lg"
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

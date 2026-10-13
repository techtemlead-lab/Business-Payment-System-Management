import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { SlidersHorizontal, Layout, Image, Sparkles, MessageSquare, BookOpen, Share2, RotateCcw, Save, ExternalLink } from 'lucide-react';

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

interface CustomizationData {
    top_bar_text?: string;
    search_placeholder?: string;
    header_offers_title?: string;
    header_offers_subtitle?: string;
    header_projects_title?: string;
    header_projects_subtitle?: string;
    header_account_title?: string;
    header_account_subtitle?: string;
    header_shop_title?: string;
    nav_categories?: string;

    announcement_text?: string;

    slides?: Slide[];
    side_banner_1_image?: string;
    side_banner_1_link?: string;
    side_banner_1_alt?: string;
    side_banner_2_image?: string;
    side_banner_2_link?: string;
    side_banner_2_alt?: string;

    shortcuts?: Shortcut[];

    featured_categories_title?: string;
    featured_categories_subtitle?: string;
    featured_products_title?: string;
    featured_products_subtitle?: string;

    about_headline?: string;
    about_lead?: string;
    about_summary_title?: string;
    about_summary_body?: string;

    footer_support_subtitle?: string;
    footer_support_title?: string;
    footer_support_link?: string;
    footer_directory_subtitle?: string;
    footer_directory_title?: string;
    footer_directory_link?: string;
    footer_about_title?: string;
    footer_about_desc?: string;

    social_facebook?: string;
    social_youtube?: string;
    social_linkedin?: string;
    social_instagram?: string;
    social_whatsapp?: string;
    whatsapp_link?: string;

    copyright_text?: string;
}

export default function SiteCustomizationEditor({ customization, defaults }: { customization: CustomizationData; defaults: CustomizationData }) {
    const [tab, setTab] = useState<'header' | 'banners' | 'shortcuts' | 'headings' | 'about' | 'footer'>('header');

    const form = useForm<{ customization: CustomizationData }>({
        customization: {
            top_bar_text: customization.top_bar_text || '',
            search_placeholder: customization.search_placeholder || '',
            header_offers_title: customization.header_offers_title || '',
            header_offers_subtitle: customization.header_offers_subtitle || '',
            header_projects_title: customization.header_projects_title || '',
            header_projects_subtitle: customization.header_projects_subtitle || '',
            header_account_title: customization.header_account_title || '',
            header_account_subtitle: customization.header_account_subtitle || '',
            header_shop_title: customization.header_shop_title || '',
            nav_categories: customization.nav_categories || '',

            announcement_text: customization.announcement_text || '',

            slides: (defaults.slides || []).map((defSlide, i) => {
                const s = customization.slides?.[i];
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

            shortcuts: (defaults.shortcuts || []).map((defSc, i) => {
                const sc = customization.shortcuts?.[i];
                return {
                    title: sc?.title || '',
                    subtitle: sc?.subtitle || '',
                    icon: sc?.icon || '',
                    link: sc?.link || ''
                };
            }),

            featured_categories_title: customization.featured_categories_title || '',
            featured_categories_subtitle: customization.featured_categories_subtitle || '',
            featured_products_title: customization.featured_products_title || '',
            featured_products_subtitle: customization.featured_products_subtitle || '',

            about_headline: customization.about_headline || '',
            about_lead: customization.about_lead || '',
            about_summary_title: customization.about_summary_title || '',
            about_summary_body: customization.about_summary_body || '',

            footer_support_subtitle: customization.footer_support_subtitle || '',
            footer_support_title: customization.footer_support_title || '',
            footer_support_link: customization.footer_support_link || '',
            footer_directory_subtitle: customization.footer_directory_subtitle || '',
            footer_directory_title: customization.footer_directory_title || '',
            footer_directory_link: customization.footer_directory_link || '',
            footer_about_title: customization.footer_about_title || '',
            footer_about_desc: customization.footer_about_desc || '',

            social_facebook: customization.social_facebook || '',
            social_youtube: customization.social_youtube || '',
            social_linkedin: customization.social_linkedin || '',
            social_instagram: customization.social_instagram || '',
            social_whatsapp: customization.social_whatsapp || '',
            whatsapp_link: customization.whatsapp_link || '',

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
                        { id: 'headings', label: 'Headings & Ticker', icon: MessageSquare },
                        { id: 'about', label: 'Homepage Story', icon: BookOpen },
                        { id: 'footer', label: 'Footer & Social', icon: Share2 },
                    ].map(t => {
                        const Icon = t.icon;
                        const active = tab === t.id;
                        return (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => setTab(t.id as any)}
                                className={`button small ${active ? 'dark' : ''}`}
                                style={{ display: 'flex', alignItems: 'center', gap: 7, borderRadius: 6 }}
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
                    {tab === 'header' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Top Bar & Header Navigation</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Top Company Bar Text</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.top_bar_text}
                                        placeholder={defaults.top_bar_text}
                                        onChange={e => updateField('top_bar_text', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.top_bar_text}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Search Input Placeholder</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.search_placeholder}
                                        placeholder={defaults.search_placeholder}
                                        onChange={e => updateField('search_placeholder', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.search_placeholder}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Offers Link Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_offers_title}
                                        placeholder={defaults.header_offers_title}
                                        onChange={e => updateField('header_offers_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_offers_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Offers Link Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_offers_subtitle}
                                        placeholder={defaults.header_offers_subtitle}
                                        onChange={e => updateField('header_offers_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_offers_subtitle}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Projects Link Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_projects_title}
                                        placeholder={defaults.header_projects_title}
                                        onChange={e => updateField('header_projects_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_projects_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Projects Link Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_projects_subtitle}
                                        placeholder={defaults.header_projects_subtitle}
                                        onChange={e => updateField('header_projects_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_projects_subtitle}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Partner Account Link Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_account_title}
                                        placeholder={defaults.header_account_title}
                                        onChange={e => updateField('header_account_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_account_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Partner Account Link Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_account_subtitle}
                                        placeholder={defaults.header_account_subtitle}
                                        onChange={e => updateField('header_account_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_account_subtitle}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Shop Button Label</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.header_shop_title}
                                        placeholder={defaults.header_shop_title}
                                        onChange={e => updateField('header_shop_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.header_shop_title}</small>
                                </label>

                                <label style={{ display: 'block', gridColumn: '1 / -1' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Category Navigation Bar (One per line)</span>
                                    <textarea
                                        rows={4}
                                        value={form.data.customization.nav_categories}
                                        placeholder={defaults.nav_categories}
                                        onChange={e => updateField('nav_categories', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6, fontFamily: 'monospace', fontSize: 13 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Enter category names separated by new lines.</small>
                                </label>
                            </div>
                        </div>
                    )}

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

                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Side Promotional Banners</h3>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                    <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Side Banner 1 (Top)</h4>
                                    <label style={{ display: 'block', marginBottom: 10 }}>
                                        <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Image URL</span>
                                        <input
                                            type="text"
                                            value={form.data.customization.side_banner_1_image}
                                            placeholder={defaults.side_banner_1_image}
                                            onChange={e => updateField('side_banner_1_image', e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                        />
                                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaults.side_banner_1_image}</small>
                                    </label>
                                    <label style={{ display: 'block', marginBottom: 10 }}>
                                        <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Target Link</span>
                                        <input
                                            type="text"
                                            value={form.data.customization.side_banner_1_link}
                                            placeholder={defaults.side_banner_1_link}
                                            onChange={e => updateField('side_banner_1_link', e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                        />
                                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaults.side_banner_1_link}</small>
                                    </label>
                                    <label style={{ display: 'block' }}>
                                        <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Alt Text</span>
                                        <input
                                            type="text"
                                            value={form.data.customization.side_banner_1_alt}
                                            placeholder={defaults.side_banner_1_alt}
                                            onChange={e => updateField('side_banner_1_alt', e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                        />
                                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaults.side_banner_1_alt}</small>
                                    </label>
                                </div>

                                <div style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                    <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Side Banner 2 (Bottom)</h4>
                                    <label style={{ display: 'block', marginBottom: 10 }}>
                                        <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Image URL</span>
                                        <input
                                            type="text"
                                            value={form.data.customization.side_banner_2_image}
                                            placeholder={defaults.side_banner_2_image}
                                            onChange={e => updateField('side_banner_2_image', e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                        />
                                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaults.side_banner_2_image}</small>
                                    </label>
                                    <label style={{ display: 'block', marginBottom: 10 }}>
                                        <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Target Link</span>
                                        <input
                                            type="text"
                                            value={form.data.customization.side_banner_2_link}
                                            placeholder={defaults.side_banner_2_link}
                                            onChange={e => updateField('side_banner_2_link', e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                        />
                                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaults.side_banner_2_link}</small>
                                    </label>
                                    <label style={{ display: 'block' }}>
                                        <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Alt Text</span>
                                        <input
                                            type="text"
                                            value={form.data.customization.side_banner_2_alt}
                                            placeholder={defaults.side_banner_2_alt}
                                            onChange={e => updateField('side_banner_2_alt', e.target.value)}
                                            style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                        />
                                        <small style={{ color: '#94a3b8' }}>Placeholder: {defaults.side_banner_2_alt}</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'shortcuts' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Quick Shortcut Cards (5 Cards)</h3>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                                {(form.data.customization.shortcuts || []).map((sc, idx) => {
                                    const defSc = defaults.shortcuts?.[idx] || { title: '', subtitle: '', icon: '', link: '' };
                                    return (
                                        <div key={idx} style={{ padding: 18, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' }}>
                                            <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 700, color: '#2e3192' }}>Shortcut #{idx + 1}</h4>
                                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: 14 }}>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>Title</span>
                                                    <input
                                                        type="text"
                                                        value={sc.title}
                                                        placeholder={defSc.title}
                                                        onChange={e => updateShortcut(idx, 'title', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSc.title}</small>
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
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSc.subtitle}</small>
                                                </label>
                                                <label>
                                                    <span style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 4 }}>FontAwesome Icon Class</span>
                                                    <input
                                                        type="text"
                                                        value={sc.icon}
                                                        placeholder={defSc.icon}
                                                        onChange={e => updateShortcut(idx, 'icon', e.target.value)}
                                                        style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, fontSize: 13 }}
                                                    />
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSc.icon}</small>
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
                                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 2 }}>Placeholder: {defSc.link}</small>
                                                </label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {tab === 'headings' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Announcement Ticker & Section Headings</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <label style={{ display: 'block', gridColumn: '1 / -1' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Announcement Ticker Message</span>
                                    <textarea
                                        rows={2}
                                        value={form.data.customization.announcement_text}
                                        placeholder={defaults.announcement_text}
                                        onChange={e => updateField('announcement_text', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.announcement_text}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Featured Categories Section Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.featured_categories_title}
                                        placeholder={defaults.featured_categories_title}
                                        onChange={e => updateField('featured_categories_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.featured_categories_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Featured Categories Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.featured_categories_subtitle}
                                        placeholder={defaults.featured_categories_subtitle}
                                        onChange={e => updateField('featured_categories_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.featured_categories_subtitle}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Featured Products Section Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.featured_products_title}
                                        placeholder={defaults.featured_products_title}
                                        onChange={e => updateField('featured_products_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.featured_products_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Featured Products Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.featured_products_subtitle}
                                        placeholder={defaults.featured_products_subtitle}
                                        onChange={e => updateField('featured_products_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.featured_products_subtitle}</small>
                                </label>
                            </div>
                        </div>
                    )}

                    {tab === 'about' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Homepage About Story & Mission</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 20 }}>
                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Main Headline</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.about_headline}
                                        placeholder={defaults.about_headline}
                                        onChange={e => updateField('about_headline', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.about_headline}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Lead Description Paragraph</span>
                                    <textarea
                                        rows={3}
                                        value={form.data.customization.about_lead}
                                        placeholder={defaults.about_lead}
                                        onChange={e => updateField('about_lead', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.about_lead}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Read More Summary Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.about_summary_title}
                                        placeholder={defaults.about_summary_title}
                                        onChange={e => updateField('about_summary_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.about_summary_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Read More Expanded Content</span>
                                    <textarea
                                        rows={4}
                                        value={form.data.customization.about_summary_body}
                                        placeholder={defaults.about_summary_body}
                                        onChange={e => updateField('about_summary_body', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.about_summary_body}</small>
                                </label>
                            </div>
                        </div>
                    )}

                    {tab === 'footer' && (
                        <div>
                            <h3 style={{ fontSize: 16, fontWeight: 700, marginBottom: 16, color: '#011627' }}>Footer Support & Social Channels</h3>
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 20 }}>
                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Support Box 1 Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_support_title}
                                        placeholder={defaults.footer_support_title}
                                        onChange={e => updateField('footer_support_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_support_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Support Box 1 Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_support_subtitle}
                                        placeholder={defaults.footer_support_subtitle}
                                        onChange={e => updateField('footer_support_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_support_subtitle}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Support Box 1 Target Link</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_support_link}
                                        placeholder={defaults.footer_support_link}
                                        onChange={e => updateField('footer_support_link', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_support_link}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Directory Box 2 Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_directory_title}
                                        placeholder={defaults.footer_directory_title}
                                        onChange={e => updateField('footer_directory_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_directory_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Directory Box 2 Subtitle</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_directory_subtitle}
                                        placeholder={defaults.footer_directory_subtitle}
                                        onChange={e => updateField('footer_directory_subtitle', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_directory_subtitle}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Directory Box 2 Target Link</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_directory_link}
                                        placeholder={defaults.footer_directory_link}
                                        onChange={e => updateField('footer_directory_link', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_directory_link}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Footer Brand Title</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_about_title}
                                        placeholder={defaults.footer_about_title}
                                        onChange={e => updateField('footer_about_title', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_about_title}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Footer Short Description</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.footer_about_desc}
                                        placeholder={defaults.footer_about_desc}
                                        onChange={e => updateField('footer_about_desc', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.footer_about_desc}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Facebook Page URL</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.social_facebook}
                                        placeholder={defaults.social_facebook}
                                        onChange={e => updateField('social_facebook', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.social_facebook}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>YouTube Channel URL</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.social_youtube}
                                        placeholder={defaults.social_youtube}
                                        onChange={e => updateField('social_youtube', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.social_youtube}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>LinkedIn Page URL</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.social_linkedin}
                                        placeholder={defaults.social_linkedin}
                                        onChange={e => updateField('social_linkedin', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.social_linkedin}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Instagram Profile URL</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.social_instagram}
                                        placeholder={defaults.social_instagram}
                                        onChange={e => updateField('social_instagram', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.social_instagram}</small>
                                </label>

                                <label style={{ display: 'block' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>WhatsApp Chat Link / Number</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.whatsapp_link}
                                        placeholder={defaults.whatsapp_link}
                                        onChange={e => updateField('whatsapp_link', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.whatsapp_link}</small>
                                </label>

                                <label style={{ display: 'block', gridColumn: '1 / -1' }}>
                                    <span style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>Footer Copyright Notice</span>
                                    <input
                                        type="text"
                                        value={form.data.customization.copyright_text}
                                        placeholder={defaults.copyright_text}
                                        onChange={e => updateField('copyright_text', e.target.value)}
                                        style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 6 }}
                                    />
                                    <small style={{ color: '#94a3b8', display: 'block', marginTop: 4 }}>Default placeholder: {defaults.copyright_text}</small>
                                </label>
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

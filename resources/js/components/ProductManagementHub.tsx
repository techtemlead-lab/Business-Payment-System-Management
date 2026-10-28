import React, { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Package,
    Sparkles,
    Tag,
    Plus,
    Search,
    Save,
    RotateCcw,
    Layers,
    CheckCircle2,
    Eye
} from 'lucide-react';
import {
    FeaturedProductsManager,
    type CustomizationData
} from './SiteCustomizationEditor';
import { Row, money } from './UI';
import { useT } from '../shared/i18n/t';
import LuxuryProductCard from './LuxuryProductCard';

export default function ProductManagementHub({
    products,
    allProducts = [],
    categories = [],
    customization = {},
    defaults = {},
    filters = {},
    canCreate = false,
    onCreateProduct,
    initialTab = 'products',
}: {
    products: Row[];
    allProducts: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    categories?: string[];
    customization?: CustomizationData;
    defaults?: CustomizationData;
    filters?: Row;
    canCreate?: boolean;
    onCreateProduct: () => void;
    initialTab?: string;
}) {
    const translate = useT();
    const {auth,staffActions}=usePage<any>().props;
    const canStock=auth.capabilities.includes('inventory')&&(staffActions?.inventory?.edit??true);
    const canEditCatalog=auth.capabilities.includes('catalog')&&(staffActions?.catalog?.edit??true);
    const canEditMarketing=canEditCatalog||(auth.capabilities.includes('content')&&(staffActions?.content?.edit??true));
    const validTabs: ('products' | 'featured')[] = ['products', 'featured'];
    const getInitialTab = (): 'products' | 'featured' => {
        if (typeof window !== 'undefined') {
            const urlTab = new URLSearchParams(window.location.search).get('tab');
            if (urlTab && validTabs.includes(urlTab as any)) {
                return urlTab as 'products' | 'featured';
            }
        }
        return validTabs.includes(initialTab as any) ? (initialTab as 'products' | 'featured') : 'products';
    };
    const [activeTab, setActiveTab] = useState<'products' | 'featured'>(getInitialTab);
    const [searchQuery, setSearchQuery] = useState(filters?.q || '');

    // Form for Featured Products Showcase
    const featuredForm = useForm({
        customization: {
            featured_products_enabled: customization.featured_products_enabled !== undefined ? customization.featured_products_enabled : '1',
            featured_products_badge: customization.featured_products_badge || '',
            featured_products_title: customization.featured_products_title || '',
            public_products_subtitle: customization.public_products_subtitle || '',
            featured_products_view_all: customization.featured_products_view_all || '',
            featured_products_tabs_enabled: customization.featured_products_tabs_enabled !== undefined ? customization.featured_products_tabs_enabled : '1',
            featured_products_mode: customization.featured_products_mode || 'auto',
            featured_products_items: Array.isArray(customization.featured_products_items)
                ? customization.featured_products_items
                : (defaults.featured_products_items || []),
        }
    });

    const handleSaveFeatured = (e: React.FormEvent) => {
        e.preventDefault();
        featuredForm.post('/commands/customization', {
            preserveScroll: true,
        });
    };

    const handleTabChange = (t: 'products' | 'featured') => {
        setActiveTab(t);
        const url = new URL(window.location.href);
        if (t === 'products') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', t);
        }
        window.history.replaceState({}, '', url.toString());
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/catalog', { q: searchQuery, tab: activeTab !== 'products' ? activeTab : undefined }, { preserveState: true });
    };


    return (
        <div>
            {/* Top Navigation Tabs for Product Management */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                padding: '8px 12px',
                marginBottom: 24,
                boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 12
            }}>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={() => handleTabChange('products')}
                        style={{
                            padding: '9px 16px',
                            borderRadius: 7,
                            fontSize: 13,
                            fontWeight: 700,
                            cursor: 'pointer',
                            border: 'none',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            background: activeTab === 'products' ? '#006837' : 'transparent',
                            color: activeTab === 'products' ? '#ffffff' : '#475569',
                            transition: 'all 0.15s ease'
                        }}
                    >
                        <Package size={16} />
                        <span>{translate('portal', 'all_products_count', { count: String(products.length) })}</span>
                    </button>



                    <button
                        type="button"
                        onClick={() => handleTabChange('featured')}
                        style={{
                            padding: '9px 16px',
                            borderRadius: 7,
                            fontSize: 13,
                            fontWeight: 700,
                            cursor: 'pointer',
                            border: 'none',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            background: activeTab === 'featured' ? '#006837' : 'transparent',
                            color: activeTab === 'featured' ? '#ffffff' : '#475569',
                            transition: 'all 0.15s ease'
                        }}
                    >
                        <Sparkles size={16} />
                        <span>{translate('portal', 'featured_showcase')}</span>
                    </button>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <a
                        href="/"
                        target="_blank"
                        rel="noopener noreferrer"
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            fontSize: 12,
                            fontWeight: 600,
                            color: '#006837',
                            padding: '6px 12px',
                            borderRadius: 6,
                            border: '1px solid #bbf7d0',
                            background: '#f0fdf4',
                            textDecoration: 'none'
                        }}
                    >
                        <Eye size={14} /> {translate('portal', 'view_homepage')}
                    </a>
                </div>
            </div>

            {/* TAB 1: ALL PRODUCTS CATALOG */}
            {activeTab === 'products' && (
                <div>
                    {/* Catalog Control Bar */}
                    <div style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        marginBottom: 20,
                        flexWrap: 'wrap',
                        gap: 12
                    }}>
                        <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: 8, flex: 1, maxWidth: 440 }}>
                            <div className="search-input" style={{ flex: 1 }}>
                                <Search size={17} />
                                <input
                                    aria-label={translate('portal', 'search_records')}
                                    placeholder={translate('portal', 'search_products_catalog')}
                                    value={searchQuery}
                                    onChange={e => setSearchQuery(e.target.value)}
                                />
                            </div>
                            <button className="button small">{translate('portal', 'search')}</button>
                            {filters?.q && (
                                <Link href="/catalog" className="text-link" style={{ alignSelf: 'center' }}>
                                    {translate('portal', 'clear')}
                                </Link>
                            )}
                        </form>

                        {canCreate && (
                            <button
                                type="button"
                                className="button dark"
                                onClick={onCreateProduct}
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 6,
                                    fontWeight: 700,
                                    boxShadow: '0 2px 6px rgba(0, 104, 55, 0.2)'
                                }}
                            >
                                <Plus size={17} /> {translate('portal', 'add_new_product')}
                            </button>
                        )}
                    </div>

                    {/* Products Grid */}
                    <div className="luxury-product-grid">
                        {products.map(p => (
                            <LuxuryProductCard
                                key={p.id}
                                product={p}
                                role="admin"
                                canStock={canStock}
                                canEditCatalog={canEditCatalog}
                                detailsUrl={`/products/${p.id}`}
                                onToggleStock={(id) => router.post(`/commands/products/${id}/toggle-stock`, {}, { preserveScroll: true })}
                                onArchive={(id, name) => {
                                    const reason = window.prompt(`Archive "${name}"? Enter a reason (required):`);
                                    if (reason !== null && reason.trim().length >= 3) {
                                        router.delete(`/commands/products/${id}`, { data: { reason } });
                                    }
                                }}
                            />
                        ))}
                    </div>

                    {!products.length && (
                        <div style={{
                            padding: '40px 20px',
                            background: '#fff',
                            border: '1px solid #e2e8f0',
                            borderRadius: 10,
                            textAlign: 'center',
                            marginTop: 16
                        }}>
                            <Package size={36} style={{ color: '#94a3b8', margin: '0 auto 12px' }} />
                            <h3 style={{ margin: '0 0 6px', fontSize: 16, color: '#334155' }}>{translate('portal', 'no_matching_products')}</h3>
                            <p style={{ margin: '0 0 16px', fontSize: 13, color: '#64748b' }}>
                                {translate('portal', 'no_matching_products_body')}
                            </p>
                            {canCreate && (
                                <button type="button" className="button dark" onClick={onCreateProduct}>
                                    <Plus size={16} /> {translate('portal', 'add_product')}
                                </button>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* TAB 3: FEATURED PRODUCTS SHOWCASE */}
            {activeTab === 'featured' && (
                <form onSubmit={handleSaveFeatured}>
                    <div style={{
                        background: '#f8fafc',
                        border: '1px solid #e2e8f0',
                        borderRadius: 10,
                        padding: '16px 20px',
                        marginBottom: 20
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
                            <div>
                                <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#011627' }}>
                                    {translate('portal', 'featured_showcase')}
                                </h3>
                                <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
                                    {translate('portal', 'catalog_staff_hint')}
                                </p>
                            </div>
                            <button
                                type="submit"
                                disabled={!canEditMarketing || featuredForm.processing}
                                style={{
                                    background: '#006837',
                                    color: '#ffffff',
                                    border: 'none',
                                    padding: '10px 22px',
                                    borderRadius: 7,
                                    fontSize: 13,
                                    fontWeight: 700,
                                    cursor: 'pointer',
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    boxShadow: '0 2px 6px rgba(0, 104, 55, 0.2)'
                                }}
                            >
                                <Save size={16} />
                                {featuredForm.processing ? translate('portal', 'cms_saving') : translate('portal', 'pm_save_featured')}
                            </button>
                        </div>
                    </div>

                    <FeaturedProductsManager
                        customization={featuredForm.data.customization as any}
                        defaults={defaults}
                        products={allProducts.length > 0 ? allProducts : products as any}
                        updateField={(key, val) => featuredForm.setData('customization', {
                            ...featuredForm.data.customization,
                            [key]: val
                        })}
                    />

                    {/* Bottom Save Bar */}
                    <div style={{
                        marginTop: 24,
                        padding: '16px 20px',
                        background: '#ffffff',
                        border: '1px solid #e2e8f0',
                        borderRadius: 10,
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        flexWrap: 'wrap',
                        gap: 12
                    }}>
                        <div style={{ fontSize: 13, color: '#64748b' }}>
                            {translate('portal', 'pm_featured_save_hint')}
                        </div>
                        <button
                            type="submit"
                            disabled={!canEditMarketing || featuredForm.processing}
                            style={{
                                background: '#006837',
                                color: '#ffffff',
                                border: 'none',
                                padding: '10px 24px',
                                borderRadius: 7,
                                fontSize: 13,
                                fontWeight: 700,
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                                boxShadow: '0 2px 6px rgba(0, 104, 55, 0.2)'
                            }}
                        >
                            <Save size={16} />
                            {featuredForm.processing ? translate('portal', 'cms_saving') : translate('portal', 'pm_save_featured')}
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}

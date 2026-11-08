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
import { FeaturedProductsManager } from './website/FeaturedProductsManager';
import type { CustomizationData } from './website/types';
import { Row, money } from './UI';
import { useT } from '../shared/i18n/t';
import LuxuryProductCard from './LuxuryProductCard';
import CatalogTaxonomyHub from './CatalogTaxonomyHub';

export default function ProductManagementHub({
    products,
    allProducts = [],
    categories = [],
    catalogCategories = [],
    catalogUnits = [],
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
    catalogCategories?: any[];
    catalogUnits?: any[];
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
    const validTabs: ('products' | 'featured' | 'taxonomy')[] = ['products', 'featured', 'taxonomy'];
    const getInitialTab = (): 'products' | 'featured' | 'taxonomy' => {
        if (typeof window !== 'undefined') {
            const urlTab = new URLSearchParams(window.location.search).get('tab');
            if (urlTab && validTabs.includes(urlTab as any)) {
                return urlTab as 'products' | 'featured' | 'taxonomy';
            }
        }
        return validTabs.includes(initialTab as any) ? (initialTab as 'products' | 'featured' | 'taxonomy') : 'products';
    };
    const [activeTab, setActiveTab] = useState<'products' | 'featured' | 'taxonomy'>(getInitialTab);
    const [searchQuery, setSearchQuery] = useState(filters?.q || '');
    const [category, setCategory] = useState(String(filters?.category || ''));
    const [subCategory, setSubCategory] = useState(String(filters?.sub_category || ''));
    const [unit, setUnit] = useState(String(filters?.unit || ''));
    const [minMrp, setMinMrp] = useState(String(filters?.min_mrp || ''));
    const [maxMrp, setMaxMrp] = useState(String(filters?.max_mrp || ''));
    const [availability, setAvailability] = useState(String(filters?.availability || ''));
    const [sort, setSort] = useState(String(filters?.sort || 'name_asc'));

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

    const handleTabChange = (t: 'products' | 'featured' | 'taxonomy') => {
        setActiveTab(t);
        const url = new URL(window.location.href);
        if (t === 'products') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', t);
        }
        window.history.replaceState({}, '', url.toString());
    };

    const categoryOptions = catalogCategories
        .map((item: { name?: string }) => item.name)
        .filter((name): name is string => Boolean(name));
    const selectedCatalogCategory = catalogCategories.find((item: { name?: string }) => item.name === category);
    const subCategoryOptions = (selectedCatalogCategory?.subcategories ?? [])
        .map((item: { name?: string }) => item.name)
        .filter((name: string): name is string => Boolean(name));
    const unitOptions = catalogUnits
        .map((item: { name?: string }) => item.name)
        .filter((name): name is string => Boolean(name));
    const filtersActive = Boolean(searchQuery || category || subCategory || unit || minMrp || maxMrp || availability || (sort && sort !== 'name_asc'));

    const applyCatalogFilters = (event?: React.FormEvent, next: Record<string, string> = {}) => {
        event?.preventDefault();
        const selectedSort = next.sort ?? sort;
        const query = {
            q: next.q ?? searchQuery,
            category: next.category ?? category,
            sub_category: next.sub_category ?? subCategory,
            unit: next.unit ?? unit,
            min_mrp: next.min_mrp ?? minMrp,
            max_mrp: next.max_mrp ?? maxMrp,
            availability: next.availability ?? availability,
            sort: selectedSort === 'name_asc' ? '' : selectedSort,
            tab: activeTab !== 'products' ? activeTab : '',
        };
        router.get('/catalog', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '')), { preserveState: true });
    };


    return (
        <div>
            {/* Top Navigation Tabs for Product Management */}
            <div className="ops-card ops-row is-between is-wrap is-loose ops-mb-6 pmh-tabbar">
                <div className="ops-row is-wrap is-snug">
                    <button
                        type="button"
                        onClick={() => handleTabChange('products')}
                        className={`pmh-tab${activeTab === 'products' ? ' is-active' : ''}`}
                    >
                        <Package size={16} />
                        <span>{translate('portal', 'all_products_count', { count: String(products.length) })}</span>
                    </button>



                    <button
                        type="button"
                        onClick={() => handleTabChange('featured')}
                        className={`pmh-tab${activeTab === 'featured' ? ' is-active' : ''}`}
                    >
                        <Sparkles size={16} />
                        <span>{translate('portal', 'featured_showcase')}</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => handleTabChange('taxonomy')}
                        className={`pmh-tab${activeTab === 'taxonomy' ? ' is-active' : ''}`}
                    >
                        <Layers size={16} />
                        <span>{translate('portal', 'cms_taxonomy_tab')}</span>
                    </button>
                </div>

                <div className="ops-row">
                    <a
                        href="/"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="button small light"
                    >
                        <Eye size={14} /> {translate('portal', 'view_homepage')}
                    </a>
                </div>
            </div>

            {/* TAB 1: ALL PRODUCTS CATALOG */}
            {activeTab === 'products' && (
                <div>
                    {/* Catalog Control Bar */}
                    <div className="ops-row is-between is-wrap is-loose ops-mb-5">
                        <form className="catalog-toolbar" onSubmit={applyCatalogFilters}>
                            <div className="catalog-toolbar-search">
                                <div className="search-input">
                                    <Search size={17} />
                                    <input
                                        aria-label={translate('portal', 'search_records')}
                                        placeholder={translate('portal', 'search_products_catalog')}
                                        value={searchQuery}
                                        onChange={e => setSearchQuery(e.target.value)}
                                    />
                                </div>
                                <button className="button small">{translate('portal', 'search')}</button>
                            </div>
                            <div className="catalog-toolbar-filters">
                                <select className="catalog-sort" aria-label={translate('portal', 'catalog_sort_by')} value={sort} onChange={e => { setSort(e.target.value); applyCatalogFilters(undefined, { sort: e.target.value }); }}>
                                    <option value="name_asc">{translate('portal', 'catalog_sort_name_asc')}</option>
                                    <option value="name_desc">{translate('portal', 'catalog_sort_name_desc')}</option>
                                    <option value="price_asc">{translate('portal', 'catalog_sort_price_asc')}</option>
                                    <option value="price_desc">{translate('portal', 'catalog_sort_price_desc')}</option>
                                    <option value="newest">{translate('portal', 'catalog_sort_newest')}</option>
                                    <option value="stock_asc">{translate('portal', 'catalog_sort_stock_asc')}</option>
                                    <option value="stock_desc">{translate('portal', 'catalog_sort_stock_desc')}</option>
                                </select>
                                <select aria-label={translate('portal', 'col_category')} value={category} onChange={e => { setCategory(e.target.value); setSubCategory(''); applyCatalogFilters(undefined, { category: e.target.value, sub_category: '' }); }}>
                                    <option value="">{translate('portal', 'col_category')}</option>
                                    {categoryOptions.map(name => <option key={name} value={name}>{name}</option>)}
                                </select>
                                <select aria-label={translate('portal', 'col_subcategory')} value={subCategory} disabled={!category} onChange={e => { setSubCategory(e.target.value); applyCatalogFilters(undefined, { sub_category: e.target.value }); }}>
                                    <option value="">{translate('portal', 'col_subcategory')}</option>
                                    {subCategoryOptions.map((name: string) => <option key={name} value={name}>{name}</option>)}
                                </select>
                                <select aria-label={translate('portal', 'col_unit')} value={unit} onChange={e => { setUnit(e.target.value); applyCatalogFilters(undefined, { unit: e.target.value }); }}>
                                    <option value="">{translate('portal', 'col_unit')}</option>
                                    {unitOptions.map(name => <option key={name} value={name}>{name}</option>)}
                                </select>
                                <input aria-label={translate('portal', 'catalog_filter_min_price')} type="number" min="0" step="0.01" placeholder={translate('portal', 'catalog_filter_min_price')} value={minMrp} onChange={e => setMinMrp(e.target.value)} />
                                <input aria-label={translate('portal', 'catalog_filter_max_price')} type="number" min="0" step="0.01" placeholder={translate('portal', 'catalog_filter_max_price')} value={maxMrp} onChange={e => setMaxMrp(e.target.value)} />
                                <select aria-label={translate('portal', 'catalog_filter_availability')} value={availability} onChange={e => { setAvailability(e.target.value); applyCatalogFilters(undefined, { availability: e.target.value }); }}>
                                    <option value="">{translate('portal', 'catalog_filter_availability')}</option>
                                    <option value="published">{translate('portal', 'catalog_filter_published')}</option>
                                    <option value="draft">{translate('portal', 'catalog_filter_draft')}</option>
                                    <option value="in_stock">{translate('portal', 'catalog_filter_in_stock')}</option>
                                    <option value="out_of_stock">{translate('portal', 'catalog_filter_out_of_stock')}</option>
                                </select>
                                {filtersActive && <Link href="/catalog" className="text-link">{translate('portal', 'clear')}</Link>}
                            </div>
                        </form>

                        {canCreate && (
                            <button
                                type="button"
                                className="button dark"
                                onClick={onCreateProduct}
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
                        <div className="ops-card ops-center ops-mt-4 pmh-empty">
                            <Package size={36} className="ops-faint pmh-empty-icon" />
                            <h3 className="pmh-empty-title">{translate('portal', 'no_matching_products')}</h3>
                            <p className="ops-m-0 ops-mb-4 ops-fs-sm ops-muted">
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
                    <div className="ops-card is-soft ops-mb-5 pmh-bar">
                        <div className="ops-row is-between is-wrap is-loose">
                            <div>
                                <h3 className="hub-title">
                                    {translate('portal', 'featured_showcase')}
                                </h3>
                                <p className="hub-sub">
                                    {translate('portal', 'catalog_staff_hint')}
                                </p>
                            </div>
                            <button
                                type="submit"
                                className="button dark"
                                disabled={!canEditMarketing || featuredForm.processing}
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
                    <div className="ops-card ops-row is-between is-wrap is-loose ops-mt-6 pmh-bar">
                        <div className="ops-fs-sm ops-muted">
                            {translate('portal', 'pm_featured_save_hint')}
                        </div>
                        <button
                            type="submit"
                            className="button dark"
                            disabled={!canEditMarketing || featuredForm.processing}
                        >
                            <Save size={16} />
                            {featuredForm.processing ? translate('portal', 'cms_saving') : translate('portal', 'pm_save_featured')}
                        </button>
                    </div>
                </form>
            )}

            {activeTab === 'taxonomy' && (
                <CatalogTaxonomyHub
                    categories={catalogCategories}
                    units={catalogUnits}
                    canManage={canEditCatalog || canCreate}
                />
            )}
        </div>
    );
}

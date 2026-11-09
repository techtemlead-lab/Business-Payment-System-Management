import React, { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Package,
    Sparkles,
    Tag,
    Plus,
    Save,
    RotateCcw,
    Layers,
    CheckCircle2,
    Eye
} from 'lucide-react';
import { FeaturedProductsManager } from './website/FeaturedProductsManager';
import { FeaturedOrderList, type FeaturedOrderProduct } from './website/FeaturedOrderList';
import type { CustomizationData } from './website/types';
import { Pagination, Row, money } from './UI';
import { CatalogFilterBar, type StockFilterBarData } from './StockFilterBar';
import { useT } from '../shared/i18n/t';
import LuxuryProductCard from './LuxuryProductCard';
import CatalogTaxonomyHub from './CatalogTaxonomyHub';

export default function ProductManagementHub({
    products,
    allProducts = [],
    featuredOrderProducts = [],
    categories = [],
    catalogCategories = [],
    usedCategories = [],
    catalogUnits = [],
    customization = {},
    defaults = {},
    filters = {},
    catalogFilters,
    productsPage,
    canCreate = false,
    onCreateProduct,
    initialTab = 'products',
}: {
    products: Row[];
    allProducts: { id: number; name: string; sku?: string; mrp: string; dealer_price?: string; category: string; featured?: boolean; stock?: number; image?: string }[];
    featuredOrderProducts?: FeaturedOrderProduct[];
    categories?: string[];
    catalogCategories?: any[];
    usedCategories?: string[];
    catalogUnits?: any[];
    customization?: CustomizationData;
    defaults?: CustomizationData;
    filters?: Row;
    catalogFilters?: StockFilterBarData;
    productsPage?: Row;
    canCreate?: boolean;
    onCreateProduct: () => void;
    initialTab?: string;
}) {
    const translate = useT();
    const {auth,staffActions,errors}=usePage<any>().props;
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
    // With no filters the server sends an empty list, and a list has its own `sort`.
    const sort = typeof filters?.sort === 'string' && filters.sort !== '' ? filters.sort : 'name_asc';


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

    // The owner's list for the home page. It follows the server only when the saved list itself changes,
    // so a search or an unrelated save never wipes changes that are not saved yet.
    const [featuredOrder, setFeaturedOrder] = useState<FeaturedOrderProduct[]>(featuredOrderProducts);
    const [savingFeaturedOrder, setSavingFeaturedOrder] = useState(false);
    const savedFeaturedIds = featuredOrderProducts.map(product => product.id).join(',');
    const featuredOrderChanged = featuredOrder.map(product => product.id).join(',') !== savedFeaturedIds;
    useEffect(() => setFeaturedOrder(featuredOrderProducts), [savedFeaturedIds]);

    const saveFeaturedOrder = () => {
        router.post('/commands/products/featured-order', { product_ids: featuredOrder.map(product => product.id) }, {
            preserveScroll: true,
            onStart: () => setSavingFeaturedOrder(true),
            onFinish: () => setSavingFeaturedOrder(false),
        });
    };

    const handleSaveFeatured = (e: React.FormEvent) => {
        e.preventDefault();
        const orderNeedsSaving = canEditCatalog && featuredForm.data.customization.featured_products_mode === 'auto' && featuredOrderChanged;
        if (orderNeedsSaving && !featuredForm.isDirty) {
            saveFeaturedOrder();

            return;
        }
        featuredForm.post('/commands/customization', {
            preserveScroll: true,
            onSuccess: () => {
                if (orderNeedsSaving) {
                    saveFeaturedOrder();
                }
            },
        });
    };
    const savingFeatured = featuredForm.processing || savingFeaturedOrder;
    const showcaseProducts = allProducts.length > 0 ? allProducts : products as any;

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

    const sortChoices = ['name_asc', 'name_desc', 'price_asc', 'price_desc', 'newest', 'stock_asc', 'stock_desc'].map(value => ({ value, label: translate('portal', `catalog_sort_${value}`) }));

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
                        <span>{translate('portal', 'all_products_count', { count: String(productsPage?.total ?? products.length) })}</span>
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
                    {canCreate && (
                        <div className="sf-catalog-head">
                            <button type="button" className="button dark" onClick={onCreateProduct}>
                                <Plus size={17} /> {translate('portal', 'add_new_product')}
                            </button>
                        </div>
                    )}
                    {catalogFilters && (
                        <CatalogFilterBar
                            bar={catalogFilters}
                            q={String(filters?.q || '')}
                            period={String(filters?.period || '')}
                            from={String(filters?.from || '')}
                            to={String(filters?.to || '')}
                            sort={sort}
                            sortChoices={sortChoices}
                        />
                    )}

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
                                    const reason = window.prompt(translate('portal', 'archive_product_prompt', { name }));
                                    if (reason !== null) {
                                        router.delete(`/commands/products/${id}`, { data: { reason } });
                                    }
                                }}
                            />
                        ))}
                    </div>

                    {productsPage && <Pagination page={productsPage} />}

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
                                disabled={!canEditMarketing || savingFeatured}
                            >
                                <Save size={16} />
                                {savingFeatured ? translate('portal', 'cms_saving') : translate('portal', 'pm_save_featured')}
                            </button>
                        </div>
                    </div>

                    <FeaturedProductsManager
                        customization={featuredForm.data.customization as any}
                        defaults={defaults}
                        products={showcaseProducts}
                        updateField={(key, val) => featuredForm.setData('customization', {
                            ...featuredForm.data.customization,
                            [key]: val
                        })}
                        autoModeList={
                            <FeaturedOrderList
                                items={featuredOrder}
                                candidates={showcaseProducts}
                                canEdit={canEditCatalog}
                                changed={featuredOrderChanged}
                                error={errors?.product_ids}
                                onChange={setFeaturedOrder}
                            />
                        }
                    />

                    {/* Bottom Save Bar */}
                    <div className="ops-card ops-row is-between is-wrap is-loose ops-mt-6 pmh-bar">
                        <div className="ops-fs-sm ops-muted">
                            {translate('portal', 'pm_featured_save_hint')}
                        </div>
                        <button
                            type="submit"
                            className="button dark"
                            disabled={!canEditMarketing || savingFeatured}
                        >
                            <Save size={16} />
                            {savingFeatured ? translate('portal', 'cms_saving') : translate('portal', 'pm_save_featured')}
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

import React, { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Eye, Plus, Save } from 'lucide-react';
import { FeaturedProductsManager } from './website/FeaturedProductsManager';
import { FeaturedOrderList, type FeaturedOrderProduct } from './website/FeaturedOrderList';
import type { CustomizationData } from './website/types';
import { Row, money, number } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { useAddressVisit, type FilterBarData } from './MoneyTableFilters';
import { useT } from '../shared/i18n/t';
import CatalogTaxonomyHub from './CatalogTaxonomyHub';
import ProductDrawer, { ProductStatusBadge } from './ProductDrawer';
import { restoreProduct } from './ProductRemoveDialog';
import { localizeDigits } from '../business-date';

const SORT_KEYS = ['name', 'mrp', 'stock', 'created'];

const OLD_SORT_LINKS: Record<string, [string, 'asc' | 'desc']> = {
    name_desc: ['name', 'desc'],
    price_asc: ['mrp', 'asc'],
    price_desc: ['mrp', 'desc'],
    newest: ['created', 'desc'],
    stock_asc: ['stock', 'asc'],
    stock_desc: ['stock', 'desc'],
};

function percentOff(value: unknown, translate: ReturnType<typeof useT>): string | null {
    const amount = Number(value);
    if (value == null || value === '' || !Number.isFinite(amount) || amount <= 0) {
        return null;
    }
    const trimmed = Number.isInteger(amount) ? String(amount) : amount.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');

    return translate('office_stock', 'percent_off', { percent: `${localizeDigits(trimmed)}%` });
}

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
    canOpenProduct = false,
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
    catalogFilters?: FilterBarData;
    productsPage?: Row;
    canCreate?: boolean;
    canOpenProduct?: boolean;
    onCreateProduct: () => void;
    initialTab?: string;
}) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string | number>) => translate('office_stock', key, replace);
    const { url, props: pageProps } = usePage<any>();
    const { auth, staffActions, errors } = pageProps;
    const archivedCount = Number(pageProps.data?.archived_count ?? 0);
    const draftCount = Number(pageProps.data?.draft_count ?? 0);
    const visit = useAddressVisit(null);
    const [drawerProductId, setDrawerProductId] = useState<number | string | null>(null);
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
    const addressQuery = new URLSearchParams(url.split('?')[1] ?? '');
    const sortInAddress = addressQuery.get('sort') ?? '';
    const [sortKey, sortDirection] = OLD_SORT_LINKS[sortInAddress]
        ?? (SORT_KEYS.includes(sortInAddress) ? [sortInAddress, addressQuery.get('dir') === 'desc' ? 'desc' as const : 'asc' as const] : ['name', 'asc' as const]);
    const showsArchived = catalogFilters?.active?.product_state === 'archived';
    const showsDrafts = catalogFilters?.active?.product_state === 'draft';

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

    const openArchived = () => {
        handleTabChange('products');
        if (!showsArchived) {
            visit({ product_state: 'archived' });
        }
    };

    const openDrafts = () => {
        handleTabChange('products');
        if (!showsDrafts) {
            visit({ product_state: 'draft' });
        }
    };

    const openAllProducts = () => {
        handleTabChange('products');
        if (showsArchived || showsDrafts) {
            visit({ product_state: null });
        }
    };

    const showsStock = products.some(product => product.stock !== undefined);
    const showsChannelPrices = products.some(product => product.dealer_price !== undefined);
    const productFirstLine = (product: Row) => [product.sku, product.category, product.unit].filter(Boolean).join(' · ');

    const columns: MoneyColumn<Row>[] = [
        {
            key: 'name',
            title: translate('portal', 'col_product'),
            sortKey: 'name',
            phone: 'title',
            render: (product) => (
                <div className="os-product">
                    {product.image_url || product.image_fallback?.url
                        ? <img className="os-product-photo" src={product.image_url || product.image_fallback?.url} alt="" loading="lazy" />
                        : <span className="os-product-photo" aria-hidden="true" />}
                    <div>
                        <span className="os-product-name">{product.name}</span>
                        <small className="cell-sub">{productFirstLine(product)}</small>
                    </div>
                </div>
            ),
        },
        { key: 'mrp', title: t('mrp'), sortKey: 'mrp', money: true, phone: 'hide' },
        ...(showsChannelPrices ? [{
            key: 'dealer_price',
            title: t('col_dealer_price'),
            align: 'right' as const,
            phone: 'hide' as const,
            render: (product: Row) => <>{product.dealer_price != null ? money(product.dealer_price) : '—'}<small className="cell-sub">{percentOff(product.dealer_discount_pct, translate)}</small></>,
        }, {
            key: 'sub_dealer_price',
            title: t('col_sub_dealer_price'),
            align: 'right' as const,
            phone: 'hide' as const,
            render: (product: Row) => <>{product.sub_dealer_price != null ? money(product.sub_dealer_price) : '—'}<small className="cell-sub">{percentOff(product.sub_dealer_discount_pct, translate)}</small></>,
        }] : []),
        ...(showsStock ? [{
            key: 'stock',
            title: t('col_stock'),
            sortKey: 'stock',
            align: 'right' as const,
            phone: 'amount' as const,
            render: (product: Row) => <b className={product.stock_status === 'low_stock' ? 'text-amber' : Number(product.stock) <= 0 ? 'text-danger' : undefined}>{number(product.stock)}</b>,
        }] : []),
        {
            key: 'status',
            title: translate('portal', 'col_status'),
            phone: 'sub',
            render: (product) => <ProductStatusBadge product={product} />,
        },
        {
            key: 'actions',
            title: '',
            phone: 'sub',
            render: (product) => (
                <div className="os-row-actions">
                    {showsArchived && canEditCatalog && <button type="button" className="button small dark" onClick={() => restoreProduct({ id: product.id, name: String(product.name) })}>{t('button_restore')}</button>}
                    <button type="button" className="button small" aria-haspopup="dialog" onClick={() => setDrawerProductId(product.id)}>{t('more')}</button>
                </div>
            ),
        },
    ];

    const productTable: MoneyTableData<Row> = {
        rows: { ...(productsPage ?? {}), data: products },
        totals: { count: Number(productsPage?.total ?? products.length), capped: false, sums: null },
        state: {
            q: String(filters?.q ?? ''),
            period: String(filters?.period ?? ''),
            from: filters?.from ? String(filters.from) : null,
            to: filters?.to ? String(filters.to) : null,
            sort: sortKey,
            dir: sortDirection,
            filters: catalogFilters?.active ?? {},
        },
        filters: catalogFilters?.fields ?? [],
        sortable: showsStock ? SORT_KEYS : SORT_KEYS.filter(key => key !== 'stock'),
    };
    const emptyLine = showsArchived
        ? t('empty_archived')
        : showsDrafts ? t('empty_drafts') : archivedCount > 0 && filters?.q ? t('empty_search_but_archived', { count: archivedCount }) : translate('portal', 'no_matching_products');
    const drawerProduct = products.find(product => product.id === drawerProductId);
    const switchChoices: { key: string; label: string; active: boolean; choose: () => void }[] = [
        { key: 'products', label: t('all_products'), active: activeTab === 'products' && !showsArchived && !showsDrafts, choose: openAllProducts },
        { key: 'drafts', label: draftCount > 0 ? `${t('draft_products')} (${number(draftCount)})` : t('draft_products'), active: activeTab === 'products' && showsDrafts, choose: openDrafts },
        { key: 'archived', label: archivedCount > 0 ? `${t('archived_products')} (${number(archivedCount)})` : t('archived_products'), active: activeTab === 'products' && showsArchived, choose: openArchived },
        { key: 'featured', label: t('home_page_list'), active: activeTab === 'featured', choose: () => handleTabChange('featured') },
        { key: 'taxonomy', label: t('categories_units'), active: activeTab === 'taxonomy', choose: () => handleTabChange('taxonomy') },
    ];

    return (
        <div>
            <div className="os-page-bar">
                <div className="os-switch" role="tablist">
                    {switchChoices.map((choice) => (
                        <button key={choice.key} type="button" role="tab" aria-selected={choice.active} className={`os-switch-choice${choice.active ? ' is-on' : ''}`} onClick={choice.choose}>{choice.label}</button>
                    ))}
                </div>
                <div className="os-page-actions">
                    <a href="/" target="_blank" rel="noopener noreferrer" className="button small">
                        <Eye size={14} aria-hidden="true" /> {t('view_homepage')}
                    </a>
                    {canCreate && (
                        <button type="button" className="button dark" onClick={onCreateProduct}>
                            <Plus size={17} aria-hidden="true" /> {t('new_product')}
                        </button>
                    )}
                </div>
            </div>

            {activeTab === 'products' && (
                <div className="sf-table">
                    <MoneyTable
                        data={productTable}
                        prop="data"
                        columns={columns}
                        searchPlaceholder={translate('portal', 'search_products_catalog')}
                        rowHref={canOpenProduct ? (product) => `/products/${product.id}` : undefined}
                        empty={emptyLine}
                    />
                </div>
            )}

            {drawerProduct && (
                <ProductDrawer
                    product={drawerProduct}
                    canStock={canStock}
                    canEditCatalog={canEditCatalog}
                    canOpenProduct={canOpenProduct}
                    onClose={() => setDrawerProductId(null)}
                />
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

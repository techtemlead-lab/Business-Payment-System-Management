import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Empty, money, date } from './UI';
import { useT } from '../shared/i18n/t';

type StockProduct = {
    id: number;
    name: string;
    register_name?: string;
    sku: string;
    category?: string | null;
    sub_category?: string | null;
    unit?: string | null;
    strength?: string | null;
    mrp?: string | number | null;
    dealer_price?: string | number | null;
    dealer_discount_pct?: string | null;
    sub_dealer_price?: string | number | null;
    sub_dealer_discount_pct?: string | null;
    expires_on?: string | null;
    stock?: number;
    branch_stock?: number;
    company_stock?: number;
    low_stock_at?: number;
    is_stock_paused?: boolean;
};

type SortKey = 'name' | 'sku' | 'available' | 'expires' | 'mrp';

function displayName(product: StockProduct): string {
    if (product.register_name) {
        return product.register_name;
    }
    const strength = (product.strength ?? '').trim();
    if (strength && !product.name.toLowerCase().includes(strength.toLowerCase())) {
        return `${product.name}-${strength}`;
    }

    return product.name;
}

function percentLabel(value?: string | null): string {
    if (value == null || value === '') {
        return '—';
    }
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
        return '—';
    }
    const trimmed = Number.isInteger(amount)
        ? String(amount)
        : amount.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');

    return `${trimmed}%`;
}

function availableUnits(product: StockProduct): number {
    return Number(product.branch_stock ?? product.stock ?? 0);
}

function uniqueValues(products: StockProduct[], key: 'category' | 'sub_category' | 'unit'): string[] {
    return [...new Set(products.map((product) => (product[key] ?? '').trim()).filter(Boolean))].sort((left, right) => left.localeCompare(right));
}

function RateCell({ percent, price }: { percent?: string | null; price?: string | number | null }) {
    return (
        <span className="stock-rate">
            <b>{percentLabel(percent)}</b>
            <small>{price != null && price !== '' ? money(price) : '—'}</small>
        </span>
    );
}

export default function StockOnHand({
    products,
    branchName,
    branchCount,
    canToggle,
}: {
    products: StockProduct[];
    branchName: string;
    branchCount: number;
    canToggle: boolean;
}) {
    const translate = useT();
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('');
    const [subCategory, setSubCategory] = useState('');
    const [unit, setUnit] = useState('');
    const [sort, setSort] = useState<SortKey>('name');

    const categories = useMemo(() => uniqueValues(products, 'category'), [products]);
    const units = useMemo(() => uniqueValues(products, 'unit'), [products]);
    const subCategories = useMemo(() => {
        const source = category
            ? products.filter((product) => (product.category ?? '') === category)
            : products;

        return uniqueValues(source, 'sub_category');
    }, [category, products]);

    const rows = useMemo(() => {
        const needle = query.trim().toLowerCase();
        const filtered = products.filter((product) => {
            if (category && (product.category ?? '') !== category) {
                return false;
            }
            if (subCategory && (product.sub_category ?? '') !== subCategory) {
                return false;
            }
            if (unit && (product.unit ?? '') !== unit) {
                return false;
            }
            if (!needle) {
                return true;
            }
            const haystack = [displayName(product), product.name, product.sku, product.strength, product.category, product.sub_category, product.unit]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return haystack.includes(needle);
        });

        const sorted = [...filtered];
        sorted.sort((left, right) => {
            if (sort === 'sku') {
                return left.sku.localeCompare(right.sku);
            }
            if (sort === 'available') {
                return availableUnits(right) - availableUnits(left);
            }
            if (sort === 'mrp') {
                return Number(left.mrp ?? 0) - Number(right.mrp ?? 0);
            }
            if (sort === 'expires') {
                const leftDate = left.expires_on || '';
                const rightDate = right.expires_on || '';
                if (!leftDate && !rightDate) {
                    return 0;
                }
                if (!leftDate) {
                    return 1;
                }
                if (!rightDate) {
                    return -1;
                }

                return leftDate.localeCompare(rightDate);
            }

            return displayName(left).localeCompare(displayName(right));
        });

        return sorted;
    }, [category, products, query, sort, subCategory, unit]);

    const branchSummary = branchCount > 0
        ? `${branchName} · ${branchCount} ${translate('portal', 'branches_short')}`
        : branchName;

    return (
        <section className="panel stock-register">
            <div className="panel-heading">
                <h2>{translate('portal', 'stock_on_hand')}</h2>
                <span>{branchSummary}</span>
            </div>
            <div className="stock-toolbar">
                <label className="stock-search">
                    <Search size={14} aria-hidden="true" />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={translate('portal', 'search_products')}
                        aria-label={translate('portal', 'search_products')}
                    />
                </label>
                <select aria-label={translate('portal', 'col_category')} value={category} onChange={(event) => {
                    setCategory(event.target.value);
                    setSubCategory('');
                }}>
                    <option value="">{translate('portal', 'stock_filter_all')}</option>
                    {categories.map((item) => <option key={item} value={item}>{item}</option>)}
                </select>
                <select aria-label={translate('portal', 'col_subcategory')} value={subCategory} onChange={(event) => setSubCategory(event.target.value)}>
                    <option value="">{translate('portal', 'stock_filter_all')}</option>
                    {subCategories.map((item) => <option key={item} value={item}>{item}</option>)}
                </select>
                <select aria-label={translate('portal', 'col_unit')} value={unit} onChange={(event) => setUnit(event.target.value)}>
                    <option value="">{translate('portal', 'stock_filter_all')}</option>
                    {units.map((item) => <option key={item} value={item}>{item}</option>)}
                </select>
                <select aria-label={translate('portal', 'stock_sort')} value={sort} onChange={(event) => setSort(event.target.value as SortKey)}>
                    <option value="name">{translate('portal', 'stock_sort_name')}</option>
                    <option value="sku">{translate('portal', 'stock_sort_sku')}</option>
                    <option value="available">{translate('portal', 'stock_sort_available')}</option>
                    <option value="expires">{translate('portal', 'stock_sort_expiry')}</option>
                    <option value="mrp">{translate('portal', 'stock_sort_mrp')}</option>
                </select>
            </div>
            {rows.length === 0 ? <Empty title={translate('portal', 'stock_no_matches')} body={translate('portal', 'stock_filter_hint')} /> : (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{translate('portal', 'col_product')}</th>
                                <th>{translate('portal', 'col_sku')}</th>
                                <th>{translate('portal', 'col_category')}</th>
                                <th>{translate('portal', 'col_subcategory')}</th>
                                <th>{translate('portal', 'col_unit')}</th>
                                <th>{translate('portal', 'col_mrp')}</th>
                                <th>{translate('portal', 'col_dealer_commission')}</th>
                                <th>{translate('portal', 'col_subdealer_commission')}</th>
                                <th>{translate('portal', 'col_expiry')}</th>
                                <th>{translate('portal', 'available_units')}</th>
                                <th>{translate('portal', 'low_stock_threshold')}</th>
                                <th>{translate('portal', 'one_click_stock')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((product) => {
                                const onHand = availableUnits(product);
                                const low = Number(product.low_stock_at ?? 0);
                                const paused = Boolean(product.is_stock_paused);

                                return (
                                    <tr key={product.id}>
                                        <td>{displayName(product)}</td>
                                        <td>{product.sku}</td>
                                        <td>{product.category || '—'}</td>
                                        <td>{product.sub_category || '—'}</td>
                                        <td>{product.unit || '—'}</td>
                                        <td>{product.mrp != null && product.mrp !== '' ? money(product.mrp) : '—'}</td>
                                        <td><RateCell percent={product.dealer_discount_pct} price={product.dealer_price} /></td>
                                        <td><RateCell percent={product.sub_dealer_discount_pct} price={product.sub_dealer_price} /></td>
                                        <td>{product.expires_on ? date(product.expires_on) : '—'}</td>
                                        <td>
                                            <b className={onHand <= low ? 'text-amber' : ''}>
                                                {onHand}
                                                {product.company_stock != null && product.company_stock !== onHand ? (
                                                    <small className="cell-sub">{translate('portal', 'branch_company_total', { count: String(product.company_stock) })}</small>
                                                ) : null}
                                            </b>
                                        </td>
                                        <td>{product.low_stock_at ?? '—'}</td>
                                        <td>
                                            <button
                                                type="button"
                                                className={`button small ${paused ? 'stock-resume' : 'stock-pause'}`}
                                                disabled={!canToggle}
                                                onClick={() => router.post(`/commands/products/${product.id}/toggle-stock`, {}, { preserveScroll: true })}
                                            >
                                                {paused ? translate('portal', 'resume_ordering') : translate('portal', 'pause_ordering')}
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

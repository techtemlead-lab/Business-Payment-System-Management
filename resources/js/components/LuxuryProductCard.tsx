import React from 'react';
import { Link } from '@inertiajs/react';
import { money, type Row } from './UI';
import { useT } from '../shared/i18n/t';
import { ShoppingBag } from 'lucide-react';

export interface LuxuryProductCardProps {
    product: Row;
    role?: 'admin' | 'staff' | 'dealer' | 'subdealer' | 'public';
    canStock?: boolean;
    canEditCatalog?: boolean;
    onToggleStock?: (id: number | string) => void;
    onArchive?: (id: number | string, name: string) => void;
    detailsUrl?: string;
    orderUrl?: string;
}

export default function LuxuryProductCard({
    product,
    role = 'admin',
    canStock = true,
    canEditCatalog = true,
    onToggleStock,
    onArchive,
    detailsUrl,
    orderUrl,
}: LuxuryProductCardProps) {
    const translate = useT();

    const category = String(product.category || 'Botanical');

    // Pricing resolution based on role
    const mrpNum = Number(product.mrp) || 0;
    let primaryPrice = Number(product.price ?? product.mrp ?? 0);
    let priceLabel = translate('portal', 'dealer_price');

    if (role === 'subdealer') {
        primaryPrice = Number(product.sub_dealer_price ?? product.price ?? product.mrp ?? 0);
        priceLabel = translate('portal', 'pe_sub_dealer_price') || 'Sub-dealer price';
    } else if (role === 'dealer') {
        primaryPrice = Number(product.dealer_price ?? product.price ?? product.mrp ?? 0);
        priceLabel = translate('portal', 'dealer_price') || 'Dealer price';
    } else if (role === 'public') {
        primaryPrice = mrpNum;
        priceLabel = translate('portal', 'retail_mrp') || 'Retail price (MRP)';
    } else {
        // admin / staff default
        primaryPrice = Number(product.dealer_price ?? product.price ?? product.mrp ?? 0);
        priceLabel = translate('portal', 'dealer_price') || 'Dealer price';
    }

    const hasDiscount = mrpNum > 0 && primaryPrice > 0 && primaryPrice < mrpNum;
    const discountPct = hasDiscount ? Math.round(((mrpNum - primaryPrice) / mrpNum) * 100) : 0;

    // Stock Status text
    const stockCount = Number(product.stock ?? 0);
    let stockCaption = '';
    if (!product.active) {
        stockCaption = translate('portal', 'draft_units', { count: String(stockCount) });
    } else if (product.is_stock_paused) {
        stockCaption = translate('portal', 'stock_awaiting');
    } else if (product.stock_status === 'low_stock') {
        stockCaption = translate('portal', 'low_stock_units', { count: String(stockCount) });
    } else if (stockCount > 0) {
        stockCaption = translate('portal', 'stock_in_units', { count: String(stockCount) });
    } else {
        stockCaption = translate('portal', 'stock_awaiting');
    }

    const resolvedDetailsUrl = detailsUrl || `/products/${product.id}`;
    const resolvedOrderUrl = orderUrl || `/purchase?product_id=${product.id}`;

    const isStaffOrAdmin = role === 'admin' || role === 'staff';

    return (
        <article className="luxury-product-card" id={`product-card-${product.id}`}>
            {/* Botanical Watercolor Corner Flourishes */}
            <div className="luxury-card-botanical-bg" aria-hidden="true">
                {/* Top-left foliage flourish */}
                <svg className="flourish-tl" viewBox="0 0 140 150" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M-5 10C25 35 45 75 40 120C38 135 30 145 20 155" stroke="#7a9775" strokeWidth="1.2" strokeLinecap="round" opacity="0.45" />
                    <path d="M25 45C42 35 52 48 45 60C38 72 22 60 25 45Z" fill="#8ca886" opacity="0.45" />
                    <path d="M38 85C55 75 65 90 58 100C50 110 35 98 38 85Z" fill="#7d9b78" opacity="0.4" />
                    <circle cx="32" cy="40" r="3.5" fill="#fdfcf7" stroke="#b2c6af" strokeWidth="0.8" />
                </svg>

                {/* Bottom-right foliage & flower flourish */}
                <svg className="flourish-br" viewBox="0 0 200 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M195 215C165 175 145 125 155 65C160 35 175 15 195 0" stroke="#7a9775" strokeWidth="1.4" strokeLinecap="round" opacity="0.55" />
                    <path d="M148 95C128 80 115 95 125 110C135 125 152 110 148 95Z" fill="#8ca886" opacity="0.45" />
                    <path d="M168 55C148 42 138 56 148 70C158 82 172 68 168 55Z" fill="#7d9b78" opacity="0.5" />
                    <path d="M178 135C155 128 145 148 160 160C175 170 190 150 178 135Z" fill="#9db898" opacity="0.4" />
                    <circle cx="132" cy="85" r="4" fill="#fdfcf7" stroke="#b2c6af" strokeWidth="1" />
                    <circle cx="162" cy="125" r="3.5" fill="#fdfcf7" stroke="#b2c6af" strokeWidth="1" />
                    <circle cx="142" cy="45" r="4" fill="#fdfcf7" stroke="#b2c6af" strokeWidth="1" />
                </svg>
            </div>

            {/* Header: Category Badge on Left, Featured Ribbon on Right */}
            <div className="luxury-card-header">
                <div className="luxury-category-pill">
                    <span>{category}</span>
                    <span role="img" aria-label="botanical" style={{ fontSize: 13 }}>🌿</span>
                </div>

                {Boolean(product.featured) && (
                    <div className="luxury-featured-ribbon">
                        <span>★</span>
                        <span>{translate('portal', 'featured_badge') || 'Featured'}</span>
                    </div>
                )}
            </div>

            {/* Hero Stage: Arched Halo & 3D Marble Pedestal with Product */}
            <div className="luxury-hero-stage">
                {/* Architectural Arched Halo */}
                <div className="luxury-arched-halo" aria-hidden="true" />

                {/* 3D Cylindrical Marble Pedestal */}
                <div className="luxury-marble-pedestal" aria-hidden="true">
                    <div className="pedestal-shadow" />
                    <div className="pedestal-base" />
                    <div className="pedestal-top" />
                </div>

                {/* Product Showcase upright on Pedestal */}
                <div className="luxury-product-showcase">
                    {product.image_url ? (
                        <img
                            className="luxury-product-img"
                            src={product.image_url}
                            alt={String(product.name || 'Product')}
                            loading="lazy"
                        />
                    ) : (
                        <div className="luxury-art-bottle" aria-hidden="true">
                            <div className="bottle-cap" />
                            <div className="bottle-gold-neck" />
                            {/* Silver necklace chain with leaf charm */}
                            <svg className="bottle-pendant" viewBox="0 0 20 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M2 2C8 12 16 12 18 2" stroke="#94a3b8" strokeWidth="1.2" strokeLinecap="round" />
                                <path d="M10 10V16" stroke="#94a3b8" strokeWidth="1" />
                                <path d="M10 16C6 18 6 24 10 28C14 24 14 18 10 16Z" fill="#cbd5e1" stroke="#64748b" strokeWidth="0.8" />
                                <path d="M10 18V26" stroke="#64748b" strokeWidth="0.6" />
                            </svg>
                            <div className="bottle-label">
                                <span className="brand">madina</span>
                                <span className="cat">{category}</span>
                                <span className="sub">{translate('portal', 'brand_natural_care') || 'NATURAL CARE'}</span>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Content & Typography Body */}
            <div className="luxury-card-body">
                <h3 className="luxury-product-title">
                    {isStaffOrAdmin ? (
                        <Link href={resolvedDetailsUrl}>{product.name}</Link>
                    ) : (
                        <a href={resolvedDetailsUrl}>{product.name}</a>
                    )}
                </h3>

                {/* Pricing Block */}
                <div className="luxury-price-block">
                    <div className="luxury-price-val">
                        <span className="currency">৳</span>
                        <span>{money(primaryPrice).replace(/^[^\d]+/, '')}</span>
                    </div>

                    <div className="luxury-price-label">{priceLabel}</div>

                    {hasDiscount && (
                        <div className="luxury-mrp-row">
                            <span>MRP</span>
                            <span className="mrp-crossed">৳ {money(mrpNum).replace(/^[^\d]+/, '')}</span>
                            {discountPct > 0 && (
                                <span className="luxury-discount-pill">
                                    {translate('portal', 'pct_off', { pct: String(discountPct) }) || `-${discountPct}%`}
                                </span>
                            )}
                        </div>
                    )}
                </div>

                {/* Role-Aware Action Row */}
                <div className="luxury-action-row">
                    {isStaffOrAdmin ? (
                        <>
                            <div className="luxury-action-left">
                                <Link className="luxury-btn-details" href={resolvedDetailsUrl}>
                                    {translate('portal', 'details')}
                                </Link>

                                <button
                                    type="button"
                                    className={`luxury-btn-pause ${product.is_stock_paused ? 'is-paused' : ''}`}
                                    disabled={!canStock}
                                    title={!product.is_stock_paused ? translate('portal', 'pause_ordering') : translate('portal', 'resume_ordering')}
                                    onClick={() => onToggleStock?.(product.id)}
                                >
                                    {!product.is_stock_paused
                                        ? translate('portal', 'pause_ordering')
                                        : translate('portal', 'resume_ordering')}
                                </button>
                            </div>

                            <div className="luxury-action-right">
                                <button
                                    type="button"
                                    className="luxury-btn-archive"
                                    disabled={!canEditCatalog}
                                    onClick={() => onArchive?.(product.id, String(product.name))}
                                >
                                    {translate('portal', 'archive_btn') || 'Archive'}
                                </button>
                                <span className="luxury-status-caption">{stockCaption}</span>
                            </div>
                        </>
                    ) : role === 'dealer' || role === 'subdealer' ? (
                        <>
                            <div className="luxury-action-left">
                                <a className="luxury-btn-details" href={resolvedDetailsUrl}>
                                    {translate('portal', 'details')}
                                </a>
                            </div>

                            <div className="luxury-action-right">
                                <Link className="luxury-btn-order" href={resolvedOrderUrl}>
                                    <ShoppingBag size={14} />
                                    <span>{translate('portal', 'b2b_order_item_btn') || 'Order Now'}</span>
                                </Link>
                                <span className="luxury-status-caption">{stockCaption}</span>
                            </div>
                        </>
                    ) : (
                        /* Public / Reference Website View */
                        <>
                            <div className="luxury-action-left">
                                <a className="luxury-btn-details" href={resolvedDetailsUrl}>
                                    {translate('portal', 'details') || 'Details'}
                                </a>
                            </div>

                            <div className="luxury-action-right">
                                <span className="luxury-status-caption">{stockCaption}</span>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </article>
    );
}

import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Star } from 'lucide-react';
import { money, number, type Row } from './UI';
import { ProductStatusBadge } from './ProductDrawer';
import { useT } from '../shared/i18n/t';
import { catalogWord } from '../shared/catalogWords';
import '../../css/screens-partner-cart.css';

/**
 * A product card of the office (manage and owner) products page. It has the same look as the website's and the
 * partners' cards (one calm picture panel, category, name, price), with the office figures: the channel prices,
 * the stock units and the product's state, and a button that opens the product's drawer.
 */
export default function OfficeProductCard({ product, openUrl, onMore }: {
    product: Row;
    /** The product's own page, when this person may open it. */
    openUrl: string | null;
    onMore: () => void;
}) {
    const translate = useT();
    const office = (key: string) => translate('office_stock', key);

    const image = product.image_url
        ? { url: String(product.image_url), alt: String(product.name ?? '') }
        : product.image_fallback ? { url: String(product.image_fallback.url), alt: String(product.image_fallback.alt ?? '') } : null;
    const showsStock = product.stock !== undefined;
    const units = Number(product.stock ?? 0);
    const lowStock = product.stock_status === 'low_stock';
    const stockWords = product.is_stock_paused
        ? translate('portal', 'stock_awaiting')
        : lowStock
            ? translate('portal', 'low_stock_units', { count: number(units) })
            : units > 0 ? translate('portal', 'stock_in_units', { count: number(units) }) : translate('portal', 'stock_awaiting');
    const stockIsOut = Boolean(product.is_stock_paused) || units <= 0 || lowStock;
    const channelPrices = [
        { key: 'dealer', label: office('col_dealer_price'), value: product.dealer_price },
        { key: 'sub', label: office('col_sub_dealer_price'), value: product.sub_dealer_price },
    ].filter(price => price.value !== undefined && price.value !== null);
    const sizeTag = product.size_label || product.strength;

    return (
        <article className="pcart-card" id={`product-card-${product.id}`}>
            <a className={`pcart-picture${product.image_url ? '' : ' is-stock'}`} href={openUrl ?? undefined} aria-label={String(product.name)}>
                {image ? <img src={image.url} alt={image.alt} loading="lazy" decoding="async" /> : <span className="pcart-nophoto">{translate('catalog_cart', 'no_photo')}</span>}
            </a>
            {!product.image_url && product.image_fallback?.caption ? <p className="pcart-note">{String(product.image_fallback.caption)}</p> : null}

            <div className="pcart-body">
                <div className="pcart-meta">
                    <span className="pcart-category">{catalogWord(translate, 'category', product.category)}</span>
                    {Boolean(product.featured) && <span className="pcart-flag"><Star size={12} aria-hidden="true" /> {translate('catalog_cart', 'featured')}</span>}
                </div>

                <h3 className="pcart-title">{openUrl ? <Link href={openUrl}>{String(product.name)}</Link> : String(product.name)}</h3>
                {product.group ? <p className="pcart-group">{String(product.group)}</p> : null}
                {sizeTag ? <span className="pcart-onesize">{String(sizeTag)}</span> : null}

                <div className="pcart-price-row">
                    <div className="pcart-price">
                        <strong>{money(Number(product.mrp || 0))}</strong>
                        <small>{office('mrp')}</small>
                        {channelPrices.map(price => (
                            <span key={price.key} className="pcart-mrp">{price.label}: <b>{money(Number(price.value))}</b></span>
                        ))}
                    </div>
                    {showsStock && <span className={`pcart-stock${stockIsOut ? ' is-out' : ' is-in'}`}>{stockWords}</span>}
                </div>

                <div className="pcart-actions">
                    <span className="pcart-state"><ProductStatusBadge product={product} /></span>
                    <button type="button" className="pcart-now" aria-haspopup="dialog" onClick={onMore}>
                        {office('more')} <ArrowRight size={14} aria-hidden="true" />
                    </button>
                </div>
            </div>
        </article>
    );
}

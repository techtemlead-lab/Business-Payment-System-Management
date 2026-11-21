import { useState } from 'react';
import { ShoppingCart, Zap, Star, ArrowRight } from 'lucide-react';
import { money, number, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { catalogWord } from '../../shared/catalogWords';
import type { SizeCard } from '../../shared/productSizes';
import '../../../css/screens-partner-cart.css';

/**
 * A product card of the partner products page. It has the look of the website's product card (one calm picture panel,
 * category, name, size buttons, price, stock), with the partner's own price, plus the cart buttons.
 */
export default function PartnerCartCard({ card, detailsUrl, availableOf, onChoose }: {
    card: SizeCard;
    detailsUrl: (product: Row) => string;
    availableOf: (product: Row) => number | null;
    onChoose: (card: SizeCard, startId: number | string, mode: 'cart' | 'now') => void;
}) {
    const translate = useT();
    const words = (key: string, replace?: Record<string, string | number>) => translate('catalog_cart', key, replace);
    const [chosenId, setChosenId] = useState<number | string>(card.first.id);
    const product = card.sizes.find(size => size.id === chosenId) ?? card.first;

    const mrp = Number(product.mrp || 0);
    const price = Number(product.price || product.mrp || 0);
    const hasDiscount = mrp > 0 && price > 0 && price < mrp;
    const off = hasDiscount ? Math.round(((mrp - price) / mrp) * 100) : 0;
    const available = availableOf(product);
    const paused = Boolean(product.is_stock_paused);
    const image = product.image_url
        ? { url: String(product.image_url), alt: String(product.name ?? '') }
        : product.image_fallback ? { url: String(product.image_fallback.url), alt: String(product.image_fallback.alt ?? '') } : null;

    return (
        <article className="pcart-card" id={`product-card-${product.id}`}>
            <a className={`pcart-picture${product.image_url ? '' : ' is-stock'}`} href={detailsUrl(product)} aria-label={String(product.name)}>
                {image ? <img src={image.url} alt={image.alt} loading="lazy" decoding="async" /> : <span className="pcart-nophoto">{words('no_photo')}</span>}
            </a>
            {!product.image_url && product.image_fallback?.caption ? <p className="pcart-note">{String(product.image_fallback.caption)}</p> : null}

            <div className="pcart-body">
                <div className="pcart-meta">
                    <span className="pcart-category">{catalogWord(translate, 'category', product.category)}</span>
                    {Boolean(product.featured) && <span className="pcart-flag"><Star size={12} aria-hidden="true" /> {words('featured')}</span>}
                </div>

                <h3 className="pcart-title"><a href={detailsUrl(product)}>{String(product.name)}</a></h3>
                {product.group ? <p className="pcart-group">{String(product.group)}</p> : null}

                {card.sizes.length > 1 && (
                    <div className="pcart-sizes-row" role="group" aria-label={translate('size_groups', 'choose_size')}>
                        {card.sizes.map(size => (
                            <button
                                key={size.id}
                                type="button"
                                className={`pcart-size${size.id === product.id ? ' is-on' : ''}`}
                                aria-pressed={size.id === product.id}
                                onClick={() => setChosenId(size.id)}
                            >
                                {String(size.size_label)}
                            </button>
                        ))}
                    </div>
                )}
                {card.sizes.length === 1 && (product.size_label || product.strength) ? <span className="pcart-onesize">{String(product.size_label || product.strength)}</span> : null}

                <div className="pcart-price-row">
                    <div className="pcart-price">
                        <strong>{money(price)}</strong>
                        <small>{words('your_price')}</small>
                        {hasDiscount && (
                            <span className="pcart-mrp">
                                {words('mrp_label')} <s>{money(mrp)}</s> <b>{translate('portal', 'pct_off', { pct: String(off) })}</b>
                            </span>
                        )}
                    </div>
                    {paused
                        ? <span className="pcart-stock is-out">{translate('portal', 'stock_awaiting')}</span>
                        : available === null
                            ? null
                            : <span className={`pcart-stock ${available > 0 ? 'is-in' : 'is-out'}`}>{available > 0 ? translate('purchase_page', 'available_now', { qty: number(available) }) : translate('purchase_page', 'available_none')}</span>}
                </div>

                <div className="pcart-actions">
                    <button type="button" className="pcart-add" disabled={paused} onClick={() => onChoose(card, product.id, 'cart')} data-testid="add-to-cart">
                        <ShoppingCart size={18} aria-hidden="true" /> {words('add_to_cart')}
                    </button>
                    <button type="button" className="pcart-now" disabled={paused} onClick={() => onChoose(card, product.id, 'now')} data-testid="order-now">
                        <Zap size={16} aria-hidden="true" /> {words('order_now')}
                    </button>
                    <a className="pcart-details" href={detailsUrl(product)}>
                        {translate('portal', 'details')} <ArrowRight size={14} aria-hidden="true" />
                    </a>
                </div>
            </div>
        </article>
    );
}

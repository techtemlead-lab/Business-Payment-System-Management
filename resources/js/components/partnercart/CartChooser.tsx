import { useMemo, useState } from 'react';
import { Minus, Plus, ShoppingCart, ArrowRight } from 'lucide-react';
import { money, number, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { clampQuantity, MAX_CART_QUANTITY } from '../../shared/partnerCart';
import { stockState } from '../../shared/orderSearch';
import type { SizeCard } from '../../shared/productSizes';
import CartSheet from './CartSheet';

/** The quick quantity buttons. */
const QUICK = [1, 2, 3];

/**
 * The small chooser behind "Add to cart" and "Order now": how many, and which pack size when the product has more than one.
 * A product with one size shows no size choice.
 */
export default function CartChooser({ card, startId, mode, availableOf, inCartOf, onClose, onConfirm }: {
    card: SizeCard;
    startId: number | string;
    mode: 'cart' | 'now';
    /** Units free to promise now, or null when unknown. */
    availableOf: (product: Row) => number | null;
    /** Quantity of a product already in the cart. */
    inCartOf: (product: Row) => number;
    onClose: () => void;
    onConfirm: (product: Row, quantity: number) => void;
}) {
    const translate = useT();
    const words = (key: string, replace?: Record<string, string | number>) => translate('catalog_cart', key, replace);
    const [sizeId, setSizeId] = useState<number | string>(startId);
    const [quantity, setQuantity] = useState(1);
    const product = useMemo(() => card.sizes.find(size => String(size.id) === String(sizeId)) ?? card.first, [card, sizeId]);

    const unitPrice = Number(product.price || product.mrp || 0);
    const state = stockState(product);
    const available = availableOf(product);
    const wanted = inCartOf(product) + quantity;
    const over = available === null ? 0 : Math.max(0, wanted - available);
    const orderable = state === 'in';
    const submit = () => {
        if (orderable) {
            onConfirm(product, clampQuantity(quantity));
        }
    };

    return (
        <CartSheet
            title={words(mode === 'cart' ? 'chooser_title_cart' : 'chooser_title_now')}
            onClose={onClose}
            testId="cart-chooser"
            footer={(
                <>
                    <button type="button" className="button" onClick={onClose}>{words('cancel')}</button>
                    <button type="button" className="button dark pcart-confirm" disabled={!orderable} onClick={submit} data-testid="chooser-confirm">
                        {mode === 'cart' ? <ShoppingCart size={17} aria-hidden="true" /> : null}
                        {words(mode === 'cart' ? 'confirm_cart' : 'confirm_now')}
                        {mode === 'now' ? <ArrowRight size={17} aria-hidden="true" /> : null}
                    </button>
                </>
            )}
        >
            <p className="pcart-chooser-name">{String(product.name)}</p>

            {card.sizes.length > 1 && (
                <fieldset className="pcart-sizes">
                    <legend>{translate('size_groups', 'choose_size')}</legend>
                    <div className="pcart-size-row">
                        {card.sizes.map(size => (
                            <button
                                key={size.id}
                                type="button"
                                className={`pcart-size${String(size.id) === String(product.id) ? ' is-on' : ''}`}
                                aria-pressed={String(size.id) === String(product.id)}
                                onClick={() => setSizeId(size.id)}
                            >
                                {String(size.size_label)}
                            </button>
                        ))}
                    </div>
                </fieldset>
            )}

            <div className="pcart-qty-block">
                <label htmlFor="pcart-qty" className="pcart-label">{words('quantity')}</label>
                <div className="pcart-quick" role="group" aria-label={words('quick_quantity')}>
                    {QUICK.map(choice => (
                        <button key={choice} type="button" className={`pcart-quick-btn${quantity === choice ? ' is-on' : ''}`} aria-pressed={quantity === choice} onClick={() => setQuantity(choice)}>
                            {number(choice)}
                        </button>
                    ))}
                </div>
                <div className="pcart-stepper">
                    <button type="button" className="pcart-step" onClick={() => setQuantity(current => clampQuantity(current - 1))} disabled={quantity <= 1} aria-label={words('quantity_less')}>
                        <Minus size={20} aria-hidden="true" />
                    </button>
                    <input
                        id="pcart-qty"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={MAX_CART_QUANTITY}
                        value={quantity}
                        data-autofocus
                        onChange={event => setQuantity(clampQuantity(event.target.value))}
                        onFocus={event => event.target.select()}
                        onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); submit(); } }}
                    />
                    <button type="button" className="pcart-step" onClick={() => setQuantity(current => clampQuantity(current + 1))} aria-label={words('quantity_more')}>
                        <Plus size={20} aria-hidden="true" />
                    </button>
                </div>
            </div>

            <p className="pcart-line-total" data-testid="chooser-total">{words('line_total', { amount: money(unitPrice * quantity) })}</p>

            {!orderable && <p className="field-hint is-warn" role="status">{translate('order_search', `blocked_${state}`)}</p>}
            {orderable && over > 0 && available !== null && (
                <p className="field-hint is-warn" role="status" data-testid="chooser-backorder">
                    {translate('purchase_page', 'over_warning', { qty: number(wanted), available: number(available), extra: number(over) })}
                </p>
            )}
        </CartSheet>
    );
}

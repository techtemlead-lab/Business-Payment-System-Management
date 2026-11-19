import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Minus, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { money, number, Modal } from '../UI';
import { useT } from '../../shared/i18n/t';
import { catalogWord } from '../../shared/catalogWords';
import { amountInWords } from '../../shared/orderAmountWords';
import { cartTotal, clampQuantity, lineCount, removeLine, setLineQuantity, unitCount, MAX_CART_QUANTITY } from '../../shared/partnerCart';
import { usePartnerCartLines } from '../../shared/usePartnerCart';
import CartSheet from './CartSheet';
import '../../../css/screens-partner-cart.css';

/**
 * The cart icon with its count, in the top bar. It opens the cart: the lines, a quantity box for each, the total with the amount
 * in words, and the button that moves every line to the order page.
 */
export default function PartnerCartButton() {
    const translate = useT();
    const words = (key: string, replace?: Record<string, string | number>) => translate('catalog_cart', key, replace);
    const page = usePage<any>();
    const portalBase = String(page.props.portalBase ?? '');
    const path = String(page.url ?? '').split('?')[0];
    const { lines, setLines } = usePartnerCartLines();
    const [open, setOpen] = useState(false);
    const [clearAsk, setClearAsk] = useState(false);
    const count = lineCount(lines);
    const total = cartTotal(lines);
    const onOrderPage = path.endsWith('/purchase');

    const goToOrder = () => {
        setOpen(false);
        if (!onOrderPage) {
            router.visit(`${portalBase}/purchase`);
        }
    };

    return (
        <>
            <button
                type="button"
                className="icon-button pcart-icon"
                onClick={() => setOpen(true)}
                aria-label={words('cart_icon', { count: number(count) })}
                title={words('cart_open')}
                data-testid="cart-icon"
            >
                <ShoppingCart size={19} aria-hidden="true" />
                {count > 0 && <span className="pcart-count" data-testid="cart-count">{number(count)}</span>}
            </button>

            {open && (
                <CartSheet
                    title={words('cart_title')}
                    side
                    onClose={() => setOpen(false)}
                    testId="cart-panel"
                    footer={count > 0 ? (
                        <div className="pcart-foot-stack">
                            <div className="pcart-total-box">
                                <span>{words('cart_total')}</span>
                                <strong data-testid="cart-total">{money(total)}</strong>
                                <small data-testid="cart-words">{translate('purchase_page', 'confirm_in_words')}: {amountInWords(total)}</small>
                            </div>
                            <button type="button" className="button dark pcart-confirm" onClick={goToOrder} data-testid="cart-order">
                                {words('cart_order')}
                            </button>
                        </div>
                    ) : undefined}
                >
                    {count === 0 ? (
                        <div className="pcart-empty" data-testid="cart-empty">
                            <ShoppingCart size={40} aria-hidden="true" />
                            <p>{words('cart_empty')}</p>
                            {!onOrderPage && (
                                <button type="button" className="button dark" onClick={() => { setOpen(false); router.visit(`${portalBase}/catalog`); }}>
                                    {words('cart_see_products')}
                                </button>
                            )}
                        </div>
                    ) : (
                        <>
                            <p className="pcart-summary">{words('cart_lines', { count: number(count), qty: number(unitCount(lines)) })}</p>
                            <ul className="pcart-lines">
                                {lines.map(line => (
                                    <li key={line.productId} className="pcart-line" data-testid="cart-line">
                                        <div className="pcart-line-pic">{line.imageUrl ? <img src={line.imageUrl} alt="" loading="lazy" /> : null}</div>
                                        <div className="pcart-line-main">
                                            <strong>{line.name}</strong>
                                            {line.sizeLabel ? <span className="pcart-line-size">{line.sizeLabel}</span> : null}
                                            <small>{words('each', { price: money(line.price) })}{line.unit ? ` · ${catalogWord(translate, 'unit', line.unit)}` : ''}</small>
                                            <div className="pcart-stepper is-small">
                                                <button type="button" className="pcart-step" aria-label={words('quantity_less')} disabled={line.quantity <= 1} onClick={() => setLines(setLineQuantity(lines, line.productId, line.quantity - 1))}>
                                                    <Minus size={18} aria-hidden="true" />
                                                </button>
                                                <input
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={1}
                                                    max={MAX_CART_QUANTITY}
                                                    value={line.quantity}
                                                    aria-label={`${words('quantity')}: ${line.name}`}
                                                    onChange={event => setLines(setLineQuantity(lines, line.productId, clampQuantity(event.target.value)))}
                                                    onFocus={event => event.target.select()}
                                                />
                                                <button type="button" className="pcart-step" aria-label={words('quantity_more')} onClick={() => setLines(setLineQuantity(lines, line.productId, line.quantity + 1))}>
                                                    <Plus size={18} aria-hidden="true" />
                                                </button>
                                            </div>
                                        </div>
                                        <div className="pcart-line-end">
                                            <b>{money(line.price * line.quantity)}</b>
                                            <button type="button" className="pcart-remove" onClick={() => setLines(removeLine(lines, line.productId))} aria-label={`${words('cart_remove')}: ${line.name}`} title={words('cart_remove')}>
                                                <Trash2 size={17} aria-hidden="true" />
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <p className="pcart-fine">{words('cart_price_note')}</p>
                            <button type="button" className="text-link pcart-clear" onClick={() => setClearAsk(true)} data-testid="cart-clear-all">
                                {translate('portal', 'b2b_clear_all')}
                            </button>
                        </>
                    )}
                </CartSheet>
            )}

            {clearAsk && (
                <Modal
                    title={translate('leftovers_partner', 'clear_cart_title')}
                    onClose={() => setClearAsk(false)}
                    footer={(
                        <>
                            <button type="button" className="button" onClick={() => setClearAsk(false)}>{translate('leftovers_partner', 'clear_cart_no')}</button>
                            <button type="button" className="button dark" data-testid="cart-clear-all-yes" onClick={() => { setLines([]); setClearAsk(false); }}>{translate('leftovers_partner', 'clear_cart_yes')}</button>
                        </>
                    )}
                >
                    <p>{translate('leftovers_partner', 'clear_cart_body', { count: String(count) })}</p>
                </Modal>
            )}
        </>
    );
}

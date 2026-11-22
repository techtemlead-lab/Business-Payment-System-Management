import { useState, useEffect } from 'react';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { backgroundRefreshHeaders } from '../shared/backgroundRefresh';
import Shell from '../components/Shell';
import { Row, Table, Badge, money, number, date, dateTime, label, ActionForm, DateInput, FormErrors } from '../components/UI';
import OrderReturnsPanel, { ReturnsData } from '../components/OrderReturnsPanel';
import OrderNumber from '../components/OrderNumber';
import { ProofLink, proofKindOf } from '../components/ProofViewer';
import { actionAllowed } from '../components/action-permissions';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { randomUuid } from '../shared/randomUuid';
import TransactionProgressStepper from '../components/TransactionProgressStepper';
import CourierTrackingPanel from '../components/CourierTrackingPanel';
import PartlyDeliveredCommission from '../components/PartlyDeliveredCommission';
import OrderMoneyBreakdown, { type OrderMoneyBreakdownData } from '../components/OrderMoneyBreakdown';
import { shipmentArrivalText } from '../components/orderShipmentArrival';
import { returnedByLine } from '../components/office/portalShared';
import { orderLineDiscountPercent } from '../components/orderLineDiscount';
import OwedItemsPanel, { type OwedItem } from '../components/OwedItemsPanel';
import ClaimDecision from '../components/ClaimDecision';
import OrderNextStep, { type OrderNextStepData } from '../components/OrderNextStep';

/** Units of a line still to send: not sent and not closed by a cancel. */
const unsentOf = (line: Row) => Math.max(0, Number(line.quantity) - Number(line.dispatched) - Number(line.closed ?? 0));

type BranchStock = { branch_id: number; name: string; code: string; products: Record<string, number>; can_send_all: boolean };

function DispatchForm({ order, courierChoices, stockByBranch }: { order: Row; courierChoices: { key: string; label: string; manual?: boolean }[]; stockByBranch: BranchStock[] }) {
    const translate = useT();
    // A manual courier the dealer picked is filled in; staff only type the consignment number.
    const chosenManual = courierChoices.find(option => option.manual && option.key === order.courier_choice);
    // The order's own branch first, else one that holds everything still to send.
    const firstBranch = stockByBranch.find(branch => branch.branch_id === order.fulfillment_branch_id) ?? stockByBranch.find(branch => branch.can_send_all) ?? stockByBranch[0];
    const form = useForm({ courier: chosenManual?.label ?? '', tracking: '', courier_contact: '', eta: '', courier_provider: chosenManual?.key ?? '', idempotency_key: randomUuid(),
        branch_id: firstBranch ? String(firstBranch.branch_id) : '',
        items: order.lines.filter((line: Row) => unsentOf(line) > 0).map((line: Row) => ({ line_id: line.id, quantity: unsentOf(line) })) });
    const chosenBranch = stockByBranch.find(branch => String(branch.branch_id) === form.data.branch_id);
    return <form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.transform(data => ({ ...data, branch_id: data.branch_id ? Number(data.branch_id) : null, courier_provider: data.courier_provider || null, items: data.items.filter((item: Row) => Number(item.quantity) > 0) }));
        // Keep the page where it is, so a refusal (for example not enough stock) shows beside the form.
        form.post(`/commands/orders/${order.id}/dispatch`, { preserveScroll: true, onSuccess: () => form.setData('idempotency_key', randomUuid()) });
    }}>
        <p className="muted">{translate('portal', 'dispatch_hint')}</p>
        <FormErrors errors={form.errors}/>
        {stockByBranch.length > 0 && <label>{translate('orders_stock', 'dispatch_branch')}<select value={form.data.branch_id} onChange={event => form.setData('branch_id', event.target.value)}>
            {stockByBranch.map(branch => {
                const units = order.lines.filter((line: Row) => unsentOf(line) > 0).reduce((sum: number, line: Row) => sum + Number(branch.products[String(line.product_id)] ?? 0), 0);
                return <option key={branch.branch_id} value={String(branch.branch_id)}>{translate('orders_stock', 'dispatch_branch_stock', { branch: branch.name, units: number(units) })}</option>;
            })}
        </select></label>}
        <div className="form-grid">
            {courierChoices.length > 0 && <label>{translate('courier', 'dispatch_provider_label')}<select value={form.data.courier_provider} onChange={event => {
                const choice = courierChoices.find(option => option.key === event.target.value);
                form.setData(data => ({ ...data, courier_provider: event.target.value, courier: choice && data.courier === '' ? choice.label : data.courier }));
            }}>
                <option value="">{translate('courier', 'dispatch_provider_none')}</option>
                {courierChoices.map(option => <option key={option.key} value={option.key}>{option.label}</option>)}
            </select></label>}
            <label>{translate('portal', 'courier')}<input required aria-label={translate('portal', 'courier')} value={form.data.courier} onChange={event => form.setData('courier', event.target.value)} /></label>
            <label>{translate('portal', 'tracking_reference')}<input required={form.data.courier_provider === '' || Boolean(courierChoices.find(option => option.key === form.data.courier_provider)?.manual)} aria-label={translate('portal', 'tracking_reference')} value={form.data.tracking} onChange={event => form.setData('tracking', event.target.value)} /></label>
            <label>{translate('portal', 'courier_contact_label')}<input maxLength={120} aria-label={translate('portal', 'courier_contact_label')} value={form.data.courier_contact} onChange={event => form.setData('courier_contact', event.target.value)} /></label>
            <label>{translate('portal', 'estimated_delivery')}<DateInput value={form.data.eta} onChange={event => form.setData('eta', event.target.value)} /></label>
        </div>
        {form.data.items.map((item: Row, index: number) => {
            const line = order.lines.find((other: Row) => other.id === item.line_id);
            const inStock = chosenBranch ? Number(chosenBranch.products[String(line?.product_id)] ?? 0) : null;
            return <label key={item.line_id}>
                {line?.name}{inStock !== null && <small className="cell-sub">{translate('orders_stock', 'approval_stock')}: {number(inStock)}</small>}
                <input type="number" min="0" step="1" value={item.quantity} onChange={event => form.setData('items', form.data.items.map((other: Row, otherIndex: number) => index === otherIndex ? { ...other, quantity: Number(event.target.value) } : other))} />
            </label>;
        })}
        <button className="button dark" disabled={form.processing}>{translate('portal', 'record_dispatch')}</button>
    </form>;
}

/**
 * Store team: the partner took the goods at the office. One step takes everything still to send out of the
 * chosen branch's stock and marks the order delivered; no courier and no tracking number.
 */
function OfficeHandoverForm({ order, stockByBranch, act = false }: { order: Row; stockByBranch: BranchStock[]; act?: boolean }) {
    const translate = useT();
    // One key for this hand-over: a double press records it once.
    const [handoverKey] = useState(() => randomUuid());
    const firstBranch = stockByBranch.find(branch => branch.branch_id === order.fulfillment_branch_id) ?? stockByBranch.find(branch => branch.can_send_all) ?? stockByBranch[0];
    return <section id="od-handover" className={`panel narrow-panel${act ? ' od-act' : ''}`} data-testid="office-handover">
        <h2>{translate('order_delivery', 'handover_title')}</h2>
        <p className="muted">{translate('order_delivery', 'handover_hint')}</p>
        {order.delivery_method !== 'office' && <p className="notice" role="note">{translate('order_delivery', 'handover_courier_order_note')}</p>}
        <ActionForm action={`/commands/orders/${order.id}/office-handover`} fields={[
            ...(stockByBranch.length > 0 ? [{ name: 'branch_id', label: translate('order_delivery', 'handover_branch'), options: stockByBranch.map(branch => ({ value: String(branch.branch_id), label: branch.name })) }] : []),
            { name: 'note', label: translate('order_delivery', 'handover_note'), type: 'textarea', place: 'order.delivery_confirm' },
        ]} defaults={{ idempotency_key: handoverKey, ...(firstBranch ? { branch_id: String(firstBranch.branch_id) } : {}) }} submit={translate('order_delivery', 'handover_button')} />
    </section>;
}

/** Store team: when the items still waiting for stock are expected to go out (back-order date). */
function SupplyDateForm({ order }: { order: Row }) {
    const translate = useT();
    const form = useForm({ supply_eta: order.supply_eta ?? '', supply_note: order.supply_note ?? '' });
    const supplyNote = useReasonField('order.supply_note');
    return <div className="panel"><form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.transform(data => ({ supply_eta: data.supply_eta || null, supply_note: supplyNote.shown ? data.supply_note || null : null }));
        form.post(`/commands/orders/${order.id}/supply-date`, { preserveScroll: true });
    }}>
        <h3>{translate('portal', 'supply_eta_form_title')}</h3>
        <p className="muted">{translate('portal', 'supply_eta_form_hint')}</p>
        <div className="form-grid">
            <label>{translate('portal', 'supply_eta_label')}<DateInput value={form.data.supply_eta} onChange={event => form.setData('supply_eta', event.target.value)} /></label>
            {supplyNote.shown && <label>{supplyNote.label(translate('reason_fields', 'box_supply_note'))}<input maxLength={300} required={supplyNote.required} value={form.data.supply_note} onChange={event => form.setData('supply_note', event.target.value)} /></label>}
        </div>
        <FormErrors errors={form.errors}/>
        <button className="button" disabled={form.processing}>{translate('portal', 'supply_eta_save')}</button>
    </form></div>;
}

/** Pay what is due on this order without typing anything: cash at the office, or the partner's own account. */
function PayWithoutDetails({ orderId, outstanding, payOptions, act = false }: { orderId: string; outstanding: string; act?: boolean; payOptions?: { account_available: string; account_usable: boolean; account_block: null | 'debt' | 'off' } | null }) {
    const translate = useT();
    const balance = Number(payOptions?.account_available ?? 0);
    const due = Number(outstanding);
    const short = Math.round(due * 100) - Math.round(balance * 100);
    return <>
        <section id="od-pay" className={`panel narrow-panel${act ? ' od-act' : ''}`} data-testid="pay-at-office">
            <h2>{translate('order_payment', 'office_pay_title')}</h2>
            <p className="muted">{translate('order_payment', 'office_pay_hint', { amount: money(outstanding) })}</p>
            <ActionForm action={`/commands/orders/${orderId}/payments`} fields={[]} defaults={{ channel: 'Office' }} submit={translate('order_payment', 'office_pay_button')} />
        </section>
        {payOptions && payOptions.account_block !== 'off' && <section className="panel narrow-panel" data-testid="pay-from-account">
            <h2>{translate('order_payment', 'account_pay_title')}</h2>
            <p className="muted">{translate('order_payment', 'account_balance')}: <b>{money(balance)}</b></p>
            {payOptions.account_block === 'debt' && <p className="field-hint is-warn" role="status">{translate('order_payment', 'account_block_debt')}</p>}
            {payOptions.account_usable && short > 0 && <p className="field-hint is-warn" role="status">{translate('order_payment', 'account_short', { amount: money(short / 100) })}</p>}
            {payOptions.account_usable && short <= 0 && <>
                <p className="muted">{translate('order_payment', 'account_after')}: <b>{money(balance - due)}</b></p>
                <ActionForm action={`/commands/orders/${orderId}/pay-from-account`} fields={[]} submit={translate('order_payment', 'account_pay_button', { amount: money(outstanding) })} />
            </>}
        </section>}
    </>;
}

type BackOrder = { days: number; late: boolean; warn: boolean; days_left: number; deadline_days: number; stock_elsewhere: { branch: string; units: number; can_send_all: boolean }[] };

export default function OrderDetail({ order, claims, shipments, events = [], outstanding, paymentPrompt, backUrl, replacementSlots=[], replacementCandidates=[], fullPaymentRequired=false, courierChoices=[], payOptions=null, returns=null, handledBy=[], backOrder=null, stockByBranch=[], moneyBreakdown=null, owedItems=[] }: { moneyBreakdown?: OrderMoneyBreakdownData | null; owedItems?: OwedItem[]; order: Row; claims: Row[]; shipments: Row[]; events: Row[]; outstanding: string; paymentPrompt: { ask_for_payment: boolean; waiting_for_check: boolean; waiting_amount: string }; backUrl?:string; replacementSlots?:Row[]; replacementCandidates?:Row[]; fullPaymentRequired?:boolean; courierChoices?:{ key: string; label: string }[]; payOptions?:{ account_available: string; account_usable: boolean; account_block: null | 'debt' | 'off' } | null; returns?: ReturnsData | null; handledBy?: string[]; backOrder?: BackOrder | null; stockByBranch?: BranchStock[] }) {
    const translate = useT();
    const { auth,staffActions,portalBase:base,collectionAccounts=[] } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const owner = auth.user.id === order.user_id && capabilities.length === 0;
    const canVerify = actionAllowed('/commands/claims/0/verify', staffActions);
    const open = !['rejected', 'cancelled'].includes(order.status);
    const itemsLeft = (order.lines ?? []).some((line: Row) => unsentOf(line) > 0);
    const anyClosed = (order.lines ?? []).some((line: Row) => Number(line.closed ?? 0) > 0);
    const anyGivenBack = (order.lines ?? []).some((line: Row) => Number(line.returned ?? 0) > 0);
    const parcelOnRoad = shipments.some(shipment => shipment.status === 'dispatched');
    const neededOf = (productId: number) => (order.lines ?? []).filter((line: Row) => Number(line.product_id) === productId).reduce((sum: number, line: Row) => sum + unsentOf(line), 0);
    const sendableOf = (productId: number) => stockByBranch.reduce((sum, branch) => sum + Number(branch.products[String(productId)] ?? 0), 0);
    const returnedOf = returnedByLine(shipments);
    const officeCashWaiting = claims.some(claim => claim.status === 'pending' && claim.channel === 'Office');
    // The partner collects the goods at the office: no courier, label or tracking for this order.
    const pickup = order.delivery_method === 'office';
    const staffOnOrder = auth.user.id !== order.user_id;
    const canSendGoods = capabilities.includes('inventory') && (staffActions?.inventory?.edit ?? true) && staffOnOrder;
    const canChangeDelivery = (capabilities.includes('inventory') || capabilities.includes('order_review')) && staffOnOrder
        && ['submitted', 'held', 'approved'].includes(order.status) && shipments.length === 0;
    // Phone: the one thing this person should do now (see OrderNextStep and mobile-office.css).
    const nextStep: OrderNextStepData | null = (() => {
        if (owner) {
            if (parcelOnRoad) {
                return { key: 'received', targetId: 'od-timeline' };
            }
            return paymentPrompt.ask_for_payment ? { key: 'pay', targetId: 'od-pay' } : null;
        }
        if (!staffOnOrder) {
            return null;
        }
        if (capabilities.includes('finance') && canVerify && claims.some(claim => claim.status === 'pending')) {
            return { key: 'verify', targetId: 'od-claims' };
        }
        if (capabilities.includes('order_review') && ['submitted', 'held'].includes(order.status)) {
            return { key: 'approve', targetId: 'od-review' };
        }
        if (capabilities.includes('inventory') && (staffActions?.inventory?.edit ?? true) && !pickup && ['approved', 'partially_dispatched'].includes(order.status)) {
            return { key: 'courier', href: `/orders/${order.id}/courier` };
        }
        if (canSendGoods && itemsLeft && pickup && ['approved', 'partially_dispatched'].includes(order.status) && !parcelOnRoad) {
            return { key: 'handover', targetId: 'od-handover' };
        }
        if (capabilities.includes('inventory') && parcelOnRoad) {
            return { key: 'delivered', targetId: 'od-timeline' };
        }
        return null;
    })();
    const act = (id: string) => nextStep?.targetId === id;
    const [shareOpen, setShareOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const printUrl = `/orders/${order.id}/invoice/print`;
    const pdfUrl = `/orders/${order.id}/invoice`;
    const shareText = translate('portal', 'share_invoice_text', { number: String(order.number) });
    const absolutePrintUrl = typeof window !== 'undefined' ? `${window.location.origin}${printUrl}` : printUrl;
    const encodedUrl = encodeURIComponent(absolutePrintUrl);
    const encodedShareBody = encodeURIComponent(`${shareText} ${absolutePrintUrl}`);
    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(absolutePrintUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };
    const shareNative = async () => {
        if (typeof navigator !== 'undefined' && navigator.share) {
            try {
                await navigator.share({ title: shareText, text: shareText, url: absolutePrintUrl });
                return;
            } catch {
                // Fall through to copy when the sheet is dismissed or unavailable.
            }
        }
        await copyLink();
    };
    const inFlight = ['submitted', 'held', 'approved', 'partially_dispatched', 'dispatched'].includes(order.status);
    const { stop } = usePoll(5000, { only: ['order', 'claims', 'shipments', 'events', 'outstanding', 'paymentPrompt', 'handledBy', 'returns'], headers: backgroundRefreshHeaders }, { autoStart: inFlight });
    useEffect(() => {
        if (!inFlight) {
            stop();
        }
    }, [inFlight, stop]);

    return <Shell section="order">
        <Head title={order.number} />
        <Link className="text-link" href={backUrl??base+'/orders'}>{translate('portal', 'all_orders')}</Link>
        <div className="order-title"><div><h2><OrderNumber number={String(order.number)} /></h2><p>{order.user.name} · {order.user.dealer_code} · {date(order.created_at)}</p></div><div className="ops-row is-wrap ops-relative" data-tour="invoice-actions">
            <a className="button small dark" href={pdfUrl}>{translate('portal','download_invoice')}</a>
            <a className="button small" href={printUrl} target="_blank" rel="noopener noreferrer">{translate('portal','print_invoice')}</a>
            {capabilities.includes('inventory') && (staffActions?.inventory?.edit??true) && auth.user.id !== order.user_id && !pickup && ['approved', 'partially_dispatched', 'dispatched'].includes(order.status) && <a className="button small" data-testid="shipping-label-link" href={`/orders/${order.id}/shipping-label`} target="_blank" rel="noopener noreferrer">{translate('shipping_label','print_label')}</a>}
            <button type="button" className="button small" onClick={() => setShareOpen((v) => !v)}>{translate('portal','share_invoice')}</button>
            {shareOpen && (
                <div className="panel odt-share-menu" data-tour="invoice-share-menu">
                    <button type="button" className="text-link ops-plain-button odt-share-link" onClick={shareNative}>{translate('portal','share_invoice_direct')}</button>
                    <a className="text-link odt-share-link" target="_blank" rel="noopener noreferrer" href={`https://wa.me/?text=${encodedShareBody}`}>{translate('portal','share_invoice_whatsapp')}</a>
                    <a className="text-link odt-share-link" target="_blank" rel="noopener noreferrer" href={`https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`}>{translate('portal','share_facebook')}</a>
                    <a className="text-link odt-share-link" target="_blank" rel="noopener noreferrer" href={`https://t.me/share/url?url=${encodedUrl}&text=${encodeURIComponent(shareText)}`}>{translate('portal','share_telegram')}</a>
                    <a className="text-link odt-share-link" target="_blank" rel="noopener noreferrer" href={`https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}`}>{translate('portal','share_linkedin')}</a>
                    <a className="text-link odt-share-link" target="_blank" rel="noopener noreferrer" href={`https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodeURIComponent(shareText)}`}>{translate('portal','share_x')}</a>
                    <a className="text-link odt-share-link" href={`mailto:?subject=${encodeURIComponent(shareText)}&body=${encodedShareBody}`}>{translate('portal','share_invoice_email')}</a>
                    <a className="text-link odt-share-link" href={`sms:?&body=${encodedShareBody}`}>{translate('portal','share_invoice_sms')}</a>
                    <button type="button" className="text-link ops-plain-button odt-share-link" onClick={copyLink}>{copied ? translate('portal','share_invoice_copied') : translate('portal','share_invoice_copy')}</button>
                    <p className="muted odt-share-hint">{translate('portal','share_invoice_login_hint')}</p>
                </div>
            )}
            <Badge status={['cancelled', 'approved'].includes(order.status) && shipments.some(shipment => shipment.status === 'returned') ? 'returned' : order.status} />
        </div></div>
        <div className="od-flow">
        <OrderNextStep step={nextStep} />
        {handledBy.length > 0 && <p className="notice" role="status" data-testid="handled-by">{translate('orders_stock', 'handled_by', { names: handledBy.join(', ') })}</p>}
        <TransactionProgressStepper order={order} currentStatus={order.status} claims={claims} shipments={shipments} events={events} />
        <CourierTrackingPanel order={order} shipments={shipments} events={events} courierChoices={courierChoices}
            canManage={(capabilities.includes('inventory') || capabilities.includes('order_review')) && auth.user.id !== order.user_id} />
        {pickup && <section className="panel" data-testid="delivery-pickup">
            <div className="panel-heading"><h2>{translate('order_delivery', 'detail_title')}</h2><span>{translate('order_delivery', 'collect_from_office')}</span></div>
            <p>{translate('order_delivery', ['dispatched', 'delivered', 'partly_delivered'].includes(order.status)
                ? 'detail_office_done'
                : ['approved', 'partially_dispatched'].includes(order.status) ? 'detail_office_ready' : 'detail_office_waiting')}</p>
        </section>}
        {canChangeDelivery && <section className="panel narrow-panel" data-testid="delivery-change">
            <h2>{translate('order_delivery', 'change_title')}</h2>
            <p className="muted">{translate('order_delivery', 'change_hint')}</p>
            <ActionForm action={`/commands/orders/${order.id}/delivery-method`} fields={[
                { name: 'delivery_method', label: translate('order_delivery', 'change_label'), required: true, options: (['courier', 'office'] as const).filter(method => method !== (pickup ? 'office' : 'courier')).map(method => ({ value: method, label: translate('order_delivery', `method_${method}_staff`) })) },
                { name: 'note', label: translate('order_delivery', 'change_note'), type: 'textarea', place: 'order.courier_change' },
            ]} defaults={{ delivery_method: pickup ? 'courier' : 'office' }} submit={translate('order_delivery', 'change_submit')} />
        </section>}

        <div className="metric-grid three">
            <div className="metric"><span>{translate('portal', 'order_total')}</span><strong>{money(order.total)}</strong></div>
            <div className="metric"><span>{translate('portal', 'verified_payment')}</span><strong>{money(order.paid)}</strong></div>
            {paymentPrompt.waiting_for_check
                ? <div className="metric"><span>{translate('order_payment', officeCashWaiting ? 'office_waiting_metric' : 'waiting_metric')}</span><strong>{money(paymentPrompt.waiting_amount)}</strong>{owner && !officeCashWaiting && <small>{translate('order_payment', 'waiting_metric_hint')}</small>}</div>
                : <div className="metric"><span>{translate('portal', 'remaining_payment')}</span><strong>{money(outstanding)}</strong>{paymentPrompt.ask_for_payment && <small>{translate('portal', 'remaining_payment_hint')}</small>}</div>}
        </div>
        <OrderMoneyBreakdown data={moneyBreakdown} />
        {owner && paymentPrompt.waiting_for_check && <div className="notice" role="status"><b>{translate('order_payment', officeCashWaiting ? 'office_waiting_title' : 'waiting_title')}</b><p>{translate('order_payment', officeCashWaiting ? 'office_waiting_body' : 'waiting_body', { amount: money(paymentPrompt.waiting_amount) })}</p></div>}
        <section className="panel"><div className="panel-heading"><h2>{translate('portal', 'products_fulfilment')}</h2><span>{translate('portal', 'prices_saved_at_submission')}</span></div>
            <Table rows={order.lines} phoneColumns={['name', 'quantity', 'total']} columns={[
                { key: 'name', title: translate('portal', 'col_product'), render: line => line.is_free ? <>{line.name}<small className="cell-sub">{translate('schemes_commission', 'free_line_scheme', { scheme: line.scheme_title ?? translate('schemes_commission', 'free_line_label') })}</small></> : line.name }, { key: 'quantity', title: translate('portal', 'col_ordered_quantity'), align: 'right', render: line => number(line.quantity) }, { key: 'dispatched', title: translate('portal', 'col_dispatched'), align: 'right', render: line => number(line.dispatched - (returnedOf.get(Number(line.id)) ?? 0)) },
                ...(returnedOf.size > 0 ? [{ key: 'returned', title: translate('office_orders', 'col_returned'), align: 'right' as const, render: (line: Row) => number(returnedOf.get(Number(line.id)) ?? 0) }] : []),
                ...(anyGivenBack ? [{ key: 'given_back', title: translate('orders_stock', 'kind_return'), align: 'right' as const, render: (line: Row) => number(line.returned ?? 0) }] : []),
                ...(anyClosed ? [{ key: 'closed', title: translate('orders_stock', 'kind_cancel'), align: 'right' as const, render: (line: Row) => number(line.closed ?? 0) }] : []),
                { key: 'remaining', title: translate('portal', 'col_remaining'), align: 'right', render: line => number(unsentOf(line)) },
                { key: 'mrp', title: translate('office_orders', 'col_mrp'), render: line => line.is_free || !Number(line.mrp) ? '—' : money(line.mrp) },
                { key: 'unit_price', title: translate('portal', 'unit_price'), render: line => money(line.unit_price) },
                { key: 'discount_pct', title: translate('portal', 'col_discount_pct'), align: 'right', render: line => {
                    const percent = orderLineDiscountPercent(line);
                    return percent === null ? '—' : `${number(percent)}%`;
                } },
                { key: 'total', title: translate('portal', 'col_total'), render: line => money(line.total) },
            ]} />
            {itemsLeft && open && <p className="muted">
                <b>{translate('portal', 'supply_eta_label')}:</b> {order.supply_eta ? date(order.supply_eta) : translate('portal', 'supply_eta_unknown')}
                {order.supply_eta && order.supply_note ? ` · ${order.supply_note}` : ''}
            </p>}
            {backOrder && <div className={backOrder.late ? 'notice is-danger' : 'notice'} role="status" data-testid="back-order">
                <b>{translate('orders_stock', 'back_order_title')}</b>
                <p>{translate('orders_stock', 'back_order_days', { days: number(backOrder.days) })}</p>
                {owner ? <p>{translate('orders_stock', 'back_order_partner')}</p> : <>
                    {backOrder.late ? <p>{translate('orders_stock', 'back_order_late')}</p> : backOrder.warn ? <p>{translate('orders_stock', 'back_order_soon', { days: number(backOrder.days_left) })}</p> : null}
                    {backOrder.stock_elsewhere.length > 0 && <p>{translate('orders_stock', 'back_order_stock_elsewhere', { branches: backOrder.stock_elsewhere.map(item => `${item.branch} (${number(item.units)})`).join(', ') })}</p>}
                </>}
            </div>}
            <OwedItemsPanel order={order} owedItems={owedItems} owner={owner} canSendGoods={canSendGoods} canRefund={staffOnOrder && capabilities.includes('finance')} unsentOf={unsentOf} />
            {itemsLeft && capabilities.includes('inventory') && ['submitted', 'held', 'approved', 'partially_dispatched'].includes(order.status) && auth.user.id !== order.user_id && <SupplyDateForm key={`${order.supply_eta ?? ''}|${order.supply_note ?? ''}`} order={order} />}
        </section>
        <div id="od-claims" className={`two-column${act('od-claims') ? ' od-act' : ''}`}>
            <section className="panel"><h2>{translate('portal', 'payment_claims')}</h2>
                <Table rows={claims} columns={[
                    { key: 'reference', title: translate('portal', 'col_reference') }, { key: 'channel', title: translate('portal', 'payment_method'), render: (r: Row) => { const raw = String(r.channel ?? ''); const key = `pay_channel_${raw.toLowerCase()}`; const text = translate('portal', key); return raw === '' ? '—' : (text !== key ? text : raw); } }, { key: 'amount', title: translate('portal', 'col_amount'), render: claim => money(claim.amount) },
                    { key: 'status', title: translate('portal', 'col_status'), render: claim => <Badge status={claim.status} /> }, { key: 'reason', title: translate('portal', 'review_note') },
                    // Only the office checking payments may open the dealer's screenshot; the file route refuses anybody else.
                    ...(capabilities.includes('finance') ? [{ key: 'proof', title: translate('portal', 'payment_proof_col'), render: (claim: Row) => claim.has_proof ? <ProofLink className="text-link" href={`/commands/operations/receipts/payment/${claim.id}`} kind={proofKindOf(claim.proof_mime)}>{translate('portal', 'payment_proof_view')}</ProofLink> : translate('portal', 'payment_proof_none') }] : []),
                ]} />
                {claims.every(claim => !['Office', 'Account'].includes(String(claim.channel))) && <p className="muted">{translate('portal', 'claim_not_verified_hint')}</p>}
                {capabilities.includes('finance') && !canVerify && claims.some(claim => claim.status === 'pending') && <p className="notice">{translate('portal', 'payment_view_only_note')}</p>}
                {/* Two clear buttons, then a sheet with the amount and who paid (ClaimDecision posts the same approve/reason/cash_counted). */}
                {capabilities.includes('finance') && canVerify && claims.filter(claim => claim.status === 'pending').map(claim => <ClaimDecision key={claim.id} claim={claim} order={order} />)}
            </section>
            <section id="od-timeline" className={`panel${act('od-timeline') ? ' od-act' : ''}`}><h2>{translate('portal', 'delivery_timeline')}</h2>
                {shipments.length ? shipments.map(shipment => {
                    const lines = typeof shipment.lines === 'string' ? JSON.parse(shipment.lines) : shipment.lines;
                    return <div className="timeline-item" key={shipment.id}><i /><div>
                        <b>{shipment.courier}{shipment.tracking ? ` · ${shipment.tracking}` : ''}</b>
                        {shipment.courier_contact && <p>{translate('portal', 'courier_contact_shown')}: {shipment.courier_contact}</p>}
                        {['pending', 'booking'].includes(shipment.courier_booking_status) && <p className="muted">{translate('portal', 'shipment_courier_booking_pending')}</p>}
                        {shipment.courier_booking_status === 'failed' && <p className="muted">{translate('portal', 'shipment_courier_booking_failed')}</p>}
                        <p>{[date(shipment.created_at), shipmentArrivalText(shipment, translate, date)].filter(Boolean).join(' · ')}</p>
                        <p>{lines.map((line: Row) => `${line.name} × ${line.quantity}`).join(', ')}</p>
                        {lines.flatMap((line: Row) => (line.lots ?? []) as Row[]).filter((lot: Row) => lot.batch_number || lot.expires_on).map((lot: Row) => <p className="muted" key={lot.lot_id}>{lot.batch_number ? translate('portal', 'inv_batch', { batch: String(lot.batch_number) }) : ''}{lot.expires_on ? ` · ${translate('portal', 'inv_expiry', { date: date(String(lot.expires_on)) })}` : ''} × {lot.quantity}</p>)}
                        <Badge status={shipment.status} />
                        {capabilities.includes('inventory') && shipment.status === 'dispatched' && (shipment.courier_status === 'returned'
                            ? <>
                                <p className="muted" role="status">{translate('courier', 'returned_cannot_confirm')}</p>
                                <p className="muted">{translate('courier', 'received_back_hint')}</p>
                                <p className="muted">{translate('courier', 'received_back_money_note')}</p>
                                <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/received-back`} fields={[{ name: 'note', label: translate('reason_fields', 'box_received_back_note'), type: 'textarea', place: 'order.received_back' }]} submit={translate('courier', 'received_back_action')} />
                            </>
                            : <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/delivered`} fields={[{ name: 'reason', label: translate('portal', 'delivery_confirmation_evidence'), required: true, place: 'order.delivery_confirm' }]} submit={translate('portal', 'confirm_delivery')} />)}
                        {capabilities.includes('inventory') && shipment.status === 'returned' && <p className="muted" role="status">{translate('courier', 'received_back_money_note')}</p>}
                    </div></div>;
                }) : <p className="muted">{translate('portal', 'shipment_empty')}</p>}
                {owner && parcelOnRoad && <div className="panel" data-testid="partner-received"><p className="muted">{translate('orders_stock', 'received_hint')}</p>
                    <ActionForm action={`/commands/orders/${order.id}/received`} fields={[]} submit={translate('orders_stock', 'received_button')} /></div>}
            </section>
        </div>
        {order.note && <div className="notice"><b>{translate('portal', 'order_note')}</b><p>{order.note}</p></div>}
        {order.decision_reason && <div className="notice"><b>{translate('portal', 'staff_decision')}</b><p>{order.decision_reason}</p></div>}
        <PartlyDeliveredCommission />
        <OrderReturnsPanel order={order} data={returns} />
        {owner && paymentPrompt.ask_for_payment && <PayWithoutDetails orderId={String(order.id)} outstanding={outstanding} payOptions={payOptions} act={act('od-pay')} />}
        {owner && paymentPrompt.ask_for_payment && <section className="panel narrow-panel">
            <h2>{translate('portal', 'submit_payment_reference')}</h2><p className="muted">{fullPaymentRequired ? translate('portal', 'submit_full_payment_hint', { amount: money(outstanding) }) : translate('portal', 'submit_payment_reference_hint')}</p>
            <ActionForm action={`/commands/orders/${order.id}/payments`} fields={[
                { name: 'channel', label: translate('portal', 'payment_method'), required: true, options: [
                    { value: 'Bank', label: translate('portal', 'pay_channel_bank') },
                    { value: 'bKash', label: translate('portal', 'pay_channel_bkash') },
                    { value: 'Nagad', label: translate('portal', 'pay_channel_nagad') },
                    { value: 'Rocket', label: translate('portal', 'pay_channel_rocket') },
                ] },
                ...(collectionAccounts.length ? [{name:'payment_account_id',label:translate('portal','company_collection_account'),required:true,options:collectionAccounts.map((a:Row)=>({value:String(a.id),label:`${a.channel} · ${a.holder} · ${a.account_number}`}))}] : []),
                { name: 'reference', label: translate('portal', 'transaction_reference'), required: true }, { name: 'amount', label: translate('portal', 'claimed_amount_bdt'), type: 'number', required: true, ...(fullPaymentRequired ? { value: outstanding, hint: translate('portal', 'submit_full_payment_amount_hint') } : {}) },
                { name: 'proof', label: translate('portal', 'payment_proof_label'), type: 'file', required: true, accept: 'image/jpeg,image/png,image/webp,application/pdf', hint: translate('portal', 'payment_proof_hint') },
            ]} submit={translate('portal', 'submit_for_verification')} />
        </section>}
        {capabilities.includes('order_review') && ['submitted', 'held'].includes(order.status) && <section id="od-review" className={`panel narrow-panel${act('od-review') ? ' od-act' : ''}`}>
            <h2>{translate('portal', 'review_this_order')}</h2><p className="muted">{translate('portal', 'review_this_order_hint')}</p>
            {stockByBranch.length > 0 && <ul className="plain-list" data-testid="approval-stock">{Array.from(new Set<number>((order.lines ?? []).map((line: Row) => Number(line.product_id)))).map((productId: number) => {
                const line = order.lines.find((other: Row) => Number(other.product_id) === productId);
                const short = sendableOf(productId) < neededOf(productId);
                return <li key={productId} className={short ? 'is-warn' : undefined}>{line?.name}: {translate('orders_stock', 'approval_stock')} {number(sendableOf(productId))} / {number(neededOf(productId))}{short ? ` · ${translate('orders_stock', 'approval_stock_short')}` : ''}</li>;
            })}</ul>}{replacementSlots.length>0&&<div className="notice"><p>{translate('portal', 'replacement_hint')}</p><form method="get"><label>{translate('portal', 'find_replacement_partners')}<input name="replacement_q" type="search"/></label><button className="button small">{translate('portal', 'find_partners')}</button></form></div>}
            <ActionForm action={`/commands/orders/${order.id}/decision`} fields={[
                { name: 'status', label: translate('portal', 'decision'), required: true, options: ['approved', 'held', 'rejected'].map(value => ({ value, label: label(value, translate) })) },
                ...replacementSlots.map((slot:Row)=>({name:`referral_replacement_${slot.generation}`,label:translate('portal','replacement_for_generation',{generation:String(slot.generation),name:String(slot.name)}),search:true,options:replacementCandidates.map((person:Row)=>({value:String(person.id),label:`${person.name} · ${person.dealer_code??translate('portal','col_partner')}`}))})),
                { name: 'reason', label: translate('portal', 'decision_reason'), type: 'textarea', place: 'order.decision' },
            ]} submit={translate('portal', 'record_decision')} />
        </section>}
        {capabilities.includes('inventory') && (staffActions?.inventory?.edit??true) && !pickup && ['approved', 'partially_dispatched'].includes(order.status) && <section id="od-dispatch" className={`panel${nextStep?.key === 'courier' ? ' od-act' : ''}`}><h2>{translate('portal', 'dispatch_available_items')}</h2>
            <p><Link className="button dark" data-testid="choose-courier-link" href={`/orders/${order.id}/courier`}>{translate('courier', 'pick_open_link')}</Link></p><DispatchForm key={`${order.id}-${shipments.length}`} order={order} courierChoices={courierChoices} stockByBranch={stockByBranch} /></section>}
        {canSendGoods && itemsLeft && ['approved', 'partially_dispatched'].includes(order.status) && !parcelOnRoad && <OfficeHandoverForm key={`${order.id}-${shipments.length}`} order={order} stockByBranch={stockByBranch} act={act('od-handover')} />}
        <section className="panel"><h2>{translate('portal', 'order_activity')}</h2>
            {events.length ? events.map(event => {
                const metadata = typeof event.metadata === 'string' ? JSON.parse(event.metadata) : event.metadata;
                const eventKey = `event_${event.action.replaceAll('.', '_')}`;
                const courierLabel = translate('courier', eventKey);
                const portalLabel = translate('portal', eventKey);
                const deliveryLabel = translate('order_delivery', eventKey);
                const paymentLabel = translate('order_payment', eventKey);
                const title = courierLabel !== eventKey ? courierLabel : portalLabel !== eventKey ? portalLabel : deliveryLabel !== eventKey ? deliveryLabel : paymentLabel !== eventKey ? paymentLabel : label(event.action.replaceAll('.', ' '));
                const detail = event.action === 'order.delivery_changed'
                    ? translate('order_delivery', 'changed_from_to', { from: translate('order_delivery', `method_${metadata?.from}_staff`), to: translate('order_delivery', `method_${metadata?.to}_staff`) }) + (metadata?.note ? ` · ${metadata.note}` : '')
                    : event.action === 'order.office_handover' ? (metadata?.note ?? null)
                    : event.action === 'order.courier_changed'
                    ? translate('courier', 'changed_from_to', { from: String(metadata?.from_label ?? ''), to: String(metadata?.to_label ?? '') }) + (metadata?.note ? ` · ${metadata.note}` : '')
                    : event.action === 'shipment.courier_status' && metadata?.to ? translate('courier', `status_${metadata.to}`)
                    : event.action === 'order.courier_auto_skipped' ? (metadata?.reason ? translate('portal', `courier_skip_reason_${metadata.reason}`) : null)
                    : metadata?.reason;
                return <div className="timeline-item" key={event.id}><i /><div><b>{title}</b><p>{dateTime(event.created_at)}{event.actor_name ? ` · ${event.actor_name}` : ''}{detail ? ` · ${detail}` : ''}</p></div></div>;
            }) : <p className="muted">{translate('portal', 'order_activity_empty')}</p>}
        </section>
        </div>
    </Shell>;
}

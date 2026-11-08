import { useState, useEffect } from 'react';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import Shell from '../components/Shell';
import { Row, Table, Badge, money, number, date, label, ActionForm, DateInput, FormErrors } from '../components/UI';
import { useT } from '../shared/i18n/t';
import { randomUuid } from '../shared/randomUuid';
import TransactionProgressStepper from '../components/TransactionProgressStepper';
import CourierTrackingPanel from '../components/CourierTrackingPanel';
import { shipmentArrivalText } from '../components/orderShipmentArrival';

function DispatchForm({ order, courierChoices }: { order: Row; courierChoices: { key: string; label: string; manual?: boolean }[] }) {
    const translate = useT();
    // A manual courier the dealer picked is filled in; staff only type the consignment number.
    const chosenManual = courierChoices.find(option => option.manual && option.key === order.courier_choice);
    const form = useForm({ courier: chosenManual?.label ?? '', tracking: '', courier_contact: '', eta: '', courier_provider: chosenManual?.key ?? '', idempotency_key: randomUuid(),
        items: order.lines.filter((line: Row) => line.dispatched < line.quantity).map((line: Row) => ({ line_id: line.id, quantity: line.quantity - line.dispatched })) });
    return <form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.transform(data => ({ ...data, courier_provider: data.courier_provider || null, items: data.items.filter((item: Row) => Number(item.quantity) > 0) }));
        form.post(`/commands/orders/${order.id}/dispatch`, { onSuccess: () => form.setData('idempotency_key', randomUuid()) });
    }}>
        <p className="muted">{translate('portal', 'dispatch_hint')}</p>
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
        {form.data.items.map((item: Row, index: number) => <label key={item.line_id}>
            {order.lines.find((line: Row) => line.id === item.line_id)?.name}
            <input type="number" min="0" step="1" value={item.quantity} onChange={event => form.setData('items', form.data.items.map((other: Row, otherIndex: number) => index === otherIndex ? { ...other, quantity: Number(event.target.value) } : other))} />
        </label>)}
        <FormErrors errors={form.errors}/>
        <button className="button dark" disabled={form.processing}>{translate('portal', 'record_dispatch')}</button>
    </form>;
}

/** Store team: when the items still waiting for stock are expected to go out (back-order date). */
function SupplyDateForm({ order }: { order: Row }) {
    const translate = useT();
    const form = useForm({ supply_eta: order.supply_eta ?? '', supply_note: order.supply_note ?? '' });
    return <div className="panel"><form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.transform(data => ({ supply_eta: data.supply_eta || null, supply_note: data.supply_note || null }));
        form.post(`/commands/orders/${order.id}/supply-date`, { preserveScroll: true });
    }}>
        <h3>{translate('portal', 'supply_eta_form_title')}</h3>
        <p className="muted">{translate('portal', 'supply_eta_form_hint')}</p>
        <div className="form-grid">
            <label>{translate('portal', 'supply_eta_label')}<DateInput value={form.data.supply_eta} onChange={event => form.setData('supply_eta', event.target.value)} /></label>
            <label>{translate('portal', 'supply_note_label')}<input maxLength={300} value={form.data.supply_note} onChange={event => form.setData('supply_note', event.target.value)} /></label>
        </div>
        <FormErrors errors={form.errors}/>
        <button className="button" disabled={form.processing}>{translate('portal', 'supply_eta_save')}</button>
    </form></div>;
}

export default function OrderDetail({ order, claims, shipments, events = [], outstanding, paymentPrompt, backUrl, replacementSlots=[], replacementCandidates=[], refund=null, canRefund=false, fullPaymentRequired=false, courierChoices=[] }: { order: Row; claims: Row[]; shipments: Row[]; events: Row[]; outstanding: string; paymentPrompt: { ask_for_payment: boolean; waiting_for_check: boolean; waiting_amount: string }; backUrl?:string; replacementSlots?:Row[]; replacementCandidates?:Row[]; refund?:Row|null; canRefund?:boolean; fullPaymentRequired?:boolean; courierChoices?:{ key: string; label: string }[] }) {
    const translate = useT();
    const { auth,staffActions,portalBase:base,collectionAccounts=[] } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const owner = auth.user.id === order.user_id && capabilities.length === 0;
    const open = !['rejected', 'cancelled'].includes(order.status);
    const itemsLeft = (order.lines ?? []).some((line: Row) => Number(line.dispatched) < Number(line.quantity));
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
    const { stop } = usePoll(5000, { only: ['order', 'claims', 'shipments', 'outstanding', 'paymentPrompt'] }, { autoStart: inFlight });
    useEffect(() => {
        if (!inFlight) {
            stop();
        }
    }, [inFlight, stop]);

    return <Shell section="order">
        <Head title={order.number} />
        <Link className="text-link" href={backUrl??base+'/orders'}>{translate('portal', 'all_orders')}</Link>
        <div className="order-title"><div><h2>{order.number}</h2><p>{order.user.name} · {order.user.dealer_code} · {date(order.created_at)}</p></div><div className="ops-row is-wrap ops-relative" data-tour="invoice-actions">
            <a className="button small dark" href={pdfUrl}>{translate('portal','download_invoice')}</a>
            <a className="button small" href={printUrl} target="_blank" rel="noopener noreferrer">{translate('portal','print_invoice')}</a>
            {capabilities.includes('inventory') && (staffActions?.inventory?.edit??true) && auth.user.id !== order.user_id && ['approved', 'partially_dispatched', 'dispatched'].includes(order.status) && <a className="button small" data-testid="shipping-label-link" href={`/orders/${order.id}/shipping-label`} target="_blank" rel="noopener noreferrer">{translate('shipping_label','print_label')}</a>}
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
            <Badge status={order.status} />
        </div></div>
        <TransactionProgressStepper order={order} currentStatus={order.status} claims={claims} shipments={shipments} events={events} />
        <CourierTrackingPanel order={order} shipments={shipments} events={events} courierChoices={courierChoices}
            canManage={(capabilities.includes('inventory') || capabilities.includes('order_review')) && auth.user.id !== order.user_id} />

        <div className="metric-grid three">
            <div className="metric"><span>{translate('portal', 'order_total')}</span><strong>{money(order.total)}</strong></div>
            <div className="metric"><span>{translate('portal', 'verified_payment')}</span><strong>{money(order.paid)}</strong></div>
            {paymentPrompt.waiting_for_check
                ? <div className="metric"><span>{translate('order_payment', 'waiting_metric')}</span><strong>{money(paymentPrompt.waiting_amount)}</strong>{owner && <small>{translate('order_payment', 'waiting_metric_hint')}</small>}</div>
                : <div className="metric"><span>{translate('portal', 'remaining_payment')}</span><strong>{money(outstanding)}</strong>{paymentPrompt.ask_for_payment && <small>{translate('portal', 'remaining_payment_hint')}</small>}</div>}
        </div>
        {owner && paymentPrompt.waiting_for_check && <div className="notice" role="status"><b>{translate('order_payment', 'waiting_title')}</b><p>{translate('order_payment', 'waiting_body', { amount: money(paymentPrompt.waiting_amount) })}</p></div>}
        <section className="panel"><div className="panel-heading"><h2>{translate('portal', 'products_fulfilment')}</h2><span>{translate('portal', 'prices_saved_at_submission')}</span></div>
            <Table rows={order.lines} columns={[
                { key: 'name', title: translate('portal', 'col_product'), render: line => line.is_free ? <>{line.name}<small className="cell-sub">{translate('schemes_commission', 'free_line_scheme', { scheme: line.scheme_title ?? translate('schemes_commission', 'free_line_label') })}</small></> : line.name }, { key: 'quantity', title: translate('portal', 'report_col_ordered'), align: 'right', render: line => number(line.quantity) }, { key: 'dispatched', title: translate('portal', 'col_dispatched'), align: 'right', render: line => number(line.dispatched) },
                { key: 'remaining', title: translate('portal', 'col_remaining'), align: 'right', render: line => number(line.quantity - line.dispatched) },
                { key: 'unit_price', title: translate('portal', 'unit_price'), render: line => money(line.unit_price) }, { key: 'total', title: translate('portal', 'col_total'), render: line => money(line.total) },
            ]} />
            {itemsLeft && open && <p className="muted">
                <b>{translate('portal', 'supply_eta_label')}:</b> {order.supply_eta ? date(order.supply_eta) : translate('portal', 'supply_eta_unknown')}
                {order.supply_eta && order.supply_note ? ` · ${order.supply_note}` : ''}
            </p>}
            {itemsLeft && capabilities.includes('inventory') && ['submitted', 'held', 'approved', 'partially_dispatched'].includes(order.status) && auth.user.id !== order.user_id && <SupplyDateForm key={`${order.supply_eta ?? ''}|${order.supply_note ?? ''}`} order={order} />}
        </section>
        <div className="two-column">
            <section className="panel"><h2>{translate('portal', 'payment_claims')}</h2>
                <Table rows={claims} columns={[
                    { key: 'reference', title: translate('portal', 'col_reference') }, { key: 'channel', title: translate('portal', 'payment_method'), render: (r: Row) => { const raw = String(r.channel ?? ''); const key = `pay_channel_${raw.toLowerCase()}`; const text = translate('portal', key); return raw === '' ? '—' : (text !== key ? text : raw); } }, { key: 'amount', title: translate('portal', 'col_amount'), render: claim => money(claim.amount) },
                    { key: 'status', title: translate('portal', 'col_status'), render: claim => <Badge status={claim.status} /> }, { key: 'reason', title: translate('portal', 'review_note') },
                ]} />
                <p className="muted">{translate('portal', 'claim_not_verified_hint')}</p>
                {capabilities.includes('finance') && claims.filter(claim => claim.status === 'pending').map(claim => <div className="panel" key={claim.id}>
                    <h3>{translate('portal', 'review_claim', { reference: claim.reference })}</h3>
                    <ActionForm action={`/commands/claims/${claim.id}/verify`} fields={[
                        { name: 'approve', label: translate('portal', 'decision'), required: true, options: [{ value: '1', label: translate('portal', 'verify_received_payment') }, { value: '0', label: translate('portal', 'reject_claim') }] },
                        { name: 'reason', label: translate('portal', 'verification_evidence'), type: 'textarea', required: true },
                    ]} submit={translate('portal', 'record_payment_review')} />
                </div>)}
            </section>
            <section className="panel"><h2>{translate('portal', 'delivery_timeline')}</h2>
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
                                <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/received-back`} fields={[{ name: 'note', label: translate('courier', 'received_back_note_label'), type: 'textarea' }]} submit={translate('courier', 'received_back_action')} />
                            </>
                            : <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/delivered`} fields={[{ name: 'reason', label: translate('portal', 'delivery_confirmation_evidence'), required: true }]} submit={translate('portal', 'confirm_delivery')} />)}
                        {capabilities.includes('inventory') && shipment.status === 'returned' && <p className="muted" role="status">{translate('courier', 'received_back_money_note')}</p>}
                    </div></div>;
                }) : <p className="muted">{translate('portal', 'shipment_empty')}</p>}
            </section>
        </div>
        {order.note && <div className="notice"><b>{translate('portal', 'order_note')}</b><p>{order.note}</p></div>}
        {order.decision_reason && <div className="notice"><b>{translate('portal', 'staff_decision')}</b><p>{order.decision_reason}</p></div>}
        {refund && <section className="panel" data-testid="order-refund"><h2>{translate('portal', 'order_refund_recorded')}</h2><p>{translate('portal', 'order_refund_recorded_body', { amount: money(refund.amount), name: String(refund.refunded_by), date: date(refund.created_at) })}</p><p className="muted">{refund.reason}</p></section>}
        {canRefund && !refund && <section className="panel narrow-panel"><h2>{translate('portal', 'order_refund_title')}</h2><p className="muted">{translate('portal', 'order_refund_hint')}</p>
            <ActionForm action={`/commands/orders/${order.id}/refund`} fields={[
                { name: 'amount', label: translate('portal', 'order_refund_amount'), type: 'number', required: true, value: String(order.paid) },
                { name: 'reason', label: translate('portal', 'order_refund_reason'), type: 'textarea', required: true },
            ]} submit={translate('portal', 'order_refund_submit')} />
        </section>}
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
        {capabilities.includes('order_review') && ['submitted', 'held'].includes(order.status) && <section className="panel narrow-panel">
            <h2>{translate('portal', 'review_this_order')}</h2><p className="muted">{translate('portal', 'review_this_order_hint')}</p>{replacementSlots.length>0&&<div className="notice"><p>{translate('portal', 'replacement_hint')}</p><form method="get"><label>{translate('portal', 'find_replacement_partners')}<input name="replacement_q" type="search"/></label><button className="button small">{translate('portal', 'find_partners')}</button></form></div>}
            <ActionForm action={`/commands/orders/${order.id}/decision`} fields={[
                { name: 'status', label: translate('portal', 'decision'), required: true, options: ['approved', 'held', 'rejected'].map(value => ({ value, label: label(value, translate) })) },
                ...replacementSlots.map((slot:Row)=>({name:`referral_replacement_${slot.generation}`,label:translate('portal','replacement_for_generation',{generation:String(slot.generation),name:String(slot.name)}),options:replacementCandidates.map((person:Row)=>({value:String(person.id),label:`${person.name} · ${person.dealer_code??translate('portal','col_partner')}`}))})),
                { name: 'reason', label: translate('portal', 'decision_reason'), type: 'textarea', required: true },
            ]} submit={translate('portal', 'record_decision')} />
        </section>}
        {capabilities.includes('inventory') && (staffActions?.inventory?.edit??true) && ['approved', 'partially_dispatched'].includes(order.status) && <section className="panel"><h2>{translate('portal', 'dispatch_available_items')}</h2><DispatchForm key={`${order.id}-${shipments.length}`} order={order} courierChoices={courierChoices} /></section>}
        <section className="panel"><h2>{translate('portal', 'order_activity')}</h2>
            {events.length ? events.map(event => {
                const metadata = typeof event.metadata === 'string' ? JSON.parse(event.metadata) : event.metadata;
                const eventKey = `event_${event.action.replaceAll('.', '_')}`;
                const courierLabel = translate('courier', eventKey);
                const portalLabel = translate('portal', eventKey);
                const title = courierLabel !== eventKey ? courierLabel : portalLabel !== eventKey ? portalLabel : label(event.action.replaceAll('.', ' '));
                const detail = event.action === 'order.courier_changed'
                    ? translate('courier', 'changed_from_to', { from: String(metadata?.from_label ?? ''), to: String(metadata?.to_label ?? '') }) + (metadata?.note ? ` · ${metadata.note}` : '')
                    : event.action === 'shipment.courier_status' && metadata?.to ? translate('courier', `status_${metadata.to}`) : metadata?.reason;
                return <div className="timeline-item" key={event.id}><i /><div><b>{title}</b><p>{date(event.created_at)}{detail ? ` · ${detail}` : ''}</p></div></div>;
            }) : <p className="muted">{translate('portal', 'order_activity_empty')}</p>}
        </section>
    </Shell>;
}

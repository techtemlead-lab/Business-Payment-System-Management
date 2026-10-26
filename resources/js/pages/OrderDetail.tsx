import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import { Row, Table, Badge, money, date, label, ActionForm } from '../components/UI';
import { useT } from '../shared/i18n/t';

function DispatchForm({ order }: { order: Row }) {
    const translate = useT();
    const form = useForm({ courier: '', tracking: '', eta: '', idempotency_key: crypto.randomUUID(),
        items: order.lines.filter((line: Row) => line.dispatched < line.quantity).map((line: Row) => ({ line_id: line.id, quantity: line.quantity - line.dispatched })) });
    return <form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.transform(data => ({ ...data, items: data.items.filter((item: Row) => Number(item.quantity) > 0) }));
        form.post(`/commands/orders/${order.id}/dispatch`, { onSuccess: () => form.setData('idempotency_key', crypto.randomUUID()) });
    }}>
        <p className="muted">{translate('portal', 'dispatch_hint')}</p>
        <div className="form-grid">
            <label>{translate('portal', 'courier')}<input required value={form.data.courier} onChange={event => form.setData('courier', event.target.value)} /></label>
            <label>{translate('portal', 'tracking_reference')}<input required value={form.data.tracking} onChange={event => form.setData('tracking', event.target.value)} /></label>
            <label>{translate('portal', 'estimated_delivery')}<input type="date" value={form.data.eta} onChange={event => form.setData('eta', event.target.value)} /></label>
        </div>
        {form.data.items.map((item: Row, index: number) => <label key={item.line_id}>
            {order.lines.find((line: Row) => line.id === item.line_id)?.name}
            <input type="number" min="0" step="1" value={item.quantity} onChange={event => form.setData('items', form.data.items.map((other: Row, otherIndex: number) => index === otherIndex ? { ...other, quantity: Number(event.target.value) } : other))} />
        </label>)}
        {Object.keys(form.errors).length > 0 && <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
        <button className="button dark" disabled={form.processing}>{translate('portal', 'record_dispatch')}</button>
    </form>;
}

export default function OrderDetail({ order, claims, shipments, events = [], outstanding, backUrl, adjustments=[], replacementSlots=[], replacementCandidates=[] }: { order: Row; claims: Row[]; shipments: Row[]; events: Row[]; outstanding: string; backUrl?:string; adjustments?:Row[]; replacementSlots?:Row[]; replacementCandidates?:Row[] }) {
    const translate = useT();
    const { auth,staffActions,portalBase:base,collectionAccounts=[] } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const owner = auth.user.id === order.user_id && capabilities.length === 0;
    const open = !['rejected', 'cancelled'].includes(order.status);
    const [shareOpen, setShareOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const printUrl = `/orders/${order.id}/invoice/print`;
    const pdfUrl = `/orders/${order.id}/invoice`;
    const shareText = translate('portal', 'share_invoice_text', { number: String(order.number) });
    const absolutePrintUrl = typeof window !== 'undefined' ? `${window.location.origin}${printUrl}` : printUrl;
    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(absolutePrintUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };
    return <Shell section="order">
        <Head title={order.number} />
        <Link className="text-link" href={backUrl??base+'/orders'}>{translate('portal', 'all_orders')}</Link>
        <div className="order-title"><div><h2>{order.number}</h2><p>{order.user.name} · {order.user.dealer_code} · {date(order.created_at)}</p></div><div style={{display:'flex',gap:8,alignItems:'center',flexWrap:'wrap',position:'relative'}} data-tour="invoice-actions">
            <a className="button small dark" href={pdfUrl}>{translate('portal','download_invoice')}</a>
            <a className="button small" href={printUrl} target="_blank" rel="noopener noreferrer">{translate('portal','print_invoice')}</a>
            <button type="button" className="button small" onClick={() => setShareOpen((v) => !v)}>{translate('portal','share_invoice')}</button>
            {shareOpen && (
                <div className="panel" style={{position:'absolute',right:0,top:'110%',zIndex:20,minWidth:220,padding:12,boxShadow:'0 12px 28px #01162722'}}>
                    <a className="text-link" style={{display:'block',marginBottom:8}} target="_blank" rel="noopener noreferrer" href={`https://wa.me/?text=${encodeURIComponent(`${shareText} ${absolutePrintUrl}`)}`}>{translate('portal','share_invoice_whatsapp')}</a>
                    <a className="text-link" style={{display:'block',marginBottom:8}} href={`mailto:?subject=${encodeURIComponent(shareText)}&body=${encodeURIComponent(absolutePrintUrl)}`}>{translate('portal','share_invoice_email')}</a>
                    <button type="button" className="text-link" style={{display:'block',background:'none',border:0,padding:0,cursor:'pointer'}} onClick={copyLink}>{copied ? translate('portal','share_invoice_copied') : translate('portal','share_invoice_copy')}</button>
                </div>
            )}
            <Badge status={order.status} />
        </div></div>
        <div className="metric-grid three">
            <div className="metric"><span>{translate('portal', 'order_total')}</span><strong>{money(order.total)}</strong></div>
            <div className="metric"><span>{translate('portal', 'verified_payment')}</span><strong>{money(order.paid)}</strong></div>
            <div className="metric"><span>{translate('portal', 'remaining_payment')}</span><strong>{money(outstanding)}</strong><small>{translate('portal', 'remaining_payment_hint')}</small></div>
        </div>
        {Number(order.due_credit)>0&&<div className="notice"><strong>{translate('portal', 'approved_due_credits', { amount: money(order.due_credit) })}</strong><p>{translate('portal', 'approved_due_credits_hint')}</p></div>}
        {adjustments.length>0&&<section className="panel"><h2>{translate('portal', 'due_adjustment_history')}</h2><Table rows={adjustments} columns={[{key:'reference',title:translate('portal','col_reference')},{key:'kind',title:translate('portal','col_type')},{key:'amount',title:translate('portal','col_amount'),render:r=>money(r.amount)},{key:'status',title:translate('portal','col_status'),render:r=><Badge status={r.status}/>},{key:'before_outstanding',title:translate('portal','due_before'),render:r=>r.before_outstanding==null?'—':money(r.before_outstanding)},{key:'after_outstanding',title:translate('portal','due_after'),render:r=>r.after_outstanding==null?'—':money(r.after_outstanding)},{key:'reason',title:translate('portal','col_reason')},{key:'prepared_by',title:translate('portal','prepared_by')},{key:'reviewed_by',title:translate('portal','reviewed_by')}]}/></section>}
        <section className="panel"><div className="panel-heading"><h2>{translate('portal', 'products_fulfilment')}</h2><span>{translate('portal', 'prices_saved_at_submission')}</span></div>
            <Table rows={order.lines} columns={[
                { key: 'name', title: translate('portal', 'col_product') }, { key: 'quantity', title: translate('portal', 'report_col_ordered') }, { key: 'dispatched', title: translate('portal', 'col_dispatched') },
                { key: 'remaining', title: translate('portal', 'col_remaining'), render: line => line.quantity - line.dispatched },
                { key: 'unit_price', title: translate('portal', 'unit_price'), render: line => money(line.unit_price) }, { key: 'total', title: translate('portal', 'col_total'), render: line => money(line.total) },
            ]} />
        </section>
        <div className="two-column">
            <section className="panel"><h2>{translate('portal', 'payment_claims')}</h2>
                <Table rows={claims} columns={[
                    { key: 'reference', title: translate('portal', 'col_reference') }, { key: 'channel', title: translate('portal', 'payment_method') }, { key: 'amount', title: translate('portal', 'col_amount'), render: claim => money(claim.amount) },
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
                        <b>{shipment.courier} · {shipment.tracking}</b><p>{date(shipment.created_at)} · {shipment.eta ? `${translate('portal', 'expected')} ${date(shipment.eta)}` : translate('portal', 'eta_to_be_confirmed')}</p>
                        <p>{lines.map((line: Row) => `${line.name} × ${line.quantity}`).join(', ')}</p>
                        <Badge status={shipment.status} />
                        {capabilities.includes('inventory') && shipment.status === 'dispatched' && <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/delivered`} fields={[{ name: 'reason', label: translate('portal', 'delivery_confirmation_evidence'), required: true }]} submit={translate('portal', 'confirm_delivery')} />}
                    </div></div>;
                }) : <p className="muted">{translate('portal', 'shipment_empty')}</p>}
            </section>
        </div>
        {order.note && <div className="notice"><b>{translate('portal', 'order_note')}</b><p>{order.note}</p></div>}
        {order.decision_reason && <div className="notice"><b>{translate('portal', 'staff_decision')}</b><p>{order.decision_reason}</p></div>}
        {owner && open && outstanding !== '0.00' && <section className="panel narrow-panel">
            <h2>{translate('portal', 'submit_payment_reference')}</h2><p className="muted">{translate('portal', 'submit_payment_reference_hint')}</p>
            <ActionForm action={`/commands/orders/${order.id}/payments`} fields={[
                { name: 'channel', label: translate('portal', 'payment_method'), required: true, options: [
                    { value: 'Bank', label: translate('portal', 'pay_channel_bank') },
                    { value: 'bKash', label: translate('portal', 'pay_channel_bkash') },
                    { value: 'Nagad', label: translate('portal', 'pay_channel_nagad') },
                    { value: 'Rocket', label: translate('portal', 'pay_channel_rocket') },
                ] },
                ...(collectionAccounts.length ? [{name:'payment_account_id',label:translate('portal','company_collection_account'),required:true,options:collectionAccounts.map((a:Row)=>({value:String(a.id),label:`${a.channel} · ${a.holder} · ${a.account_number}`}))}] : []),
                { name: 'reference', label: translate('portal', 'transaction_reference'), required: true }, { name: 'amount', label: translate('portal', 'claimed_amount_bdt'), type: 'number', required: true },
            ]} submit={translate('portal', 'submit_for_verification')} />
        </section>}
        {capabilities.includes('order_review') && ['submitted', 'held'].includes(order.status) && <section className="panel narrow-panel">
            <h2>{translate('portal', 'review_this_order')}</h2><p className="muted">{translate('portal', 'review_this_order_hint')}</p>{replacementSlots.length>0&&<div className="notice"><p>{translate('portal', 'replacement_hint')}</p><form method="get"><label>{translate('portal', 'find_replacement_partners')}<input name="replacement_q" type="search"/></label><button className="button small">{translate('portal', 'find_partners')}</button></form></div>}
            <ActionForm action={`/commands/orders/${order.id}/decision`} fields={[
                { name: 'status', label: translate('portal', 'decision'), required: true, options: ['approved', 'held', 'rejected'].map(value => ({ value, label: label(value) })) },
                ...(capabilities.includes('finance') ? [{ name: 'allow_credit', label: translate('portal', 'credit_authorization'), value: '0', options: [{ value: '0', label: translate('portal', 'full_payment_required') }, { value: '1', label: translate('portal', 'authorize_credit') }] }] : []),
                ...replacementSlots.map((slot:Row)=>({name:`referral_replacement_${slot.generation}`,label:translate('portal','replacement_for_generation',{generation:String(slot.generation),name:String(slot.name)}),options:replacementCandidates.map((person:Row)=>({value:String(person.id),label:`${person.name} · ${person.dealer_code??translate('portal','col_partner')}`}))})),
                { name: 'reason', label: translate('portal', 'decision_reason'), type: 'textarea', required: true },
            ]} submit={translate('portal', 'record_decision')} />
        </section>}
        {capabilities.includes('inventory') && (staffActions?.inventory?.edit??true) && ['approved', 'partially_dispatched'].includes(order.status) && <section className="panel"><h2>{translate('portal', 'dispatch_available_items')}</h2><DispatchForm key={`${order.id}-${shipments.length}`} order={order} /></section>}
        <section className="panel"><h2>{translate('portal', 'order_activity')}</h2>
            {events.length ? events.map(event => {
                const metadata = typeof event.metadata === 'string' ? JSON.parse(event.metadata) : event.metadata;
                return <div className="timeline-item" key={event.id}><i /><div><b>{label(event.action.replaceAll('.', ' '))}</b><p>{date(event.created_at)}{metadata?.reason ? ` · ${metadata.reason}` : ''}</p></div></div>;
            }) : <p className="muted">{translate('portal', 'order_activity_empty')}</p>}
        </section>
    </Shell>;
}

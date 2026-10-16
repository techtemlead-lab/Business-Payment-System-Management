import { Head, Link, useForm, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import { Row, Table, Badge, money, date, label, ActionForm } from '../components/UI';

function DispatchForm({ order }: { order: Row }) {
    const form = useForm({ courier: '', tracking: '', eta: '', idempotency_key: crypto.randomUUID(),
        items: order.lines.filter((line: Row) => line.dispatched < line.quantity).map((line: Row) => ({ line_id: line.id, quantity: line.quantity - line.dispatched })) });
    return <form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.transform(data => ({ ...data, items: data.items.filter((item: Row) => Number(item.quantity) > 0) }));
        form.post(`/commands/orders/${order.id}/dispatch`, { onSuccess: () => form.setData('idempotency_key', crypto.randomUUID()) });
    }}>
        <p className="muted">Enter zero to leave an item for a later shipment. Stock and remaining quantities are checked on the server.</p>
        <div className="form-grid">
            <label>Courier<input required value={form.data.courier} onChange={event => form.setData('courier', event.target.value)} /></label>
            <label>Tracking reference<input required value={form.data.tracking} onChange={event => form.setData('tracking', event.target.value)} /></label>
            <label>Estimated delivery<input type="date" value={form.data.eta} onChange={event => form.setData('eta', event.target.value)} /></label>
        </div>
        {form.data.items.map((item: Row, index: number) => <label key={item.line_id}>
            {order.lines.find((line: Row) => line.id === item.line_id)?.name}
            <input type="number" min="0" step="1" value={item.quantity} onChange={event => form.setData('items', form.data.items.map((other: Row, otherIndex: number) => index === otherIndex ? { ...other, quantity: Number(event.target.value) } : other))} />
        </label>)}
        {Object.keys(form.errors).length > 0 && <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
        <button className="button dark" disabled={form.processing}>Record dispatch</button>
    </form>;
}

export default function OrderDetail({ order, claims, shipments, events = [], outstanding, backUrl }: { order: Row; claims: Row[]; shipments: Row[]; events: Row[]; outstanding: string; backUrl?:string }) {
    const { auth,portalBase:base,collectionAccounts=[] } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const owner = auth.user.id === order.user_id && capabilities.length === 0;
    const open = !['rejected', 'cancelled'].includes(order.status);
    return <Shell section="order">
        <Head title={order.number} />
        <Link className="text-link" href={backUrl??base+'/orders'}>← All orders</Link>
        <div className="order-title"><div><h2>{order.number}</h2><p>{order.user.name} · {order.user.dealer_code} · {date(order.created_at)}</p></div><Badge status={order.status} /></div>
        <div className="metric-grid three">
            <div className="metric"><span>Order total</span><strong>{money(order.total)}</strong></div>
            <div className="metric"><span>Verified payment</span><strong>{money(order.paid)}</strong></div>
            <div className="metric"><span>Remaining payment</span><strong>{money(outstanding)}</strong><small>Only approved orders contribute to financial dues.</small></div>
        </div>
        <section className="panel"><div className="panel-heading"><h2>Products & fulfilment</h2><span>Prices saved at submission</span></div>
            <Table rows={order.lines} columns={[
                { key: 'name', title: 'Product' }, { key: 'quantity', title: 'Ordered' }, { key: 'dispatched', title: 'Dispatched' },
                { key: 'remaining', title: 'Remaining', render: line => line.quantity - line.dispatched },
                { key: 'unit_price', title: 'Unit price', render: line => money(line.unit_price) }, { key: 'total', title: 'Total', render: line => money(line.total) },
            ]} />
        </section>
        <div className="two-column">
            <section className="panel"><h2>Payment claims</h2>
                <Table rows={claims} columns={[
                    { key: 'reference', title: 'Reference' }, { key: 'channel', title: 'Method' }, { key: 'amount', title: 'Amount', render: claim => money(claim.amount) },
                    { key: 'status', title: 'Status', render: claim => <Badge status={claim.status} /> }, { key: 'reason', title: 'Review note' },
                ]} />
                <p className="muted">A submitted reference is not a verified payment.</p>
                {capabilities.includes('finance') && claims.filter(claim => claim.status === 'pending').map(claim => <div className="panel" key={claim.id}>
                    <h3>Review {claim.reference}</h3>
                    <ActionForm action={`/commands/claims/${claim.id}/verify`} fields={[
                        { name: 'approve', label: 'Decision', required: true, options: [{ value: '1', label: 'Verify received payment' }, { value: '0', label: 'Reject claim' }] },
                        { name: 'reason', label: 'Verification evidence / reason', type: 'textarea', required: true },
                    ]} submit="Record payment review" />
                </div>)}
            </section>
            <section className="panel"><h2>Delivery timeline</h2>
                {shipments.length ? shipments.map(shipment => {
                    const lines = typeof shipment.lines === 'string' ? JSON.parse(shipment.lines) : shipment.lines;
                    return <div className="timeline-item" key={shipment.id}><i /><div>
                        <b>{shipment.courier} · {shipment.tracking}</b><p>{date(shipment.created_at)} · Expected {shipment.eta ? date(shipment.eta) : 'to be confirmed'}</p>
                        <p>{lines.map((line: Row) => `${line.name} × ${line.quantity}`).join(', ')}</p>
                        <Badge status={shipment.status} />
                        {capabilities.includes('inventory') && shipment.status === 'dispatched' && <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/delivered`} fields={[{ name: 'reason', label: 'Delivery confirmation evidence', required: true }]} submit="Confirm delivery" />}
                    </div></div>;
                }) : <p className="muted">Shipment information will appear after dispatch.</p>}
            </section>
        </div>
        {order.note && <div className="notice"><b>Order note</b><p>{order.note}</p></div>}
        {order.decision_reason && <div className="notice"><b>Staff decision</b><p>{order.decision_reason}</p></div>}
        {owner && open && outstanding !== '0.00' && <section className="panel narrow-panel">
            <h2>Submit another payment reference</h2><p className="muted">Submit a payment made against this order. Company staff will verify receipt separately.</p>
            <ActionForm action={`/commands/orders/${order.id}/payments`} fields={[
                { name: 'channel', label: 'Payment method', required: true, options: ['Bank', 'bKash', 'Nagad', 'Rocket'].map(value => ({ value, label: value })) },
                ...(collectionAccounts.length ? [{name:'payment_account_id',label:'Company collection account',required:true,options:collectionAccounts.map((a:Row)=>({value:String(a.id),label:`${a.channel} · ${a.holder} · ${a.account_number}`}))}] : []),
                { name: 'reference', label: 'Transaction reference', required: true }, { name: 'amount', label: 'Claimed amount (BDT)', type: 'number', required: true },
            ]} submit="Submit for verification" />
        </section>}
        {capabilities.includes('order_review') && ['submitted', 'held'].includes(order.status) && <section className="panel narrow-panel">
            <h2>Review this order</h2><p className="muted">Approval is separate from payment and delivery. Only Finance may explicitly authorize an unpaid credit sale.</p>
            <ActionForm action={`/commands/orders/${order.id}/decision`} fields={[
                { name: 'status', label: 'Decision', required: true, options: ['approved', 'held', 'rejected'].map(value => ({ value, label: label(value) })) },
                ...(capabilities.includes('finance') ? [{ name: 'allow_credit', label: 'Credit authorization', value: '0', options: [{ value: '0', label: 'Full verified payment required' }, { value: '1', label: 'I authorize the unpaid amount as credit' }] }] : []),
                { name: 'reason', label: 'Decision / credit authorization reason', type: 'textarea', required: true },
            ]} submit="Record decision" />
        </section>}
        {capabilities.includes('inventory') && ['approved', 'partially_dispatched'].includes(order.status) && <section className="panel"><h2>Dispatch available items</h2><DispatchForm key={`${order.id}-${shipments.length}`} order={order} /></section>}
        <section className="panel"><h2>Order activity</h2>
            {events.length ? events.map(event => {
                const metadata = typeof event.metadata === 'string' ? JSON.parse(event.metadata) : event.metadata;
                return <div className="timeline-item" key={event.id}><i /><div><b>{label(event.action.replaceAll('.', ' '))}</b><p>{date(event.created_at)}{metadata?.reason ? ` · ${metadata.reason}` : ''}</p></div></div>;
            }) : <p className="muted">Activity recording starts with new actions in this application.</p>}
        </section>
    </Shell>;
}

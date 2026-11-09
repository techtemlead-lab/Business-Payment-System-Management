import { useState } from 'react';
import { Row, ActionForm, dateTime } from './UI';
import { useT } from '../shared/i18n/t';
import { courierCheckedText } from './courierCheckedText';

const STEPS = ['waiting_pickup', 'picked_up', 'in_transit', 'delivered'];
const ALL_STEPS = [...STEPS, 'returned'];

type Props = {
    order: Row;
    shipments: Row[];
    events: Row[];
    /** Couriers switched on in Integrations; empty for dealers. */
    courierChoices: { key: string; label: string }[];
    /** Staff who may change the courier, retry a booking or mark a pickup. */
    canManage: boolean;
};

function parse(value: unknown): Row {
    if (typeof value === 'string') {
        try {
            return JSON.parse(value) ?? {};
        } catch {
            return {};
        }
    }
    return (value as Row) ?? {};
}

function CopyButton({ text }: { text: string }) {
    const translate = useT();
    const [copied, setCopied] = useState(false);
    return <button type="button" className="button small" onClick={async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    }}>{copied ? translate('courier', 'copied') : translate('courier', 'copy')}</button>;
}

/** One courier parcel: the steps so far, the tracking number to copy, and the courier's own page. */
function ParcelTracking({ order, shipment, canManage }: { order: Row; shipment: Row; canManage: boolean }) {
    const translate = useT();
    const timeline: Row[] = Array.isArray(shipment.courier_timeline) ? shipment.courier_timeline : [];
    const reached = new Map<string, string>(timeline.map((entry: Row) => [String(entry.status), String(entry.at)]));
    const returned = shipment.courier_status === 'returned';
    const steps = returned ? [...STEPS.slice(0, 3), 'returned'] : STEPS;
    const current = shipment.courier_status ? steps.indexOf(shipment.courier_status) : -1;
    const booking = shipment.courier_booking_status;
    const lastUpdate = shipment.courier_status_at ?? (timeline.length ? timeline[timeline.length - 1].at : null);

    return <div className="timeline-item" data-testid="courier-parcel"><i /><div>
        <b>{shipment.courier}</b>
        {['pending', 'booking'].includes(booking) && <p className="muted">{translate('courier', 'booking_pending')}</p>}
        {booking === 'failed' && <p className="muted" role="status">{canManage && shipment.courier_booking_error
            ? translate('courier', 'booking_failed_staff', { message: String(shipment.courier_booking_error) })
            : translate('courier', 'booking_failed')}</p>}
        {['booked', 'manual'].includes(booking) && <>
            <ol className="ops-row is-wrap" aria-label={translate('courier', 'tracking_title')}>
                {steps.map((step, index) => <li key={step} className={`hub-pill ${index <= current ? 'tone-ok' : ''}`} aria-current={index === current ? 'step' : undefined}>
                    {translate('courier', `status_${step}`)}{reached.get(step) ? ` · ${dateTime(reached.get(step))}` : ''}
                </li>)}
            </ol>
            {shipment.tracking && <p className="ops-row is-wrap">
                <span>{translate('courier', 'tracking_number')}: <b>{shipment.tracking}</b></span>
                <CopyButton text={String(shipment.tracking)} />
                {shipment.tracking_url && <a className="button small" href={shipment.tracking_url} target="_blank" rel="noopener noreferrer">{translate('courier', 'open_courier_page')}</a>}
            </p>}
            {shipment.courier_status_raw && <p className="muted">{translate('courier', 'courier_said', { text: String(shipment.courier_status_raw) })}</p>}
            {lastUpdate && <p className="muted">{translate('courier', 'last_update')}: {dateTime(lastUpdate)}</p>}
            {shipment.courier_answered_at
                ? <p className="muted" data-testid="courier-last-checked">{translate('courier', 'last_checked')}: {courierCheckedText(Number(shipment.courier_answered_minutes_ago), String(shipment.courier_answered_at), translate, dateTime)}</p>
                : booking === 'booked' && shipment.status === 'dispatched' && !['delivered', 'returned'].includes(shipment.courier_status) && <p className="muted" data-testid="courier-last-checked">{translate('courier', 'last_checked')}: {translate('courier', 'checked_no_answer_yet')}</p>}
        </>}
        {canManage && booking === 'failed' && shipment.status === 'dispatched' && <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/courier-retry`} fields={[]} submit={translate('courier', 'retry')} />}
        {canManage && booking === 'booked' && shipment.status === 'dispatched' && (!shipment.courier_status || shipment.courier_status === 'waiting_pickup')
            && <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/picked-up`} fields={[]} submit={translate('courier', 'mark_picked_up')} />}
        {canManage && booking === 'manual' && shipment.status === 'dispatched' && !['delivered', 'returned'].includes(shipment.courier_status) && <>
            <p className="muted">{translate('courier_more', 'manual_hint')}</p>
            <ActionForm action={`/commands/orders/${order.id}/shipments/${shipment.id}/manual-update`} fields={[
                { name: 'status', label: translate('courier_more', 'manual_status_label'), options: ALL_STEPS.slice(ALL_STEPS.indexOf(shipment.courier_status ?? 'waiting_pickup') + 1).map(step => ({ value: step, label: translate('courier', `status_${step}`) })) },
                { name: 'tracking', label: translate('courier_more', 'manual_tracking_label') },
            ]} submit={translate('courier_more', 'manual_submit')} />
        </>}
    </div></div>;
}

/**
 * Courier panel on the order page. Dealers follow their parcel (steps, tracking number,
 * the courier's own page) and see any courier change with its note. Staff can also change
 * the courier, try a refused booking again, or mark a parcel picked up.
 */
export default function CourierTrackingPanel({ order, shipments, events, courierChoices, canManage }: Props) {
    const translate = useT();
    const courierShipments = shipments.filter(shipment => shipment.courier_provider);
    const changes: Row[] = events.filter(event => event.action === 'order.courier_changed').map(event => ({ id: event.id, at: event.created_at, ...parse(event.metadata) }));
    const latestCourierShipment = courierShipments[courierShipments.length - 1];
    const canChange = canManage && (latestCourierShipment
        ? ['pending', 'failed'].includes(latestCourierShipment.courier_booking_status) && latestCourierShipment.status === 'dispatched'
        :['submitted', 'held', 'approved'].includes(order.status) && shipments.length === 0);
    const current = latestCourierShipment?.courier_provider ?? order.courier_choice;
    const others = courierChoices.filter(choice => choice.key !== current);
    /** Dealers get no courier list, so their chosen courier's name comes from the shared provider names. */
    const providerName = (provider: string): string => {
        const key = `si_provider_${provider}`;
        const name = translate('portal', key);

        return name !== key ? name : provider.charAt(0).toUpperCase() + provider.slice(1);
    };
    const chosenLabel = latestCourierShipment?.courier
        ?? courierChoices.find(choice => choice.key === order.courier_choice)?.label
        ?? (order.courier_choice ? providerName(String(order.courier_choice)) : translate('courier', 'company_decides'));

    if (!order.courier_choice && courierShipments.length === 0 && changes.length === 0) {
        return null;
    }

    return <section className="panel" data-testid="courier-tracking">
        <div className="panel-heading"><h2>{translate('courier', 'tracking_title')}</h2><span>{translate('courier', 'chosen_courier')}: {chosenLabel}</span></div>
        {changes.map(change => <div className="notice" key={change.id}>
            <b>{translate('courier', 'changed_from_to', { from: String(change.from_label ?? change.from ?? ''), to: String(change.to_label ?? change.to ?? '') })}</b>
            {change.note && <p>{translate('courier', 'changed_note', { note: String(change.note) })}</p>}
            <p className="muted">{dateTime(change.at)}</p>
        </div>)}
        {courierShipments.length === 0 && order.courier_choice && ['submitted', 'held'].includes(order.status) && <p className="muted">{translate('courier', 'waiting_for_booking')}</p>}
        {courierShipments.map(shipment => <ParcelTracking key={shipment.id} order={order} shipment={shipment} canManage={canManage} />)}
        {canChange && <div className="panel narrow-panel">
            <h3>{translate('courier', 'change_title')}</h3>
            <p className="muted">{translate('courier', 'change_hint')}</p>
            {others.length ? <ActionForm action={`/commands/orders/${order.id}/courier`} fields={[
                { name: 'courier', label: translate('courier', 'change_label'), required: true, options: others.map(choice => ({ value: choice.key, label: choice.label })) },
                { name: 'note', label: translate('courier', 'change_note_label'), type: 'textarea', hint: translate('courier', 'change_note_hint') },
            ]} submit={translate('courier', 'change_submit')} /> : <p className="muted">{translate('courier', 'no_other_courier')}</p>}
        </div>}
    </section>;
}

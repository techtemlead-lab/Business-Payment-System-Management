import { Head, Link, useForm } from '@inertiajs/react';
import Shell from '../components/Shell';
import { Row, FormErrors, number } from '../components/UI';
import OrderNumber from '../components/OrderNumber';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { randomUuid } from '../shared/randomUuid';

type Courier = { key: string; label: string; manual: boolean; api_ready: boolean | null; area_missing: boolean };
type BranchStock = { branch_id: number; name: string; code: string; products: Record<string, number>; can_send_all: boolean };

/** Units of a line still to send: not sent and not closed by a cancel. */
const unsentOf = (line: Row) => Math.max(0, Number(line.quantity) - Number(line.dispatched) - Number(line.closed ?? 0));

/**
 * The page staff land on right after approving an order. The dealer's courier is already ticked;
 * staff keep it or tick another, then press Next. A courier with a booking system receives the
 * order by itself, so nothing else has to be typed; a manual courier asks for its tracking number.
 */
export default function OrderCourier({ order, receiver, couriers, dealerChoice, stockByBranch }: {
    order: Row; receiver: { name: string | null; phone: string | null; address: string };
    couriers: Courier[]; dealerChoice: string | null; stockByBranch: BranchStock[];
}) {
    const translate = useT();
    const usable = (courier: Courier) => courier.manual || courier.api_ready === true;
    const firstChoice = couriers.find(courier => courier.key === dealerChoice && usable(courier)) ?? null;
    const note = useReasonField('order.courier_change');
    const firstBranch = stockByBranch.find(branch => branch.branch_id === order.fulfillment_branch_id) ?? stockByBranch.find(branch => branch.can_send_all) ?? stockByBranch[0];
    const form = useForm({
        courier_provider: firstChoice?.key ?? '',
        tracking: '',
        note: '',
        branch_id: firstBranch ? String(firstBranch.branch_id) : '',
        idempotency_key: randomUuid(),
    });
    const chosen = couriers.find(courier => courier.key === form.data.courier_provider) ?? null;
    const changed = chosen !== null && chosen.key !== (dealerChoice ?? '');

    return <Shell section="order">
        <Head title={translate('courier', 'pick_title')} />
        <Link className="text-link" href={`/orders/${order.id}`}>{translate('courier', 'pick_later')}</Link>
        <div className="order-title"><div>
            <h2>{translate('courier', 'pick_title')} · <OrderNumber number={String(order.number)} /></h2>
            <p>{translate('courier', 'pick_intro')}</p>
        </div></div>

        <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.transform(data => ({ ...data, branch_id: data.branch_id ? Number(data.branch_id) : null, note: changed && note.shown ? data.note || null : null,
                tracking: chosen?.manual ? data.tracking : null }));
            // A refusal (for example not enough stock) stays on this page, beside the buttons.
            form.post(`/commands/orders/${order.id}/courier-ship`, { preserveScroll: true });
        }}>
            <FormErrors errors={form.errors} />

            <section className="panel">
                <div className="courier-pick-summary">
                    <div><b>{translate('courier', 'pick_receiver')}</b>
                        <p>{receiver.name}{receiver.phone ? ` · ${receiver.phone}` : ''}</p>
                        <p className="muted">{receiver.address}</p></div>
                    <div><b>{translate('courier', 'pick_items')}</b>
                        <ul className="plain-list">{order.lines.map((line: Row) => <li key={line.id}>{line.name} × {number(unsentOf(line))}</li>)}</ul></div>
                </div>
            </section>

            <section className="panel">
                <h2>{translate('courier', 'pick_courier_heading')}</h2>
                <p className="notice" role="status">{dealerChoice !== null && firstChoice !== null
                    ? translate('courier', 'pick_dealer_choice', { courier: firstChoice.label })
                    : translate('courier', 'pick_dealer_left_it')}</p>
                <p className="muted">{translate('courier', 'pick_charge_hint')}</p>
                {couriers.length === 0
                    ? <p className="notice">{translate('courier', 'pick_no_couriers')}</p>
                    : <div className="courier-pick" role="radiogroup" aria-label={translate('courier', 'pick_courier_heading')}>
                        {couriers.map(courier => <label key={courier.key} className={`courier-pick-card${form.data.courier_provider === courier.key ? ' is-chosen' : ''}${usable(courier) ? '' : ' is-off'}`}>
                            <input type="radio" name="courier_provider" value={courier.key} disabled={!usable(courier)}
                                checked={form.data.courier_provider === courier.key}
                                onChange={() => form.setData('courier_provider', courier.key)} />
                            <span>
                                <span className="courier-pick-name">{courier.label}</span>
                                <span className="courier-pick-tags">
                                    {courier.key === dealerChoice && <span className="badge approved">{translate('courier', 'pick_badge_dealer')}</span>}
                                    {courier.manual ? <span className="badge">{translate('courier', 'pick_badge_manual')}</span>
                                        : courier.api_ready ? <span className="badge dispatched">{translate('courier', 'pick_badge_api')}</span> : null}
                                </span>
                                {!courier.manual && courier.api_ready === false && <small className="courier-pick-warn">{translate('courier', 'pick_not_connected')}</small>}
                                {usable(courier) && courier.area_missing && <small className="courier-pick-warn">{translate('courier', 'pick_area_missing')}</small>}
                            </span>
                        </label>)}
                    </div>}

                {chosen?.manual && <label>{translate('courier', 'pick_tracking_label')}
                    <input required maxLength={100} value={form.data.tracking} onChange={event => form.setData('tracking', event.target.value)} /></label>}
                {changed && note.shown && <label>{note.label(translate('courier', 'pick_note_label'))}
                    <textarea rows={2} maxLength={500} required={note.required} value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></label>}
                {stockByBranch.length > 1 && <label>{translate('orders_stock', 'dispatch_branch')}
                    <select value={form.data.branch_id} onChange={event => form.setData('branch_id', event.target.value)}>
                        {stockByBranch.map(branch => <option key={branch.branch_id} value={String(branch.branch_id)}>
                            {translate('orders_stock', 'dispatch_branch_stock', { branch: branch.name, units: number(order.lines.reduce((sum: number, line: Row) => sum + Math.min(unsentOf(line), Number(branch.products[String(line.product_id)] ?? 0)), 0)) })}
                        </option>)}
                    </select></label>}
            </section>

            <div className="courier-pick-actions">
                <button className="button dark" disabled={form.processing || chosen === null}>
                    {form.processing ? translate('courier', 'pick_sending') : translate('courier', chosen?.manual ? 'pick_next_manual' : 'pick_next')}
                </button>
                <Link className="button" href={`/orders/${order.id}`}>{translate('courier', 'pick_later')}</Link>
            </div>
        </form>
    </Shell>;
}

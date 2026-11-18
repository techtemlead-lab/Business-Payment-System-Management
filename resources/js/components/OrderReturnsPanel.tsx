import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Row, money, number, date, FormErrors } from './UI';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { amountInWords } from '../shared/orderAmountWords';

type ReturnLine = { line_id: number; name: string; is_free: boolean; unit_price: string; can_cancel: number; can_return: number; closed: number; returned: number };
type Branch = { id: number; name: string; code: string };
export type ReturnsData = {
    open: null | (Row & { id: number; kind: string; status: string; lines: { line_id: number; quantity: number }[]; reason: string; refund_amount: string | null; refund_method: string | null; first_approved_name: string | null; can_confirm: boolean; can_approve: boolean; can_withdraw: boolean });
    lines: ReturnLine[];
    refundable_now: string;
    paid_left: string;
    paid_from_account_left: string;
    refunds: Row[];
    history: Row[];
    partner_can_cancel: boolean;
    partner_can_return: boolean;
    staff_can_start: boolean;
    can_refund: boolean;
    acts_alone: boolean;
    whole_order_only: boolean;
    branches: Branch[];
    default_branch_id: number | null;
};

const cents = (value: string | number) => Math.round(Number(value || 0) * 100);

/** Highest refund for the units chosen: money not tied to goods the partner keeps, never above what is left of the payment. */
function maxRefund(data: ReturnsData, kind: string, units: Record<number, number>): number {
    const goods = kind === 'refund' ? 0 : data.lines.reduce((sum, line) => sum + (line.is_free ? 0 : cents(line.unit_price) * (units[line.line_id] ?? 0)), 0);

    return Math.min(cents(data.paid_left), cents(data.refundable_now) + goods);
}

function LineInputs({ lines, field, units, onChange }: { lines: ReturnLine[]; field: 'can_cancel' | 'can_return'; units: Record<number, number>; onChange: (next: Record<number, number>) => void }) {
    const translate = useT();
    const shown = lines.filter(line => line[field] > 0);

    return <div className="form-grid">{shown.map(line => <label key={line.line_id}>
        {translate('orders_stock', 'qty_label', { product: line.name, max: number(line[field]) })}
        <input type="number" min={0} max={line[field]} step={1} value={units[line.line_id] ?? 0}
            onChange={event => onChange({ ...units, [line.line_id]: Math.max(0, Math.min(line[field], Math.floor(Number(event.target.value) || 0))) })} />
    </label>)}</div>;
}

/** The second step: says in words what will happen before anything is sent. */
function ConfirmBox({ orderNumber, amount, method, unitCount, kind, needsSecond, processing, onYes, onBack }: { orderNumber: string; amount: number; method: string; unitCount: number; kind: string; needsSecond: boolean; processing: boolean; onYes: () => void; onBack: () => void }) {
    const translate = useT();
    const taka = (amount / 100).toFixed(2);

    return <div className="notice" role="alertdialog" aria-labelledby="return-confirm-title" data-testid="return-confirm">
        <b id="return-confirm-title">{translate('orders_stock', 'confirm_title')}</b>
        <p>
            {amount > 0
                ? translate('orders_stock', 'confirm_sentence_money', { number: orderNumber, amount: money(taka).replace('৳', ''), words: amountInWords(taka), method: translate('orders_stock', `method_${method}`) })
                : translate('orders_stock', 'confirm_sentence_no_money', { number: orderNumber })}
            {unitCount > 0 && translate('orders_stock', 'confirm_sentence_units', { count: number(unitCount), kind: translate('orders_stock', `kind_${kind}`) })}
        </p>
        {needsSecond && amount > 0 && <p className="muted">{translate('orders_stock', 'confirm_needs_second')}</p>}
        <div className="ops-row is-wrap">
            <button type="button" className="button dark" disabled={processing} onClick={onYes}>{translate('orders_stock', 'confirm_yes')}</button>
            <button type="button" className="button" disabled={processing} onClick={onBack}>{translate('orders_stock', 'confirm_back')}</button>
        </div>
    </div>;
}

/** Refund amount (never filled in), method and, for returns, the branch. */
function MoneyFields({ data, form, kind, maxCents }: { data: ReturnsData; form: any; kind: string; maxCents: number }) {
    const translate = useT();

    return <>
        <label>{translate('orders_stock', 'refund_amount')}
            <input inputMode="decimal" autoComplete="off" value={form.data.refund_amount} placeholder="0" aria-describedby="refund-amount-hint"
                onChange={event => form.setData('refund_amount', event.target.value.replace(/[^\d.]/g, ''))} />
            <small id="refund-amount-hint" className="field-hint">{translate('orders_stock', 'refund_amount_hint', { max: money((maxCents / 100).toFixed(2)).replace('৳', '') })}</small>
        </label>
        <fieldset className="plain-fieldset">
            <legend>{translate('orders_stock', 'refund_method')}</legend>
            {(['cash', 'account'] as const).map(method => <label key={method} className="inline-choice">
                <input type="radio" name="refund_method" value={method} checked={form.data.refund_method === method} onChange={() => form.setData('refund_method', method)} />
                {translate('orders_stock', `method_${method}`)}
            </label>)}
            {cents(data.paid_from_account_left) > 0 && <small className="field-hint">{translate('orders_stock', 'refund_method_hint', { account: money(data.paid_from_account_left).replace('৳', '') })}</small>}
        </fieldset>
        {kind === 'return' && data.branches.length > 0 && <label>{translate('orders_stock', 'branch_label')}
            <select value={form.data.branch_id} onChange={event => form.setData('branch_id', event.target.value)}>
                {data.branches.map(branch => <option key={branch.id} value={String(branch.id)}>{branch.name}</option>)}
            </select>
        </label>}
    </>;
}

/** Staff approve a partner's request, or start a cancel, return or refund themselves. */
function StaffForm({ order, data, request }: { order: Row; data: ReturnsData; request?: ReturnsData['open'] }) {
    const translate = useT();
    // The office note follows the owner's switch for refunds (compulsory, optional or off), as the server checks it.
    const note = useReasonField('order.refund');
    const startable = order.status === 'partially_dispatched' ? 'cancel' : data.lines.some(line => line.can_return > 0) ? 'return' : data.lines.some(line => line.can_cancel > 0) ? 'cancel' : 'refund';
    const [kind, setKind] = useState<string>(request?.kind ?? startable);
    const requestUnits = Object.fromEntries((request?.lines ?? []).map(line => [line.line_id, line.quantity]));
    const allUnsent = Object.fromEntries(data.lines.map(line => [line.line_id, line.can_cancel]));
    const [units, setUnits] = useState<Record<number, number>>(request ? requestUnits : kind === 'cancel' ? allUnsent : {});
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ refund_amount: '', refund_method: 'cash', branch_id: String(data.default_branch_id ?? data.branches[0]?.id ?? ''), reason: '' });
    const maxCents = maxRefund(data, kind, units);
    const amount = cents(form.data.refund_amount);
    const unitCount = kind === 'refund' ? 0 : Object.values(units).reduce((sum, value) => sum + value, 0);
    const fixedUnits = Boolean(request) || (kind === 'cancel' && data.whole_order_only);
    const send = () => {
        const url = request ? `/commands/order-returns/${request.id}/approve` : `/commands/orders/${order.id}/returns/start`;
        form.transform(values => ({
            ...values, confirmed: '1', kind,
            lines: request || kind === 'refund' ? [] : Object.entries(units).map(([line_id, quantity]) => ({ line_id: Number(line_id), quantity })),
            branch_id: kind === 'return' ? values.branch_id || null : null,
        }));
        form.post(url, { preserveScroll: true, onFinish: () => setConfirming(false) });
    };

    return <form className="action-form" onSubmit={event => { event.preventDefault(); setConfirming(true); }}>
        {!request && <label>{translate('orders_stock', 'start_kind')}
            <select value={kind} onChange={event => { setKind(event.target.value); setUnits(event.target.value === 'cancel' ? allUnsent : {}); setConfirming(false); }}>
                {data.lines.some(line => line.can_cancel > 0) && <option value="cancel">{translate('orders_stock', order.status === 'partially_dispatched' ? 'start_kind_close_rest' : 'start_kind_cancel')}</option>}
                {data.lines.some(line => line.can_return > 0) && <option value="return">{translate('orders_stock', 'start_kind_return')}</option>}
                <option value="refund">{translate('orders_stock', 'start_kind_refund')}</option>
            </select>
        </label>}
        {kind === 'cancel' && data.whole_order_only && <p className="muted">{translate('orders_stock', 'whole_order_only')}</p>}
        {kind !== 'refund' && !fixedUnits && <LineInputs lines={data.lines} field={kind === 'cancel' ? 'can_cancel' : 'can_return'} units={units} onChange={next => { setUnits(next); setConfirming(false); }} />}
        {data.can_refund ? <MoneyFields data={data} form={form} kind={kind} maxCents={maxCents} /> : null}
        {note.shown && <label>{note.label(translate('orders_stock', 'note_label'))}<textarea maxLength={1000} required={note.required} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>}
        <FormErrors errors={form.errors} />
        {confirming
            ? <ConfirmBox orderNumber={String(order.number)} amount={amount} method={form.data.refund_method} unitCount={unitCount} kind={kind}
                needsSecond={!data.acts_alone} processing={form.processing} onYes={send} onBack={() => setConfirming(false)} />
            : <button className="button dark" disabled={form.processing || amount > maxCents || (kind === 'refund' && amount <= 0) || (kind !== 'refund' && unitCount === 0)}>
                {translate('orders_stock', request ? 'approve_submit' : 'start_submit')}
            </button>}
    </form>;
}

function RejectForm({ requestId }: { requestId: number }) {
    const translate = useT();
    const form = useForm({ reason: '' });

    return <form className="action-form" onSubmit={event => { event.preventDefault(); form.post(`/commands/order-returns/${requestId}/reject`, { preserveScroll: true }); }}>
        <label>{translate('orders_stock', 'reject_reason')}<textarea required minLength={3} maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
        <FormErrors errors={form.errors} />
        <button className="button" disabled={form.processing}>{translate('orders_stock', 'reject_submit')}</button>
    </form>;
}

function SecondApproval({ order, request }: { order: Row; request: NonNullable<ReturnsData['open']> }) {
    const translate = useT();
    const note = useReasonField('order.refund');
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ reason: '', confirmed: '1' });
    const amount = cents(request.refund_amount ?? '0');
    const unitCount = request.lines.reduce((sum, line) => sum + line.quantity, 0);

    return <form className="action-form" onSubmit={event => { event.preventDefault(); setConfirming(true); }}>
        <h3>{translate('orders_stock', 'confirm_second_title')}</h3>
        {note.shown && <label>{note.label(translate('orders_stock', 'note_label'))}<textarea maxLength={1000} required={note.required} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>}
        <FormErrors errors={form.errors} />
        {confirming
            ? <ConfirmBox orderNumber={String(order.number)} amount={amount} method={String(request.refund_method ?? 'cash')} unitCount={unitCount} kind={request.kind}
                needsSecond={false} processing={form.processing} onYes={() => form.post(`/commands/order-returns/${request.id}/confirm`, { preserveScroll: true, onFinish: () => setConfirming(false) })} onBack={() => setConfirming(false)} />
            : <button className="button dark" disabled={form.processing}>{translate('orders_stock', 'confirm_second_submit')}</button>}
    </form>;
}

/** The partner asks to cancel (before shipping) or return (after delivery). */
function PartnerForm({ order, data, kind }: { order: Row; data: ReturnsData; kind: 'cancel' | 'return' }) {
    const translate = useT();
    const [units, setUnits] = useState<Record<number, number>>({});
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ reason: '' });
    const paid = cents(order.paid) > 0;
    const unitCount = Object.values(units).reduce((sum, value) => sum + value, 0);
    const send = () => {
        form.transform(values => ({ ...values, kind, lines: kind === 'return' ? Object.entries(units).map(([line_id, quantity]) => ({ line_id: Number(line_id), quantity })) : [] }));
        form.post(`/commands/orders/${order.id}/returns`, { preserveScroll: true, onFinish: () => setConfirming(false) });
    };

    return <section className="panel narrow-panel" data-testid={`partner-${kind}`}>
        <h2>{translate('orders_stock', kind === 'cancel' ? 'partner_cancel_title' : 'partner_return_title')}</h2>
        <p className="muted">{translate('orders_stock', kind === 'return' ? 'partner_return_hint' : paid ? 'partner_cancel_hint_paid' : 'partner_cancel_hint_unpaid')}</p>
        <form className="action-form" onSubmit={event => { event.preventDefault(); setConfirming(true); }}>
            {kind === 'return' && <LineInputs lines={data.lines} field="can_return" units={units} onChange={setUnits} />}
            <label>{translate('orders_stock', 'reason_label')}<textarea required minLength={3} maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            <FormErrors errors={form.errors} />
            {confirming
                ? <div className="notice" role="alertdialog"><b>{translate('orders_stock', 'confirm_title')}</b>
                    <p>{translate('orders_stock', kind === 'cancel' ? 'partner_cancel_title' : 'partner_return_title')} {String(order.number)}</p>
                    <div className="ops-row is-wrap">
                        <button type="button" className="button dark" disabled={form.processing} onClick={send}>{translate('orders_stock', 'confirm_yes')}</button>
                        <button type="button" className="button" onClick={() => setConfirming(false)}>{translate('orders_stock', 'confirm_back')}</button>
                    </div></div>
                : <button className="button" disabled={form.processing || (kind === 'return' && unitCount === 0)}>{translate('orders_stock', kind === 'cancel' ? 'partner_cancel_submit' : 'partner_return_submit')}</button>}
        </form>
    </section>;
}

function WithdrawButton({ requestId }: { requestId: number }) {
    const translate = useT();
    const form = useForm({});

    return <button type="button" className="button small" disabled={form.processing} onClick={() => form.post(`/commands/order-returns/${requestId}/withdraw`, { preserveScroll: true })}>{translate('orders_stock', 'withdraw')}</button>;
}

/** Cancel, return and refund on the order page (OS-01, OS-02, MA-16). */
export default function OrderReturnsPanel({ order, data }: { order: Row; data: ReturnsData | null | undefined }) {
    const translate = useT();
    if (!data) {
        return null;
    }
    const open = data.open;
    const lineName = (lineId: number) => data.lines.find(line => line.line_id === lineId)?.name ?? '';
    const showsStaff = data.staff_can_start || open?.can_approve || open?.can_confirm;

    return <>
        {open && <section className="panel" data-testid="return-open">
            <h2>{translate('orders_stock', 'open_title', { kind: translate('orders_stock', `kind_${open.kind}`) })}</h2>
            <p><b>{translate('orders_stock', 'open_reason')}:</b> {open.reason}</p>
            {open.lines.length > 0 && <p><b>{translate('orders_stock', 'open_lines')}:</b> {open.lines.map(line => `${lineName(line.line_id)} × ${number(line.quantity)}`).join(', ')}</p>}
            {open.status === 'awaiting_second' && open.first_approved_name && <p>{translate('orders_stock', 'open_first', { name: open.first_approved_name, amount: money(open.refund_amount ?? '0').replace('৳', ''), method: translate('orders_stock', `method_${open.refund_method ?? 'cash'}`) })}</p>}
            <p className="muted" role="status">{translate('orders_stock', open.status === 'awaiting_second' ? 'open_waiting_second' : 'open_waiting_office')}</p>
            {open.can_withdraw && <WithdrawButton requestId={open.id} />}
            {open.can_approve && <><h3>{translate('orders_stock', 'approve_title')}</h3><StaffForm key={`approve-${open.id}`} order={order} data={data} request={open} /><h3>{translate('orders_stock', 'reject_title')}</h3><RejectForm requestId={open.id} /></>}
            {open.can_confirm && <><SecondApproval order={order} request={open} /><h3>{translate('orders_stock', 'reject_title')}</h3><RejectForm requestId={open.id} /></>}
        </section>}
        {data.partner_can_cancel && <PartnerForm order={order} data={data} kind="cancel" />}
        {data.partner_can_return && <PartnerForm order={order} data={data} kind="return" />}
        {data.staff_can_start && <details className="panel" data-testid="return-start">
            <summary><h2 className="inline-heading">{translate('orders_stock', 'start_title')}</h2></summary>
            <p className="muted">{translate('orders_stock', 'panel_hint')}</p>
            <StaffForm key={`start-${order.status}-${data.refunds.length}`} order={order} data={data} />
        </details>}
        {(data.refunds.length > 0 || data.history.length > 0) && <section className="panel" data-testid="return-history">
            <h2>{translate('orders_stock', 'history_title')}</h2>
            {data.refunds.map(refund => <div className="timeline-item" key={String(refund.id)}><i /><div>
                <b>{translate('orders_stock', 'refund_row', {
                    amount: money(refund.amount).replace('৳', ''), kind: translate('orders_stock', `kind_${refund.kind}`), date: date(refund.created_at),
                    first: String(refund.refunded_by ?? '—'), second: refund.approved_by ? translate('orders_stock', 'refund_row_second', { name: String(refund.approved_by) }) : '',
                })}</b>
                <p>{translate('orders_stock', 'refund_row_split', { account: money(cents(refund.to_account) / 100 + cents(refund.cash_to_account) / 100).replace('৳', ''), cash: money((cents(refund.amount) - cents(refund.to_account) - cents(refund.cash_to_account)) / 100).replace('৳', '') })}</p>
                {refund.reason && <p className="muted">{refund.reason}</p>}
            </div></div>)}
            {data.history.filter(item => item.status !== 'done').map(item => <div className="timeline-item" key={`r${item.id}`}><i /><div>
                <b>{translate('orders_stock', `kind_${item.kind}`)} · {translate('orders_stock', `state_${item.status}`)}</b>
                <p>{item.decided_at ? date(item.decided_at) : ''}{item.decided_by_name ? ` · ${item.decided_by_name}` : ''}{item.decision_reason ? ` · ${item.decision_reason}` : ''}</p>
            </div></div>)}
        </section>}
        {showsStaff && cents(data.paid_left) === 0 && !open && <p className="muted">{translate('orders_stock', 'nothing_refundable')}</p>}
    </>;
}

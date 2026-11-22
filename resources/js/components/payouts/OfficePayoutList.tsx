import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Banknote, IdCard } from 'lucide-react';
import { FormErrors, money, date } from '../UI';
import { useT } from '../../shared/i18n/t';
import { useReasonFields } from '../../shared/reasonFields';
import { useEscape } from '../../shared/useEscape';
import { AmountWords } from './WithdrawalFacts';

export interface OfficePayoutRow {
    id: string;
    user_name: string;
    dealer_code: string | null;
    phone: string | null;
    amount: string;
    withheld_amount: string | null;
    net_amount: string;
    status: 'requested' | 'approved' | 'paid';
    created_at: string;
    paid_at: string | null;
    payout_date: string | null;
    due: boolean;
    id_hint: string | null;
    blocker: string | null;
    payer_state?: 'second_person' | null;
    waits_for_owner?: boolean;
}

const nowInDhaka = () => new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Dhaka' }).slice(0, 16).replace(' ', 'T');

/**
 * "Office withdrawal" on the payout day: who comes for cash, how much to hand over after tax, what to check
 * on their ID card, and one button to record the handover. Approval stays on the payouts table below.
 */
export default function OfficePayoutList({ rows, total, canProcess }: { rows: OfficePayoutRow[]; total?: string | number; canProcess: boolean }) {
    const translate = useT();
    const [handOver, setHandOver] = useState<OfficePayoutRow | null>(null);
    const ready = rows.filter((row) => row.status === 'approved');

    return (
        <section className="panel office-payouts" data-tour="office-payouts">
            <h2 className="hub-title"><Banknote size={18} aria-hidden="true" /> {translate('office_withdrawal', 'list_title')}</h2>
            <p className="hub-sub">{translate('office_withdrawal', 'list_body')}</p>
            {ready.length > 0 && <p className="hub-hint"><b>{translate('office_withdrawal', 'list_total', { amount: money(total ?? 0), count: String(ready.length) })}</b></p>}
            {rows.length === 0 && <p className="muted">{translate('office_withdrawal', 'list_empty')}</p>}
            {rows.map((row) => (
                <article key={row.id} className="hub-row is-between office-payout-row">
                    <div>
                        <b>{row.user_name}</b> <span className="muted">{[row.dealer_code, row.phone].filter(Boolean).join(' · ')}</span>
                        <small className="cell-sub">{translate('office_withdrawal', 'tax_line', { gross: money(row.amount), tax: money(row.withheld_amount ?? 0) })}</small>
                        {row.id_hint && <small className="cell-sub"><IdCard size={14} aria-hidden="true" /> {row.id_hint}</small>}
                        {row.payout_date && <small className="cell-sub">{translate('office_withdrawal', 'col_payout_day')}: {date(row.payout_date)} · {translate('office_withdrawal', row.due ? 'payout_today' : 'payout_later')}</small>}
                        {row.blocker && <small className="cell-sub field-error" role="alert">{row.blocker}</small>}
                    </div>
                    <div className="hub-row is-end">
                        <span><b>{money(row.net_amount)}</b><AmountWords amount={row.net_amount} /></span>
                        <span className={`badge ${row.status}`}>{translate('office_withdrawal', `status_${row.status}`)}{row.status === 'paid' && row.paid_at ? ` · ${date(row.paid_at)}` : ''}</span>
                        {row.status === 'approved' && row.payer_state === 'second_person' && <small className="muted">{translate('self_approval', 'wait_second_person')}</small>}
                        {row.status === 'approved' && row.payer_state !== 'second_person' && canProcess && (
                            <button type="button" className="button small dark" disabled={Boolean(row.blocker)} onClick={() => setHandOver(row)}>{translate('office_withdrawal', 'hand_over')}</button>
                        )}
                        <a className="text-link" href={`/withdrawals/${row.id}`}>{translate('portal', 'pay_details')}</a>
                    </div>
                </article>
            ))}
            {handOver && <HandOverDialog record={handOver} onClose={() => setHandOver(null)} />}
        </section>
    );
}

/** Records cash handed over at the office: when, who took it (the partner unless changed) and an optional note. */
export function HandOverDialog({ record, onClose }: { record: Pick<OfficePayoutRow, 'id' | 'user_name' | 'dealer_code' | 'net_amount'>; onClose: () => void }) {
    const translate = useT();
    const note = useReasonFields()('money.withdrawal_settle');
    const [paidAt, setPaidAt] = useState(nowInDhaka);
    const [receivedBy, setReceivedBy] = useState(record.user_name);
    const [text, setText] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    useEscape(true, onClose);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        router.post(`/commands/withdrawals/${record.id}/hand-over`, { paid_at: paidAt, received_by: receivedBy, note: text }, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (failed) => setErrors(failed),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="hub-overlay">
            <div className="hub-dialog" role="dialog" aria-modal="true" aria-labelledby="hand-over-title">
                <h3 id="hand-over-title" className="hub-title is-lg">{translate('office_withdrawal', 'hand_over_title')}</h3>
                <p className="hub-sub is-lead">{translate('office_withdrawal', 'hand_over_meta', { name: record.user_name, code: record.dealer_code ?? '', amount: money(record.net_amount) })}</p>
                <AmountWords amount={record.net_amount} />
                <form onSubmit={submit}>
                    <div className="hub-field">
                        <label htmlFor="hand-over-when">{translate('office_withdrawal', 'hand_over_when')}</label>
                        <input id="hand-over-when" type="datetime-local" required value={paidAt} onChange={(event) => setPaidAt(event.target.value)} />
                    </div>
                    <div className="hub-field">
                        <label htmlFor="hand-over-who">{translate('office_withdrawal', 'hand_over_received_by')}</label>
                        <input id="hand-over-who" type="text" maxLength={100} value={receivedBy} onChange={(event) => setReceivedBy(event.target.value)} />
                        <small className="hub-hint">{translate('office_withdrawal', 'hand_over_received_by_hint')}</small>
                    </div>
                    {note.shown && <div className="hub-field is-last">
                        <label htmlFor="hand-over-note">{note.required ? translate('portal', 'pay_fld_notes') : translate('office_withdrawal', 'hand_over_note')}</label>
                        <textarea id="hand-over-note" rows={2} maxLength={1000} required={note.required} value={text} onChange={(event) => setText(event.target.value)} />
                    </div>}
                    <FormErrors errors={errors} />
                    <div className="hub-row is-end">
                        <button type="button" className="button" onClick={onClose}>{translate('portal', 'pay_cancel')}</button>
                        <button type="submit" className="button dark" disabled={processing}>{processing ? translate('portal', 'pay_recording') : translate('office_withdrawal', 'hand_over_confirm')}</button>
                    </div>
                </form>
            </div>
        </div>
    );
}

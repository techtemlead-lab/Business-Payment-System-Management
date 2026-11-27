import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Banknote, IdCard } from 'lucide-react';
import { money, date } from '../UI';
import { useT } from '../../shared/i18n/t';
import { useReasonFields } from '../../shared/reasonFields';
import { AmountWords } from './WithdrawalFacts';
import ConfirmSheet from '../ConfirmSheet';

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
    /** The one-step "money sent, mark paid" (ManualPayoutService::oneStepState). */
    one_step_state?: 'ready' | 'two_people' | null;
    waits_for_owner?: boolean;
}

const nowInDhaka = () => new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Dhaka' }).slice(0, 16).replace(' ', 'T');

/**
 * "Office withdrawal" on the payout day: who comes for cash, how much to hand over after tax, what to check
 * on their ID card, and one button to record the handover. For the owner a request still waiting gets the same
 * button: one press approves it and records the handover (owner, 2026-11-27). Staff approve on the payouts
 * table below, and another finance person hands the cash over.
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
                        {row.status === 'requested' && row.one_step_state === 'ready' && canProcess && (
                            <button type="button" className="button small dark" disabled={Boolean(row.blocker)} onClick={() => setHandOver(row)}>{translate('office_withdrawal', 'hand_over')}</button>
                        )}
                        <a className="text-link" href={`/withdrawals/${row.id}`}>{translate('portal', 'pay_details')}</a>
                    </div>
                </article>
            ))}
            {handOver && <HandOverDialog record={handOver} oneStep={handOver.status === 'requested'} onClose={() => setHandOver(null)} />}
        </section>
    );
}

/**
 * Records cash handed over at the office: when, who took it (the partner unless changed) and an optional note.
 * A confirm sheet (a bottom sheet on a phone) repeats the amount and the name before the same request is sent.
 * With `oneStep` (a request still waiting, the owner only) the same fields go to the one-step "sent, mark paid",
 * which approves the request and records the handover together.
 */
export function HandOverDialog({ record, onClose, oneStep = false }: { record: Pick<OfficePayoutRow, 'id' | 'user_name' | 'dealer_code' | 'net_amount'>; onClose: () => void; oneStep?: boolean }) {
    const translate = useT();
    const note = useReasonFields()('money.withdrawal_settle');
    const [paidAt, setPaidAt] = useState(nowInDhaka);
    const [receivedBy, setReceivedBy] = useState(record.user_name);
    const [text, setText] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const payee = record.dealer_code ? `${record.user_name} (${record.dealer_code})` : record.user_name;

    const submit = () => {
        setProcessing(true);
        router.post(`/commands/withdrawals/${record.id}/${oneStep ? 'sent-paid' : 'hand-over'}`, { paid_at: paidAt, received_by: receivedBy, note: text }, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (failed) => setErrors(failed),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <ConfirmSheet
            title={translate('office_withdrawal', 'hand_over_title')}
            sentence={oneStep ? translate('payouts', 'one_step_cash_sentence', { name: payee }) : translate('office_withdrawal', 'hand_over_sentence', { name: payee })}
            amount={record.net_amount}
            facts={[{ label: translate('payouts', 'cs_fact_payee'), value: payee }]}
            confirmLabel={translate('office_withdrawal', 'hand_over_confirm')}
            cancelLabel={translate('portal', 'pay_cancel')}
            pending={processing}
            errors={errors}
            onCancel={onClose}
            onConfirm={submit}
        >
            <label htmlFor="hand-over-when">{translate('office_withdrawal', 'hand_over_when')}
                <input id="hand-over-when" type="datetime-local" required value={paidAt} onChange={(event) => setPaidAt(event.target.value)} />
            </label>
            <label htmlFor="hand-over-who">{translate('office_withdrawal', 'hand_over_received_by')}
                <input id="hand-over-who" type="text" maxLength={100} value={receivedBy} onChange={(event) => setReceivedBy(event.target.value)} />
                <small className="field-hint">{translate('office_withdrawal', 'hand_over_received_by_hint')}</small>
            </label>
            {note.shown && <label htmlFor="hand-over-note">{note.required ? translate('portal', 'pay_fld_notes') : translate('office_withdrawal', 'hand_over_note')}
                <textarea id="hand-over-note" rows={2} maxLength={1000} required={note.required} value={text} onChange={(event) => setText(event.target.value)} />
            </label>}
        </ConfirmSheet>
    );
}

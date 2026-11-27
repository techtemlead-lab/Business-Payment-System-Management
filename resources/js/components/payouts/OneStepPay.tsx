import { useState } from 'react';
import { router } from '@inertiajs/react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { channelName } from '../../shared/payoutMethods';
import ConfirmSheet, { type ConfirmFact } from '../ConfirmSheet';
import ReceiptPicker from './ReceiptPicker';
import { useWithdrawalFacts } from './WithdrawalFacts';

/** What the one-step box needs about a withdrawal (a payouts table row or the detail page). */
export interface OneStepRecord {
    id: string;
    channel: string;
    user_name: string;
    dealer_code?: string | null;
    amount: string | number;
    net_amount?: string | number | null;
}

/**
 * Owner, 2026-11-27: the office sends the money by hand (bKash, Nagad, Rocket) or hands over the cash, then
 * presses ONE button. The box shows the number to send to and the amount after tax; the transaction ID and
 * the screenshot are optional. It posts to /commands/withdrawals/{id}/sent-paid, which approves the request
 * when it still waits and records the payment; a second press gets the first receipt back.
 *
 * `destination` is passed by the detail page, which already shows the full number; elsewhere the box loads
 * it with the review facts (opening them is logged).
 */
export default function OneStepPayDialog({ record, destination = null, onClose }: { record: OneStepRecord; destination?: string | null; onClose: () => void }) {
    const translate = useT();
    const note = useReasonField('money.withdrawal_settle');
    const loaded = useWithdrawalFacts(destination ? null : record.id);
    const isCash = record.channel === 'Office';
    const [reference, setReference] = useState('');
    const [proof, setProof] = useState<File | null>(null);
    const [receivedBy, setReceivedBy] = useState(record.user_name);
    const [text, setText] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const payee = record.dealer_code ? `${record.user_name} (${record.dealer_code})` : record.user_name;
    const sendTo = destination ?? loaded.facts?.destination ?? '';
    const net = record.net_amount ?? record.amount;
    const blocker = loaded.facts?.blocker ?? null;
    const facts: ConfirmFact[] = [
        { label: translate('payouts', 'cs_fact_payee'), value: payee },
        { label: translate('payouts', 'cs_fact_method'), value: channelName(record.channel, translate) },
        ...(!isCash && sendTo ? [{ label: translate('payouts', 'cs_fact_account'), value: <b>{sendTo}</b> }] : []),
    ];

    const submit = () => {
        const form = new FormData();
        if (isCash) {
            form.append('received_by', receivedBy);
        } else {
            form.append('payment_reference', reference);
            if (proof) {
                form.append('receipt', proof);
            }
        }
        form.append('note', text);
        setProcessing(true);
        router.post(`/commands/withdrawals/${record.id}/sent-paid`, form, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
            onError: (failed) => setErrors(failed),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <ConfirmSheet
            title={translate('payouts', 'one_step_title')}
            sentence={translate('payouts', isCash ? 'one_step_cash_sentence' : 'one_step_sentence', { name: payee, amount: money(net) })}
            amount={net}
            facts={facts}
            confirmLabel={translate('payouts', 'one_step_button')}
            cancelLabel={translate('portal', 'pay_cancel')}
            pending={processing}
            errors={blocker ? { withdrawal: blocker, ...errors } : errors}
            onCancel={onClose}
            onConfirm={submit}
        >
            {isCash ? (
                <label htmlFor="one-step-received-by">{translate('office_withdrawal', 'hand_over_received_by')}
                    <input id="one-step-received-by" type="text" maxLength={100} value={receivedBy} onChange={(event) => setReceivedBy(event.target.value)} />
                    <small className="field-hint">{translate('office_withdrawal', 'hand_over_received_by_hint')}</small>
                </label>
            ) : (
                <>
                    <label htmlFor="one-step-reference">{translate('payouts', 'one_step_reference')}
                        <input id="one-step-reference" type="text" maxLength={100} autoComplete="off" value={reference} onChange={(event) => setReference(event.target.value)} />
                    </label>
                    <ReceiptPicker id="one-step-proof" label={translate('payouts', 'one_step_proof')} required={false} webp file={proof} onChange={setProof} />
                    <small className="field-hint">{translate('payouts', 'one_step_proof_hint')}</small>
                </>
            )}
            {note.shown && <label htmlFor="one-step-note">{note.required ? translate('portal', 'pay_fld_notes') : translate('office_withdrawal', 'hand_over_note')}
                <textarea id="one-step-note" rows={2} maxLength={1000} required={note.required} value={text} onChange={(event) => setText(event.target.value)} />
            </label>}
        </ConfirmSheet>
    );
}

import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import { money, type Row } from './UI';
import ConfirmSheet, { type ConfirmFact } from './ConfirmSheet';
import { AutoSelect } from './SearchSelect';
import { ProofLink, proofKindOf } from './ProofViewer';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

/** The payment method in plain words ("bKash", "Office cash"); unknown codes show as sent. */
function channelText(channel: unknown, translate: (group: string, key: string) => string): string {
    const raw = String(channel ?? '');
    const key = `pay_channel_${raw.toLowerCase()}`;
    const text = translate('portal', key);

    return raw === '' ? '—' : (text !== key ? text : raw);
}

/**
 * One press for "the money came, approve the order" (owner, 2026-11-27). Shown in the order's review box
 * when one waiting payment pays the whole price. The sheet repeats the money, asks the payment check's
 * questions (reason, counted cash for office money) and the approval's (upline replacements), and posts
 * once to `/commands/orders/{id}/verify-and-approve`. The server checks the payment and approves the
 * order together, or does neither.
 */
export default function VerifyAndApprove({ order, claim, replacementSlots = [], replacementCandidates = [] }: {
    order: Row; claim: Row; replacementSlots?: Row[]; replacementCandidates?: Row[];
}) {
    const translate = useT();
    const office = claim.channel === 'Office';
    const reason = useReasonField('payment.verify');
    const [asking, setAsking] = useState(false);
    const form = useForm<Record<string, string>>({
        claim_id: String(claim.id),
        ...(reason.shown ? { reason: '' } : {}),
        ...(office ? { cash_counted: '' } : {}),
        ...Object.fromEntries(replacementSlots.map((slot: Row) => [`referral_replacement_${slot.generation}`, ''])),
    });
    const partner = [order.user?.name, order.user?.dealer_code].filter(Boolean).join(' · ');
    const facts: ConfirmFact[] = [
        { label: translate('order_payment', 'claim_fact_order'), value: String(order.number ?? '') },
        ...(partner ? [{ label: translate('order_payment', 'claim_fact_partner'), value: partner }] : []),
        { label: translate('payouts', 'cs_fact_method'), value: channelText(claim.channel, translate) },
        ...(claim.reference ? [{ label: translate('order_payment', 'claim_fact_reference'), value: String(claim.reference) }] : []),
    ];
    const candidateOptions = replacementCandidates.map((person: Row) => ({ value: String(person.id), label: `${person.name} · ${person.dealer_code ?? translate('portal', 'col_partner')}` }));

    return <div className="panel" data-testid="verify-and-approve">
        <h3>{translate('order_payment', 'one_step_title')}</h3>
        <div className="decision-summary">
            <strong>{money(claim.amount)}</strong>
            <span>{[partner, channelText(claim.channel, translate)].filter(Boolean).join(' · ')}</span>
        </div>
        <p className="muted">{translate('order_payment', office ? 'one_step_hint_office' : 'one_step_hint')}</p>
        {claim.has_proof && <p><ProofLink className="text-link" href={`/commands/operations/receipts/payment/${claim.id}`} kind={proofKindOf(claim.proof_mime)}>{translate('portal', 'payment_proof_view')}</ProofLink></p>}
        <div className="decision-buttons">
            <button type="button" className="button dark" onClick={() => { form.clearErrors(); setAsking(true); }}>
                <CheckCheck size={18} aria-hidden="true" />{translate('order_payment', 'one_step_button')}
            </button>
        </div>
        {asking && (
            <ConfirmSheet
                title={translate('order_payment', 'one_step_confirm_title')}
                sentence={translate('order_payment', 'one_step_confirm_sentence', { name: String(order.user?.name ?? ''), number: String(order.number ?? '') })}
                amount={claim.amount}
                facts={facts}
                tone="primary"
                confirmLabel={translate('order_payment', 'one_step_button')}
                reason={reason.shown ? { label: reason.label(translate(office ? 'order_payment' : 'portal', office ? 'office_who_took' : 'verification_evidence')), required: reason.required, maxLength: 1000, value: form.data.reason ?? '', onChange: (value) => form.setData('reason', value) } : undefined}
                pending={form.processing}
                errors={form.errors}
                onCancel={() => setAsking(false)}
                onConfirm={() => form.post(`/commands/orders/${order.id}/verify-and-approve`, { preserveScroll: true, onSuccess: () => setAsking(false) })}
            >
                {/* Office cash is checked only against the counted amount, exactly as on the payment card. */}
                {office && (
                    <label>{translate('payouts', 'cash_counted')}
                        <input type="number" inputMode="decimal" step="any" required value={form.data.cash_counted ?? ''} onChange={(event) => form.setData('cash_counted', event.target.value)} />
                        <small className="field-hint">{translate('payouts', 'cash_counted_hint', { amount: money(claim.amount) })}</small>
                    </label>
                )}
                {replacementSlots.map((slot: Row) => {
                    const field = `referral_replacement_${slot.generation}`;
                    return <label key={field}>{translate('portal', 'replacement_for_generation', { generation: String(slot.generation), name: String(slot.name) })}
                        <AutoSelect search value={form.data[field] ?? ''} onChange={(value) => form.setData(field, value)} emptyLabel={translate('portal', 'ops_fld_select')} options={candidateOptions} />
                    </label>;
                })}
            </ConfirmSheet>
        )}
    </div>;
}

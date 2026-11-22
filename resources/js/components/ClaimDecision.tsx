import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, XCircle } from 'lucide-react';
import { money, type Row } from './UI';
import ConfirmSheet, { type ConfirmFact } from './ConfirmSheet';
import { actionAllowed } from './action-permissions';
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
 * Finance checks one payment claim on the order page with two clear buttons. A sheet repeats the amount,
 * who paid and the transaction number, asks for the reason (and the counted cash for office money), and
 * posts exactly what the old form did: `approve` (1 or 0), `reason` unless the owner switched it off, and
 * `cash_counted` for office cash.
 */
export default function ClaimDecision({ claim, order }: { claim: Row; order: Row }) {
    const translate = useT();
    const { portal, staffActions } = usePage<any>().props;
    const action = `/commands/claims/${claim.id}/verify`;
    const office = claim.channel === 'Office';
    const reason = useReasonField('payment.verify');
    const [asking, setAsking] = useState<null | '1' | '0'>(null);
    const form = useForm<Record<string, string>>({
        approve: '',
        ...(reason.shown ? { reason: '' } : {}),
        ...(office ? { cash_counted: '' } : {}),
    });
    if ((portal === 'manage' || portal === 'sadmin') && !actionAllowed(action, staffActions)) {
        return null;
    }
    const partner = [order.user?.name, order.user?.dealer_code].filter(Boolean).join(' · ');
    const facts: ConfirmFact[] = [
        { label: translate('order_payment', 'claim_fact_order'), value: String(order.number ?? '') },
        ...(partner ? [{ label: translate('order_payment', 'claim_fact_partner'), value: partner }] : []),
        { label: translate('payouts', 'cs_fact_method'), value: channelText(claim.channel, translate) },
        ...(claim.reference ? [{ label: translate('order_payment', 'claim_fact_reference'), value: String(claim.reference) }] : []),
    ];
    const open = (approve: '1' | '0') => {
        form.clearErrors();
        form.setData((data) => ({ ...data, approve, ...(office && approve === '0' ? { cash_counted: '' } : {}) }));
        setAsking(approve);
    };
    const approving = asking === '1';
    const reasonLabel = translate(office ? 'order_payment' : 'portal', office ? 'office_who_took' : 'verification_evidence');

    return (
        <div className="panel" data-testid="claim-decision">
            <h3>{translate('portal', 'review_claim', { reference: claim.reference })}</h3>
            <div className="decision-summary">
                <strong>{money(claim.amount)}</strong>
                <span>{[partner, channelText(claim.channel, translate)].filter(Boolean).join(' · ')}</span>
            </div>
            <p className="muted">{translate('order_payment', 'claim_decide_hint')}</p>
            <div className="decision-buttons">
                <button type="button" className="button dark" onClick={() => open('1')}><Check size={18} aria-hidden="true" />{translate('order_payment', 'claim_approve_button')}</button>
                <button type="button" className="button danger" onClick={() => open('0')}><XCircle size={18} aria-hidden="true" />{translate('order_payment', 'claim_reject_button')}</button>
            </div>
            {asking && (
                <ConfirmSheet
                    title={translate('order_payment', approving ? 'claim_approve_title' : 'claim_reject_title')}
                    sentence={approving
                        ? translate('order_payment', 'claim_approve_sentence', { name: String(order.user?.name ?? ''), number: String(order.number ?? '') })
                        : translate('order_payment', 'claim_reject_sentence')}
                    amount={claim.amount}
                    facts={facts}
                    tone={approving ? 'primary' : 'danger'}
                    confirmLabel={translate('order_payment', approving ? 'claim_approve_button' : 'claim_reject_button')}
                    reason={reason.shown ? { label: reason.label(reasonLabel), required: reason.required, maxLength: 1000, value: form.data.reason ?? '', onChange: (value) => form.setData('reason', value) } : undefined}
                    pending={form.processing}
                    errors={form.errors}
                    onCancel={() => setAsking(null)}
                    onConfirm={() => form.post(action, { preserveScroll: true, onSuccess: () => setAsking(null) })}
                >
                    {/* Office cash is confirmed only against the counted amount (OrderService::verify); same box as the Payments page. */}
                    {approving && office && (
                        <label>{translate('payouts', 'cash_counted')}
                            <input type="number" inputMode="decimal" step="any" required value={form.data.cash_counted ?? ''} onChange={(event) => form.setData('cash_counted', event.target.value)} />
                            <small className="field-hint">{translate('payouts', 'cash_counted_hint', { amount: money(claim.amount) })}</small>
                        </label>
                    )}
                </ConfirmSheet>
            )}
        </div>
    );
}

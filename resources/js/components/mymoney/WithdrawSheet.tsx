import { useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, Landmark, ShoppingBag } from 'lucide-react';
import { ActionForm, Modal, money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { randomUuid } from '../../shared/randomUuid';
import type { WithdrawInfo } from './types';

type Step = 'choose' | 'take' | 'order';

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Amounts go into the address and to the server in plain digits, whichever keyboard typed them. */
function plainDigits(text: string): string {
    return text.replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit))).trim();
}

/**
 * The withdraw sheet opens with two choices: take the money to a bank or mobile
 * account, or spend it on an order. The second choice moves nothing and reserves
 * nothing; it only opens the order page with this amount in mind.
 */
export default function WithdrawSheet({ available, withdraw, onClose }: { available: string; withdraw: WithdrawInfo; onClose: () => void }) {
    const translate = useT();
    const { portalBase: base, financeActive } = usePage<any>().props;
    const [step, setStep] = useState<Step>('choose');
    const [budget, setBudget] = useState(Number(available) > 0 ? available : '');
    const requestKey = useRef(randomUuid());

    const saved = withdraw.saved_accounts ?? [];
    // Older pages without the list keep every way; the server refuses a way that is switched off.
    const methods = withdraw.methods ?? ['Office', 'Bank', 'bKash', 'Nagad', 'Rocket'];
    const officeOn = methods.includes('Office');
    const onlineChannels = methods.filter((method) => method !== 'Office');
    const [way, setWay] = useState<'office' | 'online'>(officeOn ? 'office' : 'online');
    const takeLabel =onlineChannels.length === 0 ? translate('office_withdrawal', 'take_money_office') : officeOn ? translate('office_withdrawal', 'take_money_any') : translate('my_money', 'take_money');
    const usable = Number(available);
    const budgetAmount = Number(plainDigits(budget));
    const budgetIsFine = Number.isFinite(budgetAmount) && budgetAmount > 0 && budgetAmount <= usable;

    const takeRefusal = withdraw.kyc_block
        ? translate('portal', 'wd_kyc_needed_body')
        : withdraw.nid_block
            ? translate('payouts', 'err_nid_first')
        : withdraw.security_wait
            ? withdraw.security_wait
            : !withdraw.can || !financeActive
            ? translate('portal', 'withdrawals_pending_activation_notice')
            : usable < Number(withdraw.minimum)
                ? translate('my_money', 'below_minimum', { amount: money(withdraw.minimum) })
                : null;
    const orderRefusal = !financeActive
        ? translate('portal', 'withdrawals_pending_activation_notice')
        : usable <= 0
            ? translate('my_money', 'nothing_to_use')
            : null;

    const titles: Record<Step, string> = {
        choose: translate('my_money', 'withdraw'),
        take: takeLabel,
        order: translate('my_money', 'order_with_money'),
    };

    return (
        <Modal title={titles[step]} onClose={onClose}>
            <p className="mm-sheet-balance">{translate('my_money', 'usable')} <b>{money(available)}</b></p>

            {step === 'choose' && (
                <div className="mm-choices">
                    {/* Requests are taken on any day (owner, 2026-11-27); the line under the button names the payout day. */}
                    <button type="button" className="mm-choice" disabled={takeRefusal !== null} onClick={() => setStep('take')}>
                        <Landmark size={22} aria-hidden="true" />
                        <span>
                            <b>{takeLabel}</b>
                            {takeRefusal && <small>{takeRefusal}</small>}
                            {!takeRefusal && withdraw.week?.enabled && <small>{translate('office_withdrawal', `${officeOn ? 'payout_line_office' : 'payout_line_online'}${withdraw.week.same_day ? '_today' : ''}`, { day: withdraw.week.payout_day, date: withdraw.week.payout_date, time: withdraw.week.same_day_until ?? '' })}</small>}
                            {!takeRefusal && withdraw.week?.enabled && withdraw.week.same_day_until && <small>{translate('office_withdrawal', 'same_day_rule', { day: withdraw.week.payout_day, time: withdraw.week.same_day_until })}</small>}
                        </span>
                    </button>
                    <button type="button" className="mm-choice" disabled={orderRefusal !== null} onClick={() => setStep('order')}>
                        <ShoppingBag size={22} aria-hidden="true" />
                        <span>
                            <b>{translate('my_money', 'order_with_money')}</b>
                            {orderRefusal && <small>{orderRefusal}</small>}
                        </span>
                    </button>
                </div>
            )}

            {/* Only the ways the owner switched on; "Office withdrawal" (cash at the office) is chosen first. */}
            {step === 'take' && officeOn && onlineChannels.length > 0 && (
                <fieldset className="mm-methods">
                    <legend>{translate('office_withdrawal', 'choose_method')}</legend>
                    <label className="hub-choice">
                        <input type="radio" name="wd-method" checked={way === 'office'} onChange={() => setWay('office')} />
                        <span><b>{translate('office_withdrawal', 'method_office')}</b> <small>{translate('office_withdrawal', 'method_office_hint')}</small></span>
                    </label>
                    <label className="hub-choice">
                        <input type="radio" name="wd-method" checked={way === 'online'} onChange={() => setWay('online')} />
                        <span><b>{translate('office_withdrawal', 'other_methods')}</b> <small>{onlineChannels.map((channel) => translate('portal', `pay_channel_${channel.toLowerCase()}`)).join(', ')}</small></span>
                    </label>
                </fieldset>
            )}

            {step === 'take' && way === 'office' && (
                <>
                    <p className="muted">{translate('office_withdrawal', 'method_office')} · {translate('office_withdrawal', 'method_office_hint')}</p>
                    <ActionForm
                        action="/commands/withdrawals"
                        defaults={{ idempotency_key: requestKey.current, channel: 'Office' }}
                        submit={translate('portal', 'request_withdrawal')}
                        fields={[
                            { name: 'amount', type: 'number', label: translate('portal', 'amount_minimum', { amount: String(withdraw.minimum) }), required: true },
                        ]}
                    />
                </>
            )}

            {/* V3: with saved accounts the partner picks one; its channel and number are read from the saved record. */}
            {step === 'take' && way === 'online' && saved.length > 0 && (
                <>
                    <ActionForm
                        action="/commands/withdrawals"
                        defaults={{ idempotency_key: requestKey.current, payout_account_id: String((saved.find((account) => account.is_default) ?? saved[0]).id) }}
                        submit={translate('portal', 'request_withdrawal')}
                        fields={[
                            { name: 'amount', type: 'number', label: translate('portal', 'amount_minimum', { amount: String(withdraw.minimum) }), required: true },
                            { name: 'payout_account_id', label: translate('leftovers_partner', 'pa_pick'), required: true, options: saved.map((account) => ({ value: String(account.id), label: account.label })) },
                        ]}
                    />
                    <Link className="text-link" href={`${base}/profile`}>{translate('leftovers_partner', 'pa_manage_link')}</Link>
                </>
            )}

            {step === 'take' && way === 'online' && saved.length === 0 && (
                <>
                <p className="muted">{translate('leftovers_partner', 'pa_none_withdraw')} <Link className="text-link" href={`${base}/profile`}>{translate('leftovers_partner', 'pa_manage_link')}</Link></p>
                <ActionForm
                    action="/commands/withdrawals"
                    defaults={{ idempotency_key: requestKey.current }}
                    submit={translate('portal', 'request_withdrawal')}
                    fields={[
                        { name: 'amount', type: 'number', label: translate('portal', 'amount_minimum', { amount: String(withdraw.minimum) }), required: true },
                        // Each method shows its own most-per-withdrawal, so nobody asks bKash for ৳9 crore (MA-08).
                        { name: 'channel', label: translate('portal', 'payment_method'), required: true, options: onlineChannels.map((channel) => {
                            const limit = withdraw.channel_limits?.[channel];
                            const name = translate('portal', `pay_channel_${channel.toLowerCase()}`);
                            return { value: channel, label: limit ? `${name} · ${translate('payouts', 'withdraw_limit_hint', { limit: money(limit) })}` : name };
                        }) },
                        { name: 'destination', label: translate('portal', 'account_or_wallet'), required: true },
                    ]}
                />
                </>
            )}

            {step === 'take' && (
                <details className="mm-tin">
                    <summary>{translate('payouts', 'tin_title')}{withdraw.tin_last4 ? ` · ${translate('payouts', 'tin_saved', { last: withdraw.tin_last4 })}` : ''}</summary>
                    <ActionForm
                        action="/commands/withdrawals/tax-number"
                        submit={translate('payouts', 'tin_save')}
                        fields={[{ name: 'tin', label: translate('payouts', 'tin_hint'), required: false }]}
                    />
                </details>
            )}

            {step === 'order' && (
                <div className="action-form mm-order-step">
                    <label>
                        {translate('my_money', 'order_amount')}
                        <input type="text" inputMode="decimal" value={budget} onChange={(event) => setBudget(event.target.value)} />
                        {budget !== '' && !budgetIsFine && <span className="field-error" role="alert">{translate('my_money', 'order_amount_too_much', { amount: money(available) })}</span>}
                    </label>
                    {budgetIsFine
                        ? <Link className="button dark" href={`${base}/purchase?pay=account&budget=${plainDigits(budget)}`}>{translate('my_money', 'go_to_order')}<ArrowUpRight size={17} /></Link>
                        : <button type="button" className="button dark" disabled>{translate('my_money', 'go_to_order')}<ArrowUpRight size={17} /></button>}
                </div>
            )}

            {step !== 'choose' && (
                <button type="button" className="text-link mm-sheet-back" onClick={() => setStep('choose')}>
                    <ArrowLeft size={16} aria-hidden="true" /> {translate('my_money', 'go_back')}
                </button>
            )}
        </Modal>
    );
}

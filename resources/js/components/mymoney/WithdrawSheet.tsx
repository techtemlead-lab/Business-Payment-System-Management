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

    const usable = Number(available);
    const budgetAmount = Number(plainDigits(budget));
    const budgetIsFine = Number.isFinite(budgetAmount) && budgetAmount > 0 && budgetAmount <= usable;

    const takeRefusal = withdraw.kyc_block
        ? translate('portal', 'wd_kyc_needed_body')
        : !withdraw.can
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
        take: translate('my_money', 'take_money'),
        order: translate('my_money', 'order_with_money'),
    };

    return (
        <Modal title={titles[step]} onClose={onClose}>
            <p className="mm-sheet-balance">{translate('my_money', 'usable')} <b>{money(available)}</b></p>

            {step === 'choose' && (
                <div className="mm-choices">
                    <button type="button" className="mm-choice" disabled={takeRefusal !== null} onClick={() => setStep('take')}>
                        <Landmark size={22} aria-hidden="true" />
                        <span>
                            <b>{translate('my_money', 'take_money')}</b>
                            {takeRefusal && <small>{takeRefusal}</small>}
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

            {step === 'take' && (
                <ActionForm
                    action="/commands/withdrawals"
                    defaults={{ idempotency_key: requestKey.current }}
                    submit={translate('portal', 'request_withdrawal')}
                    fields={[
                        { name: 'amount', type: 'number', label: translate('portal', 'amount_minimum', { amount: String(withdraw.minimum) }), required: true },
                        { name: 'channel', label: translate('portal', 'payment_method'), required: true, options: [
                            { value: 'Bank', label: translate('portal', 'pay_channel_bank') },
                            { value: 'bKash', label: translate('portal', 'pay_channel_bkash') },
                            { value: 'Nagad', label: translate('portal', 'pay_channel_nagad') },
                            { value: 'Rocket', label: translate('portal', 'pay_channel_rocket') },
                        ] },
                        { name: 'destination', label: translate('portal', 'account_or_wallet'), required: true },
                    ]}
                />
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

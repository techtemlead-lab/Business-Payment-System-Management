import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { PendingMoney as Pending } from './types';

/** The money waiting for the month's target or the month-end close, and what was paid this month. None of it is in the balance. */
export default function PendingMoney({ pending }: { pending?: Pending | null }) {
    const translate = useT();

    const rewards = pending?.rewards ?? [];
    if (!pending || (pending.lines.length === 0 && pending.paid.length === 0 && rewards.length === 0)) {
        return null;
    }

    return (
        <section className="mm-requests mm-pending" aria-label={translate('my_money', 'pending_heading')} data-testid="my-money-pending">
            {rewards.length > 0 && (
                <div data-testid="my-money-rewards-waiting">
                    <h2>{translate('my_money', 'rewards_heading')}</h2>
                    <p>{translate('my_money', 'rewards_intro')}</p>
                    <ul>
                        {rewards.map((line) => (
                            <li key={line.id}>
                                <div className="mm-request">
                                    <span>{line.what}</span>
                                    <strong>{money(line.amount)}</strong>
                                    <span className="badge pending">{translate('my_money', 'status_pending')}</span>
                                    <small>{translate('my_money', `reward_reason_${line.reason}`)}</small>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {pending.lines.length > 0 && (
                <>
                    <h2>{translate('my_money', 'pending_heading')}</h2>
                    <p>{translate('my_money', 'pending_intro')}</p>
                    <ul>
                        {pending.lines.map((line) => (
                            <li key={line.id}>
                                <div className="mm-request">
                                    <span>{line.order_number !== null
                                        ? translate('my_money', 'pending_line', { what: line.what, order: line.order_number })
                                        : translate('my_money', 'pending_line_no_order', { what: line.what, month: line.month })}</span>
                                    <strong>{line.estimate ? `${translate('my_money', 'pending_salary_estimate')} ` : ''}{money(line.amount)}</strong>
                                    <span className="badge pending">{translate('my_money', 'status_pending')}</span>
                                    <small>{translate('my_money', `pending_reason_${line.reason}`)}</small>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {pending.count > pending.lines.length && <p>{translate('my_money', 'pending_more', { count: pending.count - pending.lines.length })}</p>}
                    {pending.has_estimate && <p className="muted">{translate('my_money', 'pending_estimate_hint')}</p>}
                </>
            )}
            {pending.paid.length > 0 && (
                <>
                    <h2>{translate('my_money', 'pending_paid_heading')}</h2>
                    <ul>
                        {pending.paid.map((line) => (
                            <li key={line.id}>
                                <div className="mm-request">
                                    <span>{line.order_number !== null
                                        ? translate('my_money', 'pending_line', { what: line.what, order: line.order_number })
                                        : translate('my_money', 'pending_line_no_order', { what: line.what, month: line.month })}</span>
                                    <strong>{money(line.amount)}</strong>
                                    <span className="badge paid">{translate('my_money', 'status_paid')}</span>
                                    <small>{translate('my_money', 'pending_paid_reason')}</small>
                                </div>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

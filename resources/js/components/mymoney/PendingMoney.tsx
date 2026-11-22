import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { PendingMoney as Pending } from './types';

/** The money waiting for the month's target or the month-end close, and what was paid this month. None of it is in the balance. */
export default function PendingMoney({ pending }: { pending?: Pending | null }) {
    const translate = useT();

    const rewards = pending?.rewards ?? [];
    const monthEnd = pending?.month_end ?? [];
    if (!pending || (pending.lines.length === 0 && pending.paid.length === 0 && rewards.length === 0 && monthEnd.length === 0)) {
        return null;
    }
    const more = pending.more ?? 0;

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
                                    {line.amount !== null && <strong>{money(line.amount)}</strong>}
                                    <span className="badge pending">{translate('my_money', line.state === 'set_aside' ? 'status_set_aside' : 'status_pending')}</span>
                                    <small>{translate('my_money', `reward_reason_${line.reason}`)}</small>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {monthEnd.length > 0 && (
                <div data-testid="my-money-month-end">
                    <h2>{translate('my_money', 'month_end_heading')}</h2>
                    <p>{translate('my_money', pending.role === 'sub_dealer' ? 'month_end_intro_sub' : 'month_end_intro')}</p>
                    <ul>
                        {monthEnd.map((line) => (
                            <li key={line.id}>
                                <div className="mm-request">
                                    <span>{line.order_number !== null
                                        ? translate('my_money', 'pending_line', { what: line.what, order: line.order_number })
                                        : translate('my_money', 'pending_line_no_order', { what: line.what, month: line.month })}</span>
                                    {line.amount !== null
                                        ? <strong>{line.estimate ? `${translate('my_money', 'pending_salary_estimate')} ` : ''}{money(line.amount)}</strong>
                                        : <strong>{translate('my_money', 'month_end_no_amount')}</strong>}
                                    <span className="badge pending">{translate('my_money', 'status_pending')}</span>
                                    <small>{translate('my_money', `pending_reason_${line.reason}`)}</small>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {more > 0 && pending.lines.length === 0 && <p>{translate('my_money', 'pending_more', { count: more })}</p>}
                    {pending.has_estimate && <p className="muted">{translate('my_money', 'pending_estimate_hint')}</p>}
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
                                    <strong>{money(line.amount)}</strong>
                                    <span className="badge pending">{translate('my_money', 'status_pending')}</span>
                                    <small>{translate('my_money', `pending_reason_${line.reason}`)}</small>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {more > 0 && <p>{translate('my_money', 'pending_more', { count: more })}</p>}
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

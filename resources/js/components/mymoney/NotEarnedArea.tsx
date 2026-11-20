import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { NotEarnedArea as NotEarnedRow } from './types';

/** Area commission the partner did not receive because they missed that month's target. It was never in the balance. */
export default function NotEarnedArea({ rows }: { rows: NotEarnedRow[] }) {
    const translate = useT();

    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests" aria-labelledby="mm-not-earned-title" data-testid="my-money-not-received">
            <h2 id="mm-not-earned-title">{translate('my_money', 'not_earned_heading')}</h2>
            <p>{translate('my_money', 'not_received_note')}</p>
            <ul>
                {rows.map((row) => (
                    <li key={row.month}>
                        <div className="mm-request">
                            <span>{row.month}</span>
                            <strong>{money(row.amount)}</strong>
                            <span className="badge rejected">{translate('my_money', 'status_not_received')}</span>
                            <small>{translate('my_money', 'not_received_reason')}</small>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

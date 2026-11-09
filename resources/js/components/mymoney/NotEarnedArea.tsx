import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { NotEarnedArea as NotEarnedRow } from './types';

/** Area commission the partner did not earn because they missed that month's target. */
export default function NotEarnedArea({ rows }: { rows: NotEarnedRow[] }) {
    const translate = useT();

    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests" aria-labelledby="mm-not-earned-title">
            <h2 id="mm-not-earned-title">{translate('my_money', 'not_earned_heading')}</h2>
            <ul>
                {rows.map((row) => (
                    <li key={row.month}>
                        <div className="mm-request">
                            <span>{row.month}</span>
                            <strong>{money(row.amount)}</strong>
                            <small>{translate('my_money', 'not_earned_reason')}</small>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

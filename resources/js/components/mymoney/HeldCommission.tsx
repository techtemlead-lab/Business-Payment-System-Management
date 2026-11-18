import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { HeldCommission as Held } from './types';

/** Commission held back for the partner: what it is, which order, and why it is not in the balance yet. */
export default function HeldCommission({ held }: { held: Held }) {
    const translate = useT();

    if (held.lines.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests" aria-labelledby="mm-held-title" data-testid="my-money-held">
            <h2 id="mm-held-title">{translate('money_core', 'held_heading')}</h2>
            <p>{translate('money_core', 'held_total')}: <strong>{money(held.total)}</strong>. {translate('money_core', 'held_note')}</p>
            <ul>
                {held.lines.map((line) => (
                    <li key={line.id}>
                        <div className="mm-request">
                            <span>{translate('money_core', 'held_line', { what: line.what, order: line.order_number ?? '—' })}</span>
                            <strong>{money(line.amount)}</strong>
                            <small>{translate('money_core', `held_reason_${line.reason}`)}</small>
                        </div>
                    </li>
                ))}
            </ul>
            {held.count > held.lines.length && <p>{translate('money_core', 'held_more', { count: held.count - held.lines.length })}</p>}
        </section>
    );
}

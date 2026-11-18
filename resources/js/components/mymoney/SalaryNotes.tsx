import { money, number } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { SalaryNote } from './types';

/** The amount without the taka sign: the sentences already carry it. */
const plain = (value: unknown) => money(value, { symbol: false });

/** How the monthly salary was worked out for the last closed months, or why there was none. */
export default function SalaryNotes({ notes }: { notes: SalaryNote[] }) {
    const translate = useT();

    if (notes.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests" aria-labelledby="mm-salary-title" data-testid="my-money-salary-notes">
            <h2 id="mm-salary-title">{translate('money_core', 'salary_heading')}</h2>
            <ul>
                {notes.map((note) => (
                    <li key={note.month}>
                        <div className="mm-request">
                            {note.state === 'paid' ? (
                                <>
                                    <span>{translate('money_core', 'salary_paid', { month: note.month, amount: plain(note.amount), pool: plain(note.pool), sharers: number(note.sharers ?? 0) })}</span>
                                    <small>{translate('money_core', 'salary_rule', { min: plain(note.min_volume) })}</small>
                                </>
                            ) : (
                                <span>{translate('money_core', note.state === 'none_low_volume' ? 'salary_none_low' : 'salary_none_other', { month: note.month, min: plain(note.min_volume) })}</span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

import { useForm } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import { useReasonFields } from '../../shared/reasonFields';

/**
 * The one step that starts the books, shown to the owner on the home page until it is done.
 * It is the same fresh start the server checks and records: no earnings are paid before it.
 */
export default function StartBooksCard() {
    const translate = useT();
    const reasonOf = useReasonFields();
    const reason = reasonOf('money.opening_fresh_start');
    const form = useForm({ self_approve: false });
    const messages = Object.values(form.errors) as string[];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // The owner's reason box is a set sentence here, and left out when the owners switched that reason off.
        form.transform((data) => ({ ...data, reason: reason.shown ? translate('money_core', 'start_books_reason') : '' }));
        form.post('/commands/operations/opening-balances/fresh-start', { preserveScroll: true });
    };

    return (
        <section className="panel" data-tour="start-books" aria-labelledby="start-books-title">
            <div className="panel-heading"><h2 id="start-books-title">{translate('money_core', 'start_books_title')}</h2></div>
            <form className="action-form" onSubmit={submit}>
                <p>{translate('money_core', 'start_books_body')}</p>
                <label className="check-line">
                    <input type="checkbox" checked={form.data.self_approve} onChange={(event) => form.setData('self_approve', event.target.checked)} />
                    {' '}{translate('money_core', 'start_books_confirm')}
                </label>
                {messages.length > 0 && <div role="alert" className="error">{messages.join(' ')}</div>}
                <button type="submit" className="button dark" disabled={!form.data.self_approve || form.processing}>{translate('money_core', 'start_books_button')}</button>
            </form>
        </section>
    );
}

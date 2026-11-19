import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import { date, money, number } from '../UI';
import { useT } from '../../shared/i18n/t';

export type IncomePart = { key: string; label: string; hint: string; amount: string; total: string; entries: number };

export type IncomeRow = {
    id: string;
    occurred_at: string;
    what: string;
    percent: string | null;
    order_id: string | null;
    /** False when the order belongs to someone in the team, so there is no page to open. */
    order_is_own?: boolean;
    order_number: string | null;
    from_name: string | null;
    status: 'waiting' | 'ready' | 'taken_back';
    waiting_until: string | null;
    amount: string;
};

export type IncomeData = { parts: IncomePart[]; sum: { amount: string; total: string }; part: string; table: MoneyTableData<IncomeRow> };

/** The statement file takes the same view as the table: period, dates, search and status. */
function statementQuery(state: MoneyTableData<IncomeRow>['state']): string {
    const params = new URLSearchParams();
    params.set('period', state.period);
    if (state.from) {
        params.set('from', state.from);
    }
    if (state.to) {
        params.set('to', state.to);
    }
    if (state.q) {
        params.set('q', state.q);
    }
    for (const [key, value] of Object.entries(state.filters)) {
        params.set(key, value);
    }

    return params.toString();
}

/**
 * A partner's income one part at a time: a card per part with its total for the chosen days, then the
 * chosen part's day-by-day table and its printed statement. The partner's own page and the office's
 * copy both use it; only the address of the statement and of an order differ.
 */
export default function IncomeByPart({ income, statementBase, orderHref, prop = 'income' }: {
    income: IncomeData;
    /** Where a part's statement lives: the part and "/pdf" are added to it. */
    statementBase: string;
    orderHref?: (orderId: string) => string | null;
    /** The page prop this data arrives in, reloaded when the view changes. */
    prop?: string;
}) {
    const translate = useT();
    const { url } = usePage();
    const periodLabel = translate('cr21_income', income.table.state.period === 'month' ? 'col_this_month' : 'col_this_period');
    const chosen = income.parts.find((part) => part.key === income.part) ?? income.parts[0];

    const choose = (part: string) => {
        const current = new URL(url || '/', 'http://income.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => { params[key] = value; });
        delete params.page;
        delete params.q;
        delete params.status;
        params.part = part;
        router.get(current.pathname, params, { preserveState: true, preserveScroll: true, replace: true, only: [prop] });
    };

    const columns: MoneyColumn<IncomeRow>[] = [
        { key: 'occurred_at', title: translate('income', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.occurred_at) },
        {
            key: 'what',
            title: translate('income', 'col_what'),
            phone: 'title',
            render: (row) => (
                <>
                    {row.what}{Number(row.percent) > 0 ? ` ${number(row.percent)}%` : ''}
                    {row.waiting_until && <small className="cell-sub">{translate('income', 'waiting_until', { date: date(row.waiting_until) })}</small>}
                </>
            ),
        },
        { key: 'source', title: translate('income', 'col_source'), render: (row) => [row.order_number, row.from_name].filter(Boolean).join(' · ') || '—' },
        { key: 'status', title: translate('income', 'col_status'), render: (row) => translate('income', `status_${row.status}`) },
        {
            key: 'amount',
            title: translate('income', 'col_amount'),
            sortKey: 'amount',
            money: true,
            total: 'amount',
            phone: 'amount',
            render: (row) => <span className={Number(row.amount) < 0 ? 'is-negative' : undefined}>{Number(row.amount) < 0 ? '-' : '+'} {money(Math.abs(Number(row.amount)))}</span>,
        },
    ];

    return (
        <div className="mm-income-parts">
            <section aria-labelledby="mm-parts-title">
                <h2 id="mm-parts-title">{translate('income', 'parts_label')}</h2>
                <p className="mm-part-hint">{translate('income', 'all_parts_note')}</p>
                <div className="mm-heads-wrap">
                    <table className="mm-heads" data-testid="income-heads">
                        <thead>
                            <tr>
                                <th scope="col">{translate('cr21_income', 'col_head')}</th>
                                <th scope="col" className="num">{periodLabel}</th>
                                <th scope="col" className="num">{translate('cr21_income', 'col_to_date')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {income.parts.map((part) => (
                                <tr key={part.key} className={part.key === chosen.key ? 'is-selected' : undefined} data-head={part.key}>
                                    <th scope="row">
                                        <button type="button" aria-pressed={part.key === chosen.key} onClick={() => choose(part.key)} title={part.hint}>
                                            {part.label}
                                        </button>
                                    </th>
                                    <td data-label={periodLabel} className={`num${Number(part.amount) < 0 ? ' is-negative' : ''}`}>{money(part.amount)}</td>
                                    <td data-label={translate('cr21_income', 'col_to_date')} className={`num${Number(part.total) < 0 ? ' is-negative' : ''}`}>{money(part.total)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr data-testid="income-heads-total">
                                <th scope="row">{translate('cr21_income', 'sum_row')}</th>
                                <td data-label={periodLabel} className="num">{money(income.sum.amount)}</td>
                                <td data-label={translate('cr21_income', 'col_to_date')} className="num">{money(income.sum.total)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
            <section id="mm-book" className="mm-book" aria-labelledby="mm-part-title">
                <h2 id="mm-part-title">{chosen.label}</h2>
                <p className="mm-part-hint">{chosen.hint}. {translate('income', 'selected_total')}: <b>{money(chosen.amount)}</b></p>
                <MoneyTable
                    data={income.table}
                    prop={prop}
                    columns={columns}
                    searchPlaceholder={translate('income', 'search')}
                    rowHref={(row) => (row.order_id && row.order_is_own !== false && orderHref ?orderHref(row.order_id) : null)}
                    actions={(
                        <a className="button small" href={`${statementBase}/${chosen.key}/pdf?${statementQuery(income.table.state)}`} target="_blank" rel="noopener noreferrer">
                            <Download size={15} aria-hidden="true" /> {translate('income', 'download_pdf')}
                        </a>
                    )}
                    empty={translate('income', 'empty')}
                />
            </section>
        </div>
    );
}

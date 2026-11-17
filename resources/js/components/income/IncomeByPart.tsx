import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import { date, money, number } from '../UI';
import { useT } from '../../shared/i18n/t';

export type IncomePart = { key: string; label: string; hint: string; amount: string; entries: number };

export type IncomeRow = {
    id: string;
    occurred_at: string;
    what: string;
    percent: string | null;
    order_id: string | null;
    order_number: string | null;
    from_name: string | null;
    status: 'waiting' | 'ready' | 'taken_back';
    waiting_until: string | null;
    amount: string;
};

export type IncomeData = { parts: IncomePart[]; part: string; table: MoneyTableData<IncomeRow> };

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
                <div className="mm-cards">
                    {income.parts.map((part) => (
                        <button
                            key={part.key}
                            type="button"
                            className={`mm-card${part.key === chosen.key ? ' is-selected' : ''}`}
                            aria-pressed={part.key === chosen.key}
                            onClick={() => choose(part.key)}
                        >
                            <span>{part.label}</span>
                            <strong className={Number(part.amount) < 0 ? 'is-negative' : undefined}>{money(part.amount)}</strong>
                            <small>{translate('income', 'entries', { count: part.entries })}</small>
                        </button>
                    ))}
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
                    rowHref={(row) => (row.order_id && orderHref ? orderHref(row.order_id) : null)}
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

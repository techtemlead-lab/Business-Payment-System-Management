import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Shell from '../components/Shell';
import { Row, Table, money } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Translate = (key: string, replace?: Record<string, string | number>) => string;

const TABS = ['statements', 'limit'];

function Notice({ t }: { t: Translate }) {
    return <div className="notice warning" role="note"><p><strong>{t('management_notice')}</strong></p><p>{t('journal_scope_notice')}</p></div>;
}

function Checks({ checks, t }: { checks: Row; t: Translate }) {
    const good = checks.ties_to_journal && checks.journal_balanced && checks.statement_balances;
    return <section className="panel">
        <h2>{t('check_title')}</h2>
        <div className="metric-grid two">
            <div className="metric"><span>{t('check_journal_debit')}</span><strong>{money(checks.journal_debit)}</strong></div>
            <div className="metric"><span>{t('check_journal_credit')}</span><strong>{money(checks.journal_credit)}</strong></div>
        </div>
        <p className={good ? 'notice' : 'error'} role="status">{checks.ties_to_journal ? t('check_ties') : t('check_not_ties')} {checks.journal_balanced && checks.statement_balances ? t('check_balanced') : t('check_not_balanced')}</p>
        {checks.unclassified_accounts.length > 0 && <p className="notice warning">{t('check_unclassified', { accounts: checks.unclassified_accounts.join(', ') })}</p>}
    </section>;
}

function Downloads({ type, filters, exportBase, t }: { type: string; filters: Row; exportBase: string; t: Translate }) {
    const href = (format: string) => `${exportBase}?${new URLSearchParams({ type, format, from: filters.from, to: filters.to })}`;
    return <div className="button-row">
        <a className="button small" href={href('xlsx')}><Download size={14} aria-hidden="true" /> {t('download_excel')}</a>
        <a className="button small" href={href('pdf')}><Download size={14} aria-hidden="true" /> {t('download_pdf')}</a>
    </div>;
}

function StatementLines({ title, lines, total, totalLabel, t }: { title: string; lines: Row[]; total?: string; totalLabel?: string; t: Translate }) {
    return <div className="ops-mb-4">
        <h3>{title}</h3>
        <table className="data-table"><tbody>
            {lines.length === 0 && <tr><td className="muted" colSpan={2}>{t('no_lines')}</td></tr>}
            {lines.map(line => <tr key={line.account}><td>{line.label}</td><td className="num">{money(line.amount)}</td></tr>)}
            {totalLabel && <tr><th>{totalLabel}</th><th className="num">{money(total)}</th></tr>}
        </tbody></table>
    </div>;
}

function Statements({ statements, filters, exportBase, t }: Row & { t: Translate }) {
    const trial = statements.trial_balance;
    const pl = statements.profit_loss;
    const sheet = statements.balance_sheet;
    return <>
        <section className="panel">
            <form className="filter-bar" onSubmit={event => {
                event.preventDefault();
                const fields = new FormData(event.currentTarget);
                router.get('/operations/accounting', { from: String(fields.get('from')), to: String(fields.get('to')) }, { preserveState: true });
            }}>
                <label className="muted ptl-date-field">{t('period_from')}<input type="date" name="from" defaultValue={filters.from} required /></label>
                <label className="muted ptl-date-field">{t('period_to')}<input type="date" name="to" defaultValue={filters.to} required /></label>
                <button className="button small" type="submit">{t('show')}</button>
            </form>
        </section>
        <Notice t={t} />
        <Checks checks={statements.checks} t={t} />
        <section className="panel">
            <div className="panel-heading"><div><h2>{t('statement_trial_balance')}</h2><p className="muted">{t('balance_sign_hint')}</p></div><Downloads type="trial-balance" filters={filters} exportBase={exportBase} t={t} /></div>
            <Table searchable={false} rows={trial.rows} columns={[
                { key: 'label', title: t('column_account') },
                { key: 'opening', title: t('column_opening'), money: true, render: (row: Row) => money(row.opening) },
                { key: 'debit', title: t('column_debit'), money: true, total: true, render: (row: Row) => money(row.debit) },
                { key: 'credit', title: t('column_credit'), money: true, total: true, render: (row: Row) => money(row.credit) },
                { key: 'closing', title: t('column_closing'), money: true, render: (row: Row) => money(row.closing) },
            ]} empty={t('no_lines')} />
        </section>
        <section className="panel">
            <div className="panel-heading"><h2>{t('statement_profit_loss')}</h2><Downloads type="profit-loss" filters={filters} exportBase={exportBase} t={t} /></div>
            <StatementLines title={t('section_income')} lines={pl.income} total={pl.total_income} totalLabel={t('total_income')} t={t} />
            <StatementLines title={t('section_expenses')} lines={pl.expenses} total={pl.total_expenses} totalLabel={t('total_expenses')} t={t} />
            <div className="metric"><span>{t('result')}</span><strong>{money(pl.result)}</strong></div>
        </section>
        <section className="panel">
            <div className="panel-heading"><h2>{t('statement_balance_sheet')}</h2><Downloads type="balance-sheet" filters={filters} exportBase={exportBase} t={t} /></div>
            <StatementLines title={t('section_assets')} lines={[...sheet.assets, ...sheet.unclassified]} total={sheet.total_assets} totalLabel={t('total_assets')} t={t} />
            <StatementLines title={t('section_liabilities')} lines={sheet.liabilities} total={sheet.total_liabilities} totalLabel={t('total_liabilities')} t={t} />
            <StatementLines title={t('section_equity')} lines={[...sheet.equity, { account: 'earlier', label: t('earlier_result'), amount: sheet.earlier_result }, { account: 'period', label: t('period_result'), amount: sheet.period_result }]} total={sheet.total_liabilities_and_equity} totalLabel={t('total_liabilities_and_equity')} t={t} />
        </section>
    </>;
}

function ApprovalLimit({ approvalLimit, t }: Row & { t: Translate }) {
    const form = useForm({ amount: approvalLimit.amount, version: approvalLimit.version, reason: '' });
    return <section className="panel">
        <h2>{t('limit_title')}</h2>
        <p className="muted">{t('limit_hint')}</p>
        <div className="metric"><span>{t('limit_current')}</span><strong>{money(approvalLimit.amount)}</strong><small>{t('limit_default', { amount: money(approvalLimit.default) })}</small></div>
        {approvalLimit.can_edit ? <form className="action-form" onSubmit={event => {
            event.preventDefault();
            form.post('/commands/operations/accounting/approval-limit', { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
        }}>
            <label>{t('limit_new_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} /></label>
            <label>{t('limit_reason')}<textarea required minLength={5} maxLength={1000} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
            {Object.keys(form.errors).length > 0 && <p role="alert" className="error">{Object.values(form.errors).join(' ')}</p>}
            <button className="button dark" disabled={form.processing}>{t('limit_save')}</button>
        </form> : <p className="notice">{t('limit_owner_only')}</p>}
    </section>;
}

export default function Accounting(props: Row) {
    const translate = useT();
    const t: Translate = (key, replace = {}) => translate('accounting', key, replace);
    const { tab } = props;

    return <Shell section="accounting"><Head title={t('title')} />
        <div className="section-header"><div><h2>{t('title')}</h2><p className="muted">{t('intro')}</p></div></div>
        <nav className="preset-chips" aria-label={t('title')}>
            {TABS.map(name => <Link key={name} className={`button small ${tab === name ? 'dark' : ''}`} href={`/operations/accounting?tab=${name}`} preserveState>{t(`tab_${name}`)}</Link>)}
        </nav>
        {tab === 'statements' && <Statements {...props} t={t} />}
        {tab === 'limit' && <ApprovalLimit {...props} t={t} />}
    </Shell>;
}

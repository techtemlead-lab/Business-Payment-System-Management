import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { ActionForm, Badge, DateInput, Table, date, dateTime, label, money, number, type Row } from './UI';
import JargonHint from './JargonHint';
import { SubViewChips, useSubView } from './PartnerHubNav';
import { useT } from '../shared/i18n/t';
import { randomUuid } from '../shared/randomUuid';

const EARNINGS_VIEWS = ['list', 'ledger', 'monthly'] as const;

/** The earnings tab: what can be withdrawn, then one list at a time. */
function EarningsTab({ data: d, canWithdraw }: { data: Row; canWithdraw: boolean }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [view, chooseView] = useSubView(EARNINGS_VIEWS, 'list');
    const filters = d.earnings_filters ?? {};
    const hasDates = Boolean(filters.from || filters.to);

    return (
        <>
            <div className="metric-grid partner-money-two" data-tour="wallet-balances">
                <div className="metric"><span>{translate('portal', 'available_earnings')}</span><strong>{money(d.withdrawal_balances?.available ?? d.wallet?.available)}</strong></div>
                <div className="metric"><span>{translate('partner_hub', 'earned_in_period')}</span><strong>{money(d.period_totals?.commission)}</strong><small>{translate('partner_hub', hasDates ? 'earned_in_period_hint' : 'earned_all_time_hint')}</small></div>
            </div>
            {canWithdraw && (
                <div className="toolbar-end ops-mb-4">
                    <Link className="button dark" href={`${base}/withdrawals`} data-tour="withdrawal-form">{translate('partner_hub', 'withdraw_cta')} <ArrowUpRight size={15} /></Link>
                </div>
            )}
            <section className="panel" data-tour="commission-ledger">
                <div className="panel-heading"><div><h2>{translate('portal', 'earnings_and_reserves')}</h2><p className="muted">{translate('portal', 'filter_business_dates')}</p></div></div>
                <form className="filter-bar" onSubmit={(event) => { event.preventDefault(); const fd = new FormData(event.currentTarget); router.get(`${base}/earnings`, { from: String(fd.get('from') || '') || undefined, to: String(fd.get('to') || '') || undefined, preset: String(fd.get('preset') || '') || undefined }, { preserveState: true }); }}>
                    <div className="preset-chips">{['today', 'week', 'month', 'year'].map((preset) => <button type="button" key={preset} className={`button small ${filters.preset === preset ? 'dark' : ''}`} onClick={() => router.get(`${base}/earnings`, { preset }, { preserveState: true })}>{translate('portal', `preset_${preset}`)}</button>)}</div>
                    <label className="muted ptl-date-field">{translate('portal', 'date_from')}<DateInput name="from" defaultValue={filters.from || ''} /></label>
                    <label className="muted ptl-date-field">{translate('portal', 'date_to')}<DateInput name="to" defaultValue={filters.to || ''} /></label>
                    <button className="button small" type="submit">{translate('portal', 'apply_dates')}</button>
                    {hasDates && <Link href={`${base}/earnings`} className="text-link" preserveState>{translate('portal', 'clear')}</Link>}
                </form>
                <SubViewChips
                    active={view}
                    onSelect={chooseView}
                    items={[
                        { key: 'list', label: translate('partner_hub', 'view_earnings_list') },
                        { key: 'ledger', label: translate('portal', 'wallet_statement') },
                        { key: 'monthly', label: translate('portal', 'monthly_credits') },
                    ]}
                />
                {view === 'list' && <Table filename="earnings" rows={d.earnings ?? []} columns={[
                    { key: 'created_at', title: translate('portal', 'col_date'), render: (r: Row) => date(r.created_at) },
                    { key: 'category', title: translate('portal', 'col_category'), render: (r: Row) => label(r.category, translate) },
                    { key: 'rate_percentage', title: translate('portal', 'col_rate'), align: 'right', render: (r: Row) => r.rate_percentage ? `${number(r.rate_percentage)}%` : '—' },
                    { key: 'source_name', title: translate('portal', 'col_source_account'), render: (r: Row) => r.source_name ? <>{r.source_name}<small className="cell-sub">{r.source_code || ''}</small></> : '—' },
                    { key: 'order_number', title: translate('portal', 'col_order'), render: (r: Row) => r.order_number || '—' },
                    { key: 'amount', title: translate('portal', 'col_amount'), money: true, footer: (rows: Row[]) => money(rows.filter((r) => r.status !== 'reversed').reduce((sum, r) => sum + Number(r.amount || 0), 0)), render: (r: Row) => r.status === 'reversed' ? <s className="muted">{money(r.amount)}</s> : money(r.amount) },
                    { key: 'status', title: translate('portal', 'col_status'), render: (r: Row) => <Badge status={r.status} /> },
                ]} empty={translate('portal', 'no_posted_earnings')} />}
                {view === 'ledger' && <>
                    <div className="panel-heading">
                        <p className="muted">{translate('portal', 'wallet_statement_hint')}</p>
                        <Link className="button small" href={`${base}/statement`}>{translate('portal', 'stmt_open_full')}</Link>
                    </div>
                    <div className="metric-grid three ops-mb-4">
                        <div className="metric"><span>{translate('portal', 'opening_balance')}</span><strong>{money(d.wallet_statement?.opening_balance)}</strong></div>
                        <div className="metric"><span>{translate('portal', 'closing_balance')}</span><strong>{money(d.wallet_statement?.closing_balance)}</strong></div>
                        <div className="metric"><span>{translate('portal', 'period_net')}</span><strong>{money(d.wallet_statement?.period_net)}</strong></div>
                    </div>
                    <Table filename="wallet-statement" rows={d.wallet_statement?.lines ?? []} columns={[
                        { key: 'occurred_at', title: translate('portal', 'col_date'), render: (r: Row) => dateTime(r.occurred_at) },
                        { key: 'description', title: translate('portal', 'col_description') },
                        { key: 'account', title: translate('portal', 'col_category'), render: (r: Row) => label(r.account, translate) },
                        { key: 'debit', title: translate('portal', 'col_debit'), money: true, total: true, render: (r: Row) => money(r.debit) },
                        { key: 'credit', title: translate('portal', 'col_credit'), money: true, total: true, render: (r: Row) => money(r.credit) },
                        { key: 'balance', title: translate('portal', 'col_balance'), money: true, render: (r: Row) => money(r.balance) },
                    ]} empty={translate('portal', 'no_wallet_movements')} />
                </>}
                {view === 'monthly' && <Table filename="monthly-credits" rows={d.settlement_payouts ?? []} columns={[
                    { key: 'period_month', title: translate('portal', 'col_month') },
                    { key: 'kind', title: translate('portal', 'col_credit_kind'), render: (r: Row) => label(r.kind, translate) },
                    { key: 'amount', title: translate('portal', 'col_net_after_reversals'), money: true, total: true, sortValue: (r: Row) => Number(r.amount || 0) - Number(r.reversed_amount || 0), render: (r: Row) => money(Number(r.amount || 0) - Number(r.reversed_amount || 0)) },
                ]} empty={translate('portal', 'no_monthly_credits')} />}
            </section>
        </>
    );
}

/** The withdrawal tab: balances, the request form and past withdrawals. */
function WithdrawalsTab({ data: d, canWithdraw, history }: { data: Row; canWithdraw: boolean; history: ReactNode }) {
    const translate = useT();
    const { financeActive, withdrawalMinimum } = usePage<any>().props;

    return (
        <>
            {canWithdraw && (
                <section className="panel" data-tour="withdrawal-form">
                    <h2>{translate('portal', 'request_withdrawal')}</h2>
                    <ActionForm action="/commands/withdrawals" defaults={{ idempotency_key: randomUuid() }} fields={[
                        { name: 'amount', label: translate('portal', 'amount_minimum', { amount: String(withdrawalMinimum) }), required: true },
                        { name: 'channel', label: translate('portal', 'payment_method'), required: true, options: [{ value: 'Bank', label: translate('portal', 'pay_channel_bank') }, { value: 'bKash', label: translate('portal', 'pay_channel_bkash') }, { value: 'Nagad', label: translate('portal', 'pay_channel_nagad') }, { value: 'Rocket', label: translate('portal', 'pay_channel_rocket') }] },
                        { name: 'destination', label: translate('portal', 'account_or_wallet'), required: true },
                    ]} submit={translate('portal', 'request_withdrawal')} />
                </section>
            )}
            {!financeActive && <div className="notice warning" data-tour="withdrawal-locked"><h3>{translate('portal', 'withdrawals_temporarily_locked')}</h3><p>{translate('portal', 'withdrawals_pending_activation_notice')}</p></div>}
            <div className="metric-grid four" data-tour="wallet-balances">
                <div className="metric"><span>{translate('portal', 'available_earnings')}</span><strong>{money(d.withdrawal_balances?.available ?? d.wallet?.available)}</strong></div>
                <div className="metric"><span>{translate('portal', 'total_withdrawn')}</span><strong>{money(d.withdrawal_balances?.total_withdrawn)}</strong></div>
                <div className="metric"><span>{translate('portal', 'pending_withdrawal')}</span><strong>{money(d.withdrawal_balances?.pending_withdrawal)}</strong></div>
                <div className="metric"><span>{translate('portal', 'minimum_withdrawal')}</span><strong>{money(withdrawalMinimum)}</strong><small>{translate('portal', 'withdrawal_subject')}</small></div>
            </div>
            <JargonHint terms={['tds']} />
            {history}
        </>
    );
}

/**
 * A partner's earnings and withdrawal tabs under "আমার টাকা". The office's withdrawal
 * page is separate and stays in Portal.tsx. Every figure here comes straight from the server.
 */
export default function PartnerMoneyHub({ section, data, gate, history }: { section: 'earnings' | 'withdrawals'; data: Row; gate: ReactNode; history: ReactNode }) {
    const translate = useT();
    const { financeActive } = usePage<any>().props;
    const kycBlocked = Boolean(data.withdrawal_kyc_block);
    const canWithdraw = Boolean(financeActive) && !kycBlocked;

    return (
        <>
            {gate}
            {financeActive && kycBlocked && <div className="notice warning" data-tour="withdrawal-kyc-needed" role="alert"><h3>{translate('portal', 'wd_kyc_needed_title')}</h3><p>{translate('portal', 'wd_kyc_needed_body')}</p></div>}
            {section === 'earnings' ? <EarningsTab data={data} canWithdraw={canWithdraw} /> : <WithdrawalsTab data={data} canWithdraw={canWithdraw} history={history} />}
        </>
    );
}

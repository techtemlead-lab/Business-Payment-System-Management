import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Download, Landmark, Plus } from 'lucide-react';
import { Badge, DateInput, Empty, FormErrors, Modal, Pagination, date, money, type Row } from './UI';
import { useT } from '../shared/i18n/t';
import { businessTodayIso } from '../business-date';

type Translate = ReturnType<typeof useT>;

type FundRow = { key: string; money_in: string; money_out: string; pending: string; balance: string; available: string; spendable: boolean; award_only: boolean };
type Head = { id: number; name_en: string; name_bn: string; is_active: boolean; for_tours: boolean };
type Approval = Row & { id: number; fund_key: string; amount: string; can_review: boolean };
type ReportData = {
    by_fund: { fund: string; total: string; spends: number }[];
    by_head: { head_id: number | null; name_en: string | null; name_bn: string | null; total: string; spends: number }[];
    total: string;
    count: number;
    lines: Row;
};
export type CompanyFundsData = {
    tab: string;
    funds: FundRow[];
    selected: string | null;
    money_in: Row | null;
    money_out: Row | null;
    approvals: Approval[];
    approvals_waiting: number;
    heads: Head[];
    methods: string[];
    filters: { from: string; to: string; fund: string | null; head: number | null };
    report: ReportData | null;
};

const ROOT = '/commands/operations/company-funds';

/** Owners and finance staff: what each company fund holds, money taken out and what it was spent on. */
export default function CompanyFundsHub({ data }: { data?: CompanyFundsData | null }) {
    const translate = useT();
    const { portalBase: base, locale } = usePage<any>().props;
    const [spending, setSpending] = useState<FundRow | null>(null);
    if (!data) {
        return null;
    }
    const headName = (head: { name_en?: string | null; name_bn?: string | null } | Row | null | undefined): string => (locale === 'bn' ? head?.name_bn : head?.name_en) || translate('funds', 'no_head');
    const open = (query: Record<string, string | number | null | undefined>) => router.get(`${base}/company-funds`, Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '')) as Record<string, string>, { preserveScroll: true, preserveState: true });
    const tabs = [
        { id: 'funds', label: translate('funds', 'tab_funds') },
        { id: 'approvals', label: `${translate('funds', 'tab_approvals')}${data.approvals_waiting ? ` (${data.approvals_waiting})` : ''}` },
        { id: 'heads', label: translate('funds', 'tab_heads') },
        { id: 'report', label: translate('funds', 'tab_report') },
    ];
    const selectedFund = data.funds.find((fund) => fund.key === data.selected) ?? null;

    return (
        <div className="rx-hub">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2 className="ops-row is-inline"><Landmark size={20} /> {translate('funds', 'title')}</h2>
                        <p className="muted">{translate('funds', 'intro')}</p>
                    </div>
                </div>
                <div className="retained-detail-tabs rx-tabs" role="tablist">
                    {tabs.map((tab) => (
                        <button key={tab.id} type="button" role="tab" aria-selected={tab.id === data.tab} className={tab.id === data.tab ? 'is-active' : undefined} onClick={() => open({ tab: tab.id })}>
                            {tab.label}
                        </button>
                    ))}
                </div>
            </section>
            {data.tab === 'funds' && <FundsTab data={data} translate={translate} open={open} onSpend={setSpending} selectedFund={selectedFund} headName={headName} />}
            {data.tab === 'approvals' && <ApprovalsTab approvals={data.approvals} translate={translate} headName={headName} />}
            {data.tab === 'heads' && <HeadsTab heads={data.heads} translate={translate} />}
            {data.tab === 'report' && data.report && <ReportTab data={data} report={data.report} translate={translate} open={open} headName={headName} />}
            {spending && <SpendForm fund={spending} heads={data.heads} methods={data.methods} translate={translate} headName={headName} onClose={() => setSpending(null)} />}
        </div>
    );
}

function FundsTab({ data, translate, open, onSpend, selectedFund, headName }: { data: CompanyFundsData; translate: Translate; open: (query: Record<string, string | number | null | undefined>) => void; onSpend: (fund: FundRow) => void; selectedFund: FundRow | null; headName: (head: Row | null | undefined) => string }) {
    return (
        <>
            <section className="panel">
                <div className="table-wrap">
                    <table className="rx-table">
                        <thead>
                            <tr>
                                <th>{translate('funds', 'col_fund')}</th>
                                <th className="num">{translate('funds', 'col_money_in')}</th>
                                <th className="num">{translate('funds', 'col_money_out')}</th>
                                <th className="num">{translate('funds', 'col_waiting')}</th>
                                <th className="num">{translate('funds', 'col_balance')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {data.funds.map((fund) => (
                                <tr key={fund.key}>
                                    <td>
                                        <b>{translate('funds', `fund_${fund.key}`)}</b>
                                        {fund.award_only && <small className="cell-sub">{translate('funds', 'award_only_badge')}</small>}
                                    </td>
                                    <td className="num">{money(fund.money_in)}</td>
                                    <td className="num">{money(fund.money_out)}</td>
                                    <td className="num">{money(fund.pending)}</td>
                                    <td className="num"><b>{money(fund.balance)}</b></td>
                                    <td>
                                        <div className="ops-row is-inline">
                                            <button type="button" className="button small" onClick={() => open(selectedFund?.key === fund.key ? { tab: 'funds' } : { tab: 'funds', fund: fund.key })}>
                                                {translate('funds', selectedFund?.key === fund.key ? 'hide_history' : 'view_history')}
                                            </button>
                                            {fund.spendable && <button type="button" className="button dark" onClick={() => onSpend(fund)}>{translate('funds', 'spend')}</button>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
            {selectedFund && data.money_in && data.money_out && <FundHistory fund={selectedFund} moneyIn={data.money_in} moneyOut={data.money_out} translate={translate} headName={headName} />}
        </>
    );
}

function FundHistory({ fund, moneyIn, moneyOut, translate, headName }: { fund: FundRow; moneyIn: Row; moneyOut: Row; translate: Translate; headName: (head: Row | null | undefined) => string }) {
    const hintKey = fund.award_only ? 'award_only_hint' : fund.key === 'tour' ? 'tour_rule' : fund.key === 'clinic' ? 'negative_hint' : fund.key === 'geographic_reserve' ? 'old_area_hint' : fund.key === 'company_profit' ? 'company_profit_hint' : null;
    const metric = (key: string, value: string) => <div className="metric"><span>{translate('funds', key)}</span><strong>{money(value)}</strong></div>;
    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('funds', `fund_${fund.key}`)}</h2>
                    {hintKey && <p className="muted">{translate('funds', hintKey)}</p>}
                </div>
            </div>
            <div className="metric-grid four">
                {metric('col_money_in', fund.money_in)}
                {metric('col_money_out', fund.money_out)}
                {metric('col_waiting', fund.pending)}
                {metric('col_balance', fund.balance)}
            </div>
            <h3>{translate('funds', 'history_money_in')}</h3>
            {(moneyIn.data ?? []).length === 0 ? <Empty title={translate('funds', 'empty_money_in')} /> : (
                <div className="table-wrap">
                    <table className="rx-table">
                        <thead><tr><th>{translate('funds', 'col_date')}</th><th>{translate('funds', 'col_source')}</th><th>{translate('funds', 'col_from')}</th><th className="num">{translate('funds', 'col_amount')}</th></tr></thead>
                        <tbody>
                            {(moneyIn.data as Row[]).map((row, index) => (
                                <tr key={index}>
                                    <td>{date(row.created_at)}</td>
                                    <td>{translate('funds', row.kind === 'clinic' ? 'source_clinic' : 'source_order')} {row.reference}{row.source_label && <small className="cell-sub">{row.source_label}</small>}</td>
                                    <td>{row.from_name || '—'}</td>
                                    <td className="num">{money(row.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <Pagination page={moneyIn} />
            <h3>{translate('funds', 'history_money_out')}</h3>
            {(moneyOut.data ?? []).length === 0 ? <Empty title={translate('funds', 'empty_money_out')} /> : (
                <div className="table-wrap">
                    <table className="rx-table">
                        <thead><tr><th>{translate('funds', 'col_date')}</th><th>{translate('funds', 'col_head')}</th><th>{translate('funds', 'col_payee')}</th><th>{translate('funds', 'col_method')}</th><th>{translate('funds', 'col_reference')}</th><th>{translate('funds', 'col_receipt')}</th><th>{translate('funds', 'col_status')}</th><th className="num">{translate('funds', 'col_amount')}</th></tr></thead>
                        <tbody>
                            {(moneyOut.data as Row[]).map((row) => (
                                <tr key={row.id}>
                                    <td>{date(row.spent_on)}</td>
                                    <td>{headName({ name_en: row.head_en, name_bn: row.head_bn })}{row.reason && <small className="cell-sub">{row.reason}</small>}</td>
                                    <td>{row.payee || '—'}</td>
                                    <td>{row.payment_method ? translate('funds', `method_${row.payment_method}`) : '—'}</td>
                                    <td>{row.payment_reference || '—'}</td>
                                    <td>{row.has_receipt ? <a href={`/commands/operations/receipts/reserve/${row.id}`}>{translate('funds', 'col_receipt')}</a> : '—'}</td>
                                    <td><Badge status={row.status} /></td>
                                    <td className="num">{money(row.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <Pagination page={moneyOut} />
        </section>
    );
}

function SpendForm({ fund, heads, methods, translate, headName, onClose }: { fund: FundRow; heads: Head[]; methods: string[]; translate: Translate; headName: (head: Row | null | undefined) => string; onClose: () => void }) {
    const form = useForm<Row>({ fund: fund.key, expense_head_id: '', amount: '', spent_on: businessTodayIso(), payee: '', payment_method: '', reference: '', note: '', receipt: null, request_key: crypto.randomUUID() });
    const usableHeads = heads.filter((head) => head.is_active && (fund.key !== 'tour' || head.for_tours));
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(`${ROOT}/spends`, { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };
    return (
        <Modal title={translate('funds', 'form_title', { fund: translate('funds', `fund_${fund.key}`) })} onClose={onClose}>
            <form className="action-form" onSubmit={submit}>
                <p className="muted">{translate('funds', 'form_available', { amount: money(fund.available) })}</p>
                {fund.key === 'tour' && <p className="muted">{translate('funds', 'tour_rule')}</p>}
                <label>{translate('funds', 'fld_head')}
                    <select required value={form.data.expense_head_id} onChange={(event) => form.setData('expense_head_id', event.target.value)}>
                        <option value="">{translate('funds', 'choose_head')}</option>
                        {usableHeads.map((head) => <option key={head.id} value={head.id}>{headName(head)}</option>)}
                    </select>
                </label>
                <label>{translate('funds', 'fld_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} /></label>
                <label>{translate('funds', 'fld_date')}<DateInput required value={form.data.spent_on} onChange={(event) => form.setData('spent_on', event.target.value)} /></label>
                <label>{translate('funds', 'fld_payee')}<input required maxLength={150} value={form.data.payee} onChange={(event) => form.setData('payee', event.target.value)} /></label>
                <label>{translate('funds', 'fld_method')}
                    <select required value={form.data.payment_method} onChange={(event) => form.setData('payment_method', event.target.value)}>
                        <option value="">{translate('funds', 'choose_method')}</option>
                        {methods.map((method) => <option key={method} value={method}>{translate('funds', `method_${method}`)}</option>)}
                    </select>
                </label>
                <label>{translate('funds', 'fld_reference')}<input maxLength={100} required={form.data.payment_method !== 'cash'} value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} /></label>
                <label>{translate('funds', 'fld_receipt')}<input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => form.setData('receipt', event.target.files?.[0] ?? null)} /></label>
                <label>{translate('funds', 'fld_note')}<textarea maxLength={1000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} /></label>
                <FormErrors errors={form.errors} />
                <div className="ops-row is-inline">
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('funds', 'save_spend')}</button>
                    <button type="button" className="button small" onClick={onClose}>{translate('funds', 'cancel')}</button>
                </div>
            </form>
        </Modal>
    );
}

function ApprovalsTab({ approvals, translate, headName }: { approvals: Approval[]; translate: Translate; headName: (head: Row | null | undefined) => string }) {
    const [review, setReview] = useState<{ row: Approval; decision: 'approved' | 'rejected' } | null>(null);
    const form = useForm<Row>({ decision: '', reason: '' });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (!review) {
            return;
        }
        form.transform((current) => ({ ...current, decision: review.decision }));
        form.post(`${ROOT}/spends/${review.row.id}/review`, { preserveScroll: true, onSuccess: () => { setReview(null); form.reset(); } });
    };
    if (approvals.length === 0) {
        return <section className="panel"><Empty title={translate('funds', 'approvals_empty_title')} body={translate('funds', 'approvals_empty_body')} /></section>;
    }
    return (
        <section className="panel">
            <div className="table-wrap">
                <table className="rx-table">
                    <thead><tr><th>{translate('funds', 'col_date')}</th><th>{translate('funds', 'col_fund')}</th><th>{translate('funds', 'col_head')}</th><th>{translate('funds', 'col_payee')}</th><th>{translate('funds', 'col_method')}</th><th>{translate('funds', 'col_reference')}</th><th>{translate('funds', 'col_recorded_by')}</th><th className="num">{translate('funds', 'col_amount')}</th><th /></tr></thead>
                    <tbody>
                        {approvals.map((row) => (
                            <tr key={row.id}>
                                <td>{date(row.spent_on)}</td>
                                <td>{translate('funds', `fund_${row.fund_key}`)}</td>
                                <td>{headName(row)}{row.reason && <small className="cell-sub">{row.reason}</small>}</td>
                                <td>{row.payee_name}</td>
                                <td>{translate('funds', `method_${row.payment_method}`)}</td>
                                <td>{row.payment_reference || '—'}{row.has_receipt && <small className="cell-sub"><a href={`/commands/operations/receipts/reserve/${row.id}`}>{translate('funds', 'col_receipt')}</a></small>}</td>
                                <td>{row.maker_name}</td>
                                <td className="num"><b>{money(row.amount)}</b></td>
                                <td>
                                    {row.can_review ? (
                                        <div className="ops-row is-inline">
                                            <button type="button" className="button dark" onClick={() => setReview({ row, decision: 'approved' })}>{translate('funds', 'approve')}</button>
                                            <button type="button" className="button small" onClick={() => setReview({ row, decision: 'rejected' })}>{translate('funds', 'reject')}</button>
                                        </div>
                                    ) : <small className="muted">{translate('funds', 'own_spend_hint')}</small>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {review && (
                <Modal title={translate('funds', review.decision === 'approved' ? 'approve_title' : 'reject_title')} onClose={() => setReview(null)}>
                    <form className="action-form" onSubmit={submit}>
                        <p><b>{money(review.row.amount)}</b> · {translate('funds', `fund_${review.row.fund_key}`)} · {review.row.payee_name}</p>
                        <label>{translate('funds', 'fld_reason')}<textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /></label>
                        <FormErrors errors={form.errors} />
                        <div className="ops-row is-inline">
                            <button type="submit" className="button dark" disabled={form.processing}>{translate('funds', review.decision === 'approved' ? 'approve' : 'reject')}</button>
                            <button type="button" className="button small" onClick={() => setReview(null)}>{translate('funds', 'cancel')}</button>
                        </div>
                    </form>
                </Modal>
            )}
        </section>
    );
}

function HeadsTab({ heads, translate }: { heads: Head[]; translate: Translate }) {
    const [editing, setEditing] = useState<Head | 'new' | null>(null);
    const toggle = (head: Head) => router.patch(`${ROOT}/heads/${head.id}`, { name_en: head.name_en, name_bn: head.name_bn, for_tours: head.for_tours, is_active: !head.is_active }, { preserveScroll: true });
    return (
        <section className="panel">
            <div className="panel-heading">
                <div><p className="muted">{translate('funds', 'heads_hint')}</p></div>
                <button type="button" className="button dark" onClick={() => setEditing('new')}><Plus size={16} /> {translate('funds', 'heads_add')}</button>
            </div>
            <div className="table-wrap">
                <table className="rx-table">
                    <thead><tr><th>{translate('funds', 'col_name_en')}</th><th>{translate('funds', 'col_name_bn')}</th><th>{translate('funds', 'col_status')}</th><th /></tr></thead>
                    <tbody>
                        {heads.map((head) => (
                            <tr key={head.id}>
                                <td>{head.name_en}{head.for_tours && <small className="cell-sub">{translate('funds', 'for_tours')}</small>}</td>
                                <td>{head.name_bn}</td>
                                <td>{translate('funds', head.is_active ? 'heads_active' : 'heads_inactive')}</td>
                                <td>
                                    <div className="ops-row is-inline">
                                        <button type="button" className="button small" onClick={() => setEditing(head)}>{translate('funds', 'heads_edit')}</button>
                                        <button type="button" className="button small" onClick={() => toggle(head)}>{translate('funds', head.is_active ? 'head_deactivate' : 'head_activate')}</button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {editing && <HeadForm head={editing === 'new' ? null : editing} translate={translate} onClose={() => setEditing(null)} />}
        </section>
    );
}

function HeadForm({ head, translate, onClose }: { head: Head | null; translate: Translate; onClose: () => void }) {
    const form = useForm<Row>({ name_en: head?.name_en ?? '', name_bn: head?.name_bn ?? '', for_tours: head?.for_tours ?? false, ...(head ? { is_active: head.is_active } : {}) });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (head) {
            form.patch(`${ROOT}/heads/${head.id}`, options);
        } else {
            form.post(`${ROOT}/heads`, options);
        }
    };
    return (
        <Modal title={translate('funds', head ? 'heads_edit' : 'heads_add')} onClose={onClose}>
            <form className="action-form" onSubmit={submit}>
                <label>{translate('funds', 'col_name_en')}<input required maxLength={120} value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} /></label>
                <label>{translate('funds', 'col_name_bn')}<input required maxLength={120} value={form.data.name_bn} onChange={(event) => form.setData('name_bn', event.target.value)} /></label>
                <label className="ops-row is-inline"><input type="checkbox" checked={Boolean(form.data.for_tours)} onChange={(event) => form.setData('for_tours', event.target.checked)} /> {translate('funds', 'for_tours')}</label>
                <p className="muted">{translate('funds', 'for_tours_hint')}</p>
                <FormErrors errors={form.errors} />
                <div className="ops-row is-inline">
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('funds', 'save_head')}</button>
                    <button type="button" className="button small" onClick={onClose}>{translate('funds', 'cancel')}</button>
                </div>
            </form>
        </Modal>
    );
}

function ReportTab({ data, report, translate, open, headName }: { data: CompanyFundsData; report: ReportData; translate: Translate; open: (query: Record<string, string | number | null | undefined>) => void; headName: (head: Row | null | undefined) => string }) {
    const [filters, setFilters] = useState({ from: data.filters.from, to: data.filters.to, fund: data.filters.fund ?? '', head: data.filters.head ? String(data.filters.head) : '' });
    const set = (key: keyof typeof filters, value: string) => setFilters((current) => ({ ...current, [key]: value }));
    const exportHref = `/commands/operations/reports?${new URLSearchParams(Object.fromEntries(Object.entries({ type: 'fund-spending', format: 'xlsx', from: filters.from, to: filters.to, fund: filters.fund, head: filters.head }).filter(([, value]) => value !== ''))).toString()}`;
    const lines = (report.lines.data ?? []) as Row[];
    return (
        <>
            <section className="panel">
                <form className="ops-row is-inline" onSubmit={(event) => { event.preventDefault(); open({ tab: 'report', ...filters }); }}>
                    <label>{translate('funds', 'report_from')}<DateInput value={filters.from} onChange={(event) => set('from', event.target.value)} /></label>
                    <label>{translate('funds', 'report_to')}<DateInput value={filters.to} onChange={(event) => set('to', event.target.value)} /></label>
                    <label>{translate('funds', 'col_fund')}
                        <select value={filters.fund} onChange={(event) => set('fund', event.target.value)}>
                            <option value="">{translate('funds', 'report_all_funds')}</option>
                            {data.funds.map((fund) => <option key={fund.key} value={fund.key}>{translate('funds', `fund_${fund.key}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('funds', 'col_head')}
                        <select value={filters.head} onChange={(event) => set('head', event.target.value)}>
                            <option value="">{translate('funds', 'report_all_heads')}</option>
                            {data.heads.map((head) => <option key={head.id} value={head.id}>{headName(head)}</option>)}
                        </select>
                    </label>
                    <button type="submit" className="button dark">{translate('funds', 'report_show')}</button>
                    <a className="button small" href={exportHref}><Download size={16} /> {translate('funds', 'export')}</a>
                </form>
            </section>
            <section className="panel">
                <div className="metric-grid four"><div className="metric"><span>{translate('funds', 'report_total')}</span><strong>{money(report.total)}</strong><small>{translate('funds', 'col_spends')}: {report.count}</small></div></div>
                {report.count === 0 ? <Empty title={translate('funds', 'report_empty')} /> : (
                    <>
                        <h3>{translate('funds', 'report_by_fund')}</h3>
                        <div className="table-wrap"><table className="rx-table"><thead><tr><th>{translate('funds', 'col_fund')}</th><th className="num">{translate('funds', 'col_spends')}</th><th className="num">{translate('funds', 'col_amount')}</th></tr></thead><tbody>
                            {report.by_fund.map((row) => <tr key={row.fund}><td>{translate('funds', `fund_${row.fund}`)}</td><td className="num">{row.spends}</td><td className="num">{money(row.total)}</td></tr>)}
                        </tbody></table></div>
                        <h3>{translate('funds', 'report_by_head')}</h3>
                        <div className="table-wrap"><table className="rx-table"><thead><tr><th>{translate('funds', 'col_head')}</th><th className="num">{translate('funds', 'col_spends')}</th><th className="num">{translate('funds', 'col_amount')}</th></tr></thead><tbody>
                            {report.by_head.map((row) => <tr key={row.head_id ?? 'none'}><td>{headName(row)}</td><td className="num">{row.spends}</td><td className="num">{money(row.total)}</td></tr>)}
                        </tbody></table></div>
                        <h3>{translate('funds', 'report_lines')}</h3>
                        <div className="table-wrap"><table className="rx-table"><thead><tr><th>{translate('funds', 'col_date')}</th><th>{translate('funds', 'col_fund')}</th><th>{translate('funds', 'col_head')}</th><th>{translate('funds', 'col_payee')}</th><th>{translate('funds', 'col_method')}</th><th>{translate('funds', 'col_reference')}</th><th className="num">{translate('funds', 'col_amount')}</th></tr></thead><tbody>
                            {lines.map((row) => <tr key={row.id}><td>{date(row.spent_on)}</td><td>{translate('funds', `fund_${row.fund}`)}</td><td>{headName({ name_en: row.head_en, name_bn: row.head_bn })}</td><td>{row.payee || '—'}</td><td>{row.payment_method ? translate('funds', `method_${row.payment_method}`) : '—'}</td><td>{row.payment_reference || '—'}</td><td className="num">{money(row.amount)}</td></tr>)}
                        </tbody></table></div>
                        <Pagination page={report.lines} />
                    </>
                )}
            </section>
        </>
    );
}

import { useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { FormErrors, Modal, Table, date, money, number, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { randomUuid } from '../../../shared/randomUuid';

export type StaleHoldingsReport = {
    days: number;
    version: number;
    default: number;
    can_edit: boolean;
    can_write_off: boolean;
    items: Row[];
    summary: { kind: string; count: number; amount: string }[];
};

/** "Old money": everything that waited longer than the owner's limit, with where to act; the owner sets the days and writes off refund debts here. */
export default function StaleHoldingsPanel({ report }: { report: StaleHoldingsReport }) {
    const translate = useT();
    const [writingOff, setWritingOff] = useState<Row | null>(null);
    const days = useForm({ days: String(report.days), version: report.version });

    return (
        <>
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('money_core', 'sh_title', { days: number(report.days) })}</h2>
                        <p className="muted">{translate('money_core', 'sh_intro')}</p>
                    </div>
                </div>
                {report.summary.length > 0 && (
                    <ul className="aed-docs">
                        {report.summary.map((kind) => (
                            <li key={kind.kind}>{translate('money_core', `stale_kind_${kind.kind}`)}: <strong>{number(kind.count)}</strong> · <strong>{money(kind.amount)}</strong></li>
                        ))}
                    </ul>
                )}
                {report.can_edit && (
                    <form className="action-form" onSubmit={(event) => { event.preventDefault(); days.post('/commands/stale-holdings/days', { preserveScroll: true }); }}>
                        <label>{translate('money_core', 'sh_days_label')}
                            <input type="number" min={1} max={365} step={1} required value={days.data.days} onChange={(event) => days.setData('days', event.target.value)} />
                            <small>{translate('money_core', 'sh_days_hint', { default: number(report.default) })}</small>
                        </label>
                        <FormErrors errors={days.errors} />
                        <button className="button dark" disabled={days.processing}>{translate('money_core', 'sh_days_save')}</button>
                    </form>
                )}
            </section>
            <section className="panel table-panel">
                {report.items.length === 0 ? <p className="muted">{translate('money_core', 'sh_empty')}</p> : (
                    <Table
                        tools={false}
                        rows={report.items}
                        columns={[
                            { key: 'kind', title: translate('money_core', 'sh_col_kind'), render: (row: Row) => translate('money_core', `stale_kind_${row.kind}`) },
                            { key: 'name', title: translate('money_core', 'sh_col_who'), render: (row: Row) => <>{row.name || '—'}{row.code && <small className="cell-sub">{row.code}</small>}</> },
                            { key: 'amount', title: translate('money_core', 'sh_col_amount'), render: (row: Row) => money(row.amount) },
                            { key: 'since', title: translate('money_core', 'sh_col_since'), render: (row: Row) => date(row.since) },
                            { key: 'days', title: translate('money_core', 'sh_col_days'), render: (row: Row) => number(row.days) },
                            { key: 'where', title: translate('money_core', 'sh_col_where'), render: (row: Row) => (
                                <>
                                    <small className="cell-sub">{translate('money_core', `stale_where_${row.kind}`)}</small>
                                    {row.kind === 'clawback_unrecovered' && report.can_write_off
                                        ? <button type="button" className="button small" onClick={() => setWritingOff(row)}>{translate('money_core', 'wo_button')}</button>
                                        : <Link className="button small" href={String(row.href)}>{translate('money_core', 'sh_open')}</Link>}
                                </>
                            ) },
                        ]}
                    />
                )}
            </section>
            {writingOff && (
                <Modal title={translate('money_core', 'wo_title')} onClose={() => setWritingOff(null)}>
                    <WriteOffForm row={writingOff} onDone={() => setWritingOff(null)} />
                </Modal>
            )}
        </>
    );
}

function WriteOffForm({ row, onDone }: { row: Row; onDone: () => void }) {
    const translate = useT();
    const requestKey = useRef(randomUuid());
    const amount = money(row.amount, { symbol: false });
    const form = useForm({ reason: '', confirm: false, expected_amount: String(row.amount), request_key: requestKey.current });

    return (
        <form className="action-form" onSubmit={(event) => { event.preventDefault(); form.post(`/commands/accounts/${row.user_id}/clawback/write-off`, { preserveScroll: true, onSuccess: onDone }); }}>
            <p><b>{row.name}</b>{row.code ? ` · ${row.code}` : ''} · <strong>{money(row.amount)}</strong></p>
            <p className="muted">{translate('money_core', 'wo_intro')}</p>
            <label>{translate('money_core', 'wo_reason')}
                <textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
            </label>
            <label className="import-confirmation">
                <input type="checkbox" required checked={form.data.confirm} onChange={(event) => form.setData('confirm', event.target.checked)} /> {translate('money_core', 'wo_confirm', { amount })}
            </label>
            <FormErrors errors={form.errors} />
            <button className="button danger" disabled={form.processing || !form.data.confirm || form.data.reason.trim().length < 5}>{translate('money_core', 'wo_submit', { amount })}</button>
        </form>
    );
}

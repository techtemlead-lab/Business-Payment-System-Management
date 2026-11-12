import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Play } from 'lucide-react';
import { ActionForm, Badge, Empty, FormErrors, Pagination, Table, date, number, type Row } from './UI';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

interface StockCountData {
    branch_scope: { view: string; name?: string | null };
    can: { create: boolean; edit: boolean; approve: boolean };
    sessions: Row;
    selected: Row | null;
    branches: Row[];
    overdue: Row[];
    overdue_days: number;
}

type Translate = (key: string, replace?: Record<string, string>) => string;

function useText(): Translate {
    const translate = useT();
    return (key, replace) => translate('purchasing', key, replace);
}

const signed = (value: number): string => (value > 0 ? `+${number(value)}` : number(value));

/** Closes a submitted stock count so the branch can count again. */
function CancelSubmittedCount({ sessionId }: { sessionId: number }) {
    const translate = useT();

    return (
        <ActionForm
            action={`/commands/stock-counts/${sessionId}/cancel`}
            submit={translate('self_approval', 'count_cancel_submitted')}
            fields={[{ name: 'reason', label: translate('self_approval', 'count_cancel_reason'), type: 'textarea', required: true, place: 'stock.count_cancel' }]}
        />
    );
}

function StartCount({ branches }: { branches: Row[] }) {
    const text = useText();
    const translate = useT();
    const form = useForm({ branch_id: branches.length === 1 ? String(branches[0].id) : '' });

    return (
        <form
            className="ops-row is-wrap"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/commands/stock-counts', { preserveScroll: true });
            }}
        >
            <label>
                {translate('purchasing', 'branch')}
                <select required value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)}>
                    <option value="">{translate('portal', 'select_placeholder')}</option>
                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} · {branch.name}</option>)}
                </select>
            </label>
            <button className="button dark" disabled={form.processing}><Play size={16} /> {text('count_start')}</button>
            <FormErrors errors={form.errors} />
        </form>
    );
}

/** The open count: type counted numbers, see the difference as you go, then send for approval. */
function CountSheet({ session, can }: { session: Row; can: StockCountData['can'] }) {
    const text = useText();
    const translate = useT();
    const cancelReason = useReasonField('stock.count_cancel');
    const [cancelReasonText, setCancelReasonText] = useState('');
    const lines: Row[] = session.lines;
    const editable = session.status === 'counting' && can.create;
    const saved = Object.fromEntries(lines.map((line) => [line.id, line.counted === null ? '' : String(line.counted)])) as Record<string, string>;
    const [counts, setCounts] = useState<Record<string, string>>(saved);
    const form = useForm<{ lines: { line_id: number; counted_quantity: number | null }[] }>({ lines: [] });
    const changed = lines.filter((line) => (counts[line.id] ?? '') !== saved[line.id]);

    const save = () => {
        form.transform(() => ({
            lines: changed.map((line) => ({
                line_id: line.id,
                counted_quantity: counts[line.id] === '' ? null : Number(counts[line.id]),
            })),
        }));
        form.post(`/commands/stock-counts/${session.id}/lines`, { preserveScroll: true });
    };
    const post = (action: string) => router.post(`/commands/stock-counts/${session.id}/${action}`, {}, { preserveScroll: true });

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{text('count_session', { id: String(session.id) })} · {session.branch_code}</h2>
                    <p className="muted">{session.branch_name}</p>
                </div>
                <Badge status={session.status} />
            </div>
            <FormErrors errors={form.errors} />
            {editable && <p className="muted">{text('count_uncounted_hint')}</p>}
            <Table
                tools={false}
                rows={lines}
                columns={[
                    { key: 'product', title: text('product'), render: (line: Row) => <>{line.name}<small className="cell-sub">{line.sku}</small></> },
                    { key: 'expected', title: text('count_system_quantity'), align: 'right', render: (line: Row) => number(line.expected) },
                    {
                        key: 'counted',
                        title: text('count_counted_quantity'),
                        align: 'right',
                        render: (line: Row) => (editable ? (
                            <input
                                type="number"
                                min={0}
                                aria-label={`${text('count_counted_quantity')}: ${line.name}`}
                                value={counts[line.id] ?? ''}
                                onChange={(event) => setCounts((current) => ({ ...current, [line.id]: event.target.value }))}
                            />
                        ) : (line.counted === null ? '—' : number(line.counted))),
                    },
                    {
                        key: 'variance',
                        title: text('count_variance'),
                        align: 'right',
                        render: (line: Row) => {
                            const typed = counts[line.id] ?? '';
                            if (typed === '') {
                                return '—';
                            }
                            const variance = Number(typed) - Number(line.expected);
                            return variance === 0 ? number(0) : <b>{signed(variance)}</b>;
                        },
                    },
                ]}
            />
            {editable && (
                <div className="ops-row is-wrap">
                    <button type="button" className="button dark" disabled={form.processing || changed.length === 0} onClick={save}>{text('count_save')}</button>
                    <button type="button" className="button" disabled={changed.length > 0} onClick={() => post('submit')}>{text('count_submit')}</button>
                    {can.edit && !cancelReason.shown && <button type="button" className="button" onClick={() => post('cancel')}>{text('count_cancel')}</button>}
                    {changed.length > 0 && <small className="muted">{text('count_save_first')}</small>}
                    {can.edit && cancelReason.shown && (
                        <form
                            className="ops-row is-wrap"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.post(`/commands/stock-counts/${session.id}/cancel`, { reason: cancelReasonText }, { preserveScroll: true });
                            }}
                        >
                            <label>
                                {cancelReason.label(translate('self_approval', 'count_cancel_reason'))}
                                <textarea rows={2} maxLength={1000} required={cancelReason.required} value={cancelReasonText} onChange={(event) => setCancelReasonText(event.target.value)} />
                            </label>
                            <button type="submit" className="button">{text('count_cancel')}</button>
                        </form>
                    )}
                </div>
            )}
            {session.status === 'submitted' && (session.can_decide ? (
                <div className="ops-row is-wrap">
                    <ActionForm
                        action={`/commands/stock-counts/${session.id}/approve`}
                        fields={[{ name: 'note', label: text('count_note'), type: 'textarea', place: 'stock.count_approve' }]}
                        submit={text('count_approve')}
                    />
                    <ActionForm
                        action={`/commands/stock-counts/${session.id}/reject`}
                        fields={[{ name: 'note', label: text('count_reject_note'), type: 'textarea', required: true, place: 'stock.count_reject' }]}
                        submit={text('count_reject')}
                    />
                </div>
            ) : <p className="muted">{text('count_wait_second_person')}</p>)}
            {session.status === 'submitted' && can.edit && <CancelSubmittedCount sessionId={session.id} />}
            {session.decision_note && <p className="muted">{text('count_note')}: {session.decision_note}</p>}
        </section>
    );
}

export default function StockCountHub({ data }: { data: StockCountData }) {
    const text = useText();
    const { portalBase: base } = usePage<any>().props;
    const sessions: Row[] = data.sessions?.data ?? [];
    const open = (id: number) => router.get(`${base}/stock-counts`, { session: id }, { preserveScroll: true, preserveState: false });

    return (
        <>
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <p className="muted">{text('count_intro')}</p>
                        {data.branch_scope?.name && <small>{text('showing_branch', { name: String(data.branch_scope.name) })}</small>}
                    </div>
                </div>
                {data.overdue.length > 0 && (
                    <p className="error" role="status">
                        <b>{text('count_overdue_title')}.</b>{' '}
                        {text('count_overdue_body', { days: String(data.overdue_days), branches: data.overdue.map((branch) => branch.code).join(', ') })}
                    </p>
                )}
                {data.can.create && data.branches.length > 0 && <StartCount branches={data.branches} />}
            </section>

            {data.selected && <CountSheet key={`${data.selected.id}-${data.selected.status}`} session={data.selected} can={data.can} />}

            <section className="panel">
                <h2>{text('count_list')}</h2>
                {sessions.length === 0 ? <Empty title={text('count_no_sessions')} body={text('count_no_sessions_body')} /> : (
                    <>
                        <Table
                            rows={sessions}
                            columns={[
                                { key: 'id', title: '#', render: (session: Row) => <button type="button" className="record-link" onClick={() => open(session.id)}>{text('count_session', { id: String(session.id) })}</button> },
                                { key: 'date', title: text('date'), render: (session: Row) => date(session.created_at) },
                                { key: 'branch', title: text('branch'), render: (session: Row) => session.branch_code ?? '—' },
                                { key: 'started', title: text('count_started_by'), render: (session: Row) => session.started_by_name ?? '—' },
                                { key: 'counted', title: text('count_counted_lines'), align: 'right', render: (session: Row) => number(session.counted_lines) },
                                { key: 'variances', title: text('count_variance_lines'), align: 'right', render: (session: Row) => number(session.variance_lines) },
                                { key: 'status', title: text('status'), render: (session: Row) => <Badge status={session.status} /> },
                            ]}
                        />
                        <Pagination page={data.sessions} />
                    </>
                )}
            </section>
        </>
    );
}

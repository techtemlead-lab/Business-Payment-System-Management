import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2, Trophy } from 'lucide-react';
import { ActionForm, DateInput, Empty, FormErrors, Modal, Table, date, money, number, type Field, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

type Standing = { rank: number; name: string; dealer_code: string | null; place: string | null; value: string | null; is_me: boolean };
type ContestLocks = { metric: boolean; starts_on: boolean; ends_on: boolean };
type Contest = {
    id: number; title: string; starts_on: string; ends_on: string; metric: string; prize_text: string | null;
    status: string; standings: Standing[]; me: { rank: number; value: string | null } | null;
    locked?: ContestLocks; earliest_end?: string | null;
};
type ContestsData = { can_manage: boolean; metrics: string[]; contests: Contest[] };

/** Fields that show their own refusal under the box. */
const FIELDS_WITH_OWN_ERROR = ['title', 'starts_on', 'ends_on', 'metric', 'prize_text', 'reason'];

const NOTHING_LOCKED: ContestLocks = { metric: false, starts_on: false, ends_on: false };

function ContestEditor({ contest, metrics, metricLabel, onDone }: { contest: Contest; metrics: string[]; metricLabel: (metric: string) => string; onDone: () => void }) {
    const translate = useT();
    const form = useForm({ title: contest.title, starts_on: contest.starts_on, ends_on: contest.ends_on, metric: contest.metric, prize_text: contest.prize_text ?? '', reason: '' });
    const locked = contest.locked ?? NOTHING_LOCKED;
    /** A locked box always shows and sends what is saved, even if results arrived while this window was open. */
    const saved = {
        ...(locked.metric ? { metric: contest.metric } : {}),
        ...(locked.starts_on ? { starts_on: contest.starts_on } : {}),
        ...(locked.ends_on ? { ends_on: contest.ends_on } : {}),
    };
    const values = { ...form.data, ...saved };
    const problem = (name: keyof typeof form.errors) => (form.errors[name] ? <span className="field-error" role="alert">{form.errors[name]}</span> : null);
    const lockNote = locked.ends_on ? 'contest_locked_note_ended' : locked.metric ? 'contest_locked_note_running' : null;
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, ...saved }));
        form.patch(`/commands/contests/${contest.id}`, { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <form className="action-form" onSubmit={submit}>
            {lockNote && <div className="notice" role="status">{translate('dealer_tools', lockNote)}</div>}
            <label>{translate('dealer_tools', 'contest_title_field')}<input required maxLength={150} value={values.title} onChange={(e) => form.setData('title', e.target.value)} />{problem('title')}</label>
            <label>{translate('dealer_tools', 'contest_starts')}<DateInput required disabled={locked.starts_on} value={values.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />{problem('starts_on')}</label>
            <label>{translate('dealer_tools', 'contest_ends')}<DateInput required disabled={locked.ends_on} value={values.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />{problem('ends_on')}</label>
            <label>{translate('dealer_tools', 'contest_metric_field')}
                <select required disabled={locked.metric} value={values.metric} onChange={(e) => form.setData('metric', e.target.value)}>
                    {metrics.map((metric) => <option key={metric} value={metric}>{metricLabel(metric)}</option>)}
                </select>
                {problem('metric')}
            </label>
            <label>{translate('dealer_tools', 'contest_prize_field')}<textarea rows={4} maxLength={500} value={values.prize_text} onChange={(e) => form.setData('prize_text', e.target.value)} />{problem('prize_text')}</label>
            <label>{translate('dealer_tools', 'edit_reason_field')}<textarea required rows={2} minLength={5} maxLength={1000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /><small className="field-hint">{translate('dealer_tools', 'edit_reason_hint')}</small>{problem('reason')}</label>
            <FormErrors errors={form.errors} besideFields={FIELDS_WITH_OWN_ERROR} />
            <button type="submit" className="button dark" disabled={form.processing}>{translate('portal', form.processing ? 'saving' : 'save_changes')}</button>
        </form>
    );
}

/** Contests and live standings. The owner defines them; the company gives prizes by hand. */
export default function ContestsHub({ data }: { data: ContestsData }) {
    const translate = useT();
    const [editingId, setEditingId] = useState<number | null>(null);
    const editing = data.contests.find((contest) => contest.id === editingId);
    const metricLabel = (metric: string) => translate('dealer_tools', `contest_metric_${metric}`);
    const showValue = (contest: Contest, value: string | null) => {
        if (value === null) {
            return '—';
        }
        return contest.metric === 'purchases' ? money(value) : number(value);
    };
    const fields: Field[] = [
        { name: 'title', label: translate('dealer_tools', 'contest_title_field'), required: true },
        { name: 'starts_on', label: translate('dealer_tools', 'contest_starts'), type: 'date', required: true },
        { name: 'ends_on', label: translate('dealer_tools', 'contest_ends'), type: 'date', required: true },
        { name: 'metric', label: translate('dealer_tools', 'contest_metric_field'), required: true, options: data.metrics.map((value) => ({ value, label: metricLabel(value) })) },
        { name: 'prize_text', label: translate('dealer_tools', 'contest_prize_field'), type: 'textarea' },
    ];
    const remove = (contest: Contest) => {
        if (window.confirm(translate('dealer_tools', 'contest_remove_confirm', { title: contest.title }))) {
            router.delete(`/commands/contests/${contest.id}`, { preserveScroll: true });
        }
    };
    return (
        <>
            {data.can_manage && (
                <section className="panel narrow-panel">
                    <h2>{translate('dealer_tools', 'contest_add_title')}</h2>
                    <p className="muted">{translate('dealer_tools', 'contest_owner_hint')}</p>
                    <ActionForm action="/commands/contests" fields={fields} defaults={{ metric: 'purchases' } as Row} submit={translate('dealer_tools', 'contest_save')} />
                </section>
            )}
            {data.contests.length === 0 && (
                <section className="panel">
                    <Empty title={translate('dealer_tools', 'contest_empty_title')} body={translate('dealer_tools', 'contest_empty_body')} />
                </section>
            )}
            {data.contests.map((contest) => (
                <section key={contest.id} className="panel table-panel" data-testid="contest-board">
                    <div className="panel-heading">
                        <div>
                            <h2><Trophy size={18} /> {contest.title}</h2>
                            <p className="muted">
                                {translate('dealer_tools', `contest_status_${contest.status}`)} · {date(contest.starts_on)} - {date(contest.ends_on)} · {metricLabel(contest.metric)}
                            </p>
                        </div>
                        {data.can_manage && (
                            <div className="ops-row is-wrap">
                                <button type="button" className="button small" onClick={() => setEditingId(contest.id)}><Pencil size={14} aria-hidden="true" /> {translate('dealer_tools', 'contest_edit')}</button>
                                <button type="button" className="button small" onClick={() => remove(contest)}><Trash2 size={14} aria-hidden="true" /> {translate('dealer_tools', 'contest_remove')}</button>
                            </div>
                        )}
                    </div>
                    {contest.prize_text && <p className="ptl-prewrap">{contest.prize_text}</p>}
                    <p className="muted">{translate('dealer_tools', 'contest_prize_note')}</p>
                    {contest.me && (
                        <p><b>{translate('dealer_tools', 'contest_my_place', { rank: number(contest.me.rank) })}</b>{contest.me.value !== null ? ` · ${showValue(contest, contest.me.value)}` : ''}</p>
                    )}
                    <Table
                        tools={false}
                        searchable={false}
                        rows={contest.standings}
                        empty={translate('dealer_tools', 'contest_no_standings_title')}
                        emptyBody={translate('dealer_tools', contest.status === 'upcoming' ? 'contest_not_started_body' : 'contest_no_standings_body')}
                        columns={[
                            { key: 'rank', title: translate('dealer_tools', 'contest_col_rank'), align: 'right', render: (row: Row) => number(row.rank) },
                            { key: 'name', title: translate('dealer_tools', 'contest_col_partner'), render: (row: Row) => <>{row.is_me ? <b>{row.name}</b> : row.name}<small className="cell-sub">{row.dealer_code ?? ''}</small></> },
                            { key: 'place', title: translate('dealer_tools', 'contest_col_place'), render: (row: Row) => row.place ?? '—' },
                            { key: 'value', title: metricLabel(contest.metric), align: 'right', render: (row: Row) => showValue(contest, row.value) },
                        ]}
                    />
                </section>
            ))}
            {editing && (
                <Modal title={translate('dealer_tools', 'contest_edit_title')} onClose={() => setEditingId(null)}>
                    <ContestEditor contest={editing} metrics={data.metrics} metricLabel={metricLabel} onDone={() => setEditingId(null)} />
                </Modal>
            )}
        </>
    );
}

import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Trophy } from 'lucide-react';
import { ActionForm, Badge, DateInput, Empty, FormErrors, Modal, Table, date, money, number, type Field, type Row } from '../UI';
import OfficePage from '../office/OfficePage';
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

/** What a partner sees: each contest with its live standings. Prizes are given by hand. */
function PartnerContests({ data }: { data: ContestsData }) {
    const translate = useT();
    const metricLabel = (metric: string) => translate('dealer_tools', `contest_metric_${metric}`);
    return (
        <>
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
                    </div>
                    {contest.prize_text && <p className="ptl-prewrap">{contest.prize_text}</p>}
                    <p className="muted">{translate('dealer_tools', 'contest_prize_note')}</p>
                    {contest.me && (
                        <p><b>{translate('dealer_tools', 'contest_my_place', { rank: number(contest.me.rank) })}</b>{contest.me.value !== null ? ` · ${showValue(contest, contest.me.value)}` : ''}</p>
                    )}
                    <Standings contest={contest} metricLabel={metricLabel} />
                </section>
            ))}
        </>
    );
}

function Standings({ contest, metricLabel }: { contest: Contest; metricLabel: (metric: string) => string }) {
    const translate = useT();
    return (
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
    );
}

function showValue(contest: Contest, value: string | null): string {
    if (value === null) {
        return '—';
    }
    return contest.metric === 'purchases' ? money(value) : number(value);
}

/** The office's list of contests. A row opens the standings and the buttons to change it. */
function OfficeContests({ data }: { data: ContestsData }) {
    const translate = useT();
    const hub = usePage<any>().props.data?.hub ?? null;
    const [adding, setAdding] = useState(false);
    const [openedId, setOpenedId] = useState<number | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const opened = data.contests.find((contest) => contest.id === openedId) ?? null;
    const editing = data.contests.find((contest) => contest.id === editingId) ?? null;
    const metricLabel = (metric: string) => translate('dealer_tools', `contest_metric_${metric}`);
    const statusLabel = (contest: Contest) => translate('dealer_tools', `contest_status_${contest.status}`);
    const period = (contest: Contest) => `${date(contest.starts_on)} - ${date(contest.ends_on)}`;
    const fields: Field[] = [
        { name: 'title', label: translate('dealer_tools', 'contest_title_field'), required: true },
        { name: 'starts_on', label: translate('dealer_tools', 'contest_starts'), type: 'date', required: true },
        { name: 'ends_on', label: translate('dealer_tools', 'contest_ends'), type: 'date', required: true },
        { name: 'metric', label: translate('dealer_tools', 'contest_metric_field'), required: true, options: data.metrics.map((value) => ({ value, label: metricLabel(value) })) },
        { name: 'prize_text', label: translate('dealer_tools', 'contest_prize_field'), type: 'textarea' },
    ];
    const remove = (contest: Contest) => {
        if (window.confirm(translate('dealer_tools', 'contest_remove_confirm', { title: contest.title }))) {
            router.delete(`/commands/contests/${contest.id}`, { preserveScroll: true, onSuccess: () => setOpenedId(null) });
        }
    };

    return (
        <OfficePage
            title={translate('office_website', 'partners_title')}
            hub={hub}
            action={<button type="button" className="button dark" onClick={() => setAdding(true)}><Plus size={17} aria-hidden="true" />{translate('dealer_tools', 'contest_add_title')}</button>}
        >
            <Table
                rows={data.contests}
                onRowClick={(row: Contest) => setOpenedId(row.id)}
                empty={translate('dealer_tools', 'contest_empty_title')}
                columns={[
                    { key: 'title', title: translate('dealer_tools', 'contest_title_field'), sortable: true, render: (contest: Contest) => <button type="button" className="text-link" onClick={() => setOpenedId(contest.id)}>{contest.title}</button> },
                    { key: 'starts_on', title: translate('office_website', 'col_period'), sortable: true, render: (contest: Contest) => period(contest) },
                    { key: 'metric', title: translate('dealer_tools', 'contest_metric_field'), render: (contest: Contest) => metricLabel(contest.metric) },
                    { key: 'leader', title: translate('office_website', 'col_leader'), render: (contest: Contest) => contest.standings[0]?.name ?? '—' },
                    { key: 'status', title: translate('portal', 'enquiry_status'), render: (contest: Contest) => <Badge status={contest.status} /> },
                ]}
            />
            {adding && (
                <Modal title={translate('dealer_tools', 'contest_add_title')} onClose={() => setAdding(false)}>
                    <ActionForm action="/commands/contests" fields={fields} defaults={{ metric: 'purchases' } as Row} submit={translate('dealer_tools', 'contest_save')} onDone={() => setAdding(false)} />
                </Modal>
            )}
            {opened && (
                <Modal variant="drawer" title={opened.title} onClose={() => setOpenedId(null)}>
                    <dl className="ow-lines">
                        <div><dt>{translate('portal', 'enquiry_status')}</dt><dd>{statusLabel(opened)}</dd></div>
                        <div><dt>{translate('office_website', 'col_period')}</dt><dd>{period(opened)}</dd></div>
                        <div><dt>{translate('dealer_tools', 'contest_metric_field')}</dt><dd>{metricLabel(opened.metric)}</dd></div>
                        {opened.prize_text && <div><dt>{translate('dealer_tools', 'contest_prize_field')}</dt><dd className="ptl-prewrap">{opened.prize_text}</dd></div>}
                    </dl>
                    <Standings contest={opened} metricLabel={metricLabel} />
                    <div className="ow-drawer-actions">
                        <button type="button" className="button small dark" onClick={() => { setOpenedId(null); setEditingId(opened.id); }}><Pencil size={14} aria-hidden="true" /> {translate('office_website', 'edit')}</button>
                        <button type="button" className="button small danger" onClick={() => remove(opened)}><Trash2 size={14} aria-hidden="true" /> {translate('dealer_tools', 'contest_remove')}</button>
                    </div>
                </Modal>
            )}
            {editing && (
                <Modal title={translate('office_website', 'contest_edit_title')} onClose={() => setEditingId(null)}>
                    <ContestEditor contest={editing} metrics={data.metrics} metricLabel={metricLabel} onDone={() => setEditingId(null)} />
                </Modal>
            )}
        </OfficePage>
    );
}

/** Contests and live standings. The owner defines them; the company gives prizes by hand. */
export default function ContestsHub({ data }: { data: ContestsData }) {
    return data.can_manage ? <OfficeContests data={data} /> : <PartnerContests data={data} />;
}

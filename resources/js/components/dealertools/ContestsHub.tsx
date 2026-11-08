import React from 'react';
import { router } from '@inertiajs/react';
import { Trash2, Trophy } from 'lucide-react';
import { ActionForm, Empty, Table, date, money, number, type Field, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

type Standing = { rank: number; name: string; dealer_code: string | null; place: string | null; value: string | null; is_me: boolean };
type Contest = {
    id: number; title: string; starts_on: string; ends_on: string; metric: string; prize_text: string | null;
    status: string; standings: Standing[]; me: { rank: number; value: string | null } | null;
};
type ContestsData = { can_manage: boolean; metrics: string[]; contests: Contest[] };

/** Contests and live standings. The owner defines them; the company gives prizes by hand. */
export default function ContestsHub({ data }: { data: ContestsData }) {
    const translate = useT();
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
                        {data.can_manage && <button type="button" className="button small" onClick={() => remove(contest)}><Trash2 size={14} /> {translate('dealer_tools', 'contest_remove')}</button>}
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
        </>
    );
}

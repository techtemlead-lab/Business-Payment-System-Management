import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import type { OfficeHub } from '../OfficeHubTabs';
import CommissionBottomNav from '../commissionpage/CommissionBottomNav';
import BuyerCard from './BuyerCard';
import ConditionsCard from './ConditionsCard';
import HistoryList from './HistoryList';
import PersonPicker from './PersonPicker';
import ReceiverCard from './ReceiverCard';
import type { Person, ScheduleItem, ScheduleState, SpecialData } from './types';

type Tab = 'receiver' | 'buyer' | 'conditions';
type Row = Record<string, any>;

/** The rows the generic table sends (everyone's requests), in the shape the history list shows. */
function fromTable(rows: Row[], me: { id: number; role: string }, canFinance: boolean): ScheduleItem[] {
    const owner = me.role === 'super_admin';
    const now = Date.now();

    return rows.map((row) => {
        const expires = row.expires_at ? new Date(row.expires_at).getTime() : null;
        const started = new Date(row.effective_at).getTime() <= now;
        const state: ScheduleState = row.status === 'draft' ? (expires !== null && expires <= now ? 'expired' : 'draft')
            : row.status === 'approved' ? (expires !== null && expires <= now ? 'ended' : started ? 'active' : 'upcoming')
                : (row.status as ScheduleState);
        const live = (state === 'active' || state === 'upcoming') && row.origin !== 'rule_edit';

        return {
            id: Number(row.id), scope: String(row.scope), state, status: String(row.status), person_name: row.name,
            lines: row.changes ? [String(row.changes)] : [], effective_at: row.effective_at, expires_at: row.expires_at, reason: row.reason,
            proposed_by_name: row.proposed_by_name, reviewed_by_name: row.reviewed_by_name, review_reason: row.review_reason,
            can_review: owner && state === 'draft',
            can_cancel: state === 'draft' && (Number(row.created_by) === me.id || owner || canFinance),
            can_end: owner && live,
        };
    });
}

/**
 * "একজনের বিশেষ রেট": pick one person, then give that person a special rate (what the person earns, or the plan on the
 * person's purchases) and lift the conditions the engine can lift for one person. Every save goes through the
 * engine's own schedule or benefit-control action, so approval, limits and the audit log are the same as before.
 */
export default function SpecialRatesPage({ special, partners, partnerSearch, tables, hub }: { special?: SpecialData; partners: Person[]; partnerSearch: string; tables: Row[]; hub?: OfficeHub | null }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const { auth } = usePage<any>().props;
    const [tab, setTab] = useState<Tab>('receiver');

    if (!special) {
        return <div className="cp-page"><p className="cp-empty" role="status">{t('loading')}</p></div>;
    }
    const person = special.person;
    const allRows = (tables.find((table) => table.key === 'policy_schedules')?.page?.data ?? []) as Row[];
    const everyone = fromTable(allRows, { id: auth.user.id, role: auth.user.role }, (auth.capabilities as string[]).includes('finance'));
    const waiting = everyone.filter((item) => item.state === 'draft');

    return (
        <div className="cp-page sr-page">
            <header className="cp-head">
                <h1>{t('page_title')}</h1>
                <p>{t('page_lead')}</p>
            </header>

            <PersonPicker partners={partners} partnerSearch={partnerSearch} person={person} />

            {person === null ? (
                <>
                    <section className="cp-card" aria-labelledby="sr-how-title">
                        <h2 id="sr-how-title">{t('how_title')}</h2>
                        <ol className="sr-steps">
                            <li>{t('how_1')}</li>
                            <li>{t('how_2')}</li>
                            <li>{t('how_3')}</li>
                        </ol>
                    </section>
                    <HistoryList items={waiting} title={t('waiting_title')} hint={t('waiting_hint')} showPerson empty={t('waiting_empty')} />
                    <HistoryList items={everyone.filter((item) => item.state !== 'draft')} title={t('all_title')} showPerson empty={t('all_empty')} />
                </>
            ) : (
                <div key={person.id} className="sr-workspace">
                    <div className="cp-switch sr-tabs" role="group" aria-label={t('tabs_label')}>
                        {(['receiver', 'buyer', 'conditions'] as const).map((key) => (
                            <button key={key} type="button" aria-pressed={tab === key} className={tab === key ? 'is-on' : ''} onClick={() => setTab(key)}>{t(`tab_${key}`)}</button>
                        ))}
                    </div>
                    {tab === 'receiver' && <ReceiverCard special={special} person={person} />}
                    {tab === 'buyer' && <BuyerCard special={special} person={person} />}
                    {tab === 'conditions' && <ConditionsCard special={special} person={person} />}
                    <HistoryList items={special.schedules ?? []} title={t('history_title', { name: person.name })} hint={t('history_hint')} empty={t('history_empty')} />
                </div>
            )}

            <CommissionBottomNav hub={hub} />
        </div>
    );
}

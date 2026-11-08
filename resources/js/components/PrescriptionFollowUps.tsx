import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarClock, Check } from 'lucide-react';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type FollowUpsData = {
    due_today: number;
    overdue: number;
    rows: {
        id: number;
        number: string;
        patient: string | null;
        phone: string | null;
        follow_up_on: string;
        days_late: number;
        diagnosis: string | null;
    }[];
};

/** Patients due back today or already past their date, on the prescriptions home. */
export function FollowUpsPanel({ followUps, translate }: { followUps: FollowUpsData; translate: Translate }) {
    const [busy, setBusy] = useState<number | null>(null);

    const markDone = (id: number) => {
        setBusy(id);
        router.post(`/commands/prescriptions/${id}/follow-up-done`, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2 className="ops-row is-inline"><CalendarClock size={18} /> {translate('prescription', 'fu_title')}</h2>
                    <p className="muted">{translate('prescription', 'fu_counts', { today: followUps.due_today, overdue: followUps.overdue })}</p>
                </div>
            </div>
            {followUps.rows.length === 0 ? (
                <p className="muted">{translate('prescription', 'fu_empty')}</p>
            ) : (
                <ul className="rx-list">
                    {followUps.rows.map((row) => (
                        <li key={row.id} className="rx-card rx-patient">
                            <div>
                                <b>{row.patient}</b> · {row.number}
                                <small className="cell-sub">
                                    {[row.follow_up_on, row.phone, row.diagnosis].filter(Boolean).join(' · ')}
                                </small>
                                {row.days_late > 0
                                    ? <span className="rx-floor is-bad">{translate('prescription', 'fu_days_late', { days: row.days_late })}</span>
                                    : <span className="rx-floor">{translate('prescription', 'fu_due_today')}</span>}
                            </div>
                            <button type="button" className="button small" disabled={busy === row.id} onClick={() => markDone(row.id)}>
                                <Check size={15} /> {translate('prescription', 'fu_done')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

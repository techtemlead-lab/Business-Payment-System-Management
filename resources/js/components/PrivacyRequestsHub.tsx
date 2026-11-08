import { useState } from 'react';
import { router } from '@inertiajs/react';
import { date, dateTime, Empty, FormErrors, Pagination, type Row } from './UI';
import { useT } from '../shared/i18n/t';

type QueueRow = {
    id: number;
    kind: string;
    status: string;
    partner: string | null;
    partner_code: string | null;
    partner_role: string | null;
    resolution_note: string | null;
    created_at: string | null;
    resolved_at: string | null;
};

type QueueData = { rows: Row & { data: QueueRow[] }; statuses: string[] };

type OwnRequest = { id: number; kind: string; status: string; resolution_note: string | null; created_at: string | null };

const isWaiting = (status: string): boolean => status === 'open' || status === 'in_progress';

/** Owners only: partners' data requests. Account and request details only, never patient records. */
export default function PrivacyRequestsHub({ data }: { data?: QueueData | null }) {
    const translate = useT();
    const [choice, setChoice] = useState<Record<number, { status: string; note: string }>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<number | null>(null);
    if (!data) {
        return null;
    }
    const rows = data.rows?.data ?? [];
    const choiceOf = (row: QueueRow) => choice[row.id] ?? { status: data.statuses[0] ?? 'in_progress', note: row.resolution_note ?? '' };
    const setRow = (row: QueueRow, patch: Partial<{ status: string; note: string }>) => setChoice({ ...choice, [row.id]: { ...choiceOf(row), ...patch } });
    const save = (row: QueueRow) => {
        const { status, note } = choiceOf(row);
        setBusy(row.id);
        router.post(`/commands/privacy-requests/${row.id}/resolve`, { status, note: note || null }, {
            preserveScroll: true,
            onError: (errs) => setErrors(errs as Record<string, string>),
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(null),
        });
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('prescriber_public', 'queue_title')}</h2>
                    <p className="muted">{translate('prescriber_public', 'queue_intro')}</p>
                </div>
            </div>
            <FormErrors errors={errors} />
            {rows.length === 0 ? (
                <Empty title={translate('prescriber_public', 'queue_empty')} />
            ) : (
                <ul className="rx-list">
                    {rows.map((row) => (
                        <li key={row.id} className="rx-card">
                            <div className="rx-card-head">
                                <div>
                                    <b>{row.partner}</b>{row.partner_code ? ` · ${row.partner_code}` : ''}
                                    <small className="cell-sub">{translate('prescriber_public', `privacy_kind_${row.kind}`)} · {translate('prescriber_public', 'queue_date')}: {row.created_at ? dateTime(row.created_at) : ''}</small>
                                </div>
                                <span className={`rx-status is-${row.status}`}>{translate('prescriber_public', `privacy_status_${row.status}`)}</span>
                            </div>
                            {isWaiting(row.status) ? (
                                <div className="rx-dispense">
                                    <label>{translate('prescriber_public', 'queue_set_status')}
                                        <select value={choiceOf(row).status} onChange={(event) => setRow(row, { status: event.target.value })}>
                                            {data.statuses.map((status) => <option key={status} value={status}>{translate('prescriber_public', `privacy_status_${status}`)}</option>)}
                                        </select>
                                    </label>
                                    <label>{translate('prescriber_public', 'queue_note')}
                                        <input maxLength={1000} value={choiceOf(row).note} onChange={(event) => setRow(row, { note: event.target.value })} />
                                    </label>
                                    <button type="button" className="button dark" disabled={busy === row.id} onClick={() => save(row)}>{translate('prescriber_public', 'queue_save')}</button>
                                </div>
                            ) : (
                                row.resolution_note && <small className="cell-sub">{row.resolution_note}</small>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Pagination page={data.rows} />
        </section>
    );
}

/** A partner's own request for a copy of their data or for it to be deleted. */
export function PartnerPrivacyRequests({ data }: { data: { kinds: string[]; mine: OwnRequest[] } }) {
    const translate = useT();
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const send = (kind: string) => {
        if (kind === 'data_delete' && !window.confirm(translate('prescriber_public', 'privacy_ask_confirm_delete'))) {
            return;
        }
        setBusy(true);
        router.post('/commands/privacy-requests', { kind }, {
            preserveScroll: true,
            onError: (errs) => setErrors(errs as Record<string, string>),
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <section className="panel narrow-panel">
            <h2>{translate('prescriber_public', 'privacy_my_title')}</h2>
            <p className="muted">{translate('prescriber_public', 'privacy_my_intro')}</p>
            <FormErrors errors={errors} />
            <div className="rx-actions">
                {data.kinds.map((kind) => (
                    <button key={kind} type="button" className={kind === 'data_delete' ? 'button' : 'button dark'} disabled={busy} onClick={() => send(kind)}>{translate('prescriber_public', `privacy_ask_${kind}`)}</button>
                ))}
            </div>
            {data.mine.length > 0 && (
                <>
                    <h3>{translate('prescriber_public', 'privacy_my_requests')}</h3>
                    <ul className="rx-list">
                        {data.mine.map((row) => (
                            <li key={row.id} className="rx-card">
                                <b>{translate('prescriber_public', `privacy_kind_${row.kind}`)}</b> · {translate('prescriber_public', `privacy_status_${row.status}`)}
                                <small className="cell-sub">{row.created_at ? date(row.created_at) : ''}{row.resolution_note ? ` · ${row.resolution_note}` : ''}</small>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

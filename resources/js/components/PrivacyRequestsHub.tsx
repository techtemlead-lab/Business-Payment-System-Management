import { useState } from 'react';
import { router } from '@inertiajs/react';
import { date, dateTime, FormErrors, Modal, Pagination, Table, type Row } from './UI';
import OfficePage from './office/OfficePage';
import { settingsBack } from './office/sections/SettingsForm';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

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
    const noteBox = useReasonField('partner.privacy_resolve');
    const [openRow, setOpenRow] = useState<QueueRow | null>(null);
    const [status, setStatus] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    if (!data) {
        return null;
    }
    const rows = data.rows?.data ?? [];
    const open = (row: QueueRow) => {
        setErrors({});
        setOpenRow(row);
        setStatus(data.statuses[0] ?? 'in_progress');
        setNote(row.resolution_note ?? '');
    };
    const save = (row: QueueRow) => {
        setBusy(true);
        router.post(`/commands/privacy-requests/${row.id}/resolve`, { status, note: note || null }, {
            preserveScroll: true,
            onError: (errs) => setErrors(errs as Record<string, string>),
            onSuccess: () => {
                setErrors({});
                setOpenRow(null);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <OfficePage title={translate('prescriber_public', 'queue_title')} back={settingsBack(translate)}>
            <section className="panel table-panel">
                <Table
                    tools={false}
                    rows={rows}
                    empty={translate('prescriber_public', 'queue_empty')}
                    emptyBody=""
                    onRowClick={(row: QueueRow) => open(row)}
                    columns={[
                        { key: 'partner', title: translate('prescriber_public', 'queue_partner'), render: (row: QueueRow) => <><button type="button" className="text-link" onClick={(event) => { event.stopPropagation(); open(row); }}><b>{row.partner}</b></button>{row.partner_code && <small className="cell-sub">{row.partner_code}</small>}</> },
                        { key: 'kind', title: translate('prescriber_public', 'queue_kind'), render: (row: QueueRow) => translate('prescriber_public', `privacy_kind_${row.kind}`) },
                        { key: 'created_at', title: translate('prescriber_public', 'queue_date'), render: (row: QueueRow) => (row.created_at ? dateTime(row.created_at) : '') },
                        { key: 'status', title: translate('portal', 'col_status'), render: (row: QueueRow) => <span className={`rx-status is-${row.status}`}>{translate('prescriber_public', `privacy_status_${row.status}`)}</span> },
                    ]}
                />
                <Pagination page={data.rows} />
            </section>
            {openRow && (
                <Modal title={`${openRow.partner ?? ''}${openRow.partner_code ? ` · ${openRow.partner_code}` : ''}`} onClose={() => setOpenRow(null)}>
                    <p className="set-line">{translate('prescriber_public', `privacy_kind_${openRow.kind}`)} · {translate('prescriber_public', 'queue_date')}: {openRow.created_at ? dateTime(openRow.created_at) : ''}</p>
                    <FormErrors errors={errors} />
                    {isWaiting(openRow.status) ? (
                        <div className="action-form">
                            <label>{translate('prescriber_public', 'queue_set_status')}
                                <select value={status} onChange={(event) => setStatus(event.target.value)}>
                                    {data.statuses.map((item) => <option key={item} value={item}>{translate('prescriber_public', `privacy_status_${item}`)}</option>)}
                                </select>
                            </label>
                            {noteBox.shown && (
                                <label>{noteBox.label(translate('reason_fields', 'box_privacy_note'))}
                                    <input maxLength={1000} required={noteBox.required} value={note} onChange={(event) => setNote(event.target.value)} />
                                </label>
                            )}
                            <button type="button" className="button dark set-save" disabled={busy} onClick={() => save(openRow)}>{translate('prescriber_public', 'queue_save')}</button>
                        </div>
                    ) : (
                        <p className="set-line">
                            <span className={`rx-status is-${openRow.status}`}>{translate('prescriber_public', `privacy_status_${openRow.status}`)}</span>
                            {openRow.resolution_note ? ` · ${openRow.resolution_note}` : ''}
                        </p>
                    )}
                </Modal>
            )}
        </OfficePage>
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

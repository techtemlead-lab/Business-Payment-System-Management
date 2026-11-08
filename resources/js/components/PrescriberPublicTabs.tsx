import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { dateTime, Empty, FormErrors, Pagination, type Row } from './UI';
import type { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

type ConsultationRow = {
    id: number;
    name: string;
    phone: string;
    problem: string;
    preferred_time: string | null;
    status: 'new' | 'accepted' | 'declined';
    created_at: string | null;
};

export type PrescriberPublicData = {
    listing: { listed: boolean; chamber_hours: string | null; registered: boolean };
    public_url: string;
    new_requests: number;
    requests: ConsultationRow[];
    requests_page: Row;
};

/** The prescriber's own choice to appear on the public page. Off until they turn it on. */
export function PublicListingTab({ data, translate }: { data: PrescriberPublicData; translate: Translate }) {
    const form = useForm({ listed: data.listing.listed, chamber_hours: data.listing.chamber_hours ?? '' });

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('prescriber_public', 'listing_title')}</h2>
                    <p className="muted">{translate('prescriber_public', 'listing_intro')}</p>
                </div>
            </div>
            {!data.listing.registered && <p className="error" role="status">{translate('prescriber_public', 'listing_no_registration')}</p>}
            <form className="action-form" onSubmit={(event) => { event.preventDefault(); form.post('/commands/prescriptions/public-listing', { preserveScroll: true }); }}>
                <label className="rx-consent">
                    <input type="checkbox" checked={form.data.listed} onChange={(event) => form.setData('listed', event.target.checked)} />
                    <span>{translate('prescriber_public', 'listing_toggle')}</span>
                </label>
                <label>{translate('prescriber_public', 'listing_hours')}
                    <input maxLength={200} value={form.data.chamber_hours} onChange={(event) => form.setData('chamber_hours', event.target.value)} />
                    <small className="muted">{translate('prescriber_public', 'listing_hours_hint')}</small>
                </label>
                <p className="muted">{translate('prescriber_public', 'listing_shown')}</p>
                <FormErrors errors={form.errors} />
                <div className="rx-actions">
                    <button className="button dark" disabled={form.processing}>{translate('prescriber_public', 'listing_save')}</button>
                    <a className="button small" href={data.public_url} target="_blank" rel="noopener">{translate('prescriber_public', 'listing_view')}</a>
                </div>
            </form>
        </section>
    );
}

/** Requests visitors sent to this prescriber. Nobody else can read them. */
export function ConsultationRequestsTab({ data, translate }: { data: PrescriberPublicData; translate: Translate }) {
    const [busy, setBusy] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const answer = (row: ConsultationRow, action: 'accept' | 'decline') => {
        if (action === 'decline' && !window.confirm(translate('prescriber_public', 'request_decline_confirm'))) {
            return;
        }
        setBusy(row.id);
        router.post(`/commands/prescriptions/consultations/${row.id}/${action}`, {}, {
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
                    <h2>{translate('prescriber_public', 'requests_title')}</h2>
                    <p className="muted">{translate('prescriber_public', 'requests_intro')}</p>
                </div>
            </div>
            <FormErrors errors={errors} />
            {data.requests.length === 0 ? (
                <Empty title={translate('prescriber_public', 'requests_empty')} body={translate('prescriber_public', 'requests_empty_body')} />
            ) : (
                <ul className="rx-list">
                    {data.requests.map((row) => (
                        <li key={row.id} className="rx-card">
                            <div className="rx-card-head">
                                <div>
                                    <b>{row.name}</b> · <a href={`tel:${row.phone}`}>{row.phone}</a>
                                    <small className="cell-sub">{translate('prescriber_public', 'request_received')}: {row.created_at ? dateTime(row.created_at) : ''}</small>
                                </div>
                                <span className={`rx-status is-${row.status}`}>{translate('prescriber_public', `status_${row.status}`)}</span>
                            </div>
                            <p>{row.problem}</p>
                            {row.preferred_time && <small className="cell-sub">{translate('prescriber_public', 'request_preferred_time')}: {row.preferred_time}</small>}
                            {row.status === 'new' && (
                                <div className="rx-actions">
                                    <button type="button" className="button dark" disabled={busy === row.id} onClick={() => answer(row, 'accept')}>{translate('prescriber_public', 'request_accept')}</button>
                                    <button type="button" className="button small" disabled={busy === row.id} onClick={() => answer(row, 'decline')}>{translate('prescriber_public', 'request_decline')}</button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Pagination page={data.requests_page} />
        </section>
    );
}

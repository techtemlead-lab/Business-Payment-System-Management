import { useCallback, useEffect, useState } from 'react';
import { Badge, Modal, date, label } from './UI';
import OfficePage from './office/OfficePage';
import { settingsBack } from './office/sections/SettingsForm';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { jsonRequest } from '../shared/jsonRequest';

type Account = { id: number; name: string; code: string | null; role: string; status: string; phone: string };
type Flag = { id: number; kind: string; via: string[]; status: string; note: string | null; reviewed_by: string | null; reviewed_at: string | null; first_seen_at: string; accounts: Account[] };
type Listing = { rows: Flag[]; page: number; last_page: number; total: number; open_count: number };

/** Accounts that look like the same person. Only lists them: it never blocks anyone or changes a commission. */
export default function IdentityReviewHub() {
    const translate = useT();
    const noteBox = useReasonField('partner.identity_review');
    const [status, setStatus] = useState<'open' | 'cleared'>('open');
    const [page, setPage] = useState(1);
    const [listing, setListing] = useState<Listing | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [clearing, setClearing] = useState<Flag | null>(null);
    const [note, setNote] = useState('');

    const load = useCallback(() => {
        const query = new URLSearchParams({ status, page: String(page) });
        return jsonRequest<{ data: Listing }>(`/commands/identity-review?${query.toString()}`)
            .then(({ data }) => {
                setListing(data);
                setError(null);
            })
            .catch((failure: Error) => setError(failure.message));
    }, [page, status]);

    useEffect(() => {
        void load();
    }, [load]);

    const scan = () => {
        setBusy(true);
        jsonRequest('/commands/identity-review/scan', { method: 'POST', body: {} })
            .then(() => load())
            .catch((failure: Error) => setError(failure.message))
            .finally(() => setBusy(false));
    };

    const mark = (flag: Flag, next: 'open' | 'cleared', text: string) => {
        setBusy(true);
        jsonRequest(`/commands/identity-review/${flag.id}/mark`, { method: 'POST', body: { status: next, note: text.trim() || null } })
            .then(() => load())
            .catch((failure: Error) => setError(failure.message))
            .finally(() => {
                setBusy(false);
                setClearing(null);
                setNote('');
            });
    };

    const choose = (next: 'open' | 'cleared') => {
        setStatus(next);
        setPage(1);
    };

    return (
        <OfficePage
            title={translate('portal', 'identity_review')}
            back={settingsBack(translate)}
            action={<button type="button" className="button dark" disabled={busy} onClick={scan}>{translate('productivity', 'review_scan_now')}</button>}
        >
            <div className="set-segments" role="group" aria-label={translate('portal', 'identity_review')}>
                <button type="button" aria-pressed={status === 'open'} onClick={() => choose('open')}>{translate('productivity', 'review_tab_open')}{listing ? ` (${listing.open_count})` : ''}</button>
                <button type="button" aria-pressed={status === 'cleared'} onClick={() => choose('cleared')}>{translate('productivity', 'review_tab_cleared')}</button>
            </div>
            {error && <p className="field-error" role="alert">{error}</p>}
            {listing && listing.rows.length === 0 && <p className="set-line">{translate('productivity', 'review_empty')}</p>}
            {listing && listing.rows.length > 0 && (
                <section className="panel table-panel">
                    <div className="table-wrap">
                        <table className="cleanup-table">
                            <thead>
                                <tr>
                                    <th scope="col">{translate('portal', 'cleanup_col_kind')}</th>
                                    <th scope="col">{translate('office_settings', 'review_accounts')}</th>
                                    <th scope="col">{translate('portal', 'col_date')}</th>
                                    <th scope="col"><span className="sr-only">{translate('productivity', 'review_clear')}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {listing.rows.map((flag) => (
                                    <tr key={flag.id}>
                                        <td>
                                            <b>{translate('productivity', `review_kind_${flag.kind}`)}</b>
                                            {flag.kind === 'self_referral' && <small className="cell-sub">{flag.via.map((item) => translate('productivity', `review_kind_${item}`)).join(', ')}</small>}
                                        </td>
                                        <td>
                                            <ul className="identity-flag-accounts">
                                                {flag.accounts.map((account) => (
                                                    <li key={account.id}>
                                                        <span><b>{account.name}</b> <small className="muted">{[account.code, label(account.role, translate), account.phone].filter(Boolean).join(' · ')}</small></span>
                                                        <Badge status={account.status} />
                                                    </li>
                                                ))}
                                            </ul>
                                            {flag.status === 'cleared' && <small className="cell-sub">{translate('productivity', 'review_cleared_by', { name: flag.reviewed_by ?? '—', date: date(flag.reviewed_at) })}{flag.note ? ` · ${flag.note}` : ''}</small>}
                                        </td>
                                        <td>{date(flag.first_seen_at)}</td>
                                        <td>
                                            <button type="button" className="text-link" disabled={busy} onClick={() => {
                                                if (flag.status === 'cleared') {
                                                    mark(flag, 'open', '');
                                                } else {
                                                    setNote('');
                                                    setClearing(flag);
                                                }
                                            }}>
                                                {translate('productivity', flag.status === 'cleared' ? 'review_reopen' : 'review_clear')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {listing && listing.last_page > 1 && (
                <div className="ops-row is-between">
                    <button type="button" className="button small" disabled={listing.page <= 1} onClick={() => setPage(listing.page - 1)}>{translate('productivity', 'previous')}</button>
                    <small className="muted">{listing.page} / {listing.last_page}</small>
                    <button type="button" className="button small" disabled={listing.page >= listing.last_page} onClick={() => setPage(listing.page + 1)}>{translate('productivity', 'next')}</button>
                </div>
            )}
            {clearing && (
                <Modal title={translate('office_settings', 'review_note_title')} onClose={() => setClearing(null)}>
                    <form className="action-form" onSubmit={(event) => { event.preventDefault(); mark(clearing, 'cleared', note); }}>
                        {noteBox.shown && (
                            <label>{noteBox.label(translate('reason_fields', 'box_identity_review_note'))}
                                <input maxLength={300} required={noteBox.required} value={note} onChange={(event) => setNote(event.target.value)} />
                            </label>
                        )}
                        <button type="submit" className="button dark set-save" disabled={busy}>{translate('productivity', 'review_clear')}</button>
                    </form>
                </Modal>
            )}
        </OfficePage>
    );
}

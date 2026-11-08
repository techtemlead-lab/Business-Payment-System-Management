import { useCallback, useEffect, useState } from 'react';
import { Badge, Empty, date, label } from './UI';
import { useT } from '../shared/i18n/t';
import { jsonRequest } from '../shared/jsonRequest';

type Account = { id: number; name: string; code: string | null; role: string; status: string; phone: string };
type Flag = { id: number; kind: string; via: string[]; status: string; note: string | null; reviewed_by: string | null; reviewed_at: string | null; first_seen_at: string; accounts: Account[] };
type Listing = { rows: Flag[]; page: number; last_page: number; total: number; open_count: number };

/** Accounts that look like the same person. Only lists them: it never blocks anyone or changes a commission. */
export default function IdentityReviewHub() {
    const translate = useT();
    const [status, setStatus] = useState<'open' | 'cleared'>('open');
    const [page, setPage] = useState(1);
    const [listing, setListing] = useState<Listing | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

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

    const mark = (flag: Flag, next: 'open' | 'cleared') => {
        const note = next === 'cleared' ? window.prompt(translate('productivity', 'review_note_prompt')) : '';
        if (note === null) {
            return;
        }
        setBusy(true);
        jsonRequest(`/commands/identity-review/${flag.id}/mark`, { method: 'POST', body: { status: next, note: note.trim() || null } })
            .then(() => load())
            .catch((failure: Error) => setError(failure.message))
            .finally(() => setBusy(false));
    };

    const choose = (next: 'open' | 'cleared') => {
        setStatus(next);
        setPage(1);
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('productivity', 'review_title')}</h2>
                    <p className="muted">{translate('productivity', 'review_hint')}</p>
                </div>
                <button type="button" className="button small dark" disabled={busy} onClick={scan}>{translate('productivity', 'review_scan_now')}</button>
            </div>
            <div className="filter-bar">
                <button type="button" className="button small" aria-pressed={status === 'open'} onClick={() => choose('open')}>{translate('productivity', 'review_tab_open')}{listing ? ` (${listing.open_count})` : ''}</button>
                <button type="button" className="button small" aria-pressed={status === 'cleared'} onClick={() => choose('cleared')}>{translate('productivity', 'review_tab_cleared')}</button>
            </div>
            {error && <p className="field-error" role="alert">{error}</p>}
            {listing && listing.rows.length === 0 && <Empty title={translate('productivity', 'review_empty')} />}
            {listing?.rows.map((flag) => (
                <article className="identity-flag" key={flag.id}>
                    <div className="identity-flag-head">
                        <b>{translate('productivity', `review_kind_${flag.kind}`)}</b>
                        {flag.kind === 'self_referral' && <small className="muted">{flag.via.map((item) => translate('productivity', `review_kind_${item}`)).join(', ')}</small>}
                        <small className="muted">{translate('productivity', 'review_first_seen', { date: date(flag.first_seen_at) })}</small>
                    </div>
                    <ul className="identity-flag-accounts">
                        {flag.accounts.map((account) => (
                            <li key={account.id}>
                                <span><b>{account.name}</b> <small className="muted">{[account.code, label(account.role, translate), account.phone].filter(Boolean).join(' · ')}</small></span>
                                <Badge status={account.status} />
                            </li>
                        ))}
                    </ul>
                    {flag.status === 'cleared' && <p className="muted">{translate('productivity', 'review_cleared_by', { name: flag.reviewed_by ?? '—', date: date(flag.reviewed_at) })}{flag.note ? ` · ${flag.note}` : ''}</p>}
                    <button type="button" className="text-link" disabled={busy} onClick={() => mark(flag, flag.status === 'cleared' ? 'open' : 'cleared')}>
                        {translate('productivity', flag.status === 'cleared' ? 'review_reopen' : 'review_clear')}
                    </button>
                </article>
            ))}
            {listing && listing.last_page > 1 && (
                <div className="ops-row is-between">
                    <button type="button" className="button small" disabled={listing.page <= 1} onClick={() => setPage(listing.page - 1)}>{translate('productivity', 'previous')}</button>
                    <small className="muted">{listing.page} / {listing.last_page}</small>
                    <button type="button" className="button small" disabled={listing.page >= listing.last_page} onClick={() => setPage(listing.page + 1)}>{translate('productivity', 'next')}</button>
                </div>
            )}
        </section>
    );
}

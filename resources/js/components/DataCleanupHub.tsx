import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { dateTime, Empty, Pagination, type Row } from './UI';
import { useT } from '../shared/i18n/t';

type CleanupRow = { ref: string; kind: string; label: string; detail: string | null; happened_at: string };

type CleanupData = {
    rows: Row & { data: CleanupRow[] };
    counts: Record<string, number>;
    kinds: string[];
    filters: { kind: string | null; older_than_days: number };
    max_per_delete: number;
};

/** Owner only: pick old login codes, SMS records and closed enquiries and delete them. Nothing is deleted automatically. */
export default function DataCleanupHub({ data }: { data?: CleanupData | null }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [kind, setKind] = useState(data?.filters.kind ?? '');
    const [days, setDays] = useState(String(data?.filters.older_than_days ?? 30));
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    if (!data) {
        return null;
    }
    const rows = data.rows?.data ?? [];
    const pageRefs = rows.map((row) => row.ref);
    const allOnPage = pageRefs.length > 0 && pageRefs.every((ref) => selected.has(ref));
    const toggle = (ref: string) => setSelected((current) => {
        const next = new Set(current);
        if (next.has(ref)) {
            next.delete(ref);
        } else {
            next.add(ref);
        }
        return next;
    });
    const toggleAllOnPage = () => setSelected((current) => {
        const next = new Set(current);
        pageRefs.forEach((ref) => (allOnPage ? next.delete(ref) : next.add(ref)));
        return next;
    });
    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        setSelected(new Set());
        router.get(`${base}/data-cleanup`, Object.fromEntries(Object.entries({ kind, older_than_days: days }).filter(([, value]) => value !== '')), { preserveScroll: true });
    };
    // Enquiries show their topic as written; other kinds carry a fixed word (and the SMS provider) to translate.
    const detailText = (row: CleanupRow): string => {
        if (!row.detail || row.kind === 'closed_enquiries') {
            return row.detail ?? '';
        }
        const [word, provider] = row.detail.split('·').map((part) => part.trim());
        return [translate('portal', `cleanup_detail_${word}`), provider].filter(Boolean).join(' · ');
    };
    const remove = () => {
        const refs = Array.from(selected);
        if (refs.length === 0 || !window.confirm(translate('portal', 'cleanup_confirm', { count: String(refs.length) }))) {
            return;
        }
        setDeleting(true);
        setError(null);
        router.post('/commands/data-cleanup/delete', { refs, confirm: true }, {
            preserveScroll: true,
            onSuccess: () => setSelected(new Set()),
            onError: (errors) => setError(String(Object.values(errors)[0] ?? translate('portal', 'cleanup_err_invalid'))),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <div className="cleanup-hub" data-tour="data-cleanup">
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'cleanup_title')}</h2>
                        <p className="muted">{translate('portal', 'cleanup_intro')}</p>
                    </div>
                </div>
                <div className="metric-grid three">
                    {data.kinds.map((name) => (
                        <div className="metric" key={name}>
                            <span>{translate('portal', `cleanup_kind_${name}`)}</span>
                            <strong>{data.counts[name] ?? 0}</strong>
                            <small>{translate('portal', 'cleanup_older_than_label', { days: String(data.filters.older_than_days) })}</small>
                        </div>
                    ))}
                </div>
                <form className="cleanup-filters" onSubmit={applyFilters}>
                    <label>{translate('portal', 'cleanup_filter_kind')}
                        <select value={kind} onChange={(event) => setKind(event.target.value)}>
                            <option value="">{translate('portal', 'cleanup_kind_all')}</option>
                            {data.kinds.map((name) => <option key={name} value={name}>{translate('portal', `cleanup_kind_${name}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('portal', 'cleanup_filter_days')}
                        <input type="number" min={0} max={3650} inputMode="numeric" value={days} onChange={(event) => setDays(event.target.value)} />
                    </label>
                    <button type="submit" className="button">{translate('portal', 'cleanup_apply')}</button>
                </form>
            </section>
            <section className="panel">
                <div className="cleanup-toolbar">
                    <label className="cleanup-check">
                        <input type="checkbox" checked={allOnPage} disabled={pageRefs.length === 0} onChange={toggleAllOnPage} />
                        {translate('portal', 'cleanup_select_page')}
                    </label>
                    <span className="muted">{translate('portal', 'cleanup_selected', { count: String(selected.size) })}</span>
                    <button type="button" className="button danger" disabled={deleting || selected.size === 0 || selected.size > data.max_per_delete} onClick={remove}>
                        <Trash2 size={16} aria-hidden="true" />{translate('portal', 'cleanup_delete_selected')}
                    </button>
                </div>
                {error && <p className="field-error" role="alert">{error}</p>}
                {rows.length === 0 ? (
                    <Empty title={translate('portal', 'cleanup_empty_title')} body={translate('portal', 'cleanup_empty_body')} />
                ) : (
                    <div className="table-wrap">
                        <table className="cleanup-table">
                            <thead>
                                <tr>
                                    <th scope="col"><span className="sr-only">{translate('portal', 'cleanup_select_page')}</span></th>
                                    <th scope="col">{translate('portal', 'cleanup_col_kind')}</th>
                                    <th scope="col">{translate('portal', 'cleanup_col_record')}</th>
                                    <th scope="col">{translate('portal', 'cleanup_col_date')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.ref} className={selected.has(row.ref) ? 'is-selected' : undefined}>
                                        <td>
                                            <input type="checkbox" checked={selected.has(row.ref)} onChange={() => toggle(row.ref)} aria-label={`${translate('portal', 'cleanup_select_row')}: ${row.label}`} />
                                        </td>
                                        <td>{translate('portal', `cleanup_kind_${row.kind}`)}</td>
                                        <td><b>{row.label}</b>{row.detail && <small className="cell-sub">{detailText(row)}</small>}</td>
                                        <td>{dateTime(row.happened_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination page={data.rows} />
            </section>
        </div>
    );
}

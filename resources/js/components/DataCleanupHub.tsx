import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { dateTime, Pagination, type Row } from './UI';
import OfficePage from './office/OfficePage';
import { settingsBack } from './office/sections/SettingsForm';
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
        <OfficePage
            title={translate('portal', 'cleanup_title')}
            back={settingsBack(translate)}
            action={(
                <button type="button" className="button danger" disabled={deleting || selected.size === 0 || selected.size > data.max_per_delete} onClick={remove}>
                    <Trash2 size={16} aria-hidden="true" />{translate('portal', 'cleanup_delete_selected')}{selected.size > 0 ? ` (${selected.size})` : ''}
                </button>
            )}
        >
            <div className="cleanup-hub" data-tour="data-cleanup">
                <form className="cleanup-filters" onSubmit={applyFilters}>
                    <label>{translate('portal', 'cleanup_filter_kind')}
                        <select value={kind} onChange={(event) => setKind(event.target.value)}>
                            <option value="">{translate('portal', 'cleanup_kind_all')}</option>
                            {data.kinds.map((name) => <option key={name} value={name}>{translate('portal', `cleanup_kind_${name}`)} ({data.counts[name] ?? 0})</option>)}
                        </select>
                    </label>
                    <label>{translate('portal', 'cleanup_filter_days')}
                        <input type="number" min={0} max={3650} inputMode="numeric" value={days} onChange={(event) => setDays(event.target.value)} />
                    </label>
                    <button type="submit" className="button">{translate('portal', 'cleanup_apply')}</button>
                </form>
                {error && <p className="field-error" role="alert">{error}</p>}
                {rows.length === 0 ? (
                    <p className="set-line">{translate('portal', 'cleanup_empty_title')}</p>
                ) : (
                    <section className="panel table-panel">
                        <div className="table-wrap">
                            <table className="cleanup-table">
                                <thead>
                                    <tr>
                                        <th scope="col">
                                            <label className="cleanup-check">
                                                <input type="checkbox" checked={allOnPage} onChange={toggleAllOnPage} aria-label={translate('portal', 'cleanup_select_page')} />
                                            </label>
                                        </th>
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
                        <Pagination page={data.rows} />
                    </section>
                )}
            </div>
        </OfficePage>
    );
}

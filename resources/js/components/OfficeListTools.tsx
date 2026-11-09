import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Bookmark, Columns3, ListChecks, Trash2 } from 'lucide-react';
import { Table, type Row, type TableColumn, type TableProps } from './UI';
import { useT } from '../shared/i18n/t';
import { jsonRequest, submitFormPost } from '../shared/jsonRequest';

type SavedView = { id: number; name: string; filters: Record<string, string> };
type ListView = { views: SavedView[]; hidden: string[] };
type BulkKind = 'orders' | 'partners';

/** Columns that identify a row or hold its buttons stay on, so a list can always be used. */
const ALWAYS_SHOWN = new Set(['number', 'name', 'action']);

const loads = new Map<string, Promise<ListView>>();

function loadView(listKey: string): Promise<ListView> {
    let pending = loads.get(listKey);
    if (!pending) {
        pending = jsonRequest<{ data: ListView }>(`/commands/list-views/${listKey}`).then(({ data }) => data);
        pending.catch(() => loads.delete(listKey));
        loads.set(listKey, pending);
    }

    return pending;
}

/** The person's saved filters and hidden columns for one list; the two tools below share one request. */
function useListView(listKey: string) {
    const [view, setView] = useState<ListView>({ views: [], hidden: [] });

    useEffect(() => {
        let current = true;
        loadView(listKey).then((data) => {
            if (current) {
                setView(data);
            }
        }).catch(() => undefined);
        return () => {
            current = false;
        };
    }, [listKey]);

    const remember = useCallback((data: ListView) => {
        loads.set(listKey, Promise.resolve(data));
        setView(data);
    }, [listKey]);

    return { view, setView, remember };
}

function ToolMenu({ icon, title, children, opensRight = false }: { icon: ReactNode; title: string; children: ReactNode; opensRight?: boolean }) {
    const root = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }
        const onPointer = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="list-tool" ref={root}>
            <button type="button" className="button small" aria-haspopup="true" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                {icon}
                <span>{title}</span>
            </button>
            {open && <div className={`list-tool-panel${opensRight ? ' opens-right' : ''}`}>{children}</div>}
        </div>
    );
}

/** Save the filters now on screen under a name, and go back to them with one click. */
export function SavedFilterMenu({ listKey, section, filters }: { listKey: string; section: string; filters: Row }) {
    const translate = useT();
    const { portalBase } = usePage<any>().props;
    const { view, remember } = useListView(listKey);
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const hasFilters = useMemo(() => Object.entries(filters ?? {}).some(([key, value]) => !['branch_id', 'all_branches', 'page'].includes(key) && value !== '' && value != null), [filters]);

    const save = () => {
        const trimmed = name.trim();
        if (!trimmed) {
            return;
        }
        setError(null);
        jsonRequest<{ data: ListView }>(`/commands/list-views/${listKey}`, { method: 'POST', body: { name: trimmed, filters } })
            .then(({ data }) => {
                remember(data);
                setName('');
            })
            .catch((failure: Error) => setError(failure.message));
    };

    const remove = (id: number) => {
        jsonRequest<{ data: ListView }>(`/commands/list-views/${listKey}/${id}`, { method: 'DELETE' })
            .then(({ data }) => remember(data))
            .catch((failure: Error) => setError(failure.message));
    };

    return (
        <ToolMenu icon={<Bookmark size={14} aria-hidden="true" />} title={translate('productivity', 'views_saved_filters')}>
            {view.views.length === 0 && <p className="list-tool-empty">{translate('productivity', 'views_none_yet')}</p>}
            <ul className="list-tool-list">
                {view.views.map((saved) => (
                    <li key={saved.id}>
                        <button type="button" className="text-link" onClick={() => router.get(`${portalBase ?? ''}/${section}`, saved.filters)}>{saved.name}</button>
                        <button type="button" className="icon-button" aria-label={translate('productivity', 'views_delete', { name: saved.name })} onClick={() => remove(saved.id)}><Trash2 size={14} /></button>
                    </li>
                ))}
            </ul>
            <div className="list-tool-save">
                <input
                    value={name}
                    maxLength={60}
                    disabled={!hasFilters}
                    placeholder={translate('productivity', 'views_name_placeholder')}
                    aria-label={translate('productivity', 'views_name_placeholder')}
                    onChange={(event) => setName(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            save();
                        }
                    }}
                />
                <button type="button" className="button small dark" disabled={!hasFilters || name.trim() === ''} onClick={save}>{translate('productivity', 'views_save')}</button>
            </div>
            {!hasFilters && <p className="list-tool-empty">{translate('productivity', 'views_choose_filters_first')}</p>}
            {error && <p className="field-error" role="alert">{error}</p>}
        </ToolMenu>
    );
}

/** Tick several unread notices on this page and mark just those as read. */
export function NoticeBulkPicker({ notices }: { notices: Row[] }) {
    const translate = useT();
    const [picked, setPicked] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);
    const unread = notices.filter((notice) => !notice.read_at);
    if (unread.length < 2) {
        return null;
    }
    const chosen = unread.map((notice) => Number(notice.id)).filter((id) => picked.includes(id));

    const markRead = () => {
        setError(null);
        jsonRequest('/commands/bulk/notices-read', { method: 'POST', body: { ids: chosen } })
            .then(() => {
                setPicked([]);
                router.reload({ only: ['data', 'navAttention', 'unreadNotices'] });
            })
            .catch(() => setError(translate('productivity', 'bulk_failed')));
    };

    return (
        <ToolMenu icon={<ListChecks size={14} aria-hidden="true" />} title={translate('productivity', 'bulk_choose_notices')}>
            <ul className="list-tool-list">
                {unread.map((notice) => {
                    const id = Number(notice.id);
                    return (
                        <li key={id}>
                            <label className="list-tool-check">
                                <input type="checkbox" checked={picked.includes(id)} onChange={() => setPicked((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))} />
                                <span>{notice.title}</span>
                            </label>
                        </li>
                    );
                })}
            </ul>
            <button type="button" className="button small dark" disabled={chosen.length === 0} onClick={markRead}>{translate('productivity', 'bulk_mark_read')}</button>
            {error && <p className="field-error" role="alert">{error}</p>}
        </ToolMenu>
    );
}

function columnTitle<T extends Row>(column: TableColumn<T>): string {
    return column.title ?? column.header ?? '';
}

/** A plain table unless a list key is given, so partners' order lists load nothing extra. */
export function OfficeListTable<T extends Row = any>({ listKey, bulk, ...table }: TableProps<T> & { listKey?: string; bulk?: BulkKind }) {
    return listKey ? <ChoosableTable listKey={listKey} bulk={bulk} {...table} /> : <Table {...table} />;
}

/** A list table with a "Columns" menu and tick boxes; each person's column choice is remembered on the server. */
function ChoosableTable<T extends Row = any>({ listKey, bulk, columns, extraTools, ...table }: TableProps<T> & { listKey: string; bulk?: BulkKind }) {
    const translate = useT();
    const { view, setView, remember } = useListView(listKey);
    const [picked, setPicked] = useState<Set<string>>(() => new Set());
    const hidden = useMemo(() => new Set(view.hidden), [view.hidden]);
    const choosable = columns.filter((column) => !ALWAYS_SHOWN.has(column.key) && columnTitle(column) !== '');
    const visible = columns.filter((column) => ALWAYS_SHOWN.has(column.key) || columnTitle(column) === '' || !hidden.has(column.key));
    const pageIds = ((table.rows ?? table.data ?? []) as Row[]).map((row) => String(row.id));
    const chosen = pageIds.filter((id) => picked.has(id));

    const togglePick = (id: string) => setPicked((current) => {
        const next = new Set(current);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        return next;
    });

    const selectColumn: TableColumn<T> = {
        key: 'bulk_select',
        title: '',
        sortable: false,
        exportable: false,
        render: (row) => (
            <input
                type="checkbox"
                className="bulk-check"
                aria-label={translate('productivity', 'bulk_select_row')}
                checked={picked.has(String((row as Row).id))}
                onChange={() => togglePick(String((row as Row).id))}
            />
        ),
    };

    /** One save runs at a time. Ticks made while it runs wait here and go in the next save, so none is lost. */
    const unsavedColumns = useRef<string[] | null>(null);
    const lastSavedColumns = useRef<string[]>([]);
    const savingColumns = useRef(false);

    const saveColumns = () => {
        const sending = unsavedColumns.current;
        if (savingColumns.current || sending === null) {
            return;
        }
        savingColumns.current = true;
        unsavedColumns.current = null;
        jsonRequest<{ data: ListView }>(`/commands/list-views/${listKey}/columns`, { method: 'PUT', body: { hidden: sending } })
            .then(({ data }) => {
                lastSavedColumns.current = data.hidden;
                if (unsavedColumns.current === null) {
                    remember(data);
                }
            })
            .catch(() => {
                if (unsavedColumns.current === null) {
                    setView((current) => ({ ...current, hidden: lastSavedColumns.current }));
                }
            })
            .finally(() => {
                savingColumns.current = false;
                saveColumns();
            });
    };

    const toggle = (key: string) => {
        if (!savingColumns.current) {
            lastSavedColumns.current = view.hidden;
        }
        const next = hidden.has(key) ? view.hidden.filter((item) => item !== key) : [...view.hidden, key];
        setView({ ...view, hidden: next });
        unsavedColumns.current = next;
        saveColumns();
    };

    return (
        <Table
            {...table}
            columns={bulk ? [selectColumn, ...visible] : visible}
            extraTools={(
                <>
                    {extraTools}
                    {bulk && pageIds.length > 0 && (
                        <span className="bulk-bar">
                            <button type="button" className="button small" onClick={() => setPicked(chosen.length === pageIds.length ? new Set() : new Set(pageIds))}>
                                {translate('productivity', chosen.length === pageIds.length ? 'bulk_clear' : 'bulk_select_page')}
                            </button>
                            {chosen.length > 0 && (
                                <>
                                    <b>{translate('productivity', 'bulk_selected', { count: chosen.length })}</b>
                                    {bulk === 'orders' && <button type="button" className="button small" onClick={() => submitFormPost('/commands/bulk/order-slips', { ids: chosen }, '_blank')}>{translate('productivity', 'bulk_print_slips')}</button>}
                                    <button type="button" className="button small" onClick={() => submitFormPost(`/commands/bulk/${bulk}-export`, { ids: chosen })}>{translate('productivity', 'bulk_export')}</button>
                                </>
                            )}
                        </span>
                    )}
                    <ToolMenu opensRight icon={<Columns3 size={14} aria-hidden="true" />} title={translate('productivity', 'views_columns')}>
                        <ul className="list-tool-list">
                            {choosable.map((column) => (
                                <li key={column.key}>
                                    <label className="list-tool-check">
                                        <input type="checkbox" checked={!hidden.has(column.key)} onChange={() => toggle(column.key)} />
                                        <span>{columnTitle(column)}</span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    </ToolMenu>
                </>
            )}
        />
    );
}

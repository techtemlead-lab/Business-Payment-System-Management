import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Bookmark, Columns3, Trash2 } from 'lucide-react';
import { Table, type Row, type TableColumn, type TableProps } from './UI';
import { useT } from '../shared/i18n/t';
import { jsonRequest } from '../shared/jsonRequest';

type SavedView = { id: number; name: string; filters: Record<string, string> };
type ListView = { views: SavedView[]; hidden: string[] };

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

function ToolMenu({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
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
            {open && <div className="list-tool-panel">{children}</div>}
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

function columnTitle<T extends Row>(column: TableColumn<T>): string {
    return column.title ?? column.header ?? '';
}

/** A plain table unless a list key is given, so partners' order lists load nothing extra. */
export function OfficeListTable<T extends Row = any>({ listKey, ...table }: TableProps<T> & { listKey?: string }) {
    return listKey ? <ChoosableTable listKey={listKey} {...table} /> : <Table {...table} />;
}

/** A list table with a "Columns" menu; each person's choice is remembered on the server. */
function ChoosableTable<T extends Row = any>({ listKey, columns, extraTools, ...table }: TableProps<T> & { listKey: string }) {
    const translate = useT();
    const { view, setView, remember } = useListView(listKey);
    const hidden = useMemo(() => new Set(view.hidden), [view.hidden]);
    const choosable = columns.filter((column) => !ALWAYS_SHOWN.has(column.key) && columnTitle(column) !== '');
    const visible = columns.filter((column) => ALWAYS_SHOWN.has(column.key) || columnTitle(column) === '' || !hidden.has(column.key));

    const toggle = (key: string) => {
        const previous = view;
        const next = hidden.has(key) ? view.hidden.filter((item) => item !== key) : [...view.hidden, key];
        setView({ ...view, hidden: next });
        jsonRequest<{ data: ListView }>(`/commands/list-views/${listKey}/columns`, { method: 'PUT', body: { hidden: next } })
            .then(({ data }) => remember(data))
            .catch(() => setView(previous));
    };

    return (
        <Table
            {...table}
            columns={visible}
            extraTools={(
                <>
                    {extraTools}
                    <ToolMenu icon={<Columns3 size={14} aria-hidden="true" />} title={translate('productivity', 'views_columns')}>
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

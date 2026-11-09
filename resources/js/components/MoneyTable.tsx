import React, { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronRight, Search, SlidersHorizontal } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import { DateInput, FormErrors, Modal, Pagination, money, number } from './UI';
import { MoreFilterControls, filterKeys, isToolbarFilter, optionText, type MoneyFilter, type SortOption } from './MoneyTableFilters';
import { BulkBar, type BulkKind } from './OfficeListTools';

export type MoneyColumn<T> = {
    key: string;
    title: string;
    /** A key the server allows in `sortable`; without one the column name is plain text. */
    sortKey?: string;
    money?: boolean;
    align?: 'left' | 'right';
    /** Key in totals.sums shown under this column. */
    total?: string;
    /** Where the column goes when a row becomes a card on a phone. */
    phone?: 'title' | 'sub' | 'amount' | 'hide';
    render?: (row: T) => React.ReactNode;
};

export type MoneyTableData<T> = {
    rows: { data: T[] } & Record<string, unknown>;
    totals: { count: number; capped: boolean; sums: Record<string, string> | null };
    state: { q: string; period: string; from: string | null; to: string | null; sort: string; dir: 'asc' | 'desc'; filters: Record<string, string> };
    filters: MoneyFilter[];
    sortable: string[];
    extra_periods?: string[];
};

type MoneyTableProps<T> = {
    /** Missing while a deferred prop is still loading. */
    data: MoneyTableData<T> | undefined;
    /** The page prop to reload when the view changes. */
    prop: string;
    columns: MoneyColumn<T>[];
    searchPlaceholder: string;
    rowHref?: (row: T) => string | null;
    /** False on new pages: the row itself is the link, with no arrow at its end. */
    rowArrow?: boolean;
    /** Opens the row in a drawer instead of following a link. Wins over rowHref. */
    onRowOpen?: (row: T) => void;
    /** Sort choices for what the table has no column for. They appear in the filter sheet only. */
    extraSorts?: SortOption[];
    /** Tick boxes and the bulk bar, shown while the page's own "Select" toggle is on. */
    bulk?: { kind: BulkKind; selecting: boolean };
    /** The "( A | B )" switch above the toolbar: an .office-switch. */
    segments?: React.ReactNode;
    actions?: React.ReactNode;
    hideViewChips?: boolean;
    empty: string;
};

const VIEW_CHOICES: { value: string; word: string }[] = [
    { value: 'today', word: 'orders_period_today' },
    { value: 'week', word: 'orders_period_week' },
    { value: 'month', word: 'orders_period_month' },
    { value: 'year', word: 'orders_period_year' },
    { value: 'day', word: 'mt_view_day' },
    { value: 'custom', word: 'mt_view_range' },
    { value: '', word: 'orders_period_all' },
];

const SEARCH_PAUSE_MS = 400;

const isWholeDate = (value: string): boolean => /^\d{4}-\d{2}-\d{2}$/.test(value);

/** Money, counts and dates read best largest or newest first; words read best from A. */
const startsDescending = <T,>(column: MoneyColumn<T>): boolean =>
    column.money === true || column.align === 'right' || /(^|_)(date|time|at|on)$/.test(column.sortKey ?? column.key);

const isRightAligned = <T,>(column: MoneyColumn<T>): boolean => (column.align ?? (column.money ? 'right' : 'left')) === 'right';

const cellContent = <T,>(column: MoneyColumn<T>, row: T): React.ReactNode => {
    if (column.render) {
        return column.render(row);
    }
    const value = (row as Record<string, unknown>)[column.key];

    return column.money ? money(value) : String(value ?? '');
};

/**
 * The table for every money list: search, filters, date view and column sort all go to the
 * server, so sorting and totals cover every page and not only the rows on screen.
 */
export default function MoneyTable<T>(props: MoneyTableProps<T>) {
    if (!props.data) {
        return (
            <div className="money-table" aria-busy="true">
                {[0, 1, 2, 3, 4].map((line) => <div key={line} className="skeleton mt-loading-line" />)}
            </div>
        );
    }

    return <LoadedMoneyTable {...props} data={props.data} />;
}

function LoadedMoneyTable<T>({ data, prop, columns, searchPlaceholder, rowHref, rowArrow = true, onRowOpen, extraSorts = [], bulk, segments, actions, hideViewChips = false, empty }: MoneyTableProps<T> & { data: MoneyTableData<T> }) {
    const translate = useT();
    const { url, props: pageProps } = usePage<{ errors?: Record<string, string> }>();
    const { state, totals, filters, sortable } = data;
    const rows = data.rows.data;

    const [text, setText] = useState(state.q);
    const [choosingView, setChoosingView] = useState<string | null>(null);
    const [from, setFrom] = useState(state.from ?? '');
    const [to, setTo] = useState(state.to ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [picked, setPicked] = useState<Set<string>>(() => new Set());
    const selecting = bulk?.selecting === true;

    const latestUrl = useRef(url);
    latestUrl.current = url;
    const searchTimer = useRef<number | undefined>(undefined);
    const lastSearched = useRef(state.q);
    const viewChips = useRef<HTMLDivElement>(null);

    useEffect(() => {
        // A slower answer for earlier words must not wipe what is being typed now.
        if (state.q !== lastSearched.current) {
            lastSearched.current = state.q;
            setText(state.q);
        }
    }, [state.q]);

    useEffect(() => {
        setChoosingView(null);
        setFrom(state.from ?? '');
        setTo(state.to ?? '');
    }, [state.period, state.from, state.to]);

    useEffect(() => () => window.clearTimeout(searchTimer.current), []);

    useEffect(() => {
        if (!selecting) {
            setPicked(new Set());
        }
    }, [selecting]);

    /** Change part of the view and go back to page 1. Everything lives in the address, so a refresh keeps it. */
    const visit = (changes: Record<string, string | null>) => {
        const current = new URL(latestUrl.current || '/', 'http://money-table.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => {
            params[key] = value;
        });
        for (const [key, value] of Object.entries(changes)) {
            if (value === null) {
                delete params[key];
            } else {
                params[key] = value;
            }
        }
        delete params.page;
        router.get(current.pathname, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [prop],
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    const search = (words: string) => {
        window.clearTimeout(searchTimer.current);
        const trimmed = words.trim();
        if (trimmed === lastSearched.current) {
            return;
        }
        lastSearched.current = trimmed;
        visit({ q: trimmed || null });
    };

    const typeSearch = (words: string) => {
        setText(words);
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => search(words), SEARCH_PAUSE_MS);
    };

    const activeView = choosingView ?? state.period;
    const viewChoices = [...VIEW_CHOICES.slice(0, 3), ...(data.extra_periods ?? []).map((value) => ({ value, word: `orders_period_${value}` })), ...VIEW_CHOICES.slice(3)];

    useEffect(() => {
        // On a phone the chips scroll sideways; keep the chosen one in sight.
        const strip = viewChips.current;
        const chosen = strip?.querySelector<HTMLElement>('[aria-pressed="true"]');
        if (strip && chosen) {
            strip.scrollLeft = chosen.offsetLeft - (strip.clientWidth - chosen.offsetWidth) / 2;
        }
    }, [activeView]);

    const chooseView = (value: string) => {
        if (value === 'day' || value === 'custom') {
            setChoosingView(value);
            setFrom(value === state.period ? state.from ?? '' : '');
            setTo(value === state.period ? state.to ?? '' : '');

            return;
        }
        setChoosingView(null);
        visit({ period: value, from: null, to: null });
    };

    const changeFrom = (value: string) => {
        if (value === from) {
            return;
        }
        setFrom(value);
        if (activeView === 'day' && isWholeDate(value)) {
            visit({ period: 'day', from: value, to: null });
        } else if (activeView === 'custom' && isWholeDate(value) && isWholeDate(to)) {
            visit({ period: 'custom', from: value, to });
        }
    };

    const changeTo = (value: string) => {
        if (value === to) {
            return;
        }
        setTo(value);
        if (isWholeDate(from) && isWholeDate(value)) {
            visit({ period: 'custom', from, to: value });
        }
    };

    const sortableColumns = columns.filter((column) => column.sortKey !== undefined && sortable.includes(column.sortKey));

    const sortBy = (column: MoneyColumn<T>) => {
        const key = column.sortKey as string;
        const firstDirection = startsDescending(column) ? 'desc' : 'asc';
        const direction = state.sort === key ? (state.dir === 'asc' ? 'desc' : 'asc') : firstDirection;
        visit({ sort: key, dir: direction });
    };

    const toolbarFilters = filters.filter(isToolbarFilter);
    const usesFilterSheet = toolbarFilters.length < filters.length;
    const chosenFilterCount = Object.keys(state.filters).length;
    const hasNarrowedView = state.q !== '' || chosenFilterCount > 0 || state.period === 'day' || state.period === 'custom';

    const clearView = () => {
        window.clearTimeout(searchTimer.current);
        setText('');
        lastSearched.current = '';
        visit({
            q: null,
            period: null,
            from: null,
            to: null,
            sort: null,
            dir: null,
            ...Object.fromEntries(filters.flatMap(filterKeys).map((key) => [key, null])),
        });
    };

    const openRow = (event: React.MouseEvent, open: (() => void) | null) => {
        const clickedControl = (event.target as HTMLElement).closest('a,button,input,select,textarea,label');
        if (open && !clickedControl && !window.getSelection()?.toString()) {
            open();
        }
    };

    /** What opening this row does: its drawer, its address, or nothing. */
    const rowOpener = (row: T, href: string | null): (() => void) | null => {
        if (onRowOpen) {
            return () => onRowOpen(row);
        }

        return href ? () => router.visit(href) : null;
    };

    const filterSelect = (filter: MoneyFilter) => (
        <select aria-label={filter.label} value={state.filters[filter.key] ?? ''} onChange={(event) => visit({ [filter.key]: event.target.value || null })}>
            <option value="">{filter.label}</option>
            {filter.options?.map((option) => <option key={option.value} value={option.value}>{optionText(option)}</option>)}
        </select>
    );

    const rowId = (row: T): string => String((row as Record<string, unknown>).id);

    const pickBox = (row: T) => (
        <input
            type="checkbox"
            className="bulk-check"
            aria-label={translate('productivity', 'bulk_select_row')}
            checked={picked.has(rowId(row))}
            onChange={() => setPicked((current) => {
                const next = new Set(current);
                if (!next.delete(rowId(row))) {
                    next.add(rowId(row));
                }

                return next;
            })}
        />
    );

    /** A keyboard way to open a row with no visible link: unseen until it has focus, when the whole row shows it. */
    const openRowControl = (row: T, href: string | null) => {
        if (onRowOpen) {
            return <button type="button" className="sr-only mt-row-open" aria-haspopup="dialog" aria-label={translate('portal', 'mt_open_row')} onClick={() => onRowOpen(row)} />;
        }

        return href && !rowArrow ? <Link href={href} className="sr-only mt-row-open" aria-label={translate('portal', 'mt_open_row')} /> : null;
    };

    const sortArrow = (column: MoneyColumn<T>) => {
        if (state.sort !== column.sortKey) {
            return <ArrowUpDown size={12} aria-hidden="true" />;
        }

        return state.dir === 'asc' ? <ArrowUp size={12} aria-hidden="true" /> : <ArrowDown size={12} aria-hidden="true" />;
    };

    const sums = totals.sums;
    const totalColumns = sums ? columns.filter((column) => column.total !== undefined && sums[column.total] !== undefined) : [];
    const countText = `${number(totals.count)}${totals.capped ? '+' : ''}`;
    const totalLabel = translate('portal', 'mt_total_count', { count: countText });

    const hasOpenColumn = rowHref !== undefined && onRowOpen === undefined && rowArrow;
    const phoneTitle = columns.find((column) => column.phone === 'title') ?? columns.find((column) => !column.money && column.phone !== 'hide') ?? columns[0];
    const phoneAmount = columns.find((column) => column.phone === 'amount') ?? columns.find((column) => column.money && column.phone === undefined);
    const phoneLines = columns.filter((column) => column !== phoneTitle && column !== phoneAmount && column.phone !== 'hide' && column.phone !== 'title' && column.phone !== 'amount');

    const dateRefusal = pageProps.errors?.money_table_range;

    return (
        <div className={`money-table${busy ? ' is-busy' : ''}`} aria-busy={busy}>
            {segments && <div className="mt-segments">{segments}</div>}
            <div className="mt-toolbar">
                <form className="mt-search" role="search" onSubmit={(event) => { event.preventDefault(); search(text); }}>
                    <div className="search-input">
                        <Search size={16} aria-hidden="true" />
                        <input type="search" aria-label={searchPlaceholder} value={text} placeholder={searchPlaceholder} maxLength={100} onChange={(event) => typeSearch(event.target.value)} />
                    </div>
                </form>
                {!usesFilterSheet && (filters.length > 0 || sortableColumns.length > 0) && (
                    <button type="button" className="button small mt-sheet-button" onClick={() => setSheetOpen(true)}>
                        <SlidersHorizontal size={15} aria-hidden="true" />
                        {translate('portal', 'mt_filters')}
                        {chosenFilterCount > 0 && ` (${number(chosenFilterCount)})`}
                    </button>
                )}
                {!hideViewChips && (
                    <div className="mt-views" ref={viewChips} role="group" aria-label={translate('portal', 'mt_view_label')}>
                        {viewChoices.map((choice) => (
                            <button key={choice.value || 'all'} type="button" className="mt-view" aria-pressed={activeView === choice.value} onClick={() => chooseView(choice.value)}>
                                {translate('portal', choice.word)}
                            </button>
                        ))}
                    </div>
                )}
                {toolbarFilters.length > 0 && <div className="mt-filters">{toolbarFilters.map((filter) => <React.Fragment key={filter.key}>{filterSelect(filter)}</React.Fragment>)}</div>}
                {!usesFilterSheet && hasNarrowedView && <button type="button" className="text-link mt-clear" onClick={clearView}>{translate('portal', 'clear')}</button>}
                {usesFilterSheet && (
                    <MoreFilterControls
                        bar={{ fields: filters, active: state.filters }}
                        visit={visit}
                        sortOptions={[...sortableColumns.map((column) => ({ key: column.sortKey as string, title: column.title })), ...extraSorts.filter((option) => sortable.includes(option.key))]}
                        sortState={{ sort: state.sort, dir: state.dir }}
                        onSort={(key) => {
                            const column = sortableColumns.find((candidate) => candidate.sortKey === key);
                            if (column) {
                                sortBy(column);

                                return;
                            }
                            visit({ sort: key, dir: state.sort === key && state.dir === 'asc' ? 'desc' : 'asc' });
                        }}
                    />
                )}
                {actions && <div className="mt-actions">{actions}</div>}
            </div>

            {!hideViewChips && activeView === 'day' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_view_day')}<DateInput value={from} onChange={(event) => changeFrom(event.target.value)} /></label>
                </div>
            )}
            {!hideViewChips && activeView === 'custom' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_date_from')}<DateInput value={from} onChange={(event) => changeFrom(event.target.value)} /></label>
                    <label>{translate('portal', 'mt_date_to')}<DateInput value={to} onChange={(event) => changeTo(event.target.value)} /></label>
                </div>
            )}
            {dateRefusal && <FormErrors errors={{ money_table_range: dateRefusal }} className="error mt-error" />}
            {selecting && bulk && rows.length > 0 && <BulkBar kind={bulk.kind} pageIds={rows.map(rowId)} picked={picked} onPick={setPicked} />}

            {rows.length === 0 ? (
                <p className="mt-empty">{empty}</p>
            ) : (
                <>
                    <div className="mt-table-wrap">
                        <table className="mt-table">
                            <thead>
                                <tr>
                                    {selecting && <th className="mt-check-cell" aria-hidden="true" />}
                                    {columns.map((column) => {
                                        const canSort = sortableColumns.includes(column);
                                        const sorted = canSort && state.sort === column.sortKey;

                                        return (
                                            <th key={column.key} className={isRightAligned(column) ? 'is-right' : undefined} aria-sort={!canSort ? undefined : sorted ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
                                                {canSort ? (
                                                    <button type="button" className={`mt-sort${sorted ? ' is-active' : ''}`} onClick={() => sortBy(column)}>
                                                        {column.title}
                                                        {sortArrow(column)}
                                                    </button>
                                                ) : column.title}
                                            </th>
                                        );
                                    })}
                                    {hasOpenColumn && <th aria-hidden="true" />}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, index) => {
                                    const href = onRowOpen ? null : rowHref?.(row) ?? null;
                                    const open = rowOpener(row, href);

                                    return (
                                        <tr key={index} className={open ? 'is-link' : undefined} onClick={(event) => openRow(event, open)}>
                                            {selecting && <td className="mt-check-cell">{pickBox(row)}</td>}
                                            {columns.map((column, columnIndex) => (
                                                <td key={column.key} className={`${isRightAligned(column) ? 'is-right' : ''}${column.money ? ' is-money' : ''}`.trim() || undefined}>{cellContent(column, row)}{columnIndex === 0 && openRowControl(row, href)}</td>
                                            ))}
                                            {hasOpenColumn && <td className="mt-open-cell">{href && <Link href={href} className="mt-open" aria-label={translate('portal', 'mt_open_row')}><ChevronRight size={16} aria-hidden="true" /></Link>}</td>}
                                        </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot>
                                <tr>
                                    {selecting && <td />}
                                    {columns.map((column, index) => (
                                        <td key={column.key} className={`${isRightAligned(column) ? 'is-right' : ''}${column.money ? ' is-money' : ''}`.trim() || undefined}>
                                            {index === 0 ? totalLabel : (column.total !== undefined && sums?.[column.total] !== undefined ? money(sums[column.total]) : null)}
                                        </td>
                                    ))}
                                    {hasOpenColumn && <td />}
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <ul className="mt-cards">
                        {rows.map((row, index) => {
                            const href = onRowOpen ? null : rowHref?.(row) ?? null;
                            const open = rowOpener(row, href);

                            return (
                                <li key={index} className={`mt-card${open ? ' is-link' : ''}`} onClick={(event) => openRow(event, open)}>
                                    {selecting && pickBox(row)}
                                    <div className="mt-card-main">
                                        <div className="mt-card-title">{cellContent(phoneTitle, row)}{openRowControl(row, href)}</div>
                                        {phoneLines.length > 0 && <div className="mt-card-lines">{phoneLines.map((column) => <span key={column.key}>{cellContent(column, row)}</span>)}</div>}
                                    </div>
                                    {phoneAmount && <div className="mt-card-amount">{cellContent(phoneAmount, row)}</div>}
                                    {href && rowArrow && <Link href={href} className="mt-open" aria-label={translate('portal', 'mt_open_row')}><ChevronRight size={16} aria-hidden="true" /></Link>}
                                </li>
                            );
                        })}
                        <li className="mt-card mt-card-total">
                            <div className="mt-card-title">{totalLabel}</div>
                            {totalColumns.map((column) => (
                                <div key={column.key} className="mt-card-total-line">
                                    <span>{column.title}</span>
                                    <b>{money(sums?.[column.total as string])}</b>
                                </div>
                            ))}
                        </li>
                    </ul>

                    <Pagination page={data.rows} />
                </>
            )}

            {sheetOpen && (
                <Modal title={translate('portal', 'mt_filters')} onClose={() => setSheetOpen(false)}>
                    <div className="mt-sheet">
                        {filters.map((filter) => <label key={filter.key}>{filter.label}{filterSelect(filter)}</label>)}
                        {sortableColumns.length > 0 && (
                            <div className="mt-sheet-sort" role="group" aria-label={translate('portal', 'mt_sort')}>
                                <span>{translate('portal', 'mt_sort')}</span>
                                <div>
                                    {sortableColumns.map((column) => (
                                        <button key={column.key} type="button" className={`mt-sort${state.sort === column.sortKey ? ' is-active' : ''}`} aria-pressed={state.sort === column.sortKey} onClick={() => sortBy(column)}>
                                            {column.title}
                                            {sortArrow(column)}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </Modal>
            )}
        </div>
    );
}

import React, { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Search, SlidersHorizontal, X } from 'lucide-react';
import { DateInput, Modal, date, number } from './UI';
import { useT } from '../shared/i18n/t';
import { localizeDigits } from '../business-date';

export type StockFilterField = {
    key: string;
    type: 'select' | 'range' | 'date_range' | 'text';
    label: string;
    group: 'main' | 'more';
    options?: { value: string; label: string }[];
    from?: string;
    to?: string;
    decimal?: boolean;
};

export type StockFilterBarData = { fields: StockFilterField[]; active: Record<string, string> };

export type StockSortState = { sort: string; dir: 'asc' | 'desc' };

export type StockSortOption = { key: string; title: string };

const SEARCH_PAUSE_MS = 400;

const VIEW_CHOICES = [
    { value: '', word: 'orders_period_all' },
    { value: 'today', word: 'orders_period_today' },
    { value: 'week', word: 'orders_period_week' },
    { value: 'month', word: 'orders_period_month' },
    { value: 'year', word: 'orders_period_year' },
    { value: 'day', word: 'mt_view_day' },
    { value: 'custom', word: 'mt_view_range' },
];

/** The address keys that belong to one filter. */
const keysOf = (field: StockFilterField): string[] => (field.type === 'range' || field.type === 'date_range' ? [field.from as string, field.to as string] : [field.key]);

const VIEW_KEYS = ['q', 'period', 'from', 'to', 'sort', 'dir'];

/** Change part of the view and go back to page 1. Everything lives in the address, so a refresh keeps it. */
export function useAddressVisit(prop: string | null) {
    const { url } = usePage();
    const latestUrl = useRef(url);
    latestUrl.current = url;

    return (changes: Record<string, string | null>) => {
        const current = new URL(latestUrl.current || '/', 'http://stock-filter.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => {
            params[key] = value;
        });
        for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === '') {
                delete params[key];
            } else {
                params[key] = value;
            }
        }
        delete params.page;
        router.get(current.pathname, params, { preserveState: true, preserveScroll: true, replace: true, ...(prop ? { only: [prop] } : {}) });
    };
}

function chipText(field: StockFilterField, active: Record<string, string>, translate: ReturnType<typeof useT>): string | null {
    if (field.type === 'select') {
        const chosen = field.options?.find((option) => option.value === active[field.key]);

        return chosen ? `${field.label}: ${localizeDigits(chosen.label)}` : null;
    }
    if (field.type === 'text') {
        return active[field.key] ? `${field.label}: ${active[field.key]}` : null;
    }
    const from = active[field.from as string];
    const to = active[field.to as string];
    if (!from && !to) {
        return null;
    }
    const show = (value: string) => (field.type === 'date_range' ? date(value) : localizeDigits(value));
    if (from && to) {
        return `${field.label}: ${show(from)} – ${show(to)}`;
    }

    return from ? `${field.label}: ${translate('stock_lots', 'chip_from', { value: show(from) })}` : `${field.label}: ${translate('stock_lots', 'chip_to', { value: show(to) })}`;
}

function FieldInput({ field, draft, setDraft }: { field: StockFilterField; draft: Record<string, string>; setDraft: (key: string, value: string) => void }) {
    const translate = useT();
    if (field.type === 'select') {
        return (
            <label className="sf-field">
                <span>{field.label}</span>
                <select value={draft[field.key] ?? ''} onChange={(event) => setDraft(field.key, event.target.value)}>
                    <option value="">{translate('portal', 'stock_filter_all')}</option>
                    {field.options?.map((option) => <option key={option.value} value={option.value}>{localizeDigits(option.label)}</option>)}
                </select>
            </label>
        );
    }
    if (field.type === 'text') {
        return (
            <label className="sf-field">
                <span>{field.label}</span>
                <input type="search" maxLength={60} value={draft[field.key] ?? ''} onChange={(event) => setDraft(field.key, event.target.value)} />
            </label>
        );
    }
    const fromKey = field.from as string;
    const toKey = field.to as string;

    return (
        <fieldset className="sf-field sf-range">
            <legend>{field.label}</legend>
            {field.type === 'date_range' ? (
                <div className="sf-range-boxes">
                    <label><span>{translate('stock_lots', 'range_from')}</span><DateInput value={draft[fromKey] ?? ''} onChange={(event) => setDraft(fromKey, event.target.value)} /></label>
                    <label><span>{translate('stock_lots', 'range_to')}</span><DateInput value={draft[toKey] ?? ''} onChange={(event) => setDraft(toKey, event.target.value)} /></label>
                </div>
            ) : (
                <div className="sf-range-boxes">
                    <label><span>{translate('stock_lots', 'range_from')}</span><input type="number" inputMode={field.decimal ? 'decimal' : 'numeric'} min="0" step={field.decimal ? '0.01' : '1'} value={draft[fromKey] ?? ''} onChange={(event) => setDraft(fromKey, event.target.value)} /></label>
                    <label><span>{translate('stock_lots', 'range_to')}</span><input type="number" inputMode={field.decimal ? 'decimal' : 'numeric'} min="0" step={field.decimal ? '0.01' : '1'} value={draft[toKey] ?? ''} onChange={(event) => setDraft(toKey, event.target.value)} /></label>
                </div>
            )}
        </fieldset>
    );
}

function FilterSheet({ bar, sortOptions, sortState, visit, onClose }: {
    bar: StockFilterBarData;
    sortOptions: StockSortOption[];
    sortState?: StockSortState;
    visit: (changes: Record<string, string | null>) => void;
    onClose: () => void;
}) {
    const translate = useT();
    const [draft, setDraft] = useState<Record<string, string>>(bar.active);
    const setField = (key: string, value: string) => setDraft((current) => ({ ...current, [key]: value }));
    const allKeys = bar.fields.flatMap(keysOf);

    const apply = (event: React.FormEvent) => {
        event.preventDefault();
        visit(Object.fromEntries(allKeys.map((key) => [key, draft[key] || null])));
        onClose();
    };

    const sortBy = (key: string) => {
        const direction = sortState?.sort === key && sortState.dir === 'asc' ? 'desc' : 'asc';
        visit({ sort: key, dir: direction });
    };

    const main = bar.fields.filter((field) => field.group === 'main');
    const more = bar.fields.filter((field) => field.group === 'more');

    return (
        <Modal title={translate('stock_lots', 'more_filters')} onClose={onClose}>
            <form className="mt-sheet sf-sheet" onSubmit={apply}>
                <div className="sf-sheet-grid">
                    {main.map((field) => <div key={field.key} className="sf-main-field"><FieldInput field={field} draft={draft} setDraft={setField} /></div>)}
                    {more.map((field) => <div key={field.key} className={field.type === 'range' || field.type === 'date_range' ? 'sf-wide' : undefined}><FieldInput field={field} draft={draft} setDraft={setField} /></div>)}
                </div>
                {sortOptions.length > 0 && (
                    <div className="mt-sheet-sort sf-sort" role="group" aria-label={translate('portal', 'mt_sort')}>
                        <span>{translate('portal', 'mt_sort')}</span>
                        <div>
                            {sortOptions.map((option) => (
                                <button key={option.key} type="button" className={`mt-sort${sortState?.sort === option.key ? ' is-active' : ''}`} aria-pressed={sortState?.sort === option.key} onClick={() => sortBy(option.key)}>
                                    {option.title}
                                    {sortState?.sort !== option.key ? <ArrowUpDown size={12} aria-hidden="true" /> : sortState.dir === 'asc' ? <ArrowUp size={12} aria-hidden="true" /> : <ArrowDown size={12} aria-hidden="true" />}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <div className="sf-sheet-actions">
                    <button type="submit" className="button dark">{translate('stock_lots', 'filters_apply')}</button>
                    <button type="button" className="button" onClick={() => setDraft({})}>{translate('stock_lots', 'filters_reset')}</button>
                </div>
            </form>
        </Modal>
    );
}

/** The "More filters" button, its sheet, and every applied filter as a removable chip. */
export function StockFilterControls({ bar, prop, sortOptions = [], sortState }: {
    bar: StockFilterBarData;
    prop: string | null;
    sortOptions?: StockSortOption[];
    sortState?: StockSortState;
}) {
    const translate = useT();
    const visit = useAddressVisit(prop);
    const [open, setOpen] = useState(false);
    const chips = bar.fields.flatMap((field) => {
        const text = chipText(field, bar.active, translate);

        return text ? [{ field, text }] : [];
    });
    const moreCount = chips.filter((chip) => chip.field.group === 'more').length;

    const clearEverything = () => visit(Object.fromEntries([...bar.fields.flatMap(keysOf), ...VIEW_KEYS].map((key) => [key, null])));

    return (
        <>
            <button type="button" className="button small sf-more" onClick={() => setOpen(true)} aria-haspopup="dialog">
                <SlidersHorizontal size={15} aria-hidden="true" />
                <span className="sf-more-word">{translate('stock_lots', 'more_filters')}</span>
                <span className="sf-all-word">{translate('stock_lots', 'all_filters')}</span>
                {moreCount > 0 && <span className="sf-count sf-more-word">{number(moreCount)}</span>}
                {chips.length > 0 && <span className="sf-count sf-all-word">{number(chips.length)}</span>}
            </button>
            {chips.length > 0 && (
                <div className="sf-chips">
                    {chips.map(({ field, text }) => (
                        <span key={field.key} className="sf-chip">
                            <span className="sf-chip-text">{text}</span>
                            <button type="button" aria-label={translate('stock_lots', 'filter_remove', { filter: text })} onClick={() => visit(Object.fromEntries(keysOf(field).map((key) => [key, null])))}>
                                <X size={13} aria-hidden="true" />
                            </button>
                        </span>
                    ))}
                    <button type="button" className="text-link sf-clear-all" onClick={clearEverything}>{translate('stock_lots', 'filters_clear_all')}</button>
                </div>
            )}
            {open && <FilterSheet bar={bar} sortOptions={sortOptions} sortState={sortState} visit={visit} onClose={() => setOpen(false)} />}
        </>
    );
}

/** The Product page has cards, not columns, so this bar carries the search, view choice and sort itself. */
export function CatalogFilterBar({ bar, q, period, from, to, sort, sortChoices }: {
    bar: StockFilterBarData;
    q: string;
    period: string;
    from: string;
    to: string;
    sort: string;
    sortChoices: { value: string; label: string }[];
}) {
    const translate = useT();
    const visit = useAddressVisit(null);
    const [text, setText] = useState(q);
    const [choosing, setChoosing] = useState<string | null>(null);
    const [dayOrFrom, setDayOrFrom] = useState(from);
    const [until, setUntil] = useState(to);
    const timer = useRef<number | undefined>(undefined);
    const main = bar.fields.filter((field) => field.group === 'main');
    const view = choosing ?? period;

    useEffect(() => {
        setChoosing(null);
        setDayOrFrom(from);
        setUntil(to);
    }, [period, from, to]);
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const typeSearch = (words: string) => {
        setText(words);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => visit({ q: words.trim() || null }), SEARCH_PAUSE_MS);
    };

    const chooseView = (value: string) => {
        if (value === 'day' || value === 'custom') {
            setChoosing(value);

            return;
        }
        visit({ period: value || null, from: null, to: null });
    };

    const isDate = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value);

    return (
        <div className="sf-catalog">
            <div className="mt-toolbar">
                <form className="mt-search" role="search" onSubmit={(event) => { event.preventDefault(); window.clearTimeout(timer.current); visit({ q: text.trim() || null }); }}>
                    <label className="search-input">
                        <Search size={16} aria-hidden="true" />
                        <span className="sr-only">{translate('portal', 'search_products_catalog')}</span>
                        <input type="search" value={text} maxLength={100} placeholder={translate('portal', 'search_products_catalog')} onChange={(event) => typeSearch(event.target.value)} />
                    </label>
                </form>
                <div className="mt-views" role="group" aria-label={translate('portal', 'mt_view_label')}>
                    {VIEW_CHOICES.map((choice) => (
                        <button key={choice.value || 'all'} type="button" className="mt-view" aria-pressed={view === choice.value} onClick={() => chooseView(choice.value)}>{translate('portal', choice.word)}</button>
                    ))}
                </div>
                <div className="mt-filters">
                    <select className="catalog-sort" aria-label={translate('portal', 'catalog_sort_by')} value={sort} onChange={(event) => visit({ sort: event.target.value === 'name_asc' ? null : event.target.value })}>
                        {sortChoices.map((choice) => <option key={choice.value} value={choice.value}>{choice.label}</option>)}
                    </select>
                    {main.map((field) => (
                        <select key={field.key} aria-label={field.label} value={bar.active[field.key] ?? ''} onChange={(event) => visit({ [field.key]: event.target.value || null })}>
                            <option value="">{field.label}</option>
                            {field.options?.map((option) => <option key={option.value} value={option.value}>{localizeDigits(option.label)}</option>)}
                        </select>
                    ))}
                </div>
                <StockFilterControls bar={bar} prop={null} />
            </div>
            {view === 'day' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_view_day')}<DateInput value={dayOrFrom} onChange={(event) => { setDayOrFrom(event.target.value); if (isDate(event.target.value)) { visit({ period: 'day', from: event.target.value, to: null }); } }} /></label>
                </div>
            )}
            {view === 'custom' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_date_from')}<DateInput value={dayOrFrom} onChange={(event) => { setDayOrFrom(event.target.value); if (isDate(event.target.value) && isDate(until)) { visit({ period: 'custom', from: event.target.value, to: until }); } }} /></label>
                    <label>{translate('portal', 'mt_date_to')}<DateInput value={until} onChange={(event) => { setUntil(event.target.value); if (isDate(dayOrFrom) && isDate(event.target.value)) { visit({ period: 'custom', from: dayOrFrom, to: event.target.value }); } }} /></label>
                </div>
            )}
        </div>
    );
}

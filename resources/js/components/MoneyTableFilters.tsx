import React, { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, SlidersHorizontal, X } from 'lucide-react';
import { DateInput, Modal, date, number } from './UI';
import { useT } from '../shared/i18n/t';
import { localizeDigits } from '../business-date';
import { AutoSelect } from './SearchSelect';

export type MoneyFilterOption = { value: string; label: string; count?: number };

/** One filter as the server describes it. With no type it is a drop-down; with no group it sits on the toolbar. */
export type MoneyFilter = {
    key: string;
    label: string;
    options?: MoneyFilterOption[];
    type?: 'select' | 'range' | 'date_range' | 'text';
    group?: 'main' | 'more';
    from?: string;
    to?: string;
    decimal?: boolean;
};

export type FilterBarData = { fields: MoneyFilter[]; active: Record<string, string> };

export type SortState = { sort: string; dir: 'asc' | 'desc' };

export type SortOption = { key: string; title: string };

export type VisitAddress = (changes: Record<string, string | null>) => void;

const VIEW_KEYS = ['q', 'period', 'from', 'to', 'sort', 'dir'];

/** Only a main drop-down sits on the toolbar. Everything else opens in the sheet. */
export const isToolbarFilter = (filter: MoneyFilter): boolean => (filter.type ?? 'select') === 'select' && (filter.group ?? 'main') === 'main';

/** The address keys that belong to one filter. */
export const filterKeys = (filter: MoneyFilter): string[] => (filter.type === 'range' || filter.type === 'date_range' ? [filter.from as string, filter.to as string] : [filter.key]);

export const optionText = (option: MoneyFilterOption): string => (option.count === undefined ? option.label : `${option.label} (${number(option.count)})`);

/** Change part of the view and go back to page 1. Everything lives in the address, so a refresh keeps it. */
export function useAddressVisit(prop: string | null): VisitAddress {
    const { url } = usePage();
    const latestUrl = useRef(url);
    latestUrl.current = url;

    return (changes) => {
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

function chipText(filter: MoneyFilter, active: Record<string, string>, translate: ReturnType<typeof useT>): string | null {
    if ((filter.type ?? 'select') === 'select') {
        const chosen = filter.options?.find((option) => option.value === active[filter.key]);

        return chosen ? `${filter.label}: ${localizeDigits(chosen.label)}` : null;
    }
    if (filter.type === 'text') {
        return active[filter.key] ? `${filter.label}: ${active[filter.key]}` : null;
    }
    const from = active[filter.from as string];
    const to = active[filter.to as string];
    if (!from && !to) {
        return null;
    }
    const show = (value: string) => (filter.type === 'date_range' ? date(value) : localizeDigits(value));
    if (from && to) {
        return `${filter.label}: ${show(from)} – ${show(to)}`;
    }

    return from ? `${filter.label}: ${translate('stock_lots', 'chip_from', { value: show(from) })}` : `${filter.label}: ${translate('stock_lots', 'chip_to', { value: show(to) })}`;
}

function FieldInput({ filter, draft, setDraft }: { filter: MoneyFilter; draft: Record<string, string>; setDraft: (key: string, value: string) => void }) {
    const translate = useT();
    if ((filter.type ?? 'select') === 'select') {
        return (
            <label className="sf-field">
                <span>{filter.label}</span>
                <AutoSelect
                    value={draft[filter.key] ?? ''}
                    onChange={(value) => setDraft(filter.key, value)}
                    emptyLabel={translate('portal', 'stock_filter_all')}
                    options={(filter.options ?? []).map((option) => ({ value: option.value, label: localizeDigits(optionText(option)) }))}
                />
            </label>
        );
    }
    if (filter.type === 'text') {
        return (
            <label className="sf-field">
                <span>{filter.label}</span>
                <input type="search" maxLength={60} value={draft[filter.key] ?? ''} onChange={(event) => setDraft(filter.key, event.target.value)} />
            </label>
        );
    }
    const fromKey = filter.from as string;
    const toKey = filter.to as string;

    return (
        <fieldset className="sf-field sf-range">
            <legend>{filter.label}</legend>
            {filter.type === 'date_range' ? (
                <div className="sf-range-boxes">
                    <label><span>{translate('stock_lots', 'range_from')}</span><DateInput value={draft[fromKey] ?? ''} onChange={(event) => setDraft(fromKey, event.target.value)} /></label>
                    <label><span>{translate('stock_lots', 'range_to')}</span><DateInput value={draft[toKey] ?? ''} onChange={(event) => setDraft(toKey, event.target.value)} /></label>
                </div>
            ) : (
                <div className="sf-range-boxes">
                    <label><span>{translate('stock_lots', 'range_from')}</span><input type="number" inputMode={filter.decimal ? 'decimal' : 'numeric'} min="0" step={filter.decimal ? '0.01' : '1'} value={draft[fromKey] ?? ''} onChange={(event) => setDraft(fromKey, event.target.value)} /></label>
                    <label><span>{translate('stock_lots', 'range_to')}</span><input type="number" inputMode={filter.decimal ? 'decimal' : 'numeric'} min="0" step={filter.decimal ? '0.01' : '1'} value={draft[toKey] ?? ''} onChange={(event) => setDraft(toKey, event.target.value)} /></label>
                </div>
            )}
        </fieldset>
    );
}

function FilterSheet({ bar, sortOptions, sortState, onSort, visit, onClose }: {
    bar: FilterBarData;
    sortOptions: SortOption[];
    sortState?: SortState;
    onSort?: (key: string) => void;
    visit: VisitAddress;
    onClose: () => void;
}) {
    const translate = useT();
    const [draft, setDraft] = useState<Record<string, string>>(bar.active);
    const setField = (key: string, value: string) => setDraft((current) => ({ ...current, [key]: value }));
    const allKeys = bar.fields.flatMap(filterKeys);

    const apply = (event: React.FormEvent) => {
        event.preventDefault();
        visit(Object.fromEntries(allKeys.map((key) => [key, draft[key] || null])));
        onClose();
    };

    return (
        <Modal title={translate('stock_lots', 'more_filters')} onClose={onClose}>
            <form className="mt-sheet sf-sheet" onSubmit={apply}>
                <div className="sf-sheet-grid">
                    {bar.fields.filter(isToolbarFilter).map((filter) => <div key={filter.key} className="sf-main-field"><FieldInput filter={filter} draft={draft} setDraft={setField} /></div>)}
                    {bar.fields.filter((filter) => !isToolbarFilter(filter)).map((filter) => <div key={filter.key} className={filter.type === 'range' || filter.type === 'date_range' ? 'sf-wide' : undefined}><FieldInput filter={filter} draft={draft} setDraft={setField} /></div>)}
                </div>
                {sortOptions.length > 0 && (
                    <div className="mt-sheet-sort sf-sort" role="group" aria-label={translate('portal', 'mt_sort')}>
                        <span>{translate('portal', 'mt_sort')}</span>
                        <div>
                            {sortOptions.map((option) => (
                                <button key={option.key} type="button" className={`mt-sort${sortState?.sort === option.key ? ' is-active' : ''}`} aria-pressed={sortState?.sort === option.key} onClick={() => onSort?.(option.key)}>
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
export function MoreFilterControls({ bar, visit, sortOptions = [], sortState, onSort }: {
    bar: FilterBarData;
    visit: VisitAddress;
    sortOptions?: SortOption[];
    sortState?: SortState;
    onSort?: (key: string) => void;
}) {
    const translate = useT();
    const [open, setOpen] = useState(false);
    const chips = bar.fields.flatMap((filter) => {
        const text = chipText(filter, bar.active, translate);

        return text ? [{ filter, text }] : [];
    });
    const moreCount = chips.filter((chip) => !isToolbarFilter(chip.filter)).length;

    const clearEverything = () => visit(Object.fromEntries([...bar.fields.flatMap(filterKeys), ...VIEW_KEYS].map((key) => [key, null])));

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
                    {chips.map(({ filter, text }) => (
                        <span key={filter.key} className="sf-chip">
                            <span className="sf-chip-text">{text}</span>
                            <button type="button" aria-label={translate('stock_lots', 'filter_remove', { filter: text })} onClick={() => visit(Object.fromEntries(filterKeys(filter).map((key) => [key, null])))}>
                                <X size={13} aria-hidden="true" />
                            </button>
                        </span>
                    ))}
                    <button type="button" className="text-link sf-clear-all" onClick={clearEverything}>{translate('stock_lots', 'filters_clear_all')}</button>
                </div>
            )}
            {open && <FilterSheet bar={bar} sortOptions={sortOptions} sortState={sortState} onSort={onSort} visit={visit} onClose={() => setOpen(false)} />}
        </>
    );
}

import React, { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import { matchesSearch } from '../shared/search-match';

export type SearchOption = {
    value: string;
    label: string;
    /** Smaller text beside the label, such as a code or a price; the search looks in it too. */
    hint?: string;
    /** Extra words the search should find this row by, such as an SKU. Never shown. */
    keywords?: string;
    disabled?: boolean;
};

export type SearchSelectProps = {
    options: SearchOption[];
    value: string;
    onChange: (value: string, option: SearchOption | null) => void;
    /** The "nothing chosen" row. Leave it out when a choice can't be taken back. */
    emptyLabel?: string;
    /** Shown on the closed box while nothing is chosen and there is no emptyLabel. */
    placeholder?: string;
    /** Shown on the closed box when the chosen value is no longer in the list (a server search moved on). */
    selectedLabel?: string;
    required?: boolean;
    disabled?: boolean;
    name?: string;
    id?: string;
    ariaLabel?: string;
    className?: string;
    /** For a list searched on the server: called with the typed words, and the parent replaces `options`. */
    onSearch?: (query: string) => void;
    loading?: boolean;
};

/** A list this long or longer gets the search box when a form helper decides by size. */
export const LongListFrom = 9;

/** Rows drawn at once; typing narrows a longer list. */
const RenderLimit = 200;

type Place = { left: number; width: number; maxHeight: number; top?: number; bottom?: number };

const supportsPopover = (): boolean => typeof HTMLElement !== 'undefined' && typeof HTMLElement.prototype.showPopover === 'function';

/**
 * A drop-down whose opened list starts with a search box. It stands in for a `<select>` with a
 * long list: the person types a part of a name or code and the list narrows. The list opens in
 * the browser's top layer, so a scrolling table or a dialog never cuts it off.
 */
export default function SearchSelect({
    options, value, onChange, emptyLabel, placeholder, selectedLabel, required, disabled, name, id, ariaLabel, className, onSearch, loading,
}: SearchSelectProps) {
    const translate = useT();
    const listId = useId();
    const root = useRef<HTMLSpanElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const searchBox = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [place, setPlace] = useState<Place | null>(null);
    const [invalid, setInvalid] = useState(false);

    const chosen = options.find((option) => option.value === value) ?? null;
    const rows = useMemo(() => {
        const matched = onSearch ? options : options.filter((option) => matchesSearch(`${option.label} ${option.hint ?? ''} ${option.keywords ?? ''}`, query));
        const shown = matched.slice(0, RenderLimit);
        const blank: SearchOption[] = emptyLabel !== undefined && query.trim() === '' ? [{ value: '', label: emptyLabel }] : [];
        return { list: [...blank, ...shown], hidden: matched.length - shown.length };
    }, [options, query, emptyLabel, onSearch]);

    const position = () => {
        const box = trigger.current?.getBoundingClientRect();
        if (!box) {
            return;
        }
        const view = window.visualViewport;
        const viewLeft = view?.offsetLeft ?? 0;
        const viewTop = view?.offsetTop ?? 0;
        const viewWidth = view?.width ?? window.innerWidth;
        const viewHeight = view?.height ?? window.innerHeight;
        const width = Math.min(Math.max(box.width, 260), viewWidth - 16);
        const left = Math.min(Math.max(box.left, viewLeft + 8), viewLeft + viewWidth - width - 8);
        const below = viewTop + viewHeight - box.bottom - 12;
        const above = box.top - viewTop - 12;
        const up = below < 240 && above > below;
        setPlace({
            left,
            width,
            maxHeight: Math.max(160, Math.min(420, up ? above : below)),
            ...(up ? { bottom: window.innerHeight - box.top + 4 } : { top: box.bottom + 4 }),
        });
    };

    const close = (returnFocus: boolean) => {
        setOpen(false);
        setPlace(null);
        if (query !== '') {
            // The next opening starts from the whole list again.
            setQuery('');
            onSearch?.('');
        }
        if (returnFocus) {
            trigger.current?.focus();
        }
    };

    const openList = (typed = '') => {
        if (disabled) {
            return;
        }
        setQuery(typed);
        if (typed !== '') {
            onSearch?.(typed);
        }
        // The query is always empty while closed, so `rows` is the whole list here.
        const at = rows.list.findIndex((option) => option.value === value);
        setActive(typed === '' && at > 0 ? at : 0);
        setInvalid(false);
        setOpen(true);
    };

    const choose = (option: SearchOption) => {
        if (option.disabled) {
            return;
        }
        onChange(option.value, option.value === '' ? null : option);
        close(true);
    };

    // Places the list and lifts it into the top layer.
    useLayoutEffect(() => {
        if (!open) {
            return undefined;
        }
        position();
        const box = panel.current;
        if (box && supportsPopover() && !box.matches(':popover-open')) {
            try { box.showPopover(); } catch { /* an older browser keeps the plain fixed box */ }
        }

        const onPress = (event: PointerEvent) => {
            const target = event.target as Node;
            if (!root.current?.contains(target) && !panel.current?.contains(target)) {
                close(false);
            }
        };
        const onScroll = (event: Event) => {
            if (!panel.current?.contains(event.target as Node)) {
                position();
            }
        };
        document.addEventListener('pointerdown', onPress, true);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', position);
        window.visualViewport?.addEventListener('resize', position);
        window.visualViewport?.addEventListener('scroll', position);
        return () => {
            document.removeEventListener('pointerdown', onPress, true);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', position);
            window.visualViewport?.removeEventListener('resize', position);
            window.visualViewport?.removeEventListener('scroll', position);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // The cursor goes into the search box once the list has its place; a hidden box can't take it.
    const placed = place !== null;
    useLayoutEffect(() => {
        if (open && placed) {
            searchBox.current?.focus({ preventScroll: true });
        }
    }, [open, placed]);

    // Keeps the highlighted row inside the list and in view.
    useEffect(() => {
        if (!open) {
            return;
        }
        if (active > rows.list.length - 1) {
            setActive(Math.max(0, rows.list.length - 1));
            return;
        }
        panel.current?.querySelector(`[data-row="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [open, active, rows.list.length]);

    const move = (step: number) => {
        const count = rows.list.length;
        if (count === 0) {
            return;
        }
        let next = active;
        for (let tries = 0; tries < count; tries += 1) {
            next = (next + step + count) % count;
            if (!rows.list[next].disabled) {
                break;
            }
        }
        setActive(next);
    };

    const onSearchKey = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            move(event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter') {
            // Enter picks the highlighted row; it must never send the form around it.
            event.preventDefault();
            const row = rows.list[active];
            if (row) {
                choose(row);
            }
        } else if (event.key === 'Escape') {
            // Closes only this list, not the dialog it may sit in.
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'Tab') {
            close(true);
        }
    };

    const onTriggerKey = (event: React.KeyboardEvent<HTMLButtonElement>) => {
        if (open || disabled) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            openList();
        } else if (event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey) {
            // Typing on the closed box starts a search, like typing on a plain drop-down.
            event.preventDefault();
            openList(event.key);
        }
    };

    const shownLabel = chosen?.label ?? (value !== '' ? selectedLabel : undefined) ?? emptyLabel ?? placeholder ?? translate('portal', 'select_placeholder');
    const isBlank = chosen === null && (value === '' || selectedLabel === undefined);

    return (
        <span className={`ss${className ? ` ${className}` : ''}`} ref={root}>
            <button
                type="button"
                ref={trigger}
                id={id}
                className={`ss-trigger${isBlank ? ' is-blank' : ''}${invalid ? ' is-invalid' : ''}`}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                aria-label={ariaLabel}
                aria-invalid={invalid || undefined}
                disabled={disabled}
                onClick={() => (open ? close(false) : openList())}
                onKeyDown={onTriggerKey}
            >
                <span className="ss-value">{shownLabel}</span>
                <ChevronDown size={14} aria-hidden="true" />
            </button>
            {(name !== undefined || required) && (
                // Carries the value in a plain form post and lets the browser refuse an empty required choice.
                <input
                    className="ss-proxy"
                    tabIndex={-1}
                    aria-hidden="true"
                    name={name}
                    value={value}
                    required={required}
                    disabled={disabled}
                    onChange={() => undefined}
                    onInvalid={() => setInvalid(true)}
                    onFocus={() => trigger.current?.focus()}
                />
            )}
            {open && (
                <div
                    ref={panel}
                    className="ss-panel"
                    popover={supportsPopover() ? 'manual' : undefined}
                    style={place ? { left: place.left, width: place.width, maxHeight: place.maxHeight, top: place.top, bottom: place.bottom } : { visibility: 'hidden' }}
                    // Inside a <label>, a press on the list must not count as a press on the label.
                    onClick={(event) => event.preventDefault()}
                >
                    <div className="ss-search">
                        <Search size={15} aria-hidden="true" />
                        <input
                            ref={searchBox}
                            type="text"
                            inputMode="search"
                            enterKeyHint="search"
                            autoComplete="off"
                            autoCorrect="off"
                            spellCheck={false}
                            role="combobox"
                            aria-expanded="true"
                            aria-controls={listId}
                            aria-autocomplete="list"
                            aria-activedescendant={rows.list[active] ? `${listId}-${active}` : undefined}
                            aria-label={translate('portal', 'search_select_search')}
                            placeholder={translate('portal', 'search_select_search')}
                            value={query}
                            onChange={(event) => { setQuery(event.target.value); setActive(0); onSearch?.(event.target.value); }}
                            onKeyDown={onSearchKey}
                        />
                    </div>
                    <ul className="ss-list" id={listId} role="listbox" aria-label={ariaLabel}>
                        {rows.list.map((option, index) => (
                            <li
                                key={`${option.value}|${index}`}
                                id={`${listId}-${index}`}
                                data-row={index}
                                role="option"
                                aria-selected={option.value === value}
                                aria-disabled={option.disabled || undefined}
                                className={`ss-option${index === active ? ' is-active' : ''}${option.value === '' ? ' is-blank' : ''}`}
                                // Keeps the cursor in the search box while a row is pressed.
                                onMouseDown={(event) => event.preventDefault()}
                                onMouseEnter={() => setActive(index)}
                                onClick={() => choose(option)}
                            >
                                <span className="ss-option-label">{option.label}</span>
                                {option.hint && <span className="ss-option-hint">{option.hint}</span>}
                                {option.value === value && option.value !== '' && <Check size={14} aria-hidden="true" />}
                            </li>
                        ))}
                    </ul>
                    {loading && <p className="ss-note" role="status">{translate('portal', 'search_select_loading')}</p>}
                    {!loading && rows.list.length === 0 && <p className="ss-note" role="status">{translate('portal', 'search_select_none')}</p>}
                    {rows.hidden > 0 && <p className="ss-note">{translate('portal', 'search_select_more', { count: rows.hidden })}</p>}
                </div>
            )}
        </span>
    );
}

/**
 * For the shared form helpers, which draw whatever list they are given: a short list stays a
 * plain drop-down, and a list of LongListFrom rows or more gets the search box. `search` asks for
 * the search box whatever the size, for lists that grow without limit (products, partners, staff).
 */
export function AutoSelect({ search, ...props }: SearchSelectProps & { search?: boolean }) {
    if (search || props.onSearch || props.options.length >= LongListFrom) {
        return <SearchSelect {...props} />;
    }
    const { options, value, onChange, emptyLabel, required, disabled, name, id, ariaLabel, className } = props;

    return (
        <select
            id={id}
            name={name}
            className={className}
            aria-label={ariaLabel}
            value={value}
            required={required}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value, options.find((option) => option.value === event.target.value) ?? null)}
        >
            {emptyLabel !== undefined && <option value="">{emptyLabel}</option>}
            {options.map((option) => <option key={option.value} value={option.value} disabled={option.disabled}>{option.label}</option>)}
        </select>
    );
}

/**
 * Several choices from a long list: a search box over a list of tick boxes. It stands in for a
 * tall `<select multiple>`, which needs Ctrl held down and can't be searched.
 */
export function SearchCheckList({ options, values, onChange, disabled, ariaLabel }: {
    options: SearchOption[];
    values: string[];
    onChange: (values: string[]) => void;
    disabled?: boolean;
    ariaLabel?: string;
}) {
    const translate = useT();
    const [query, setQuery] = useState('');
    const shown = options.filter((option) => values.includes(option.value) || matchesSearch(`${option.label} ${option.hint ?? ''} ${option.keywords ?? ''}`, query));
    const toggle = (value: string) => onChange(values.includes(value) ? values.filter((item) => item !== value) : [...values, value]);

    return (
        <div className="ss-checks" role="group" aria-label={ariaLabel}>
            <div className="ss-search">
                <Search size={15} aria-hidden="true" />
                <input
                    type="text"
                    inputMode="search"
                    autoComplete="off"
                    spellCheck={false}
                    disabled={disabled}
                    aria-label={translate('portal', 'search_select_search')}
                    placeholder={translate('portal', 'search_select_search')}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    // Enter in the search box must not send the form around it.
                    onKeyDown={(event) => { if (event.key === 'Enter') event.preventDefault(); }}
                />
            </div>
            <ul className="ss-list">
                {shown.slice(0, RenderLimit).map((option) => (
                    <li key={option.value}>
                        <label className="ss-check">
                            <input type="checkbox" checked={values.includes(option.value)} disabled={disabled || option.disabled} onChange={() => toggle(option.value)} />
                            <span className="ss-option-label">{option.label}</span>
                            {option.hint && <span className="ss-option-hint">{option.hint}</span>}
                        </label>
                    </li>
                ))}
            </ul>
            {shown.length === 0 && <p className="ss-note" role="status">{translate('portal', 'search_select_none')}</p>}
            {shown.length > RenderLimit && <p className="ss-note">{translate('portal', 'search_select_more', { count: shown.length - RenderLimit })}</p>}
        </div>
    );
}

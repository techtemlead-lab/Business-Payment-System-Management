import React, { useId } from 'react';
import { useT } from '../shared/i18n/t';
import { AutoSelect, type SearchOption } from './SearchSelect';

export type FilterOption = { value: string; label: string; count?: number };

/** Kept for the callers that still use the old name. */
export type ChipOption = FilterOption;

/** A filter with this many choices or fewer (not counting "All") is a plain drop-down; a longer list gets the searchable drop-down. */
export const DropDownUpTo = 60;

/** The old name of DropDownUpTo. */
export const ChipsUpTo = DropDownUpTo;

/** Filters that pick one person, place, product or branch from a list that can grow without limit stay a searchable drop-down. */
const LongListKey = /(place|partner|product|dealer|account|branch|upazila|district|division|union|user|staff|checked_by|sponsor|member)/i;

export const wantsSearch = (key: string, optionCount: number): boolean => LongListKey.test(key) || optionCount > 60;

/**
 * One filter as its own drop-down: the name of the filter in front, the chosen value inside, "All" first
 * in the list. Choosing a value applies it at once, so there is no Apply button. It is a native select, so
 * Tab, the arrow keys and a screen reader work as everywhere else, and a phone opens its own picker. A
 * filter that is switched on is drawn in the brand colour so the page shows what is narrowed.
 */
export function FilterDropdown({ options, value, onChange, label, allLabel, ariaLabel, className, showLabel = false, highlight = true }: {
    options: FilterOption[];
    value: string;
    onChange: (value: string) => void;
    /** The name of the filter, read out for the drop-down and shown before the value when showLabel is set. */
    label: string;
    /** The first choice, which clears the filter. Leave it out when a choice can't be taken back. */
    allLabel?: string;
    ariaLabel?: string;
    className?: string;
    showLabel?: boolean;
    /** Draw the chosen value in the brand colour. Turn it off for a sort order, which narrows nothing. */
    highlight?: boolean;
}) {
    const selectId = useId();
    const hasAll = allLabel !== undefined;
    // A quick-pick list with no "All" starts on nothing: show a dash instead of falsely marking the first choice.
    const startsEmpty = !hasAll && !options.some((option) => option.value === value);
    // Two lists carry their own "all" choice with the value "all" instead of an empty one.
    const isOn = highlight && value !== '' && value !== 'all' && !startsEmpty;
    const classes = ['fd', isOn ? 'is-on' : '', showLabel ? '' : 'fd-bare', className ?? ''].filter(Boolean).join(' ');

    return (
        <div className={classes}>
            {showLabel && <label className="fd-label" htmlFor={selectId}>{label}</label>}
            <select
                id={selectId}
                className="fd-select"
                aria-label={showLabel ? undefined : ariaLabel ?? label}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {hasAll && <option value="">{allLabel}</option>}
                {startsEmpty && <option value="" disabled hidden>—</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>{option.count === undefined ? option.label : `${option.label} (${option.count})`}</option>
                ))}
            </select>
        </div>
    );
}

/** The old name of FilterDropdown. */
export const FilterChips = FilterDropdown;

/**
 * The filter control for every list: a drop-down per filter, or the searchable drop-down for a long list
 * (places, products, partners). Both apply the moment a choice is made.
 */
export default function FilterPicker({ options, value, onChange, label, allLabel, search, maxChips = DropDownUpTo, className, chipClassName, showLabel = true }: {
    options: SearchOption[] | FilterOption[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    /** The "no filter" choice; the standard "All" when left out. */
    allLabel?: string;
    /** Ask for the searchable drop-down whatever the size. */
    search?: boolean;
    /** Most choices still drawn as a plain drop-down; a longer list becomes the searchable one. Callers that never want the search box pass Infinity. */
    maxChips?: number;
    /** Class for the searchable form of the control. */
    className?: string;
    /** Class for the plain drop-down form of the control. */
    chipClassName?: string;
    /** Show the filter name before the value. */
    showLabel?: boolean;
}) {
    const translate = useT();
    const everything = allLabel ?? translate('portal', 'all');

    if (search || options.length > maxChips) {
        return (
            <AutoSelect
                search
                className={`ss-filter ss-fd${value !== '' ? ' ss-filter-on' : ''}${className ? ` ${className}` : ''}`}
                ariaLabel={label}
                value={value}
                onChange={(next) => onChange(next)}
                emptyLabel={label}
                options={options.map((option) => ({ value: option.value, label: option.label }))}
            />
        );
    }

    return <FilterDropdown options={options} value={value} onChange={onChange} label={label} allLabel={everything} className={chipClassName} showLabel={showLabel} />;
}

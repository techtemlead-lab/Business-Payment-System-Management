import React from 'react';
import { useT } from '../shared/i18n/t';
import { AutoSelect, type SearchOption } from './SearchSelect';

export type ChipOption = { value: string; label: string; count?: number };

/** A filter with this many choices or fewer (not counting "All") is shown as a row of chips; longer lists get the searchable drop-down. */
export const ChipsUpTo = 8;

/** Filters that pick one person, place, product or branch from a list that can grow without limit stay a searchable drop-down. */
const LongListKey = /(place|partner|product|dealer|account|branch|upazila|district|division|union|user|staff|checked_by|sponsor|member)/i;

export const wantsSearch = (key: string, optionCount: number): boolean => LongListKey.test(key) || optionCount > 60;

/**
 * One filter as a row of buttons: "All" first, then each choice. One click on a chip applies it, so there
 * is no drop-down to open and no Apply button. The chosen chip is marked with aria-pressed and a filled
 * look; the row wraps on a narrow screen. Each chip is a real button, so Tab, Enter and Space work.
 */
export function FilterChips({ options, value, onChange, label, allLabel, ariaLabel, className, showLabel = false }: {
    options: ChipOption[];
    value: string;
    onChange: (value: string) => void;
    /** The name of the filter, read out for the group and shown before the chips when showLabel is set. */
    label: string;
    /** The first chip, which clears the filter. Leave it out when a choice can't be taken back. */
    allLabel?: string;
    ariaLabel?: string;
    className?: string;
    showLabel?: boolean;
}) {
    const choices: ChipOption[] = allLabel === undefined ? options : [{ value: '', label: allLabel }, ...options];

    return (
        <div className={`fc${className ? ` ${className}` : ''}`} role="group" aria-label={ariaLabel ?? label}>
            {showLabel && <span className="fc-label" aria-hidden="true">{label}</span>}
            <div className="fc-row">
                {choices.map((option) => (
                    <button
                        key={option.value === '' ? '__all' : option.value}
                        type="button"
                        className="fc-chip"
                        aria-pressed={option.value === value}
                        onClick={() => option.value !== value && onChange(option.value)}
                    >
                        <span>{option.label}</span>
                        {option.count !== undefined && <span className="fc-count">{option.count}</span>}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * The filter control for every list: a small set of choices shows as chips, a long list (places, products,
 * partners) as a searchable drop-down. Both apply the moment a choice is made.
 */
export default function FilterPicker({ options, value, onChange, label, allLabel, search, maxChips = ChipsUpTo, className, chipClassName, showLabel = true }: {
    options: SearchOption[] | ChipOption[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    /** The "no filter" choice; the standard "All" when left out. */
    allLabel?: string;
    /** Ask for the searchable drop-down whatever the size. */
    search?: boolean;
    /** Most choices still drawn as chips. Fund, income head, type and status lists pass Infinity so they never become a drop-down. */
    maxChips?: number;
    /** Class for the drop-down form of the control. */
    className?: string;
    chipClassName?: string;
    /** Show the filter name before the chips. */
    showLabel?: boolean;
}) {
    const translate = useT();
    const everything = allLabel ?? translate('portal', 'all');

    if (search || options.length > maxChips) {
        return (
            <AutoSelect
                search
                className={`ss-filter${className ? ` ${className}` : ''}`}
                ariaLabel={label}
                value={value}
                onChange={(next) => onChange(next)}
                emptyLabel={label}
                options={options.map((option) => ({ value: option.value, label: option.label }))}
            />
        );
    }

    return <FilterChips options={options} value={value} onChange={onChange} label={label} allLabel={everything} className={chipClassName} showLabel={showLabel} />;
}

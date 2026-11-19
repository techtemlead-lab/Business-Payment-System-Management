import React, { useEffect, useRef, useState } from 'react';
import { Calendar as CalendarIcon } from 'lucide-react';
import { parseTypedDate, toDisplayDate, toIsoDate } from '../business-date';
import { useT } from '../shared/i18n/t';

export type DateChangeEvent = { target: { value: string; name?: string } };

export interface DateInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'defaultValue'> {
    value?: string | null;
    defaultValue?: string | null;
    onChange?: (e: DateChangeEvent) => void;
    separator?: '/' | '-';
    wrapperClassName?: string;
    placeholder?: string;
}

export default function DateInput({
    value,
    defaultValue,
    onChange,
    separator = '/',
    wrapperClassName = '',
    className = '',
    placeholder,
    name,
    required,
    disabled,
    min,
    max,
    id,
    style,
    ...rest
}: DateInputProps) {
    const translate = useT();
    const text = (key: string, fallback: string) => {
        const localized = translate('portal', key);
        return localized === key ? fallback : localized;
    };
    const effectiveInitial = value !== undefined ? value : defaultValue;
    const [displayVal, setDisplayVal] = useState<string>(() => toDisplayDate(effectiveInitial, separator));
    const [isoVal, setIsoVal] = useState<string>(() => toIsoDate(effectiveInitial));
    const nativePickerRef = useRef<HTMLInputElement>(null);

    // Synchronize when external value changes
    useEffect(() => {
        if (value !== undefined) {
            const nextIso = toIsoDate(value);
            // What this box just told the parent comes back as the same value: leave the typing alone.
            if (nextIso === isoVal) {
                return;
            }
            const nextDisplay = toDisplayDate(value, separator);
            setIsoVal(nextIso);
            setDisplayVal(nextDisplay);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value, separator]);

    const notifyChange = (newIso: string) => {
        setIsoVal(newIso);
        if (onChange) {
            onChange({ target: { value: newIso, name } });
        }
    };

    const handleTextChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        setDisplayVal(raw);

        // If user cleared the input
        if (!raw.trim()) {
            notifyChange('');
            return;
        }

        // A whole real date (15/06/1990, 15-6-1990, Bangla digits) is taken at once. Anything else drops the
        // date held before, so half-typed text can never submit an old value.
        const typed = parseTypedDate(raw);
        if (typed) {
            notifyChange(typed);
        } else if (isoVal !== '') {
            notifyChange('');
        }
    };

    const handleBlur = () => {
        const raw = displayVal.trim();
        if (!raw) return;

        const typed = parseTypedDate(raw);
        if (typed) {
            setDisplayVal(toDisplayDate(typed, separator));
            notifyChange(typed);
        } else if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            setDisplayVal(toDisplayDate(raw, separator));
            notifyChange(raw);
        }
    };

    const handleNativePickerChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const chosen = e.target.value; // YYYY-MM-DD
        if (chosen) {
            setDisplayVal(toDisplayDate(chosen, separator));
            notifyChange(chosen);
        } else {
            setDisplayVal('');
            notifyChange('');
        }
    };

    const openPicker = () => {
        if (disabled) return;
        const picker = nativePickerRef.current;
        if (!picker) return;

        if (typeof picker.showPicker === 'function') {
            try {
                picker.showPicker();
                return;
            } catch {
                // Fallback to click if showPicker throws or unsupported
            }
        }
        picker.click();
    };

    return (
        <div className={`date-input-wrapper ${wrapperClassName}`.trim()} style={style}>
            <input
                type="text"
                id={id}
                className={`date-input-field ${className}`.trim()}
                placeholder={placeholder ?? text('date_input_placeholder', 'DD/MM/YYYY')}
                value={displayVal}
                onChange={handleTextChange}
                onBlur={handleBlur}
                disabled={disabled}
                required={required && !isoVal}
                autoComplete="off"
                {...rest}
            />

            {/* Hidden field carrying the ISO value for form submissions */}
            {name && <input type="hidden" name={name} value={isoVal} />}

            {/* Native date picker used as popup calendar. It belongs to no form, so its min and max only limit the calendar and the server gives the reason in the page language. */}
            <input
                ref={nativePickerRef}
                type="date"
                form="date-picker-popup"
                tabIndex={-1}
                aria-hidden="true"
                className="date-native-picker"
                value={isoVal}
                min={min}
                max={max}
                onChange={handleNativePickerChange}
                disabled={disabled}
            />

            <button
                type="button"
                className="date-picker-btn"
                onClick={openPicker}
                disabled={disabled}
                tabIndex={-1}
                aria-label={text('date_input_open_calendar', 'Open calendar')}
            >
                <CalendarIcon size={17} />
            </button>
        </div>
    );
}

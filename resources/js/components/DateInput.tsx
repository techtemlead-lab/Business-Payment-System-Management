import React, { useEffect, useRef, useState } from 'react';
import { Calendar as CalendarIcon } from 'lucide-react';
import { toDisplayDate, toIsoDate } from '../business-date';

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
    placeholder = 'DD/MM/YYYY',
    name,
    required,
    disabled,
    min,
    max,
    id,
    style,
    ...rest
}: DateInputProps) {
    const effectiveInitial = value !== undefined ? value : defaultValue;
    const [displayVal, setDisplayVal] = useState<string>(() => toDisplayDate(effectiveInitial, separator));
    const [isoVal, setIsoVal] = useState<string>(() => toIsoDate(effectiveInitial));
    const nativePickerRef = useRef<HTMLInputElement>(null);

    // Synchronize when external value changes
    useEffect(() => {
        if (value !== undefined) {
            const nextIso = toIsoDate(value);
            const nextDisplay = toDisplayDate(value, separator);
            setIsoVal(nextIso);
            setDisplayVal(nextDisplay);
        }
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

        // Check if user has entered a valid DD/MM/YYYY or DD-MM-YYYY date
        const cleaned = raw.trim();
        const dmyMatch = cleaned.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
        if (dmyMatch) {
            const d = parseInt(dmyMatch[1], 10);
            const m = parseInt(dmyMatch[2], 10);
            const y = parseInt(dmyMatch[3], 10);
            if (m >= 1 && m <= 12 && d >= 1 && d <= 31 && y >= 1900 && y <= 2100) {
                const normalizedIso = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                notifyChange(normalizedIso);
            }
        }
    };

    const handleBlur = (e: React.FocusEvent<HTMLInputElement>) => {
        const raw = displayVal.trim();
        if (!raw) return;

        const dmyMatch = raw.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
        if (dmyMatch) {
            const d = dmyMatch[1].padStart(2, '0');
            const m = dmyMatch[2].padStart(2, '0');
            const y = dmyMatch[3];
            const normalizedDisplay = `${d}${separator}${m}${separator}${y}`;
            const normalizedIso = `${y}-${m}-${d}`;
            setDisplayVal(normalizedDisplay);
            notifyChange(normalizedIso);
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
                placeholder={placeholder}
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

            {/* Native date picker used as popup calendar */}
            <input
                ref={nativePickerRef}
                type="date"
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
                aria-label="Open Calendar"
            >
                <CalendarIcon size={17} />
            </button>
        </div>
    );
}

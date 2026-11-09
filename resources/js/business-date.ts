const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

/**
 * True when the page is rendered in Bangla (`<html lang="bn">`), the single
 * source of truth for locale-aware digits in dates, money and counts.
 */
export function isBanglaDocument(): boolean {
    return typeof document !== 'undefined' && document.documentElement.lang.toLowerCase().startsWith('bn');
}

/**
 * Swap Latin digits for Bangla digits when the page is in Bangla.
 */
export function localizeDigits(text: string): string {
    return isBanglaDocument() ? text.replace(/[0-9]/g, digit => BANGLA_DIGITS[Number(digit)]) : text;
}

function dateFormatter(withTime = false): Intl.DateTimeFormat {
    const bangla = isBanglaDocument();
    const locale = bangla ? 'bn-BD' : 'en-GB';

    return new Intl.DateTimeFormat(locale, withTime
        // Without a day period, Bangla time ends in the English letters "AM" or "PM".
        ? { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, ...(bangla ? { dayPeriod: 'short' as const } : {}), timeZone: 'Asia/Dhaka' }
        : { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Dhaka' });
}

function parseBusinessTimestamp(value: string): Date | null {
    let timestamp = value.trim().replace(' ', 'T');
    if (/^\d{4}-\d{2}-\d{2}$/.test(timestamp)) {
        timestamp += 'T00:00:00Z';
    } else if (timestamp.includes('T')) {
        timestamp = timestamp.replace(/([+-]\d{2})(\d{2})$/, '$1:$2').replace(/([+-]\d{2})$/, '$1:00');
        if (!/(Z|[+-]\d{2}:\d{2})$/i.test(timestamp)) {
            timestamp += 'Z';
        }
    }
    const parsed = new Date(timestamp);

    return Number.isNaN(parsed.valueOf()) ? null : parsed;
}

/**
 * Convert ISO / standard date string to DD/MM/YYYY or DD-MM-YYYY display format
 */
export function toDisplayDate(value?: string | null, separator: '/' | '-' = '/'): string {
    if (!value) return '';
    const trimmed = String(value).trim();
    const match = trimmed.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
    if (match) {
        const y = match[1];
        const m = match[2].padStart(2, '0');
        const d = match[3].padStart(2, '0');
        return `${d}${separator}${m}${separator}${y}`;
    }
    const dmy = trimmed.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})/);
    if (dmy) {
        const d = dmy[1].padStart(2, '0');
        const m = dmy[2].padStart(2, '0');
        const y = dmy[3];
        return `${d}${separator}${m}${separator}${y}`;
    }
    return trimmed;
}

/**
 * Convert DD/MM/YYYY or DD-MM-YYYY display format to YYYY-MM-DD ISO format
 */
export function toIsoDate(value?: string | null): string {
    if (!value) return '';
    const trimmed = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
        return trimmed;
    }
    const dmy = trimmed.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
    if (dmy) {
        const d = dmy[1].padStart(2, '0');
        const m = dmy[2].padStart(2, '0');
        const y = dmy[3];
        return `${y}-${m}-${d}`;
    }
    return trimmed;
}

export function businessTodayIso(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Dhaka',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

export function isPastBusinessDate(value?: string | null): boolean {
    const text = (value ?? '').trim();

    return /^\d{4}-\d{2}-\d{2}$/.test(text) && text < businessTodayIso();
}

export function businessDate(value?: string | null): string {
    if (!value) {
        return '—';
    }
    const parsed = parseBusinessTimestamp(String(value));

    return parsed ? dateFormatter().format(parsed) : '—';
}

/**
 * Month name and year for "YYYY-MM" values (e.g. salary or target months): "Nov 2026".
 */
export function businessMonth(value?: string | null): string {
    const text = String(value ?? '').trim();
    const match = text.match(/^(\d{4})-(\d{2})$/);
    if (!match) {
        return text || '—';
    }

    return new Intl.DateTimeFormat(isBanglaDocument() ? 'bn-BD' : 'en-GB', { month: 'short', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)));
}

/**
 * Date plus Dhaka clock time (e.g. "5 Nov 2026, 3:42 pm") for ledger, payment
 * and audit rows. Date-only values have no time, so they show the date alone.
 */
export function businessDateTime(value?: string | null): string {
    if (!value) {
        return '—';
    }
    const text = String(value).trim();
    const parsed = parseBusinessTimestamp(text);
    if (!parsed) {
        return '—';
    }

    return dateFormatter(!/^\d{4}-\d{2}-\d{2}$/.test(text)).format(parsed);
}

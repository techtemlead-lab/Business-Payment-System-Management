function dateFormatter(): Intl.DateTimeFormat {
    const locale = typeof document !== 'undefined' && document.documentElement.lang.toLowerCase().startsWith('bn')
        ? 'bn-BD'
        : 'en-GB';

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Dhaka',
    });
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

    return Number.isNaN(parsed.valueOf()) ? '—' : dateFormatter().format(parsed);
}

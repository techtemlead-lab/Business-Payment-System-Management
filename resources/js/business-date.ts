const formatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Dhaka',
});

export function businessDate(value?: string | null): string {
    if (!value) return '—';
    let timestamp = value.trim().replace(' ', 'T');
    if (/^\d{4}-\d{2}-\d{2}$/.test(timestamp)) {
        timestamp += 'T00:00:00Z';
    } else if (timestamp.includes('T')) {
        timestamp = timestamp.replace(/([+-]\d{2})(\d{2})$/, '$1:$2').replace(/([+-]\d{2})$/, '$1:00');
        if (!/(Z|[+-]\d{2}:\d{2})$/i.test(timestamp)) timestamp += 'Z';
    }
    const parsed = new Date(timestamp);
    return Number.isNaN(parsed.valueOf()) ? '—' : formatter.format(parsed);
}

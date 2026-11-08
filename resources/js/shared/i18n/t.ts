import { useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { localizeDigits } from '../../business-date';

type Dict = Record<string, Record<string, string>>;

export function useTranslations(): Dict {
    return (usePage<any>().props.translations ?? {}) as Dict;
}

/** A count, amount or percent with nothing else in it, e.g. "120", "4.00", "1,250", "60.00%". */
const PURE_NUMBER = /^[-+]?\d[\d,]*(\.\d+)?%?$/;

/**
 * Fills ":name" placeholders. On Bangla pages a purely numeric value is shown in
 * Bangla digits, so "120 ইউনিট স্টকে" becomes "১২০ ইউনিট স্টকে" without every
 * caller having to format it. Codes such as "DEMO-01" are left as they are.
 */
export function t(dict: Dict, group: string, key: string, replace: Record<string, string | number> = {}): string {
    let value = dict?.[group]?.[key] ?? key;
    // Longest names first, so ":page" never eats the start of ":pages".
    const fills = Object.entries(replace).sort(([first], [second]) => second.length - first.length);
    for (const [name, raw] of fills) {
        const content = String(raw ?? '');
        value = value.replaceAll(`:${name}`, PURE_NUMBER.test(content.trim()) ? localizeDigits(content) : content);
    }
    return value;
}

export function useT() {
    const dict = useTranslations();

    return useCallback(
        (group: string, key: string, replace: Record<string, string | number> = {}) => t(dict, group, key, replace),
        [dict],
    );
}

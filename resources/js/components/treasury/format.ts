import { number } from '../UI';

type Translate = (group: string, key: string, replace?: Record<string, string | number>) => string;

/** True when a decimal string from the server is below zero. */
export function isNegative(value: unknown): boolean {
    return String(value ?? '').trim().startsWith('-');
}

/** A rate in plain words: "10%" of the money paid, or "30% of MRP" so nobody adds the two together. */
export function percentText(translate: Translate, percent: unknown, percentOf: 'paid' | 'mrp' | null | undefined): string {
    if (percent === null || percent === undefined || percent === '') {
        return '';
    }
    const value = number(percent);

    return percentOf === 'mrp' ? translate('treasury', 'percent_of_mrp', { percent: value }) : `${value}%`;
}

/** Joins the parts of a line that exist with a middle dot, saying nothing twice. */
export function joined(parts: Array<string | null | undefined | false>): string {
    const pieces = parts.filter(Boolean).flatMap((part) => String(part).split(' · '));

    return [...new Set(pieces)].join(' · ');
}

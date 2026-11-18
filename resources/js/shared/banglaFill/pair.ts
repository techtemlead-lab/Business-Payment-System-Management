import type { BanglaKind } from './rules.ts';

/**
 * Attributes for one box of an English/Bangla pair, from the data key it edits (CR-06). The English box
 * (`title`, `title_en`) gets an id; its Bangla twin (`title_bn`) points at that id, so the page-wide helper
 * (autofill.ts) fills the Bangla box from it. `scope` keeps ids apart between forms and between rows.
 *
 *     <input {...bilingualBox('title', 'event-')} />      → id="event-title"
 *     <input {...bilingualBox('title_bn', 'event-')} />   → data-bangla-from="event-title"
 */
export function bilingualBox(key: string, scope: string, kind?: BanglaKind): Record<string, string> {
    const base = key.replace(/_(bn|en)$/, '');
    const englishId = `${scope}${base}`.replace(/[^A-Za-z0-9_-]/g, '-');
    if (!key.endsWith('_bn')) {
        return { id: englishId };
    }

    return { 'data-bangla-from': englishId, 'data-bangla-kind': kind ?? banglaKindOf(base) };
}

/** A person's name or title is spelt out with Bangladeshi spellings; anything else is translated. */
export function banglaKindOf(key: string): BanglaKind {
    return /(^|_)(name|names|signer|doctor_name)($|_)/.test(key) ? 'name' : 'text';
}

/** For forms that loop over the two languages: the attributes for the `language` box of field `key`. */
export function bilingualBoxFor(key: string, language: 'bn' | 'en', scope: string, kind?: BanglaKind): Record<string, string> {
    return bilingualBox(`${key}_${language}`, scope, kind);
}

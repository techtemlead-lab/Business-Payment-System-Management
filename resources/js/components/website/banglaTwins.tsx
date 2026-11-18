import { createContext, useContext, useSyncExternalStore } from 'react';
import { bilingualBox } from '../../shared/banglaFill/pair';
import { useT } from '../../shared/i18n/t';

/**
 * The Bangla box under each website wording (CR-06). Staff type the English; the Bangla box fills itself when
 * empty, can be corrected, and Bangla visitors read it once it holds words. Which wordings have one, and the
 * built-in Bangla shown as the hint, come from the server (SiteCustomization::banglaTwinKeys()).
 *
 * The editor keeps every box mounted, so each Bangla box reads only its own value from this small store and
 * redraws only when that value changes, not on every key press elsewhere on the page.
 */
export type BanglaTwinStore = {
    has: (key: string) => boolean;
    hint: (key: string) => string;
    get: (key: string) => string;
    set: (key: string, value: string) => void;
    subscribe: (listener: () => void) => () => void;
    changed: () => void;
};

export function createBanglaTwinStore(hints: Record<string, string>, read: (key: string) => string, write: (key: string, value: string) => void): BanglaTwinStore {
    const listeners = new Set<() => void>();

    return {
        has: (key) => Object.prototype.hasOwnProperty.call(hints, key),
        hint: (key) => hints[key] ?? '',
        get: (key) => read(`${key}_bn`),
        set: (key, value) => write(`${key}_bn`, value),
        subscribe: (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        changed: () => listeners.forEach((listener) => listener()),
    };
}

export const BanglaTwinContext = createContext<BanglaTwinStore | null>(null);

/** The Bangla box for `fieldKey`, or nothing when that wording has no Bangla box. */
export function BanglaTwinBox({ fieldKey, label, multiline, rows = 3, span2 = false }: { fieldKey: string; label: string; multiline: boolean; rows?: number; span2?: boolean }) {
    const store = useContext(BanglaTwinContext);
    const translate = useT();
    const value = useSyncExternalStore(store?.subscribe ?? noSubscription, () => store?.get(fieldKey) ?? '');
    if (!store || !store.has(fieldKey)) {
        return null;
    }
    const pair = bilingualBox(`${fieldKey}_bn`, 'sce-');
    const caption = `${label} (${translate('portal', 'sce_language_bangla')})`;
    const hint = store.hint(fieldKey);

    return (
        <label className={span2 ? 'ed-block hub-span-all' : 'ed-block'} data-field={`${fieldKey}_bn`} lang="bn">
            <span className="ed-label">{caption}</span>
            {multiline ? (
                <textarea {...pair} rows={rows} value={value} placeholder={hint} onChange={(event) => store.set(fieldKey, event.target.value)} className="ed-input" />
            ) : (
                <input {...pair} type="text" value={value} placeholder={hint} onChange={(event) => store.set(fieldKey, event.target.value)} className="ed-input" />
            )}
        </label>
    );
}

const noSubscription = () => () => undefined;

/** The Bangla boxes already saved, so the editor opens with them filled. */
export function savedBanglaTwins(customization: object, twins: Record<string, string> | undefined): Record<string, string> {
    const saved = customization as Record<string, unknown>;

    return Object.fromEntries(Object.keys(twins ?? {}).map((key) => [`${key}_bn`, typeof saved[`${key}_bn`] === 'string' ? (saved[`${key}_bn`] as string) : '']));
}

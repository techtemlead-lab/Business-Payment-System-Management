import { useEffect, useSyncExternalStore } from 'react';

export type Dict = Record<string, Record<string, string>>;

/**
 * The translation words come from one versioned file per language (/i18n/{locale}/{version}.json),
 * cached by the browser for a year, instead of travelling inside every first page. This keeps what
 * has been downloaded and tells screens when new words arrive.
 */
const loaded = new Map<string, Dict>();
const pending = new Map<string, Promise<Dict>>();
const listeners = new Set<() => void>();

export function loadTranslations(url: string): Promise<Dict> {
    const known = loaded.get(url);
    if (known) {
        return Promise.resolve(known);
    }
    let request = pending.get(url);
    if (!request) {
        request = fetch(url)
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Translations ${response.status}`);
                }

                return response.json() as Promise<Dict>;
            })
            .then((dict) => {
                loaded.set(url, dict);
                pending.delete(url);
                listeners.forEach((listener) => listener());

                return dict;
            })
            .catch((error) => {
                // Not kept as loaded, so the next screen tries again; the page shows the keys meanwhile.
                pending.delete(url);
                throw error;
            });
        pending.set(url, request);
    }

    return request;
}

/** The address named in the page the server sent, so the file can be fetched before the first screen is drawn. */
export function initialTranslationsUrl(): string | null {
    try {
        const node = document.querySelector<HTMLElement>('script[data-page="app"], #app[data-page]');
        const text = node?.tagName === 'SCRIPT' ? node.textContent : node?.getAttribute('data-page');
        const page = text ? JSON.parse(text) : null;
        const url = page?.props?.translationsUrl;

        return typeof url === 'string' && !page?.props?.translations ? url : null;
    } catch {
        return null;
    }
}

const EMPTY: Dict = {};

/** The words for the screen on show: inline in the page when the server sent them, else the cached file. */
export function useBundle(inline: Dict | null | undefined, url: string | null | undefined): Dict {
    const fetched = useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => (url ? loaded.get(url) : undefined),
    );
    useEffect(() => {
        if (!inline && url && !loaded.has(url)) {
            loadTranslations(url).catch(() => undefined);
        }
    }, [inline, url]);

    return inline ?? fetched ?? EMPTY;
}

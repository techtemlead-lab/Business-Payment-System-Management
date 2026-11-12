import React, { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import { AutoSelect } from './SearchSelect';

export type ServerListSelect = {
    name: string;
    label: string;
    value: string;
    options: { value: string; label: string }[];
};

/**
 * Search box (and optional drop-downs) for a list that is cut into pages on the server.
 * Changing anything reloads only the page data with the new words and goes back to page 1,
 * so the search covers every row, not just the 25 on screen.
 */
export default function ServerListSearch({ query, placeholder, selects = [], keep = {}, resetPages = ['page'] }: {
    query: string;
    placeholder: string;
    selects?: ServerListSelect[];
    /** Other query values to keep, e.g. the reward tab or the settlement month. */
    keep?: Record<string, string | undefined>;
    /** Page parameters that go back to page 1 when the search changes. */
    resetPages?: string[];
}) {
    const translate = useT();
    const { url } = usePage();
    const [text, setText] = useState(query);
    useEffect(() => setText(query), [query]);

    const apply = (next: Record<string, string>) => {
        const current = new URL(url || '/', 'http://list.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => { params[key] = value; });
        for (const [key, value] of Object.entries(keep)) {
            if (value) params[key] = value;
        }
        for (const [key, value] of Object.entries(next)) {
            if (value) params[key] = value; else delete params[key];
        }
        resetPages.forEach((page) => delete params[page]);
        router.get(current.pathname, params, { preserveState: true, preserveScroll: true, replace: true, only: ['data', 'filters'] });
    };

    return (
        <form className="server-list-search ops-row is-wrap" role="search" onSubmit={(event) => { event.preventDefault(); apply({ q: text.trim() }); }}>
            <label className="adh-search">
                <span className="sr-only">{placeholder}</span>
                <Search size={16} aria-hidden="true" />
                <input type="search" value={text} placeholder={placeholder} maxLength={100} onChange={(event) => setText(event.target.value)} />
            </label>
            {selects.map((select) => (
                <label key={select.name}>
                    <span className="sr-only">{select.label}</span>
                    <AutoSelect
                        className="ss-filter"
                        ariaLabel={select.label}
                        value={select.value}
                        onChange={(value) => apply({ q: text.trim(), [select.name]: value })}
                        options={select.options.map((option) => ({ value: option.value, label: option.label }))}
                    />
                </label>
            ))}
            <button type="submit" className="button small">{translate('portal', 'search')}</button>
        </form>
    );
}

import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { label } from '../UI';
import type { Person } from './types';

/** "D-100 · ডিলার · 017… · রংপুর": the partner line the rest of the office uses. */
export function partnerLine(person: Partial<Person>, translate: (group: string, key: string) => string): string {
    return [person.dealer_code, person.role ? label(person.role, translate as never) : null, person.phone, person.district].filter(Boolean).join(' · ');
}

/** Find one person by name, partner code or phone, and open that person's special rates. */
export default function PersonPicker({ partners, partnerSearch, person }: { partners: Person[]; partnerSearch: string; person: Person | null }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const [query, setQuery] = useState(partnerSearch);
    const first = useRef(true);
    const path = typeof window === 'undefined' ? '' : window.location.pathname;

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }
        const timer = window.setTimeout(() => {
            router.get(path, { q: query.trim(), ...(person ? { person: person.id } : {}) }, { preserveState: true, preserveScroll: true, replace: true, only: ['partners', 'partnerSearch'] });
        }, 350);

        return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const pick = (id: number) => router.get(path, { person: id }, { preserveScroll: true });
    const results = query.trim().length >= 2 ? partners.slice(0, 8) : [];

    return (
        <section className="cp-card sr-picker" aria-labelledby="sr-picker-title">
            <h2 id="sr-picker-title">{t('picker_title')}</h2>
            {person && (
                <div className="sr-chosen">
                    <div>
                        <b>{person.name}</b>
                        <small>{partnerLine(person, translate as never)}</small>
                        {person.status !== 'active' && <span className="cp-pill is-draft">{label(person.status, translate as never)}</span>}
                    </div>
                    <button type="button" className="button" onClick={() => router.get(path, {}, { preserveScroll: true })}><X size={16} aria-hidden="true" />{t('picker_change')}</button>
                </div>
            )}
            <label className="cp-label" htmlFor="sr-search">
                {t(person ? 'picker_other' : 'picker_label')}
                <span className="sr-search">
                    <Search size={18} aria-hidden="true" />
                    <input id="sr-search" type="search" autoComplete="off" value={query} placeholder={t('picker_placeholder')} onChange={(event) => setQuery(event.target.value)} />
                </span>
            </label>
            {query.trim().length >= 2 && (
                <ul className="sr-results" aria-label={t('picker_results')}>
                    {results.length === 0 ? <li className="sr-none">{t('picker_none')}</li> : results.map((row) => (
                        <li key={row.id}>
                            <button type="button" onClick={() => pick(row.id)}>
                                <b>{row.name}</b>
                                <small>{partnerLine(row, translate as never)}</small>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {query.trim().length > 0 && query.trim().length < 2 && <p className="cp-hint">{t('picker_short')}</p>}
        </section>
    );
}

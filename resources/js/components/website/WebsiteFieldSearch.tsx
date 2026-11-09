import React, { useEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Search } from 'lucide-react';
import { useT } from '../../shared/i18n/t';

interface SearchResult {
    id: string;
    label: string;
    place: string;
    target: HTMLElement;
}

const NAME_SELECTOR = '.ed-label, .ed-subtitle, .ed-title, .ed-strong, .ws-group-title, .ws-tool-title';
const MAX_RESULTS = 12;

const normalized = (text: string): string => text.normalize('NFC').toLowerCase().replace(/\s+/g, ' ').trim();

/** Reads the names straight from the page, so a new box can never be missing from the search. */
function findMatches(root: HTMLElement | null, query: string): SearchResult[] {
    const needle = normalized(query);
    if (!root || needle === '') {
        return [];
    }
    const seen = new Set<string>();
    const results: SearchResult[] = [];
    for (const element of Array.from(root.querySelectorAll<HTMLElement>(NAME_SELECTOR))) {
        const label = (element.textContent ?? '').replace(/\s+/g, ' ').trim();
        if (label === '' || label.length > 120) {
            continue;
        }
        const host = element.closest<HTMLElement>('label, .ws-tool, .ed-toggle-row, .ed-card, .ws-group-summary') ?? element;
        const fieldName = host.dataset.field ?? '';
        if (!normalized(`${label} ${fieldName}`).includes(needle)) {
            continue;
        }
        const groupTitle = element.closest('details.ws-group')?.querySelector('.ws-group-title')?.textContent ?? '';
        const sectionTitle = element.closest('.ws-module')?.querySelector('.ws-section-title')?.textContent ?? '';
        const place = [sectionTitle, groupTitle].filter((part) => part !== '' && part !== label).join(' › ');
        const id = `${place}|${label}`;
        if (seen.has(id)) {
            continue;
        }
        seen.add(id);
        results.push({ id, label, place, target: host });
        if (results.length >= MAX_RESULTS) {
            break;
        }
    }
    return results;
}

/** Opens any closed rows around the box, brings it into view and puts the cursor in it. */
export function jumpTo(target: HTMLElement, onReveal?: (target: HTMLElement) => void): void {
    if (onReveal) {
        flushSync(() => onReveal(target));
    }
    let parent = target.parentElement;
    while (parent) {
        if (parent instanceof HTMLDetailsElement) {
            parent.open = true;
        }
        parent = parent.parentElement;
    }
    if (target.classList.contains('ws-group-summary')) {
        const details = target.closest('details');
        if (details) {
            details.open = true;
        }
    }
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const control = target.matches('a') ? target : target.querySelector<HTMLElement>('input, textarea, select, button');
    control?.focus({ preventScroll: true });
    target.classList.add('ws-jump-flash');
    window.setTimeout(() => target.classList.remove('ws-jump-flash'), 1600);
}

export default function WebsiteFieldSearch({ pageRef, onReveal }: { pageRef: React.RefObject<HTMLDivElement | null>; onReveal?: (target: HTMLElement) => void }) {
    const translate = useT();
    const [query, setQuery] = useState('');
    const boxRef = useRef<HTMLDivElement>(null);
    const results = useMemo(() => findMatches(pageRef.current, query), [pageRef, query]);

    useEffect(() => {
        const closeOnOutsideClick = (event: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(event.target as Node)) {
                setQuery('');
            }
        };
        document.addEventListener('click', closeOnOutsideClick);
        return () => document.removeEventListener('click', closeOnOutsideClick);
    }, []);

    const choose = (result: SearchResult) => {
        setQuery('');
        jumpTo(result.target, onReveal);
    };

    return (
        <div className="ws-search" ref={boxRef}>
            <Search size={16} aria-hidden="true" className="ws-search-icon" />
            <input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setQuery('');
                    }
                    if (event.key === 'Enter' && results[0]) {
                        event.preventDefault();
                        choose(results[0]);
                    }
                }}
                placeholder={translate('website_admin', 'search_placeholder')}
                aria-label={translate('website_admin', 'search_placeholder')}
            />
            {query.trim() !== '' && (
                <div className="ws-search-results">
                    {results.length === 0 ? (
                        <p className="ws-search-empty">{translate('website_admin', 'search_empty')}</p>
                    ) : results.map((result) => (
                        <button type="button" key={result.id} className="ws-search-result" onClick={() => choose(result)}>
                            <span className="ws-search-label">{result.label}</span>
                            {result.place !== '' && <small className="ed-hint">{result.place}</small>}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

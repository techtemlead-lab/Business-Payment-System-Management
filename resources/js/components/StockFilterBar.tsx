import { useEffect, useRef, useState } from 'react';
import { Search } from 'lucide-react';
import { DateInput } from './UI';
import { MoreFilterControls, isToolbarFilter, optionText, useAddressVisit, type FilterBarData } from './MoneyTableFilters';
import { useT } from '../shared/i18n/t';
import { localizeDigits } from '../business-date';

export type StockFilterBarData = FilterBarData;

const SEARCH_PAUSE_MS = 400;

const VIEW_CHOICES = [
    { value: '', word: 'orders_period_all' },
    { value: 'today', word: 'orders_period_today' },
    { value: 'week', word: 'orders_period_week' },
    { value: 'month', word: 'orders_period_month' },
    { value: 'year', word: 'orders_period_year' },
    { value: 'day', word: 'mt_view_day' },
    { value: 'custom', word: 'mt_view_range' },
];

/** The Product page has cards, not columns, so this bar carries the search, view choice and sort itself. */
export function CatalogFilterBar({ bar, q, period, from, to, sort, sortChoices }: {
    bar: FilterBarData;
    q: string;
    period: string;
    from: string;
    to: string;
    sort: string;
    sortChoices: { value: string; label: string }[];
}) {
    const translate = useT();
    const visit = useAddressVisit(null);
    const [text, setText] = useState(q);
    const [choosing, setChoosing] = useState<string | null>(null);
    const [dayOrFrom, setDayOrFrom] = useState(from);
    const [until, setUntil] = useState(to);
    const timer = useRef<number | undefined>(undefined);
    const main = bar.fields.filter(isToolbarFilter);
    const view = choosing ?? period;

    useEffect(() => {
        setChoosing(null);
        setDayOrFrom(from);
        setUntil(to);
    }, [period, from, to]);
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const typeSearch = (words: string) => {
        setText(words);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => visit({ q: words.trim() || null }), SEARCH_PAUSE_MS);
    };

    const chooseView = (value: string) => {
        if (value === 'day' || value === 'custom') {
            setChoosing(value);

            return;
        }
        visit({ period: value || null, from: null, to: null });
    };

    const isDate = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value);

    return (
        <div className="sf-catalog">
            <div className="mt-toolbar">
                <form className="mt-search" role="search" onSubmit={(event) => { event.preventDefault(); window.clearTimeout(timer.current); visit({ q: text.trim() || null }); }}>
                    <label className="search-input">
                        <Search size={16} aria-hidden="true" />
                        <span className="sr-only">{translate('portal', 'search_products_catalog')}</span>
                        <input type="search" value={text} maxLength={100} placeholder={translate('portal', 'search_products_catalog')} onChange={(event) => typeSearch(event.target.value)} />
                    </label>
                </form>
                <div className="mt-views" role="group" aria-label={translate('portal', 'mt_view_label')}>
                    {VIEW_CHOICES.map((choice) => (
                        <button key={choice.value || 'all'} type="button" className="mt-view" aria-pressed={view === choice.value} onClick={() => chooseView(choice.value)}>{translate('portal', choice.word)}</button>
                    ))}
                </div>
                <div className="mt-filters">
                    <select className="catalog-sort" aria-label={translate('portal', 'catalog_sort_by')} value={sort} onChange={(event) => visit({ sort: event.target.value === 'name_asc' ? null : event.target.value })}>
                        {sortChoices.map((choice) => <option key={choice.value} value={choice.value}>{choice.label}</option>)}
                    </select>
                    {main.map((field) => (
                        <select key={field.key} aria-label={field.label} value={bar.active[field.key] ?? ''} onChange={(event) => visit({ [field.key]: event.target.value || null })}>
                            <option value="">{field.label}</option>
                            {field.options?.map((option) => <option key={option.value} value={option.value}>{localizeDigits(optionText(option))}</option>)}
                        </select>
                    ))}
                </div>
                <MoreFilterControls bar={bar} visit={visit} />
            </div>
            {view === 'day' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_view_day')}<DateInput value={dayOrFrom} onChange={(event) => { setDayOrFrom(event.target.value); if (isDate(event.target.value)) { visit({ period: 'day', from: event.target.value, to: null }); } }} /></label>
                </div>
            )}
            {view === 'custom' && (
                <div className="mt-dates">
                    <label>{translate('portal', 'mt_date_from')}<DateInput value={dayOrFrom} onChange={(event) => { setDayOrFrom(event.target.value); if (isDate(event.target.value) && isDate(until)) { visit({ period: 'custom', from: event.target.value, to: until }); } }} /></label>
                    <label>{translate('portal', 'mt_date_to')}<DateInput value={until} onChange={(event) => { setUntil(event.target.value); if (isDate(dayOrFrom) && isDate(event.target.value)) { visit({ period: 'custom', from: dayOrFrom, to: event.target.value }); } }} /></label>
                </div>
            )}
        </div>
    );
}

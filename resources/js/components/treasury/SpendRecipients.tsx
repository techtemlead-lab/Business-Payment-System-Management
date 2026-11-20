import { useEffect, useRef, useState } from 'react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';

/** The same address the spend form posts to; kept here so the two files do not import each other. */
const FUNDS_COMMAND_ROOT = '/commands/operations/company-funds';

export type SpendFor = { mode: 'company' | 'partners' | 'all_dealers' | 'all_sub_dealers' | 'everyone'; partner_ids: number[] };
type Found = { id: number; name: string; code: string | null; role: string; district: string | null; phone: string | null };
type Counts = { dealers: number; sub_dealers: number };

const MODES: SpendFor['mode'][] = ['company', 'partners', 'all_dealers', 'all_sub_dealers', 'everyone'];

/**
 * "Who is this for?" on the spend form: the company itself (as before), chosen partners, or every partner of a type.
 * The amount is split equally; each partner sees only their own share, and no wallet changes.
 */
export default function SpendRecipients({ value, onChange, amount }: { value: SpendFor; onChange: (next: SpendFor) => void; amount: string }) {
    const translate = useT();
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [picked, setPicked] = useState<Found[]>([]);
    const [counts, setCounts] = useState<Counts | null>(null);
    const timer = useRef<number | undefined>(undefined);

    // One lookup for the head counts, and a short wait after typing before each search.
    useEffect(() => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => {
            fetch(`${FUNDS_COMMAND_ROOT}/partners?q=${encodeURIComponent(query.trim())}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (data) {
                        setCounts(data.counts);
                        setFound(data.partners);
                    }
                })
                .catch(() => undefined);
        }, query.trim() === '' ? 0 : 300);

        return () => window.clearTimeout(timer.current);
    }, [query]);

    const choose = (mode: SpendFor['mode']) => onChange({ mode, partner_ids: mode === 'partners' ? value.partner_ids : [] });
    const add = (partner: Found) => {
        if (value.partner_ids.includes(partner.id)) {
            return;
        }
        setPicked([...picked, partner]);
        onChange({ mode: 'partners', partner_ids: [...value.partner_ids, partner.id] });
        setQuery('');
    };
    const remove = (id: number) => {
        setPicked(picked.filter((partner) => partner.id !== id));
        onChange({ mode: 'partners', partner_ids: value.partner_ids.filter((other) => other !== id) });
    };
    const count = (mode: SpendFor['mode']): number | null => (counts === null ? null : mode === 'all_dealers' ? counts.dealers : mode === 'all_sub_dealers' ? counts.sub_dealers : mode === 'everyone' ? counts.dealers + counts.sub_dealers : null);
    const people = value.mode === 'partners' ? value.partner_ids.length : value.mode === 'company' ? 0 : count(value.mode) ?? 0;
    const each = people > 0 && Number(amount) > 0 ? (Number(amount) / people).toFixed(2) : null;

    return (
        <fieldset className="spend-for" data-testid="spend-for">
            <legend>{translate('funds', 'for_title')}</legend>
            {MODES.map((mode) => (
                <label key={mode} className="spend-for-option">
                    <input type="radio" name="spend_for" checked={value.mode === mode} onChange={() => choose(mode)} />
                    {translate('funds', `for_${mode}`, { count: count(mode) ?? '' })}
                </label>
            ))}
            {value.mode === 'partners' && (
                <div className="spend-for-pick">
                    <input type="search" value={query} placeholder={translate('funds', 'for_search')} onChange={(event) => setQuery(event.target.value)} aria-label={translate('funds', 'for_search')} />
                    {query.trim().length >= 2 && (
                        <ul className="spend-for-found">
                            {found.filter((partner) => !value.partner_ids.includes(partner.id)).map((partner) => (
                                <li key={partner.id}>
                                    <button type="button" className="button small" onClick={() => add(partner)}>
                                        {partner.name}{partner.code ? ` · ${partner.code}` : ''}{partner.district ? ` · ${partner.district}` : ''}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <ul className="spend-for-chips">
                        {picked.filter((partner) => value.partner_ids.includes(partner.id)).map((partner) => (
                            <li key={partner.id}>
                                {partner.name}{partner.code ? ` · ${partner.code}` : ''}
                                <button type="button" className="button small" onClick={() => remove(partner.id)}>{translate('funds', 'for_remove')}</button>
                            </li>
                        ))}
                    </ul>
                    {value.partner_ids.length === 0 && <p className="muted">{translate('funds', 'for_none')}</p>}
                </div>
            )}
            {value.mode !== 'company' && (
                <p className="muted">
                    {each !== null ? `${translate('funds', 'for_each', { count: people, amount: money(each) })} ` : ''}
                    {translate('funds', 'for_hint')}
                </p>
            )}
        </fieldset>
    );
}

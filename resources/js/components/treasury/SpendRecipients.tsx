import { useEffect, useRef, useState } from 'react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';

/** The same address the spend form posts to; kept here so the two files do not import each other. */
const FUNDS_COMMAND_ROOT = '/commands/operations/company-funds';

export type SpendFor = {
    mode: 'company' | 'partners' | 'all_dealers' | 'all_sub_dealers' | 'everyone';
    partner_ids: number[];
    /** equal: the amount is split equally; amounts: a typed amount for each chosen partner; direct: the company pays directly, no amount per person. */
    split: 'equal' | 'amounts' | 'direct';
    amounts: Record<string, string>;
};
type Found = { id: number; name: string; code: string | null; role: string; district: string | null; phone: string | null };
type Counts = { dealers: number; sub_dealers: number };

const MODES: SpendFor['mode'][] = ['company', 'partners', 'all_dealers', 'all_sub_dealers', 'everyone'];

/** Money typed in a field, in paisa; null when it is not a plain amount. */
function cents(value: string): number | null {
    const text = value.trim();
    if (!/^\d{1,9}(\.\d{1,2})?$/.test(text)) {
        return null;
    }
    const [whole, fraction = ''] = text.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

function fromCents(value: number): string {
    return `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`;
}

/**
 * "Who is this for?" on the spend form: the company itself (as before), chosen partners, or every partner of a type.
 * The money is split equally, or each chosen partner gets an amount typed for them, or the company pays directly and nobody gets an
 * amount of their own. Each partner sees only their own line, and no wallet changes.
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

    const choose = (mode: SpendFor['mode']) => onChange({ ...value, mode, partner_ids: mode === 'partners' ? value.partner_ids : [], split: mode !== 'partners' && value.split === 'amounts' ? 'equal' : value.split });
    const add = (partner: Found) => {
        if (value.partner_ids.includes(partner.id)) {
            return;
        }
        setPicked([...picked, partner]);
        onChange({ ...value, mode: 'partners', partner_ids: [...value.partner_ids, partner.id] });
        setQuery('');
    };
    const remove = (id: number) => {
        setPicked(picked.filter((partner) => partner.id !== id));
        const { [String(id)]: _removed, ...rest } = value.amounts;
        onChange({ ...value, mode: 'partners', partner_ids: value.partner_ids.filter((other) => other !== id), amounts: rest });
    };
    const count = (mode: SpendFor['mode']): number | null => (counts === null ? null : mode === 'all_dealers' ? counts.dealers : mode === 'all_sub_dealers' ? counts.sub_dealers : mode === 'everyone' ? counts.dealers + counts.sub_dealers : null);
    const people = value.mode === 'partners' ? value.partner_ids.length : value.mode === 'company' ? 0 : count(value.mode) ?? 0;
    const total = cents(amount);
    const each = people > 0 && total !== null ? fromCents(Math.floor(total / people)) : null;
    const typedSum = value.partner_ids.reduce((sum, id) => sum + (cents(value.amounts[String(id)] ?? '') ?? 0), 0);
    const sumMatches = total !== null && typedSum === total;
    // The spend amount shared out equally, the odd paisa to the first ones, as the server does it.
    const fillEqually = () => {
        if (total === null || value.partner_ids.length === 0) {
            return;
        }
        const base = Math.floor(total / value.partner_ids.length);
        const extra = total - base * value.partner_ids.length;
        const sorted = [...value.partner_ids].sort((a, b) => a - b);
        onChange({ ...value, split: 'amounts', amounts: Object.fromEntries(sorted.map((id, index) => [String(id), fromCents(base + (index < extra ? 1 : 0))])) });
    };
    const setSplit = (split: SpendFor['split']) => onChange({ ...value, split });

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
                                <span>{partner.name}{partner.code ? ` · ${partner.code}` : ''}</span>
                                {value.split === 'amounts' && (
                                    <input
                                        inputMode="decimal"
                                        className="spend-for-amount"
                                        value={value.amounts[String(partner.id)] ?? ''}
                                        aria-label={translate('funds', 'for_amount_of', { name: partner.name })}
                                        placeholder={translate('funds', 'for_amount_placeholder')}
                                        onChange={(event) => onChange({ ...value, amounts: { ...value.amounts, [String(partner.id)]: event.target.value } })}
                                    />
                                )}
                                <button type="button" className="button small" onClick={() => remove(partner.id)}>{translate('funds', 'for_remove')}</button>
                            </li>
                        ))}
                    </ul>
                    {value.partner_ids.length === 0 && <p className="muted">{translate('funds', 'for_none')}</p>}
                </div>
            )}
            {value.mode !== 'company' && (
                <div className="spend-for-split" role="group" aria-label={translate('funds', 'for_split_title')}>
                    <b>{translate('funds', 'for_split_title')}</b>
                    <label className="spend-for-option"><input type="radio" name="spend_split" checked={value.split === 'equal'} onChange={() => setSplit('equal')} />{translate('funds', 'for_split_equal')}</label>
                    {value.mode === 'partners' && (
                        <label className="spend-for-option"><input type="radio" name="spend_split" checked={value.split === 'amounts'} onChange={() => setSplit('amounts')} />{translate('funds', 'for_split_amounts')}</label>
                    )}
                    <label className="spend-for-option"><input type="radio" name="spend_split" checked={value.split === 'direct'} onChange={() => setSplit('direct')} />{translate('funds', 'for_split_direct')}</label>
                    {value.split === 'amounts' && value.mode === 'partners' && (
                        <div className="spend-for-sum">
                            <button type="button" className="button small" onClick={fillEqually} disabled={total === null || value.partner_ids.length === 0}>{translate('funds', 'for_fill_equal')}</button>
                            <p role="status" className={sumMatches ? 'muted' : 'spend-for-warn'}>
                                {total === null
                                    ? translate('funds', 'for_sum_need_amount')
                                    : translate('funds', sumMatches ? 'for_sum_ok' : 'for_sum_now', { sum: money(fromCents(typedSum)), total: money(fromCents(total)) })}
                            </p>
                        </div>
                    )}
                    {value.split === 'equal' && each !== null && <p className="muted">{translate('funds', 'for_each', { count: people, amount: money(each) })}</p>}
                    {value.split === 'direct' && <p className="muted">{translate('funds', 'for_direct_hint')}</p>}
                    <p className="muted">{translate('funds', 'for_hint')}</p>
                </div>
            )}
        </fieldset>
    );
}

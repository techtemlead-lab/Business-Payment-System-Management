import { useState } from 'react';
import { Row } from './UI';
import { useT } from '../shared/i18n/t';
import { toLatin } from '../shared/countUp';

/** One person in the commission chain the lookup returns; empty when nobody takes that generation. */
type ChainPerson = { name?: string | null; refer_code?: string | null } | null | undefined;

type Lookup = {
    referrer: { id: number; name: string; code: string | null } | null;
    consumers: { direct_referral?: ChainPerson; d2d_gen2?: ChainPerson; d2d_gen3?: ChainPerson } | null;
};

/** What the editor will save: the new referrer's code ('' = company, no referrer) and whether the office confirmed it. */
export type ReferrerChange = { code: string; confirmed: boolean } | null;

function who(name?: string | null, code?: string | null): string {
    return `${name ?? ''}${code ? ` (${code})` : ''}`.trim();
}

/**
 * The account editor's "who referred this partner" box. The office types the new referrer's dealer or
 * sub-dealer code, the server checks it with the same rules as saving (active, not the partner, not in
 * their own team below) and shows the new three-generation chain, and the office ticks that the change
 * counts for new orders only. Nothing changes until the whole form is saved.
 */
export default function PartnerReferrerField({ account, error, onChange }: { account: Row; error?: string; onChange: (change: ReferrerChange) => void }) {
    const translate = useT();
    const current = account.sponsor as { name?: string; dealer_code?: string | null } | null | undefined;
    const [open, setOpen] = useState(false);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [lookupError, setLookupError] = useState('');
    const [found, setFound] = useState<Lookup | null>(null);
    const [pending, setPending] = useState<{ code: string; label: string; chain: string | null } | null>(null);
    const [confirmed, setConfirmed] = useState(false);

    const choose = (next: { code: string; label: string; chain: string | null } | null) => {
        setPending(next);
        setConfirmed(false);
        onChange(next ? { code: next.code, confirmed: false } : null);
    };
    const reset = () => {
        setOpen(false);
        setCode('');
        setFound(null);
        setLookupError('');
        choose(null);
    };
    const find = async () => {
        const typed = code.trim();
        if (typed === '') {
            return;
        }
        setBusy(true);
        setLookupError('');
        setFound(null);
        choose(null);
        try {
            const response = await fetch(`/commands/accounts/${account.id}/referrer-lookup?${new URLSearchParams({ code: typed })}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const json = await response.json().catch(() => null);
            if (!response.ok) {
                setLookupError(json?.errors?.code?.[0] ?? json?.message ?? translate('portal', 'acct_request_failed'));
                return;
            }
            const data = json.data as Lookup;
            setFound(data);
            const people = data.consumers ?? {};
            const chain = translate('portal', 'acct_referrer_chain', {
                gen1: who(people.direct_referral?.name, people.direct_referral?.refer_code) || '—',
                gen2: who(people.d2d_gen2?.name, people.d2d_gen2?.refer_code) || '—',
                gen3: who(people.d2d_gen3?.name, people.d2d_gen3?.refer_code) || '—',
            });
            choose(data.referrer
                ? { code: data.referrer.code ?? typed, label: who(data.referrer.name, data.referrer.code), chain }
                : { code: '', label: translate('portal', 'acct_referrer_company'), chain: null });
        } catch {
            setLookupError(translate('portal', 'acct_request_failed'));
        } finally {
            setBusy(false);
        }
    };

    return <div className="full">
        <b>{translate('portal', 'acct_referrer_title')}</b>
        <p className="muted">{translate('portal', 'acct_referrer_now', { who: current ? who(current.name, current.dealer_code) : translate('portal', 'acct_referrer_company') })}</p>
        {!open && !pending && <div className="inline-actions">
            <button type="button" className="button small" onClick={() => setOpen(true)}>{translate('portal', 'acct_referrer_change')}</button>
            {current && <button type="button" className="button small" onClick={() => choose({ code: '', label: translate('portal', 'acct_referrer_company'), chain: null })}>{translate('portal', 'acct_referrer_use_company')}</button>}
        </div>}
        {open && !pending && <div className="inline-actions">
            <label>{translate('portal', 'acct_referrer_code_label')}
                <input
                    value={code}
                    placeholder={translate('portal', 'acct_referrer_code_ph')}
                    onChange={event => { setCode(toLatin(event.target.value).toUpperCase()); setLookupError(''); setFound(null); }}
                    onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); void find(); } }}
                />
                {lookupError && <span className="field-error" role="alert">{lookupError}</span>}
            </label>
            <button type="button" className="button small dark" disabled={busy || code.trim() === ''} onClick={() => void find()}>{busy ? translate('portal', 'acct_referrer_finding') : translate('portal', 'acct_referrer_find')}</button>
            <button type="button" className="button small" onClick={reset}>{translate('portal', 'acct_referrer_keep')}</button>
        </div>}
        {pending && <div className="notice" role="status">
            <p><b>{translate('portal', 'acct_referrer_found', { who: pending.label })}</b></p>
            {pending.chain && found && <p className="muted">{pending.chain}</p>}
            <p className="muted">{translate('portal', 'acct_referrer_team_note')}</p>
            <label><input type="checkbox" checked={confirmed} onChange={event => { setConfirmed(event.target.checked); onChange({ code: pending.code, confirmed: event.target.checked }); }} /> {translate('portal', 'acct_referrer_confirm')}</label>
            <button type="button" className="button small" onClick={reset}>{translate('portal', 'acct_referrer_keep')}</button>
        </div>}
        {error && <span className="field-error" role="alert">{error}</span>}
    </div>;
}

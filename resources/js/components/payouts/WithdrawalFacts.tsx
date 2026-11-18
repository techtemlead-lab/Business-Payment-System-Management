import { useEffect, useState } from 'react';
import { money, date, dateTime, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { takaWords } from '../../shared/takaText';
import { channelName } from '../../shared/payoutMethods';

/** What the server sends from /commands/withdrawals/{id}/facts (ManualPayoutService::reviewFacts). */
export interface WithdrawalFactsData {
    partner: { name: string; code: string | null; phone: string | null; status: 'active' | 'suspended' | 'closed' | string; nid_on_file: boolean; tin_on_file: boolean };
    balance: { available: string; held: string };
    credentials_changed_at: string | null;
    changed_near_request: boolean;
    other_open: { count: number; amount: string };
    destination: string;
    channel_limit: string | null;
    over_channel_limit: boolean;
    blocker: string | null;
    history: Row[];
    payers: Array<{ id: number; name: string }>;
    amount_words: string | null;
}

/**
 * Loads the review facts for one withdrawal when a box opens. Opening them shows the full payout
 * number, so the server logs it, like the detail page.
 */
export function useWithdrawalFacts(id: string | null): { facts: WithdrawalFactsData | null; failed: boolean } {
    const [facts, setFacts] = useState<WithdrawalFactsData | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        setFacts(null);
        setFailed(false);
        if (!id) {
            return;
        }
        const controller = new AbortController();
        fetch(`/commands/withdrawals/${id}/facts`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                const json = await response.json();
                setFacts(json.data as WithdrawalFactsData);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [id]);

    return { facts, failed };
}

/** The amount in words for big figures, so ৳9 crore is not read as ৳90 lakh (MA-18). */
export function AmountWords({ amount }: { amount: unknown }) {
    const translate = useT();
    const words = takaWords(amount);

    return words ? <small className="cell-sub">{translate('payouts', 'amount_words', { words })}</small> : null;
}

/** The facts finance must see before approving or paying a withdrawal (MA-07). */
export default function WithdrawalFacts({ facts, failed = false, channel, amount }: { facts: WithdrawalFactsData | null; failed?: boolean; channel: string; amount: unknown }) {
    const translate = useT();
    if (failed) {
        return <p className="notice" role="alert">{translate('payouts', 'facts_failed')}</p>;
    }
    if (!facts) {
        return <p className="muted" role="status">{translate('payouts', 'facts_loading')}</p>;
    }
    const yesNo = (value: boolean) => translate('payouts', value ? 'fact_on_file' : 'fact_missing');
    const status = String(facts.partner.status);

    return (
        <section className="hub-ledger" aria-label={translate('payouts', 'facts_title')}>
            <h4 className="hub-title">{translate('payouts', 'facts_title')}</h4>
            {facts.blocker && (
                <div className="notice" role="alert">
                    <p><b>{translate('payouts', 'blocked_title')}</b> {facts.blocker}</p>
                </div>
            )}
            <div className="hub-ledger-row"><span>{translate('payouts', 'fact_partner')}</span><b>{[facts.partner.code, facts.partner.name, facts.partner.phone].filter(Boolean).join(' · ')}</b></div>
            <div className={`hub-ledger-row${status === 'active' ? '' : ' tone-danger'}`}><span>{translate('payouts', 'fact_status')}</span><b>{translate('payouts', `account_${status}`)}</b></div>
            <div className="hub-ledger-row"><span>{translate('payouts', 'fact_balance')}</span><b>{money(facts.balance.available)}</b></div>
            <div className="hub-ledger-row"><span>{translate('payouts', 'fact_held')}</span><b>{money(facts.balance.held)}</b></div>
            <div className={`hub-ledger-row${facts.other_open.count > 0 ? ' tone-danger' : ''}`}>
                <span>{translate('payouts', 'fact_other_open')}</span>
                <b>{facts.other_open.count > 0 ? translate('payouts', 'fact_other_open_value', { count: String(facts.other_open.count), amount: money(facts.other_open.amount) }) : translate('payouts', 'fact_none')}</b>
            </div>
            <div className="hub-ledger-row"><span>{translate('payouts', 'fact_destination')}</span><b>{facts.destination} · {channelName(channel, translate)}</b></div>
            <div className={`hub-ledger-row${facts.over_channel_limit ? ' tone-danger' : ''}`}>
                <span>{translate('payouts', 'fact_channel_limit')}</span>
                <b>{facts.channel_limit ? money(facts.channel_limit) : translate('payouts', 'fact_no_limit')}{facts.over_channel_limit ? ` · ${translate('payouts', 'fact_over_limit')}` : ''}</b>
            </div>
            <div className={`hub-ledger-row${facts.changed_near_request ? ' tone-danger' : ''}`}>
                <span>{translate('payouts', 'fact_credentials')}</span>
                <b>{facts.credentials_changed_at ? dateTime(facts.credentials_changed_at) : translate('payouts', 'fact_never')}</b>
            </div>
            {facts.changed_near_request && <p className="hub-hint">{translate('payouts', 'fact_changed_near')}</p>}
            <div className={`hub-ledger-row${facts.partner.nid_on_file ? '' : ' tone-danger'}`}><span>{translate('payouts', 'fact_nid')}</span><b>{yesNo(facts.partner.nid_on_file)}</b></div>
            <div className="hub-ledger-row"><span>{translate('payouts', 'fact_tin')}</span><b>{yesNo(facts.partner.tin_on_file)}</b></div>
            <AmountWords amount={amount} />
            <details>
                <summary>{translate('payouts', 'fact_history')}</summary>
                {facts.history.length === 0
                    ? <p className="muted">{translate('payouts', 'fact_history_none')}</p>
                    : <ul>{facts.history.map((row) => <li key={String(row.id)}>{date(row.created_at)} · {money(row.amount)} · {channelName(row.channel, translate)} · {translate('portal', `pay_status_${row.status}`) !== `pay_status_${row.status}` ? translate('portal', `pay_status_${row.status}`) : String(row.status)}</li>)}</ul>}
            </details>
        </section>
    );
}

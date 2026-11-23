import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { money } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import FundCard, { type FundCardData } from '../components/treasury/FundCard';
import { useTreasuryViewer } from '../components/treasury/TreasuryTabs';
import { useT } from '../shared/i18n/t';

/** The groups of funds, in the order the owner reads them. A fund that is in no list is shown under the last group. */
const FUND_GROUPS: { key: string; title: string; note: string; funds: string[] }[] = [
    { key: 'company', title: 'funds_company', note: 'funds_company_note', funds: ['company_share', 'company_profit', 'management', 'marketing', 'clinic', 'benefits'] },
    { key: 'rewards', title: 'funds_rewards', note: 'funds_rewards_note', funds: ['tour', 'sub_dealer_extra', 'bike', 'car'] },
    { key: 'pools', title: 'funds_pools', note: 'funds_pools_note', funds: ['salary_pool', 'royalty_mo', 'royalty_am', 'royalty_sm', 'royalty_me', 'rank_bonus'] },
    { key: 'old', title: 'funds_old', note: 'funds_old_note', funds: [] },
];

const isEmpty = (card: FundCardData): boolean => [card.money_in, card.money_out, card.balance].every((value) => Number(value) === 0);

/** Every company fund, in two groups; funds with nothing in them wait behind "show all". */
export default function TreasuryFunds({ cards, partner_totals: partnerTotals, waiting_for_delivery: waitingForDelivery }: { cards: FundCardData[]; partner_totals?: { total: string } | null; waiting_for_delivery?: string }) {
    const translate = useT();
    const viewer = useTreasuryViewer();
    const [showAll, setShowAll] = useState(false);
    const spendable = cards.filter((card) => card.spendable);
    // The menu search's "record an expense" arrives here with ?spend=1 and shows the fund choice at once.
    const [choosing, setChoosing] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('spend') === '1');

    const empty = cards.filter(isEmpty);
    const shown = showAll ? cards : cards.filter((card) => !isEmpty(card));
    const named = FUND_GROUPS.flatMap((group) => group.funds);
    const groups = FUND_GROUPS.map((group) => ({
        key: group.key,
        title: translate('office_shell', group.title),
        note: translate('office_shell', group.note),
        cards: shown.filter((card) => (group.funds.length > 0 ? group.funds.includes(card.key) : !named.includes(card.key))),
    })).filter((group) => group.cards.length > 0);

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            {viewer.finance && (
                <div className="tsy-spend-choice" data-testid="fund-spend-choice">
                    <button type="button" className="button dark" aria-expanded={choosing} onClick={() => setChoosing((value) => !value)}>{translate('findability', 'btn_fund_spend')}</button>
                    {choosing && (
                        <div role="group" aria-label={translate('findability', 'spend_choose_title')}>
                            <p><b>{translate('findability', 'spend_choose_title')}</b></p>
                            {spendable.length === 0 && <p className="muted">{translate('findability', 'spend_choose_none')}</p>}
                            <ul>
                                {spendable.map((card) => (
                                    <li key={card.key}><Link className="button" href={`${card.href}?spend=1`}>{card.name} · {money(card.balance)}</Link></li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
            {waitingForDelivery != null && (
                <section className="tsy-fund tsy-waiting" data-testid="waiting-for-delivery">
                    <h2>{translate('money_core', 'waiting_for_delivery_title')}</h2>
                    <strong>{money(waitingForDelivery)}</strong>
                    <span className="tsy-fund-label">{translate('money_core', 'waiting_for_delivery_note')}</span>
                </section>
            )}
            <div data-tour="treasury-panel">
                {groups.map((group) => (
                    <section key={group.key} aria-label={group.title}>
                        <h2 className="tsy-group-title">{group.title} <small>{group.note}</small></h2>
                        <div className="tsy-funds">
                            {group.cards.map((card) => <FundCard key={card.key} card={card} />)}
                        </div>
                    </section>
                ))}
                {partnerTotals && viewer.finance && (
                    <div className="tsy-funds">
                        <Link className="tsy-fund" href="/treasury/partners">
                            <h2>{translate('treasury', 'partner_money_card')}</h2>
                            <span className="tsy-fund-label">{translate('treasury', 'in_it_now')}</span>
                            <strong>{money(partnerTotals.total)}</strong>
                        </Link>
                    </div>
                )}
                {empty.length > 0 && (
                    <button type="button" className="button" aria-pressed={showAll} onClick={() => setShowAll((value) => !value)}>
                        {showAll ? translate('office_shell', 'funds_hide_empty') : translate('office_shell', 'funds_show_all', { count: String(empty.length) })}
                    </button>
                )}
            </div>
        </TreasuryPage>
    );
}

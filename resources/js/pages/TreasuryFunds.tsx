import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { money } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import FundCard, { type FundCardData } from '../components/treasury/FundCard';
import { useTreasuryViewer } from '../components/treasury/TreasuryTabs';
import { useT } from '../shared/i18n/t';

/** The funds that belong to the company itself; every other fund is money held for partners. */
const COMPANY_FUNDS = ['company_share', 'company_profit', 'management', 'marketing', 'clinic', 'benefits'];

const isEmpty = (card: FundCardData): boolean => [card.money_in, card.money_out, card.balance].every((value) => Number(value) === 0);

/** Every company fund, in two groups; funds with nothing in them wait behind "show all". */
export default function TreasuryFunds({ cards, partner_totals: partnerTotals, waiting_for_delivery: waitingForDelivery }: { cards: FundCardData[]; partner_totals?: { total: string } | null; waiting_for_delivery?: string }) {
    const translate = useT();
    const viewer = useTreasuryViewer();
    const [showAll, setShowAll] = useState(false);

    const empty = cards.filter(isEmpty);
    const shown = showAll ? cards : cards.filter((card) => !isEmpty(card));
    const groups = [
        { key: 'company', title: translate('office_shell', 'funds_company'), cards: shown.filter((card) => COMPANY_FUNDS.includes(card.key)) },
        { key: 'partners', title: translate('office_shell', 'funds_partners'), cards: shown.filter((card) => !COMPANY_FUNDS.includes(card.key)) },
    ].filter((group) => group.cards.length > 0);

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
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
                        <h2 className="tsy-group-title">{group.title}</h2>
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

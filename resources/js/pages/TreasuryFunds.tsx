import { Link } from '@inertiajs/react';
import { money } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import FundCard, { type FundCardData } from '../components/treasury/FundCard';
import { useTreasuryViewer } from '../components/treasury/TreasuryTabs';
import { useT } from '../shared/i18n/t';

/** Every company fund, each with what is in it now, what came in and what went out. */
export default function TreasuryFunds({ cards, partners }: { cards: FundCardData[]; partners?: { total: string } | null }) {
    const translate = useT();
    const viewer = useTreasuryViewer();

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            <div className="tsy-funds">
                {cards.map((card) => <FundCard key={card.key} card={card} />)}
                {partners && viewer.finance && (
                    <Link className="tsy-fund" href="/treasury/partners">
                        <h2>{translate('treasury', 'tab_partners')}</h2>
                        <span className="tsy-fund-label">{translate('treasury', 'card_now')}</span>
                        <strong>{money(partners.total)}</strong>
                    </Link>
                )}
            </div>
        </TreasuryPage>
    );
}

import { Link } from '@inertiajs/react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isNegative } from './format';
import Figures from '../office/Figures';

export type FundCardData = {
    key: string;
    name: string;
    money_in: string;
    money_out: string;
    balance: string;
    spendable: boolean;
    owner_only: boolean;
    href: string;
};

/** The same three cards for every fund: came in, went out, in it now. */
export function FundFigures({ card }: { card: FundCardData }) {
    const translate = useT();

    return (
        <Figures
            items={[
                { key: 'in', label: translate('treasury', 'came_in'), value: card.money_in },
                { key: 'out', label: translate('treasury', 'went_out'), value: card.money_out },
                { key: 'now', label: translate('treasury', 'in_it_now'), value: card.balance, headline: true },
            ]}
        />
    );
}

/** A fund on the Treasury home page. The whole card opens that fund. */
export default function FundCard({ card }: { card: FundCardData }) {
    const translate = useT();

    return (
        <Link className="tsy-fund" href={card.href}>
            <h2>{card.name}</h2>
            <span className="tsy-fund-label">{translate('treasury', 'in_it_now')}</span>
            <strong className={isNegative(card.balance) ? 'is-negative' : undefined}>{money(card.balance)}</strong>
            <dl>
                <div>
                    <dt>{translate('treasury', 'came_in')}</dt>
                    <dd>{money(card.money_in)}</dd>
                </div>
                <div>
                    <dt>{translate('treasury', 'went_out')}</dt>
                    <dd>{money(card.money_out)}</dd>
                </div>
            </dl>
        </Link>
    );
}

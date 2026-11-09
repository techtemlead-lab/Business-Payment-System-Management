import { Link } from '@inertiajs/react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isNegative } from './format';

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

export type FigureItem = { key: string; label: string; value: string; headline?: boolean; text?: boolean };

/** One row of cards: one label and one big number each. Only a figure below zero gets a colour. */
export function Figures({ items }: { items: FigureItem[] }) {
    return (
        <div className="tsy-figures">
            {items.map((item) => (
                <div key={item.key} className="tsy-figure">
                    <span>{item.label}</span>
                    <strong className={`${item.headline ? 'is-headline' : ''}${!item.text && isNegative(item.value) ? ' is-negative' : ''}`.trim() || undefined}>
                        {item.text ? item.value : money(item.value)}
                    </strong>
                </div>
            ))}
        </div>
    );
}

/** The same three cards for every fund: came in, went out, in it now. */
export function FundFigures({ card }: { card: FundCardData }) {
    const translate = useT();

    return (
        <Figures
            items={[
                { key: 'in', label: translate('treasury', 'card_in'), value: card.money_in },
                { key: 'out', label: translate('treasury', 'card_out'), value: card.money_out },
                { key: 'now', label: translate('treasury', 'card_now'), value: card.balance, headline: true },
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
            <span className="tsy-fund-label">{translate('treasury', 'card_now')}</span>
            <strong className={isNegative(card.balance) ? 'is-negative' : undefined}>{money(card.balance)}</strong>
            <dl>
                <div>
                    <dt>{translate('treasury', 'card_in')}</dt>
                    <dd>{money(card.money_in)}</dd>
                </div>
                <div>
                    <dt>{translate('treasury', 'card_out')}</dt>
                    <dd>{money(card.money_out)}</dd>
                </div>
            </dl>
        </Link>
    );
}

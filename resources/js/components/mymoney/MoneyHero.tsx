import type { ReactNode } from 'react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isBelowZero, type MoneySummary } from './types';

/** The one big number: what the partner can use now. Waiting money stays small beneath it. */
export default function MoneyHero({ summary, actions }: { summary: MoneySummary; actions?: ReactNode }) {
    const translate = useT();
    const smallFigures = [
        { key: 'waiting', amount: summary.waiting },
        { key: 'asked', amount: summary.asked },
    ].filter((figure) => Number(figure.amount) !== 0);

    return (
        <section className="mm-hero" aria-labelledby="mm-hero-label" data-tour="wallet-balances">
            <span id="mm-hero-label" className="mm-hero-label">{translate('my_money', 'usable')}</span>
            <strong className={`mm-hero-number${isBelowZero(summary.available) ? ' is-negative' : ''}`}>{money(summary.available)}</strong>
            {smallFigures.length > 0 && (
                <ul className="mm-hero-small">
                    {smallFigures.map((figure) => (
                        <li key={figure.key}>{translate('my_money', figure.key)} <b>{money(figure.amount)}</b></li>
                    ))}
                </ul>
            )}
            {actions && <div className="mm-hero-actions" data-tour="withdrawal-form">{actions}</div>}
        </section>
    );
}

import type { ReactNode } from 'react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isBelowZero, type MoneySummary, type PendingMoney } from './types';

/** The one big number: what the partner can use now. Waiting and pending money stay small beside and beneath it. */
export default function MoneyHero({ summary, actions, pending }: { summary: MoneySummary; actions?: ReactNode; pending?: PendingMoney | null }) {
    const translate = useT();
    const smallFigures = [
        { key: 'waiting', amount: summary.waiting },
        { key: 'asked', amount: summary.asked },
    ].filter((figure) => Number(figure.amount) !== 0);
    const hasPending = pending !== undefined && pending !== null && Number(pending.total) > 0;

    return (
        <section className="mm-hero" aria-labelledby="mm-hero-label" data-tour="wallet-balances">
            <span id="mm-hero-label" className="mm-hero-label">{translate('my_money', 'usable')}</span>
            <strong className={`mm-hero-number${isBelowZero(summary.available) ? ' is-negative' : ''}`}>{money(summary.available)}</strong>
            {hasPending && (
                <p className="mm-hero-pending" data-testid="pending-balance">
                    <span>{translate('my_money', 'pending_balance')}</span> <b>{money(pending.total)}</b>
                    <small>{translate('my_money', 'pending_balance_hint')}</small>
                </p>
            )}
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

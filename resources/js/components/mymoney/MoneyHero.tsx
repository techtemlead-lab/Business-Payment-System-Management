import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isBelowZero, type MoneySummary, type PendingMoney } from './types';

/**
 * The partner's three main accounts:
 *  1. Total income record (all earnings)
 *  2. Total withdrawal record (all paid withdrawals + pending requests)
 *  3. Current remaining balance (usable now, plus waiting/pending amounts)
 */
export default function MoneyHero({ summary, actions, pending, incomeHref, historyHref = '#mm-withdrawals' }: {
    summary: MoneySummary;
    actions?: ReactNode;
    pending?: PendingMoney | null;
    incomeHref?: string;
    historyHref?: string;
}) {
    const translate = useT();
    const smallFigures = [
        { key: 'waiting', amount: summary.waiting },
        { key: 'asked', amount: summary.asked },
    ].filter((figure) => Number(figure.amount) !== 0);
    const hasPending = pending !== undefined && pending !== null && Number(pending.total) > 0;
    const hasAsked = Number(summary.asked) > 0;

    return (
        <section className="mm-hero" aria-labelledby="mm-hero-label" data-tour="wallet-balances">
            <div className="mm-accounts" data-testid="wallet-three-accounts">
                <div className="mm-account-card">
                    <span className="mm-account-label">{translate('my_money', 'acct_total_income')}</span>
                    <strong className="mm-account-value">{money(summary.lifetime.earned)}</strong>
                    {incomeHref && (
                        <Link className="mm-account-link text-link" href={incomeHref}>
                            {translate('my_money', 'acct_open_income')}
                        </Link>
                    )}
                </div>

                <div className="mm-account-card">
                    <span className="mm-account-label">{translate('my_money', 'acct_total_withdrawn')}</span>
                    <strong className="mm-account-value">{money(summary.lifetime.withdrawn)}</strong>
                    {hasAsked && (
                        <small className="mm-account-note">
                            {translate('my_money', 'asked')}: <b>{money(summary.asked)}</b>
                        </small>
                    )}
                    <a className="mm-account-link text-link" href={historyHref}>
                        {translate('my_money', 'acct_open_history')}
                    </a>
                </div>

                <div className="mm-account-card is-balance">
                    <span id="mm-hero-label" className="mm-hero-label">{translate('my_money', 'acct_current_balance')}</span>
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
                </div>
            </div>
            {actions && <div className="mm-hero-actions" data-tour="withdrawal-form">{actions}</div>}
        </section>
    );
}

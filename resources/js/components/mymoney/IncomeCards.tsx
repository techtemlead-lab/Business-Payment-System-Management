import { Link, router, usePage } from '@inertiajs/react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import { isBelowZero, type IncomeCard } from './types';

/** On a phone the statement can be below the fold, so a card press brings it up to show what changed. */
function bringStatementIntoView(): void {
    const statement = document.getElementById('mm-book');
    if (!statement) {
        return;
    }
    const top = statement.getBoundingClientRect().top;
    if (top >= 0 && top < window.innerHeight * 0.6) {
        return;
    }
    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    statement.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
}

/** Shows one kind of income in the statement, or all of them when the chosen card is pressed again. */
export function useChooseIncomeType(selected: string): (type: string) => void {
    const { url } = usePage();

    return (type: string) => {
        const current = new URL(url || '/', 'http://list.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => { params[key] = value; });
        delete params.page;
        if (type === selected) {
            delete params.type;
        } else {
            params.type = type;
        }
        router.get(current.pathname, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['table'],
            onSuccess: bringStatementIntoView,
        });
    };
}

/**
 * One card per kind of income. On My Money a card filters the statement below it;
 * on the partner home the same cards are links to that filtered statement.
 */
export default function IncomeCards({ cards, selected = '', onChoose, hrefFor }: {
    cards: IncomeCard[];
    selected?: string;
    onChoose?: (type: string) => void;
    hrefFor?: (type: string) => string;
}) {
    const translate = useT();
    if (cards.length === 0) {
        return null;
    }

    return (
        <section className="mm-income" aria-labelledby="mm-income-title">
            <h2 id="mm-income-title">{translate('my_money', 'where_from')}</h2>
            <div className="mm-cards">
                {cards.map((card) => {
                    const body = (
                        <>
                            <span>{card.label}</span>
                            <strong className={isBelowZero(card.amount) ? 'is-negative' : undefined}>{money(card.amount)}</strong>
                        </>
                    );

                    return hrefFor
                        ? <Link key={card.key} className="mm-card" href={hrefFor(card.key)}>{body}</Link>
                        : (
                            <button
                                key={card.key}
                                type="button"
                                className={`mm-card${card.key === selected ? ' is-selected' : ''}`}
                                aria-pressed={card.key === selected}
                                onClick={() => onChoose?.(card.key)}
                            >
                                {body}
                            </button>
                        );
                })}
            </div>
        </section>
    );
}

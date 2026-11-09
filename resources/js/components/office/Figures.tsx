import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { money } from '../UI';
import { isNegative } from '../treasury/format';

export type FigureItem = {
    key: string;
    label: string;
    value: string;
    headline?: boolean;
    /** Shows the value as it is, for counts and words. Otherwise the value is money. */
    text?: boolean;
    /** The whole card opens this address. */
    href?: string;
    /** The whole card does this, such as setting a filter. */
    onClick?: () => void;
    /** Marks the card whose filter is on. */
    active?: boolean;
};

const MOST_CARDS = 4;

/** One row of up to four cards: one label and one big number each. Only a figure below zero gets a colour. */
export default function Figures({ items }: { items: FigureItem[] }) {
    const shown = items.slice(0, MOST_CARDS);

    return (
        <div className="office-figures" data-cards={shown.length}>
            {shown.map((item) => {
                const body: ReactNode = (
                    <>
                        <span>{item.label}</span>
                        <strong className={`${item.headline ? 'is-headline' : ''}${!item.text && isNegative(item.value) ? ' is-negative' : ''}`.trim() || undefined}>
                            {item.text ? item.value : money(item.value)}
                        </strong>
                    </>
                );
                const className = `office-figure${item.active ? ' is-active' : ''}`;

                if (item.href) {
                    return <Link key={item.key} className={`${className} is-link`} href={item.href}>{body}</Link>;
                }
                if (item.onClick) {
                    return <button key={item.key} type="button" className={`${className} is-link`} aria-pressed={item.active ?? false} onClick={item.onClick}>{body}</button>;
                }

                return <div key={item.key} className={className}>{body}</div>;
            })}
        </div>
    );
}

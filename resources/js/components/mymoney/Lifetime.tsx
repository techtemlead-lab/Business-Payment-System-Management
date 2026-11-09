import { money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { MoneyLifetime } from './types';

const FIGURES: { label: string; value: keyof MoneyLifetime }[] = [
    { label: 'lifetime_ordered', value: 'ordered_mrp' },
    { label: 'lifetime_cash', value: 'cash_given' },
    { label: 'withdrawn', value: 'withdrawn' },
    { label: 'spent', value: 'spent' },
];

/** The quiet "so far" line under the income cards. */
export default function Lifetime({ lifetime }: { lifetime: MoneyLifetime }) {
    const translate = useT();

    return (
        <section className="mm-lifetime" aria-labelledby="mm-lifetime-title">
            <h2 id="mm-lifetime-title">{translate('my_money', 'lifetime_heading')}</h2>
            <dl>
                {FIGURES.map((figure) => (
                    <div key={figure.value}>
                        <dt>{translate('my_money', figure.label)}</dt>
                        <dd>{money(lifetime[figure.value])}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

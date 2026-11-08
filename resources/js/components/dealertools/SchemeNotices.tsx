import React from 'react';
import { Gift } from 'lucide-react';
import { date, number } from '../UI';
import { useT } from '../../shared/i18n/t';

export type RunningScheme = {
    id: number; title: string; ends_on: string; product_ids: number[]; product_names: string[];
    min_quantity: number; benefit_type: string; benefit_text: string;
};

/** Schemes running today, with a note when the cart reaches the minimum. Information only: the order does not change. */
export default function SchemeNotices({ schemes, cartQuantities }: { schemes: RunningScheme[]; cartQuantities: Record<number, number> }) {
    const translate = useT();
    if (schemes.length === 0) {
        return null;
    }
    return (
        <section className="panel" data-testid="scheme-notices">
            <h2><Gift size={18} /> {translate('dealer_tools', 'scheme_running_title')}</h2>
            <p className="muted">{translate('dealer_tools', 'scheme_info_only')}</p>
            {schemes.map((scheme) => {
                const inCart = scheme.product_ids.reduce((sum, id) => sum + (cartQuantities[id] ?? 0), 0);
                return (
                    <article key={scheme.id} className="notification">
                        <div>
                            <b>{scheme.title}</b>
                            <p>{scheme.benefit_text}</p>
                            <small>
                                {translate('dealer_tools', `scheme_type_${scheme.benefit_type}`)} · {translate('dealer_tools', 'scheme_min', { count: number(scheme.min_quantity) })} · {translate('dealer_tools', 'scheme_until', { date: date(scheme.ends_on) })}
                            </small>
                            <small className="cell-sub">{scheme.product_names.join(', ')}</small>
                            {inCart > 0 && (
                                <small className="cell-sub"><b>{translate('dealer_tools', inCart >= scheme.min_quantity ? 'scheme_cart_reached' : 'scheme_cart_short', { count: number(Math.max(0, scheme.min_quantity - inCart)) })}</b></small>
                            )}
                        </div>
                    </article>
                );
            })}
        </section>
    );
}

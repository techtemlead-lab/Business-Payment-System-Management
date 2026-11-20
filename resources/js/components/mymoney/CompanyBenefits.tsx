import { date, money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { CompanyBenefits as Benefits } from './types';

/**
 * What the company has spent on this partner (a tour, an event or contest prize, an owner's benefit). It is not a wallet credit:
 * it is never part of the balance, the pending balance or the income totals. One line for each spend, with the partner's own share.
 */
export default function CompanyBenefits({ benefits, compact = false }: { benefits?: Benefits | null; compact?: boolean }) {
    const translate = useT();

    if (!benefits || benefits.lines.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests mm-benefits" aria-label={translate('my_money', 'benefits_heading')} data-testid={compact ? 'home-company-benefits' : 'my-money-company-benefits'}>
            <h2>{translate('my_money', 'benefits_heading')}</h2>
            {!compact && <p>{translate('my_money', 'benefits_intro')}</p>}
            <ul>
                {benefits.lines.map((line) => (
                    <li key={line.id}>
                        <div className="mm-request">
                            <span>{line.what}</span>
                            <strong>{line.amount === null ? translate('my_money', 'benefits_direct') : money(line.amount)}</strong>
                            <span className="badge paid">{translate('my_money', 'benefits_status_paid_by_office')}</span>
                            <small>{date(line.date)}{line.purpose ? ` · ${line.purpose}` : ''}</small>
                        </div>
                    </li>
                ))}
            </ul>
            {benefits.count > benefits.lines.length && <p className="muted">{translate('my_money', 'benefits_more', { count: benefits.count - benefits.lines.length })}</p>}
            <p className="muted">{translate('my_money', 'benefits_total', { amount: money(benefits.total) })}</p>
        </section>
    );
}

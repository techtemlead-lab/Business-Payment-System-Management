import { usePage } from '@inertiajs/react';
import { ActionForm, money } from './UI';
import { useT } from '../shared/i18n/t';

type Outcome = {
    state: 'posted' | 'waiting' | 'no_commission';
    delivered_value: string;
    order_total: string;
    reason: string | null;
    can_choose_no_commission: boolean;
    fund_amount?: string | null;
    taken_back?: string | null;
    still_owed?: string | null;
};

/** What happened to the commission on a partly delivered order, and the staff choice to give none. */
export default function PartlyDeliveredCommission() {
    const translate = useT();
    const { commissionOutcome: outcome, order } = usePage<{ commissionOutcome?: Outcome | null; order: { id: string } }>().props;
    if (!outcome) {
        return null;
    }
    const figure = (key: string, value: string) => <div className="metric"><span>{translate('courier_more', key)}</span><strong>{money(value)}</strong></div>;

    return <section className="panel" data-testid="partly-delivered-commission">
        <h2>{translate('courier_more', 'part_title')}</h2>
        <div className="metric-grid four">
            {figure('part_value', outcome.delivered_value)}
            {figure('part_order_total', outcome.order_total)}
            {outcome.fund_amount && figure('part_fund', outcome.fund_amount)}
            {outcome.taken_back && figure('part_taken_back', outcome.taken_back)}
            {outcome.still_owed && figure('part_still_owed', outcome.still_owed)}
        </div>
        <p>{translate('courier_more', `part_${outcome.state}`)}</p>
        {outcome.reason && <p><b>{translate('courier_more', 'part_reason')}:</b> {outcome.reason}</p>}
        {outcome.can_choose_no_commission && <>
            <p className="muted" role="status">{translate('courier_more', 'part_action_warning')}</p>
            <ActionForm action={`/commands/orders/${order.id}/mixed-outcome-decision`} defaults={{ decision: 'no_commission' }} fields={[
                { name: 'reason', label: translate('courier_more', 'part_action_reason'), type: 'textarea', required: true },
            ]} submit={translate('courier_more', 'part_action')} />
        </>}
    </section>;
}

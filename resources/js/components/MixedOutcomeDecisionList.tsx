import type { ReactNode } from 'react';
import { ActionForm, Row, Table } from './UI';
import { useT } from '../shared/i18n/t';

/** Orders with one parcel delivered and another returned: staff decide the commission after talking to the dealer. */
export default function MixedOutcomeDecisionList({ rows, canDecide, orderLink }: { rows: Row[]; canDecide: boolean; orderLink: (row: Row) => ReactNode }) {
    const translate = useT();
    if (rows.length === 0) {
        return null;
    }

    return <section className="panel table-panel" data-testid="courier-needs-decision">
        <div className="panel-heading"><h2>{translate('courier_more', 'decision_title')}</h2><span>{translate('courier_more', 'decision_hint')}</span></div>
        <p className="muted" role="status">{translate('schemes_commission', 'decision_rule_note')}</p>
        <Table rows={rows} searchable={false} tools={false} emptyMessage={translate('courier_more', 'decision_empty')} columns={[
            { key: 'number', title: translate('courier_more', 'decision_col_order'), render: orderLink },
            { key: 'partner', title: translate('courier_more', 'decision_col_partner') },
            { key: 'order_id', title: translate('courier_more', 'decision_col_action'), render: (row: Row) => canDecide
                ? <ActionForm action={`/commands/orders/${row.order_id}/mixed-outcome-decision`} fields={[
                    { name: 'decision', label: translate('courier_more', 'decision_choice_label'), required: true, options: [
                        { value: 'delivered_part', label: translate('schemes_commission', 'decision_delivered_part') },
                        { value: 'no_commission', label: translate('schemes_commission', 'decision_no_commission') },
                    ] },
                    { name: 'reason', label: translate('courier_more', 'decision_reason_label'), type: 'textarea', required: true },
                ]} submit={translate('courier_more', 'decision_submit')} />
                : <span className="muted">{translate('courier_more', 'decision_view_only')}</span> },
        ]} />
    </section>;
}

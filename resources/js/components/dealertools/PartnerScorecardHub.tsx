import React from 'react';
import { usePage } from '@inertiajs/react';
import { label, money, number, type Row } from '../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import OfficePage from '../office/OfficePage';
import type { OfficeHub } from '../OfficeHubTabs';
import { useT } from '../../shared/i18n/t';

/** For office staff: each partner's payments, cancellations and returns for orders placed in a period. */
export default function PartnerScorecardHub({ data }: { data: { partners: MoneyTableData<Row> }; filters?: Row }) {
    const translate = useT();
    const hub: OfficeHub | null = usePage<any>().props.data?.hub ?? null;
    const columns: MoneyColumn<Row>[] = [
        { key: 'name', title: translate('dealer_tools', 'scorecard_col_partner'), sortKey: 'name', phone: 'title', render: (row) => <>{row.name}<small className="cell-sub">{[row.dealer_code, label(row.role, translate)].filter(Boolean).join(' · ')}</small></> },
        { key: 'orders', title: translate('dealer_tools', 'scorecard_col_orders'), sortKey: 'orders', align: 'right', phone: 'hide', render: (row) => number(row.orders) },
        { key: 'payments_verified', title: translate('dealer_tools', 'scorecard_col_verified'), sortKey: 'payments_verified', align: 'right', phoneLabel: true, render: (row) => number(row.payments_verified) },
        { key: 'payments_verified_amount', title: translate('office_reports', 'scorecard_col_verified_amount'), sortKey: 'payments_verified_amount', money: true, phone: 'amount', render: (row) => money(row.payments_verified_amount) },
        { key: 'payments_rejected', title: translate('dealer_tools', 'scorecard_col_rejected'), sortKey: 'payments_rejected', align: 'right', phoneLabel: true, render: (row) => number(row.payments_rejected) },
        { key: 'payments_pending', title: translate('dealer_tools', 'scorecard_col_pending'), sortKey: 'payments_pending', align: 'right', phoneLabel: true, render: (row) => number(row.payments_pending) },
        { key: 'cancelled', title: translate('dealer_tools', 'scorecard_col_cancelled'), sortKey: 'cancelled', align: 'right', phoneLabel: true, render: (row) => number(row.cancelled) },
        { key: 'returned', title: translate('dealer_tools', 'scorecard_col_returned'), sortKey: 'returned', align: 'right', phoneLabel: true, render: (row) => number(row.returned) },
    ];

    return (
        <OfficePage title={hub?.label ?? translate('dealer_tools', 'scorecard_title')} hub={hub}>
            <MoneyTable<Row>
                data={data.partners}
                prop="data"
                columns={columns}
                searchPlaceholder={translate('office_reports', 'scorecard_search')}
                empty={translate('dealer_tools', 'scorecard_empty_title')}
            />
        </OfficePage>
    );
}

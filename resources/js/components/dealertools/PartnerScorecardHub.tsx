import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Pagination, Table, label, money, number, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';

/** For office staff: each partner's payments, cancellations and returns for orders placed in a period. */
export default function PartnerScorecardHub({ data, filters }: { data: { partners: Row }; filters: Row }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [search, setSearch] = useState(String(filters?.q ?? ''));
    const period = String(filters?.period ?? '');
    const buyerRole = String(filters?.buyer_role ?? '');
    const go = (next: Row) => {
        const query = Object.fromEntries(Object.entries({ q: search, period, buyer_role: buyerRole, ...next }).filter(([, value]) => value !== '' && value != null)) as Record<string, string>;
        router.get(`${base}/partner-scorecard`, query, { preserveState: true, preserveScroll: true });
    };
    return (
        <section className="panel table-panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('dealer_tools', 'scorecard_title')}</h2>
                    <p className="muted">{translate('dealer_tools', 'scorecard_hint')}</p>
                </div>
            </div>
            <form className="filter-bar" onSubmit={(event) => { event.preventDefault(); go({}); }}>
                <input aria-label={translate('dealer_tools', 'scorecard_search')} placeholder={translate('dealer_tools', 'scorecard_search')} value={search} onChange={(event) => setSearch(event.target.value)} />
                <select aria-label={translate('dealer_tools', 'scorecard_period')} value={period} onChange={(event) => go({ period: event.target.value })}>
                    <option value="">{translate('dealer_tools', 'scorecard_all_time')}</option>
                    <option value="month">{translate('dealer_tools', 'scorecard_this_month')}</option>
                    <option value="last_month">{translate('dealer_tools', 'scorecard_last_month')}</option>
                    <option value="year">{translate('dealer_tools', 'scorecard_this_year')}</option>
                </select>
                <select aria-label={translate('dealer_tools', 'scorecard_partner_type')} value={buyerRole} onChange={(event) => go({ buyer_role: event.target.value })}>
                    <option value="">{translate('dealer_tools', 'scorecard_all_partners')}</option>
                    <option value="dealer">{label('dealer', translate)}</option>
                    <option value="sub_dealer">{label('sub_dealer', translate)}</option>
                </select>
                <button type="submit" className="button small">{translate('dealer_tools', 'scorecard_search')}</button>
            </form>
            <Table
                tools={false}
                searchable={false}
                rows={data.partners?.data ?? []}
                empty={translate('dealer_tools', 'scorecard_empty_title')}
                emptyBody={translate('dealer_tools', 'scorecard_empty_body')}
                columns={[
                    { key: 'name', title: translate('dealer_tools', 'scorecard_col_partner'), render: (row: Row) => <>{row.name}<small className="cell-sub">{[row.dealer_code, label(row.role, translate)].filter(Boolean).join(' · ')}</small></> },
                    { key: 'orders', title: translate('dealer_tools', 'scorecard_col_orders'), align: 'right', render: (row: Row) => number(row.orders) },
                    { key: 'payments_verified', title: translate('dealer_tools', 'scorecard_col_verified'), align: 'right', render: (row: Row) => <>{number(row.payments_verified)}<small className="cell-sub">{money(row.payments_verified_amount)}</small></> },
                    { key: 'payments_rejected', title: translate('dealer_tools', 'scorecard_col_rejected'), align: 'right', render: (row: Row) => number(row.payments_rejected) },
                    { key: 'payments_pending', title: translate('dealer_tools', 'scorecard_col_pending'), align: 'right', render: (row: Row) => number(row.payments_pending) },
                    { key: 'cancelled', title: translate('dealer_tools', 'scorecard_col_cancelled'), align: 'right', render: (row: Row) => number(row.cancelled) },
                    { key: 'returned', title: translate('dealer_tools', 'scorecard_col_returned'), align: 'right', render: (row: Row) => number(row.returned) },
                ]}
            />
            <Pagination page={data.partners} />
        </section>
    );
}

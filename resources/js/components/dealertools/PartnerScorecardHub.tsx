import React, { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Pagination, Table, label, money, number, type Row } from '../UI';
import { ReportCards, ReportFilterSheet, ReportViewChips } from '../BranchPerformanceHub';
import OfficePage from '../office/OfficePage';
import type { OfficeHub } from '../OfficeHubTabs';
import { useT } from '../../shared/i18n/t';

const SEARCH_PAUSE_MS = 400;

/** For office staff: each partner's payments, cancellations and returns for orders placed in a period. */
export default function PartnerScorecardHub({ data, filters }: { data: { partners: Row }; filters: Row }) {
    const translate = useT();
    const { portalBase: base, data: pageData } = usePage<any>().props;
    const hub: OfficeHub | null = pageData?.hub ?? null;
    const [search, setSearch] = useState(String(filters?.q ?? ''));
    const searchTimer = useRef<number | undefined>(undefined);
    const period = String(filters?.period ?? '');
    const from = String(filters?.from ?? '');
    const to = String(filters?.to ?? '');
    const buyerRole = String(filters?.buyer_role ?? '');
    const go = (next: Row) => {
        window.clearTimeout(searchTimer.current);
        const query = Object.fromEntries(Object.entries({ q: search, period, from, to, buyer_role: buyerRole, ...next }).filter(([, value]) => value !== '' && value != null)) as Record<string, string>;
        router.get(`${base}/partner-scorecard`, query, { preserveState: true, preserveScroll: true, replace: true });
    };
    const typeSearch = (words: string) => {
        setSearch(words);
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => go({ q: words.trim() }), SEARCH_PAUSE_MS);
    };

    const rows: Row[] = data.partners?.data ?? [];
    const typePicker = (
        <select aria-label={translate('dealer_tools', 'scorecard_partner_type')} value={buyerRole} onChange={(event) => go({ buyer_role: event.target.value })}>
            <option value="">{translate('dealer_tools', 'scorecard_all_partners')}</option>
            <option value="dealer">{label('dealer', translate)}</option>
            <option value="sub_dealer">{label('sub_dealer', translate)}</option>
        </select>
    );

    return (
        <OfficePage title={hub?.label ?? translate('dealer_tools', 'scorecard_title')} hub={hub}>
            <div className="money-table">
                <div className="mt-toolbar">
                    <form className="mt-search" role="search" onSubmit={(event) => { event.preventDefault(); go({ q: search.trim() }); }}>
                        <div className="search-input">
                            <Search size={16} aria-hidden="true" />
                            <input type="search" aria-label={translate('office_reports', 'scorecard_search')} placeholder={translate('office_reports', 'scorecard_search')} value={search} maxLength={100} onChange={(event) => typeSearch(event.target.value)} />
                        </div>
                    </form>
                    <ReportFilterSheet><label>{translate('dealer_tools', 'scorecard_partner_type')}{typePicker}</label></ReportFilterSheet>
                    <ReportViewChips
                        active={period}
                        from={from}
                        to={to}
                        extraChoices={[{ value: 'last_month', word: 'orders_period_last_month' }]}
                        allowAll
                        onChoose={(view, dates) => go({ period: view, from: dates?.from ?? '', to: dates?.to ?? '' })}
                    />
                    <div className="mt-filters">{typePicker}</div>
                </div>
                <div className="mt-table-wrap table-panel">
                    <Table
                        tools={false}
                        searchable={false}
                        rows={rows}
                        empty={translate('dealer_tools', 'scorecard_empty_title')}
                        columns={[
                            { key: 'name', title: translate('dealer_tools', 'scorecard_col_partner'), render: (row: Row) => <>{row.name}<small className="cell-sub">{[row.dealer_code, label(row.role, translate)].filter(Boolean).join(' · ')}</small></> },
                            { key: 'orders', title: translate('dealer_tools', 'scorecard_col_orders'), align: 'right', render: (row: Row) => number(row.orders) },
                            { key: 'payments_verified', title: translate('dealer_tools', 'scorecard_col_verified'), align: 'right', render: (row: Row) => <>{number(row.payments_verified)}<small className="cell-sub">{money(row.payments_verified_amount)}</small></> },
                            { key: 'payments_rejected', title: translate('dealer_tools', 'scorecard_col_rejected'), align: 'right', render: (row: Row) => <>{number(row.payments_rejected)}<small className="cell-sub">{translate('dealer_tools', 'scorecard_col_pending')}: {number(row.payments_pending)}</small></> },
                            { key: 'cancelled', title: translate('dealer_tools', 'scorecard_col_cancelled'), align: 'right', render: (row: Row) => number(row.cancelled) },
                            { key: 'returned', title: translate('dealer_tools', 'scorecard_col_returned'), align: 'right', render: (row: Row) => number(row.returned) },
                        ]}
                    />
                </div>
                <ReportCards<Row>
                    rows={rows}
                    empty={translate('dealer_tools', 'scorecard_empty_title')}
                    title={(row) => <>{row.name}<small className="cell-sub">{[row.dealer_code, label(row.role, translate)].filter(Boolean).join(' · ')}</small></>}
                    lines={(row) => [
                        `${translate('dealer_tools', 'scorecard_col_verified')}: ${number(row.payments_verified)}`,
                        `${translate('dealer_tools', 'scorecard_col_rejected')}: ${number(row.payments_rejected)}`,
                        `${translate('dealer_tools', 'scorecard_col_pending')}: ${number(row.payments_pending)}`,
                        `${translate('dealer_tools', 'scorecard_col_cancelled')}: ${number(row.cancelled)}`,
                        `${translate('dealer_tools', 'scorecard_col_returned')}: ${number(row.returned)}`,
                    ]}
                    amount={(row) => money(row.payments_verified_amount)}
                />
                <Pagination page={data.partners} />
            </div>
        </OfficePage>
    );
}

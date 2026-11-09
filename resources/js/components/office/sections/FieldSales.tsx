import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { actionAllowed } from '../../action-permissions';
import { Badge, Empty, Pagination, date, label, money, type Row } from '../../UI';
import type { OfficeHub } from '../../OfficeHubTabs';
import Figures from '../Figures';
import OfficePage from '../OfficePage';
import { businessMonth } from '../../../business-date';
import { randomUuid } from '../../../shared/randomUuid';
import { useT } from '../../../shared/i18n/t';
import DealerApplicationsView from './DealerApplicationsView';
import { commandRoot, currentView, findTable, pickField, SectionTableSearch, StaffActionDialog, StaffTable, textField, ViewSwitch, type StaffAction, type StaffField, type StaffSectionTable } from './staffSectionParts';

type FieldView = 'orders' | 'targets' | 'applications' | 'areas';
type DialogKey = 'order' | 'application';

export type FieldSalesProps = {
    tables: StaffSectionTable[];
    partners: Row[];
    partnerSearch?: string;
    products: Row[];
    regions: Row[];
    summary: Record<string, string>;
    fullPaymentWhenOrdering?: Row | null;
    hub?: OfficeHub | null;
};

/** The Staff field sales tab: this month's sales, one table per view, both forms open as dialogs. */
export default function FieldSales({ tables, partners, partnerSearch = '', products, regions, summary, fullPaymentWhenOrdering, hub }: FieldSalesProps) {
    const translate = useT();
    const { auth, staffActions, collectionAccounts = [] } = usePage<any>().props;
    const { url } = usePage();
    const capabilities: string[] = auth.capabilities;
    const [dialog, setDialog] = useState<DialogKey | null>(null);
    const [partnerWords, setPartnerWords] = useState(partnerSearch);
    const [orderKey] = useState(() => randomUuid());

    const orders = findTable(tables, 'territory_orders');
    const targets = findTable(tables, 'my_targets');
    const applications = findTable(tables, 'dealer_applications');
    const views: { key: FieldView; label: string }[] = [
        ...(orders ? [{ key: 'orders' as const, label: translate('office_staff', 'field_view_orders') }] : []),
        ...(targets ? [{ key: 'targets' as const, label: translate('office_staff', 'field_view_targets') }] : []),
        ...(applications ? [{ key: 'applications' as const, label: translate('office_staff', 'field_view_applications') }] : []),
        { key: 'areas', label: translate('office_staff', 'field_view_areas') },
    ];
    const fallback: FieldView = views[0].key;
    const view = currentView(url, views.map((item) => item.key), fallback);

    const channels = [
        { value: 'Bank', label: translate('portal', 'pay_channel_bank') },
        { value: 'bKash', label: translate('portal', 'pay_channel_bkash') },
        { value: 'Nagad', label: translate('portal', 'pay_channel_nagad') },
        { value: 'Rocket', label: translate('portal', 'pay_channel_rocket') },
    ];
    // "Full payment required when ordering": staff orders carry the whole payment and its screenshot too.
    const orderPayment: StaffField[] = fullPaymentWhenOrdering?.enabled ? [
        { name: 'channel', label: translate('portal', 'payment_method'), required: true, options: channels },
        ...(collectionAccounts.length > 0 ? [{ name: 'payment_account_id', label: translate('portal', 'company_collection_account'), required: true, options: collectionAccounts.map((account: Row) => ({ value: String(account.id), label: `${account.holder} · ${account.account_number}`, channel: account.channel })) }] : []),
        textField('reference', translate('portal', 'transaction_reference')),
        textField('amount', translate('portal', 'ops_fld_full_order_amount'), 'number'),
        textField('proof', translate('portal', 'payment_proof_label'), 'file'),
    ] : [];

    const orderAction: StaffAction = {
        title: translate('portal', 'ops_submit_partner_order'),
        action: `${commandRoot}/staff/orders`,
        fields: [pickField(partners, 'user_id', translate('portal', 'ops_fld_partner')), pickField(products, 'items.0.product_id', translate('portal', 'ops_fld_product')), textField('items.0.quantity', translate('portal', 'ops_fld_quantity'), 'number'), ...orderPayment, textField('note', translate('portal', 'ops_fld_order_note'), 'textarea', false)],
        defaults: { idempotency_key: orderKey },
    };
    const accountTypes = ['dealer', 'sub_dealer'].map((value) => ({ value, label: label(value, translate) }));
    const applicationAction: StaffAction = {
        title: translate('portal', 'ops_submit_dealer_application'),
        action: `${commandRoot}/staff/applications`,
        fields: [textField('name', translate('portal', 'ops_fld_full_name')), textField('email', translate('portal', 'ops_fld_email'), 'email'), textField('phone', translate('portal', 'ops_fld_phone')), { name: 'role', label: translate('portal', 'ops_fld_account_type'), required: true, options: accountTypes }, textField('place_code', translate('portal', 'ops_fld_place_code')), pickField(partners.filter((partner) => partner.role === 'dealer'), 'sponsor_id', translate('portal', 'ops_fld_sponsor_optional'), false)],
    };

    const buttons: { key: DialogKey; label: string; action: StaffAction }[] = [
        ...(capabilities.includes('sr_orders') ? [{ key: 'order' as const, label: translate('office_staff', 'submit_order'), action: orderAction }] : []),
        ...(capabilities.includes('sr_onboarding') ? [{ key: 'application' as const, label: translate('office_staff', 'submit_application'), action: applicationAction }] : []),
    ].filter((button) => actionAllowed(button.action.action, staffActions, 'post'));

    const openDialog = buttons.find((button) => button.key === dialog);

    const findPartners = () => {
        const params = Object.fromEntries(new URLSearchParams(window.location.search).entries());
        if (partnerWords.trim()) {
            params.q = partnerWords.trim();
        } else {
            delete params.q;
        }
        router.get(window.location.pathname, params, { preserveState: true, preserveScroll: true, replace: true, only: ['partners', 'partnerSearch'] });
    };

    return (
        <OfficePage
            title={translate('office_staff', 'page_title')}
            hub={hub}
            action={buttons.length > 0 ? (
                <div className="ops-row is-wrap">
                    {buttons.map((button, index) => <button key={button.key} type="button" className={index === 0 ? 'button dark' : 'button'} onClick={() => setDialog(button.key)}>{button.label}</button>)}
                </div>
            ) : undefined}
        >
            <Figures items={[{ key: 'sales', label: translate('office_staff', 'field_sales_this_month'), value: String(summary.current_month_net_territory_sales ?? '0') }]} />
            <ViewSwitch views={views} active={view} fallback={fallback} label={translate('office_staff', 'views_label')} />

            {view === 'orders' && orders && (
                <>
                    <SectionTableSearch table={orders} />
                    <StaffTable
                        searchable={false}
                        printable
                        totalCount={orders.page.total}
                        rows={orders.page.data}
                        empty={translate('office_staff', 'orders_empty')}
                        onRowClick={capabilities.includes('sr_orders') ? (row: Row) => router.visit(`${commandRoot}/staff/orders/${row.id}`) : undefined}
                        rowClassName={() => (capabilities.includes('sr_orders') ? 'is-link' : undefined)}
                        columns={[
                            { key: 'number', title: translate('office_staff', 'col_order'), render: (row: Row) => <b>{row.number}</b> },
                            { key: 'created_at', title: translate('office_staff', 'col_date'), render: (row: Row) => date(String(row.created_at)) },
                            { key: 'name', title: translate('office_staff', 'col_partner') },
                            { key: 'status', title: translate('office_staff', 'col_status'), render: (row: Row) => <Badge status={String(row.status)} /> },
                            { key: 'total', title: translate('office_staff', 'col_total'), money: true, total: true, render: (row: Row) => money(row.total) },
                        ]}
                    />
                    <Pagination page={orders.page} />
                </>
            )}

            {view === 'targets' && targets && (
                <>
                    <SectionTableSearch table={targets} />
                    <StaffTable
                        searchable={false}
                        printable
                        totalCount={targets.page.total}
                        rows={targets.page.data}
                        empty={translate('office_staff', 'targets_empty')}
                        columns={[
                            { key: 'period_month', title: translate('office_staff', 'col_month'), render: (row: Row) => businessMonth(String(row.period_month)) },
                            { key: 'target', title: translate('office_staff', 'col_target'), money: true, render: (row: Row) => money(row.target) },
                            { key: 'bonus', title: translate('office_staff', 'col_bonus'), money: true, render: (row: Row) => money(row.bonus) },
                            { key: 'version', title: translate('office_staff', 'col_change_number') },
                        ]}
                    />
                    <Pagination page={targets.page} />
                </>
            )}

            {view === 'applications' && applications && <DealerApplicationsView table={applications} />}

            {view === 'areas' && (regions.length > 0 ? (
                <ul className="rx-list">
                    {regions.map((region) => <li key={region.id} className="rx-card">{[region.division, region.district, region.upazila, region.union].filter(Boolean).join(' / ')}</li>)}
                </ul>
            ) : <Empty line title={translate('office_staff', 'no_area_line')} />)}

            {openDialog && (
                <StaffActionDialog
                    action={openDialog.action}
                    onClose={() => setDialog(null)}
                    before={openDialog.key === 'order' || openDialog.key === 'application' ? (
                        <form className="filter-bar" role="search" onSubmit={(event) => { event.preventDefault(); findPartners(); }}>
                            <div className="search-input">
                                <input type="search" aria-label={translate('portal', 'ops_find_partner')} placeholder={translate('office_staff', 'find_partner')} value={partnerWords} onChange={(event) => setPartnerWords(event.target.value)} />
                            </div>
                            <button className="button small">{translate('portal', 'ops_search_partners')}</button>
                        </form>
                    ) : undefined}
                />
            )}
        </OfficePage>
    );
}

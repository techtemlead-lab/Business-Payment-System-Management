import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useT } from '../../../shared/i18n/t';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../../MoneyTable';
import type { MoneyFilter } from '../../MoneyTableFilters';
import { SavedFilterPanel } from '../../OfficeListTools';
import { Badge, Modal, date, label, money, number, type Row } from '../../UI';
import MoreMenu from '../MoreMenu';
import OfficePage from '../OfficePage';
import { AgingPill, channelName, notYetSendable, orderDue, orderItemCount, orderSentCount, orderStatuses, ordersExportHref, type TranslateFn } from '../portalShared';

const CLOSED_STATUSES = ['rejected', 'cancelled'];

export type OrderTableFacts = Omit<MoneyTableData<Row>, 'rows' | 'filters'>;

/** Everything in the address that the file download and a saved filter need, from what the table really used. */
export function chosenOrderFilters(state: OrderTableFacts['state'], url: string): Record<string, string> {
    const inAddress = new URL(url || '/', 'http://orders.local').searchParams;
    const chosen: Record<string, string> = { ...state.filters };
    (['q', 'period', 'from', 'to'] as const).forEach((key) => {
        const value = state[key];
        if (value) {
            chosen[key] = value;
        }
    });
    ['status', 'all_branches'].forEach((key) => {
        const value = inAddress.get(key);
        if (value) {
            chosen[key] = value;
        }
    });
    if (state.sort !== 'created_at' || state.dir !== 'desc') {
        chosen.sort = state.sort;
        chosen.dir = state.dir;
    }

    return chosen;
}

/** Dealer or sub-dealer, and the branch while the top-bar switcher shows every branch. */
export function useOrderFilters(data: Row, chosenBranch: string | undefined): MoneyFilter[] {
    const translate = useT();
    const { workspaceBranches } = usePage<any>().props;
    const branches: Row[] = workspaceBranches?.branches ?? [];
    const showsBranch = branches.length > 1 && (data.branch_scope?.view === 'all' || Boolean(chosenBranch));

    return [
        { key: 'buyer_role', label: translate('office_orders', 'filter_buyer'), options: ['dealer', 'sub_dealer'].map((role) => ({ value: role, label: label(role, translate) })) },
        ...(showsBranch ? [{ key: 'branch_id', label: translate('office_orders', 'filter_branch'), options: branches.map((branch) => ({ value: String(branch.id), label: String(branch.name) })) }] : []),
    ];
}

/** The step a courier parcel is on, in the words of the courier board. */
export function parcelStepLabel(parcel: Row, translate: TranslateFn): string {
    if (['pending', 'booking'].includes(parcel.courier_booking_status)) {
        return translate('courier', 'filter_booking');
    }
    if (['delivered', 'returned'].includes(parcel.status)) {
        return translate('courier', `status_${parcel.status}`);
    }
    if (parcel.courier_booking_status === 'failed') {
        return translate('courier', 'step_booking_failed');
    }

    return translate('courier', `status_${parcel.courier_status ?? 'waiting_pickup'}`);
}

export function OrderNumberCell({ row }: { row: Row }) {
    return <b>{row.number}</b>;
}

export function OrderPartnerCell({ row }: { row: Row }) {
    const translate = useT();

    return <>{row.user?.name ?? '—'}<small className="cell-sub">{[row.user?.dealer_code, row.user?.role ? label(row.user.role, translate) : null].filter(Boolean).join(' · ')}</small></>;
}

export function OrderStatusCell({ row }: { row: Row }) {
    return (
        <>
            <Badge status={row.came_back ? 'returned' : row.status} />
            {CLOSED_STATUSES.includes(row.status) && row.decision_reason && <small className="cell-sub" data-testid="order-closed-reason">{row.decision_reason}</small>}
        </>
    );
}

/** The columns of the office's Orders and Deliveries lists. Overdue parcels also show their age and courier. */
export function orderListColumns(translate: TranslateFn, { overdue = false }: { overdue?: boolean } = {}): MoneyColumn<Row>[] {
    return [
        { key: 'number', title: translate('office_orders', 'col_order'), sortKey: 'number', phone: 'title', render: (row) => <OrderNumberCell row={row} /> },
        { key: 'created_at', title: translate('portal', 'col_date'), sortKey: 'created_at', phone: 'sub', render: (row) => date(row.created_at) },
        { key: 'partner', title: translate('portal', 'col_partner'), sortKey: 'partner', phone: 'sub', render: (row) => <OrderPartnerCell row={row} /> },
        { key: 'phone', title: translate('portal', 'ops_fld_phone'), phone: 'hide', render: (row) => row.user?.phone || '—' },
        { key: 'area', title: translate('portal', 'col_area'), sortKey: 'area', phone: 'hide', render: (row) => [row.user?.district, row.user?.division].filter(Boolean).join(', ') || '—' },
        { key: 'branch', title: translate('portal', 'col_branch'), sortKey: 'branch', phone: 'hide', render: (row) => row.fulfillment_branch?.name || '—' },
        { key: 'items', title: translate('office_orders', 'col_items'), sortKey: 'items', align: 'right', phone: 'hide', render: (row) => number(orderItemCount(row)) },
        { key: 'delivered', title: translate('portal', 'col_delivered'), sortKey: 'delivered', align: 'right', phone: 'hide', render: (row) => (notYetSendable.includes(row.status) ? '—' : <span title={translate('portal', 'col_delivered_hint')}>{number(orderSentCount(row))} / {number(orderItemCount(row))}</span>) },
        {
            key: 'total',
            title: translate('office_orders', 'col_price'),
            sortKey: 'total',
            money: true,
            total: 'value',
            phone: 'amount',
            render: (row) => (
                <>
                    {money(row.total)}
                    {(row.status === 'partly_delivered' || (Number(row.delivered_value) > 0 && ['partially_dispatched', 'dispatched'].includes(String(row.status)))) && <small className="cell-sub">{translate('office_orders', 'delivered_part', { amount: money(row.delivered_value) })}</small>}
                    <span className="orders-status-on-phone">
                        <small className="cell-sub">{translate('office_orders', 'col_items')} {orderItemsText(row, translate)}</small>
                        <OrderStatusCell row={row} />
                    </span>
                </>
            ),
        },
        { key: 'paid', title: translate('office_orders', 'col_given'), sortKey: 'paid', money: true, total: 'paid', phone: 'hide', render: (row) => <>{money(row.paid)}{row.refunded_amount != null && <small className="cell-sub" data-testid="order-refunded">{translate('office_orders', 'refunded_part', { amount: money(row.refunded_amount) })}</small>}</> },
        { key: 'to_pay', title: translate('office_orders', 'col_to_pay'), sortKey: 'to_pay', money: true, total: 'to_pay', phone: 'hide', render: (row) => money(orderDue(row)) },
        ...(overdue ? [
            { key: 'days_since_sent', title: translate('portal', 'col_days_since_sent'), sortKey: 'days_since_sent', align: 'right' as const, phone: 'hide' as const, render: (row: Row) => (row.days_since_sent != null ? <AgingPill days={Number(row.days_since_sent)} /> : '—') },
            { key: 'courier_tracking', title: translate('portal', 'col_courier_tracking'), phone: 'hide' as const, render: (row: Row) => (row.last_courier || row.last_tracking ? <>{row.last_courier || '—'}<small className="cell-sub">{row.last_tracking}</small></> : '—') },
        ] : []),
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'hide', render: (row) => <OrderStatusCell row={row} /> },
    ];
}

/** "3 / 10" sent, or nothing yet for an order that cannot be sent. */
export function orderItemsText(row: Row, translate: TranslateFn): string {
    const total = number(orderItemCount(row));

    return notYetSendable.includes(row.status) ? total : translate('office_orders', 'items_sent', { sent: number(orderSentCount(row)), total });
}

/** The file download, saved filters and the tick boxes, in the one menu beside the table. */
export function OrderFilesMenu({ section, chosen, selecting, onToggleSelecting }: { section: string; chosen: Record<string, string>; selecting: boolean; onToggleSelecting: () => void }) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const caps: string[] = auth.capabilities;
    const mayDownload = caps.includes('reports') || caps.includes('orders') || caps.includes('finance');
    const mayOpenReports = caps.includes('reports') || caps.includes('finance');

    return (
        <span data-tour="invoice-actions">
            <MoreMenu
                label={translate('treasury', 'downloads')}
                icon={<Download size={14} aria-hidden="true" />}
                groups={[{
                    key: 'files',
                    items: [
                        ...(mayDownload ? [{ key: 'excel', label: translate('portal', 'export_csv'), href: ordersExportHref(section, chosen) }] : []),
                        ...(mayOpenReports ? [{ key: 'reports', label: translate('portal', 'excel_pdf_reports'), href: '/operations/financial-reports' }] : []),
                        { key: 'select', label: translate('office_shell', selecting ? 'stop_selecting' : 'select_rows'), onSelect: () => { onToggleSelecting(); } },
                    ],
                }]}
            >
                <SavedFilterPanel listKey={section} section={section} filters={chosen} />
            </MoreMenu>
        </span>
    );
}

/** One order in the side panel: only what its row already holds. Approving, holding and rejecting stay on the order's own page. */
export function OrderDrawer({ order, onClose }: { order: Row; onClose: () => void }) {
    const translate = useT();
    const { portalBase: base, auth, staffActions } = usePage<any>().props;
    const caps: string[] = auth.capabilities;
    const mayPrintLabel = caps.includes('inventory') && (staffActions?.inventory?.edit ?? true) && auth.user.id !== order.user_id && ['approved', 'partially_dispatched', 'dispatched'].includes(order.status);
    const isOwnOrder = auth.user.id === order.user_id;
    const facts: [string, React.ReactNode][] = [
        ...(isOwnOrder ? [] : [
            [translate('portal', 'col_partner'), [order.user?.name, order.user?.dealer_code].filter(Boolean).join(' · ')],
            [translate('portal', 'ops_fld_phone'), order.user?.phone],
            [translate('portal', 'col_area'), [order.user?.district, order.user?.division].filter(Boolean).join(', ')],
        ] as [string, React.ReactNode][]),
        [translate('portal', 'col_date'), order.created_at ? date(order.created_at) : null],
        [translate('portal', 'col_branch'), order.fulfillment_branch?.name],
        [translate('office_orders', 'detail_reason'), CLOSED_STATUSES.includes(order.status) ? order.decision_reason : null],
    ];
    const lines: Row[] = order.lines ?? [];
    const claims: Row[] = order.payment_claims ?? [];
    const parcels: Row[] = order.shipments ?? [];

    return (
        <Modal
            variant="drawer"
            title={String(order.number)}
            badge={<Badge status={order.came_back ? 'returned' : order.status} />}
            onClose={onClose}
            footer={<Link className="button dark" href={`${base}/orders/${order.id}`}>{translate('office_orders', 'open_order')}</Link>}
        >
            <div className="order-drawer">
                <FactList facts={facts} />
                {lines.length > 0 && (
                    <section>
                        <h3>{translate('office_orders', 'col_items')}</h3>
                        <FactList facts={lines.map((line) => [String(line.name), translate('office_orders', 'line_sent', { sent: number(line.dispatched ?? 0), total: number(line.quantity ?? 0) })])} />
                    </section>
                )}
                {claims.length > 0 && (
                    <section>
                        <h3>{translate('office_orders', 'section_payment')}</h3>
                        {claims.map((claim) => (
                            <div key={claim.id} className="order-drawer-row">
                                <div>{channelName(claim.channel, translate)}<small className="cell-sub">{claim.reference}</small></div>
                                <div>{money(claim.amount)}<small className="cell-sub"><Badge status={claim.status} /></small></div>
                            </div>
                        ))}
                    </section>
                )}
                {parcels.length > 0 && (
                    <section>
                        <h3>{translate('courier', 'col_courier')}</h3>
                        {parcels.map((parcel) => (
                            <div key={parcel.id} className="order-drawer-row">
                                <div>{parcel.courier}<small className="cell-sub">{parcel.tracking_url
                                    ? <a className="text-link" href={parcel.tracking_url} target="_blank" rel="noopener noreferrer">{parcel.tracking}</a>
                                    : (parcel.tracking || '—')}</small></div>
                                <div>{parcel.courier_provider || parcel.courier_status ? parcelStepLabel(parcel, translate) : label(parcel.status, translate)}</div>
                            </div>
                        ))}
                    </section>
                )}
                <div className="order-drawer-links">
                    <a className="text-link" href={`/orders/${order.id}/invoice`}>{translate('portal', 'download_invoice')}</a>
                    <a className="text-link" href={`/orders/${order.id}/invoice/print`} target="_blank" rel="noopener noreferrer">{translate('portal', 'print_invoice')}</a>
                    {mayPrintLabel && <a className="text-link" href={`/orders/${order.id}/shipping-label`} target="_blank" rel="noopener noreferrer">{translate('shipping_label', 'print_label')}</a>}
                </div>
            </div>
        </Modal>
    );
}

/** Label and value lines, leaving out anything the row does not have. */
export function FactList({ facts }: { facts: [string, React.ReactNode][] }) {
    return (
        <dl className="office-facts">
            {facts.filter(([, value]) => value !== null && value !== undefined && value !== '').map(([name, value], index) => (
                <div key={`${name}-${index}`}><dt>{name}</dt><dd>{value}</dd></div>
            ))}
        </dl>
    );
}

/** The office's orders: a tab for each status, then one table. Money in the totals row counts delivered goods only. */
export default function OfficeOrders({ data }: { data: Row }) {
    const translate = useT();
    const { url, props } = usePage<any>();
    const { portalBase: base } = props;
    const [opened, setOpened] = useState<Row | null>(null);
    const [selecting, setSelecting] = useState(false);

    const table = data.orders_table as OrderTableFacts;
    const counts = (data.order_status_counts ?? {}) as Record<string, number>;
    const filters = useOrderFilters(data, table.state.filters.branch_id);
    const tableData: MoneyTableData<Row> = { ...table, rows: data.orders, filters };

    const address = new URL(url || '/', 'http://orders.local');
    const activeStatus = address.searchParams.get('status') ?? '';

    const statusHref = (status: string) => {
        const next = new URL(address.href);
        next.searchParams.delete('page');
        next.searchParams.delete('status');
        if (status) {
            next.searchParams.set('status', status);
        }
        const query = next.searchParams.toString();

        return `${base}/orders${query ? `?${query}` : ''}`;
    };

    const everyStatus = [...orderStatuses, ...Object.keys(counts).filter((status) => !orderStatuses.includes(status))];
    const statusTabs = {
        home: 'orders',
        label: translate('portal', 'col_status'),
        tabs: [
            { key: 'all', label: translate('portal', 'orders_tab_all'), href: statusHref(''), active: activeStatus === '', count: Object.values(counts).reduce((sum, count) => sum + Number(count || 0), 0) },
            ...everyStatus.map((status) => ({ key: status, label: label(status, translate), href: statusHref(status), active: activeStatus === status, count: Number(counts[status] ?? 0) })),
        ],
    };

    return (
        <OfficePage title={translate('office_orders', 'orders_title')} hub={statusTabs}>
            <div className="orders-table" data-tour="orders-table">
                <MoneyTable
                    data={tableData}
                    prop="data"
                    columns={orderListColumns(translate)}
                    searchPlaceholder={translate('office_orders', 'search_orders')}
                    bulk={{ kind: 'orders', selecting }}
                    onRowOpen={(row) => setOpened(row)}
                    actions={<OrderFilesMenu section="orders" chosen={chosenOrderFilters(table.state, url)} selecting={selecting} onToggleSelecting={() => setSelecting((current) => !current)} />}
                    empty={translate('portal', 'empty_orders_title')}
                />
            </div>
            <p className="muted" data-testid="order-totals-note">{translate('portal', 'order_totals_note')}</p>
            {opened && <OrderDrawer order={opened} onClose={() => setOpened(null)} />}
        </OfficePage>
    );
}

import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Row, Modal, dateTime, label, number } from './UI';
import { useT } from '../shared/i18n/t';
import MixedOutcomeDecisionList from './MixedOutcomeDecisionList';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import Figures, { type FigureItem } from './office/Figures';
import OfficePage from './office/OfficePage';
import { deliveryStatuses } from './office/portalShared';
import {
    FactList,
    OrderDrawer,
    OrderFilesMenu,
    chosenOrderFilters,
    orderListColumns,
    parcelStepLabel,
    useOrderFilters,
    type OrderTableFacts,
} from './office/sections/OfficeOrders';

type DeliveryView = 'send' | 'parcels' | 'failed' | 'decisions';

/** The office's Deliveries page: orders to send, parcels with a courier and refused bookings, one view at a time. */
export default function CourierBoard({ board }: { board: Row }) {
    const translate = useT();
    const { url, props } = usePage<any>();
    const { portalBase: base } = props;
    const data = props.data as Row;
    const view = (data.delivery_view ?? 'send') as DeliveryView;
    const counts: Record<string, number> = board.counts ?? {};
    const decisions: Row[] = board.needs_decision ?? [];
    const stages: Row = data.parcel_stages ?? {};
    const summary: Row = data.delivery_summary ?? {};

    const viewHref = (next: DeliveryView) => {
        const kept = new URLSearchParams();
        const inAddress = new URL(url || '/', 'http://deliveries.local').searchParams;
        ['branch_id', 'all_branches'].forEach((key) => {
            const value = inAddress.get(key);
            if (value) {
                kept.set(key, value);
            }
        });
        if (next !== 'send') {
            kept.set('view', next);
        }
        const query = kept.toString();

        return `${base}/deliveries${query ? `?${query}` : ''}`;
    };

    const viewTab = (key: DeliveryView, word: string, count: number) => ({ key, label: translate('office_orders', word), href: viewHref(key), active: view === key, count });
    const viewTabs = {
        home: 'deliveries',
        label: translate('office_orders', 'deliveries_title'),
        tabs: [
            viewTab('send', 'view_send', Number(summary.orders ?? 0)),
            viewTab('parcels', 'view_parcels', Number(stages.with_courier ?? 0)),
            viewTab('failed', 'view_failed', Number(counts.failed ?? 0)),
            ...(decisions.length > 0 || view === 'decisions' ? [viewTab('decisions', 'view_decisions', decisions.length)] : []),
        ],
    };

    const sendFigures: FigureItem[] = [
        { key: 'orders', label: translate('portal', 'deliv_sum_orders'), value: number(summary.orders ?? 0), text: true },
        { key: 'units', label: translate('portal', 'deliv_sum_units'), value: number(summary.units ?? 0), text: true },
        { key: 'oldest', label: translate('portal', 'deliv_sum_oldest'), value: translate('portal', 'days_count', { n: number(summary.oldest_days ?? 0) }), text: true },
    ];
    const parcelFigures: FigureItem[] = [
        { key: 'with_courier', label: translate('office_orders', 'stage_with_courier'), value: number(stages.with_courier ?? 0), text: true },
        { key: 'waiting_pickup', label: translate('courier', 'status_waiting_pickup'), value: number(stages.waiting_pickup ?? 0), text: true },
        { key: 'delivered', label: translate('courier', 'status_delivered'), value: number(stages.delivered ?? 0), text: true },
        { key: 'returned', label: translate('courier', 'status_returned'), value: number(stages.returned ?? 0), text: true },
    ];
    const figures = view === 'send' ? sendFigures : view === 'parcels' ? parcelFigures : null;

    return (
        <OfficePage title={translate('office_orders', 'deliveries_title')} hub={viewTabs}>
            {figures && <Figures items={figures} />}
            {view === 'send' && <OrdersToSend data={data} />}
            {(view === 'parcels' || view === 'failed') && <ParcelTable key={view} table={data.parcels_table} failedOnly={view === 'failed'} />}
            {view === 'decisions' && (decisions.length > 0
                ? <MixedOutcomeDecisionList rows={decisions} canDecide={Boolean(board.can_decide)} orderLink={(row: Row) => <Link className="text-link" href={`${base}/orders/${row.order_id}`}>{row.number}</Link>} />
                : <p className="mt-empty">{translate('courier_more', 'decision_empty')}</p>)}
        </OfficePage>
    );
}

/** Orders with goods still to send. Their money is not counted yet, so the totals row is only a count. */
function OrdersToSend({ data }: { data: Row }) {
    const translate = useT();
    const { url } = usePage<any>();
    const [opened, setOpened] = useState<Row | null>(null);
    const [selecting, setSelecting] = useState(false);
    const table = data.orders_table as OrderTableFacts;
    const shared = useOrderFilters(data, table.state.filters.branch_id);
    const tableData: MoneyTableData<Row> = {
        ...table,
        rows: data.orders,
        filters: [{ key: 'status', label: translate('portal', 'col_status'), options: deliveryStatuses.map((status) => ({ value: status, label: label(status, translate) })) }, ...shared],
    };

    const columns = orderListColumns(translate, { overdue: table.state.filters.status === 'overdue_delivery' });

    return (
        <>
            <div className="orders-table" data-tour="orders-table">
                <MoneyTable
                    data={tableData}
                    prop="data"
                    columns={columns}
                    searchPlaceholder={translate('office_orders', 'search_orders')}
                    bulk={{ kind: 'orders', selecting }}
                    onRowOpen={(row) => setOpened(row)}
                    actions={<OrderFilesMenu section="deliveries" chosen={chosenOrderFilters(table.state, url)} selecting={selecting} onToggleSelecting={() => setSelecting((current) => !current)} />}
                    empty={translate('portal', 'empty_deliveries_title')}
                />
            </div>
            {opened && <OrderDrawer order={opened} onClose={() => setOpened(null)} />}
        </>
    );
}

/** Every parcel booked with a courier, or only the bookings the courier refused. */
function ParcelTable({ table, failedOnly }: { table: MoneyTableData<Row>; failedOnly: boolean }) {
    const translate = useT();
    const [opened, setOpened] = useState<Row | null>(null);

    const columns: MoneyColumn<Row>[] = [
        { key: 'number', title: translate('courier', 'col_order'), sortKey: 'number', phone: 'title', render: (row) => <b>{row.number}</b> },
        { key: 'partner', title: translate('courier', 'col_partner'), sortKey: 'partner', phone: 'sub', render: (row) => <>{row.partner}<small className="cell-sub">{row.dealer_code}</small></> },
        { key: 'phone', title: translate('portal', 'ops_fld_phone'), phone: 'hide', render: (row) => row.phone || '—' },
        { key: 'courier', title: translate('courier', 'col_courier'), sortKey: 'courier', phone: 'sub' },
        failedOnly
            ? { key: 'courier_booking_error', title: translate('courier', 'col_problem'), phone: 'sub' }
            : {
                key: 'tracking',
                title: translate('courier', 'col_tracking'),
                phone: 'sub',
                render: (row) => (row.tracking_url
                    ? <a className="text-link" href={row.tracking_url} target="_blank" rel="noopener noreferrer">{row.tracking}</a>
                    : (row.tracking || '—')),
            },
        { key: 'step', title: translate('courier', 'col_status'), sortKey: 'step', phone: 'amount', render: (row) => parcelStepLabel(row, translate) },
        { key: 'updated_at', title: translate('courier', 'col_updated'), sortKey: 'updated_at', phone: 'hide', render: (row) => dateTime(row.courier_status_at ?? row.updated_at) },
    ];

    return (
        <>
            <div className="orders-table" data-testid={failedOnly ? 'courier-failed' : 'courier-board'}>
                <MoneyTable
                    data={table}
                    prop="data"
                    columns={columns}
                    searchPlaceholder={translate('office_orders', 'search_parcels')}
                    onRowOpen={(row) => setOpened(row)}
                    empty={translate('courier', failedOnly ? 'failed_empty' : 'board_empty')}
                />
            </div>
            {opened && <ParcelDrawer parcel={opened} onClose={() => setOpened(null)} />}
        </>
    );
}

/** One parcel in the side panel. Retrying a booking and marking it delivered stay on the order's own page. */
function ParcelDrawer({ parcel, onClose }: { parcel: Row; onClose: () => void }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const timeline: Row[] = Array.isArray(parcel.courier_timeline) ? parcel.courier_timeline : [];
    const facts: [string, React.ReactNode][] = [
        [translate('courier', 'col_partner'), [parcel.partner, parcel.dealer_code].filter(Boolean).join(' · ')],
        [translate('portal', 'ops_fld_phone'), parcel.phone],
        [translate('courier', 'col_courier'), parcel.courier],
        [translate('courier', 'col_tracking'), parcel.tracking_url
            ? <a className="text-link" href={parcel.tracking_url} target="_blank" rel="noopener noreferrer">{parcel.tracking}</a>
            : parcel.tracking],
        [translate('courier', 'col_problem'), parcel.courier_booking_error],
    ];

    return (
        <Modal
            variant="drawer"
            title={String(parcel.number)}
            badge={parcelStepLabel(parcel, translate)}
            onClose={onClose}
            footer={<Link className="button dark" href={`${base}/orders/${parcel.order_id}`}>{translate('office_orders', 'open_order')}</Link>}
        >
            <div className="order-drawer">
                <FactList facts={facts} />
                {timeline.length > 0 && (
                    <section>
                        <h3>{translate('office_orders', 'section_history')}</h3>
                        <FactList facts={timeline.map((entry) => [translate('courier', `status_${entry.status}`), dateTime(entry.at)])} />
                    </section>
                )}
            </div>
        </Modal>
    );
}

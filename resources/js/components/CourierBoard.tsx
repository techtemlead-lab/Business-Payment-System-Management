import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Row, Table, Pagination, dateTime } from './UI';
import { useT } from '../shared/i18n/t';

const STEPS = ['waiting_pickup', 'picked_up', 'in_transit', 'delivered', 'returned'];

/** Staff deliveries board: every courier parcel, filtered by step and courier, plus refused bookings. */
export default function CourierBoard({ board }: { board: Row }) {
    const translate = useT();
    const { portalBase: base = '' } = usePage<any>().props;
    const [status, setStatus] = useState<string>(board.filters?.status ?? '');
    const [courier, setCourier] = useState<string>(board.filters?.courier ?? '');
    const counts: Record<string, number> = board.counts ?? {};
    const failed: Row[] = board.failed ?? [];
    const stepLabel = (row: Row) => {
        if (['pending', 'booking'].includes(row.courier_booking_status)) {
            return translate('courier', 'filter_booking');
        }
        if (row.courier_booking_status === 'failed') {
            return translate('courier', 'failed_title');
        }
        return translate('courier', `status_${row.courier_status ?? 'waiting_pickup'}`);
    };
    const apply = (event: React.FormEvent) => {
        event.preventDefault();
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        delete params.parcels_page;
        router.get(window.location.pathname, { ...params, ship_status: status || undefined, ship_courier: courier || undefined }, { preserveScroll: true, preserveState: true });
    };
    const orderLink = (row: Row) => <Link className="text-link" href={`${base}/orders/${row.order_id}`}>{row.number}</Link>;

    return <>
        <section className="panel table-panel" data-testid="courier-failed">
            <div className="panel-heading"><h2>{translate('courier', 'failed_title')}</h2><span>{translate('courier', 'failed_hint')}</span></div>
            <Table rows={failed} searchable={false} tools={false} emptyMessage={translate('courier', 'failed_empty')} columns={[
                { key: 'number', title: translate('courier', 'col_order'), render: orderLink },
                { key: 'partner', title: translate('courier', 'col_partner') },
                { key: 'courier', title: translate('courier', 'col_courier') },
                { key: 'courier_booking_error', title: translate('courier', 'col_problem') },
                { key: 'updated_at', title: translate('courier', 'col_updated'), render: (row: Row) => dateTime(row.updated_at) },
            ]} />
        </section>
        <section className="panel table-panel" data-testid="courier-board">
            <div className="panel-heading"><h2>{translate('courier', 'board_title')}</h2><span>{translate('courier', 'board_hint')}</span></div>
            <form className="ops-row is-wrap" onSubmit={apply}>
                <label>{translate('courier', 'filter_status')}
                    <select value={status} onChange={event => setStatus(event.target.value)}>
                        <option value="">{translate('courier', 'filter_all')}</option>
                        <option value="booking">{translate('courier', 'filter_booking')}{counts.booking ? ` (${counts.booking})` : ''}</option>
                        {STEPS.map(step => <option key={step} value={step}>{translate('courier', `status_${step}`)}{counts[step] ? ` (${counts[step]})` : ''}</option>)}
                    </select>
                </label>
                <label>{translate('courier', 'filter_courier')}
                    <select value={courier} onChange={event => setCourier(event.target.value)}>
                        <option value="">{translate('courier', 'filter_all')}</option>
                        {(board.couriers ?? []).map((option: Row) => <option key={option.key} value={option.key}>{option.label}</option>)}
                    </select>
                </label>
                <button className="button small">{translate('courier', 'filter_apply')}</button>
            </form>
            <Table rows={board.parcels?.data ?? []} searchable={false} tools={false} emptyMessage={translate('courier', 'board_empty')} columns={[
                { key: 'number', title: translate('courier', 'col_order'), render: orderLink },
                { key: 'partner', title: translate('courier', 'col_partner') },
                { key: 'courier', title: translate('courier', 'col_courier') },
                { key: 'tracking', title: translate('courier', 'col_tracking'), render: (row: Row) => row.tracking_url
                    ? <a className="text-link" href={row.tracking_url} target="_blank" rel="noopener noreferrer">{row.tracking}</a>
                    : (row.tracking || '—') },
                { key: 'courier_status', title: translate('courier', 'col_status'), render: stepLabel },
                { key: 'updated_at', title: translate('courier', 'col_updated'), render: (row: Row) => dateTime(row.courier_status_at ?? row.updated_at) },
            ]} />
            <Pagination page={board.parcels} />
        </section>
    </>;
}

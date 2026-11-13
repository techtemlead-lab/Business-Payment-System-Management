import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionForm, Modal, Table, date, number, type Row } from './UI';
import { useT } from '../shared/i18n/t';
import StockLotHoldTab from './StockLotHoldTab';
import StockLotDetailsTab from './StockLotDetailsTab';

const TABS = [
    { key: 'near', label: 'tab_near_expiry' },
    { key: 'recall', label: 'tab_recall' },
    { key: 'transfers', label: 'tab_transfers' },
    { key: 'reorder', label: 'tab_reorder' },
    { key: 'hold', label: 'tab_hold' },
    { key: 'details', label: 'tab_missing_details' },
];

function NearExpiry({ data }: { data: Row }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('stock_lots', key, replace);
    const columns = [
        { key: 'product', title: t('col_product') },
        { key: 'branch_code', title: t('col_branch') },
        { key: 'batch_number', title: t('col_batch'), render: (row: Row) => <>{row.batch_number || '—'}{row.on_hold && <small className="cell-sub">{t('on_hold_badge')}</small>}</> },
        { key: 'expires_on', title: t('col_expiry'), render: (row: Row) => date(row.expires_on) },
        { key: 'days_left', title: t('col_days_left'), align: 'right' as const, render: (row: Row) => number(row.days_left) },
        { key: 'quantity', title: t('col_quantity'), align: 'right' as const, render: (row: Row) => number(row.quantity) },
        { key: 'received_on', title: t('col_received'), render: (row: Row) => date(row.received_on) },
    ];
    const choices = (data.day_choices ?? []).map((days: number) => ({ value: String(days), label: t('days_option', { days: String(days) }) }));
    const [askingDays, setAskingDays] = useState(false);
    const [showingExpired, setShowingExpired] = useState(false);

    return (
        <section className="panel">
            <div className="os-tab-actions">
                <div className="preset-chips" role="group">
                    {(data.day_choices ?? []).map((days: number) => (
                        <button key={days} type="button" className={`button small ${!showingExpired && data.days === days ? 'dark' : ''}`} onClick={() => { setShowingExpired(false); router.get('/stock-lots', { tab: 'near', days }, { preserveState: true, replace: true }); }}>
                            {t('days_option', { days: String(days) })}
                        </button>
                    ))}
                    <button type="button" className={`button small ${showingExpired ? 'dark' : ''}`} onClick={() => setShowingExpired(true)}>{translate('office_stock', 'already_expired')}</button>
                </div>
                <button type="button" className="button small" onClick={() => setAskingDays(true)}>{translate('office_stock', 'warning_days_button')}</button>
            </div>
            {showingExpired
                ? <Table rows={data.expiry?.expired ?? []} columns={columns.filter((column) => column.key !== 'days_left')} tools={false} empty={t('none_expired')} />
                : <Table rows={data.expiry?.near ?? []} columns={columns} tools={false} empty={t('none_near')} />}
            {askingDays && (
                <Modal title={translate('office_stock', 'warning_days_title')} onClose={() => setAskingDays(false)}>
                    <ActionForm action="/commands/stock-lots/near-expiry-days" defaults={{ days: String(data.saved_days) }} fields={[{ name: 'days', label: t('near_expiry_window'), required: true, options: choices }]} submit={t('save_window')} onDone={() => setAskingDays(false)} />
                </Modal>
            )}
        </section>
    );
}

function Recall({ data }: { data: Row }) {
    const translate = useT();
    const t = (key: string) => translate('stock_lots', key);
    const recall = data.recall ?? {};
    const [batch, setBatch] = useState<string>(recall.batch ?? '');
    const nothing = recall.batch && !recall.orders?.length && !recall.clinic?.length && !recall.in_stock?.length;

    return (
        <section className="panel">
            <p className="muted">{t('recall_intro')}</p>
            <form className="filter-bar" onSubmit={(event) => { event.preventDefault(); router.get('/stock-lots', { tab: 'recall', batch }, { preserveState: true, replace: true }); }}>
                <label>{t('recall_label')}<input value={batch} maxLength={60} required onChange={(event) => setBatch(event.target.value)} /></label>
                <button type="submit" className="button dark">{t('recall_search')}</button>
            </form>
            {nothing && <p className="muted" role="status">{t('none_recall')}</p>}
            {recall.orders?.length > 0 && <>
                <h3>{t('recall_orders')}</h3>
                <Table rows={recall.orders} tools={false} columns={[
                    { key: 'order_number', title: t('col_order') },
                    { key: 'partner_name', title: t('col_partner'), render: (row: Row) => `${row.partner_name} · ${row.dealer_code ?? '—'}` },
                    { key: 'product', title: t('col_product') },
                    { key: 'branch_code', title: t('col_branch') },
                    { key: 'expires_on', title: t('col_expiry'), render: (row: Row) => row.expires_on ? date(row.expires_on) : '—' },
                    { key: 'sent', title: t('col_quantity'), align: 'right', render: (row: Row) => number(row.sent) },
                    { key: 'returned', title: t('col_returned'), align: 'right', render: (row: Row) => number(row.returned) },
                    { key: 'sent_at', title: t('col_sent_on'), render: (row: Row) => date(row.sent_at) },
                ]} />
            </>}
            {recall.clinic?.length > 0 && <>
                <h3>{t('recall_clinic')}</h3>
                <Table rows={recall.clinic} tools={false} columns={[
                    { key: 'prescription_number', title: t('col_prescription') },
                    { key: 'product', title: t('col_product') },
                    { key: 'branch_code', title: t('col_branch') },
                    { key: 'quantity', title: t('col_quantity'), align: 'right', render: (row: Row) => number(row.quantity) },
                    { key: 'sent_at', title: t('col_sent_on'), render: (row: Row) => date(row.sent_at) },
                ]} />
            </>}
            {recall.in_stock?.length > 0 && <>
                <h3>{t('recall_stock')}</h3>
                <Table rows={recall.in_stock} tools={false} columns={[
                    { key: 'product', title: t('col_product') },
                    { key: 'branch_code', title: t('col_branch') },
                    { key: 'expires_on', title: t('col_expiry'), render: (row: Row) => row.expires_on ? date(row.expires_on) : '—' },
                    { key: 'quantity', title: t('col_quantity'), align: 'right', render: (row: Row) => <>{number(row.quantity)}{row.on_hold && <small className="cell-sub">{t('on_hold_badge')}</small>}</> },
                ]} />
            </>}
        </section>
    );
}

function Transfers({ data }: { data: Row }) {
    const translate = useT();
    const t = (key: string) => translate('stock_lots', key);
    const [askingTransfer, setAskingTransfer] = useState(false);

    return (
        <>
            <div className="os-tab-actions">
                <span />
                <button type="button" className="button small dark" onClick={() => setAskingTransfer(true)}>{translate('office_stock', 'new_transfer_button')}</button>
            </div>
            {askingTransfer && (
                <Modal title={t('transfer_new')} onClose={() => setAskingTransfer(false)}>
                    <ActionForm action="/commands/stock-transfers" submit={t('transfer_request')} onDone={() => setAskingTransfer(false)} fields={[
                        { name: 'product_id', label: t('col_product'), required: true, search: true, options: data.product_options ?? [] },
                        { name: 'source_branch_id', label: t('col_from'), required: true, search: true, options: data.branch_options ?? [] },
                        { name: 'destination_branch_id', label: t('col_to'), required: true, search: true, options: data.branch_options ?? [] },
                        { name: 'quantity', label: t('col_quantity'), type: 'number', required: true },
                        { name: 'note', label: t('transfer_note'), place: 'stock.transfer_note' },
                    ]} />
                </Modal>
            )}
            <section className="panel">
                <Table rows={data.transfers ?? []} tools={false} empty={t('none_transfers')} columns={[
                    { key: 'number', title: t('col_number') },
                    { key: 'product', title: t('col_product') },
                    { key: 'source', title: t('col_from') },
                    { key: 'destination', title: t('col_to') },
                    { key: 'quantity', title: t('col_quantity'), align: 'right', render: (row: Row) => number(row.quantity) },
                    { key: 'status', title: t('col_status'), render: (row: Row) => <span className="badge">{t(`status_${row.status}`)}</span> },
                    { key: 'action', title: t('col_action'), render: (row: Row) => <div className="ops-row is-wrap">
                        {row.status === 'requested' && <ActionForm action={`/commands/stock-transfers/${row.id}/send`} fields={[]} submit={t('transfer_send')} />}
                        {row.status === 'requested' && <ActionForm action={`/commands/stock-transfers/${row.id}/cancel`} fields={[]} submit={t('transfer_cancel')} />}
                        {row.status === 'in_transit' && <ActionForm action={`/commands/stock-transfers/${row.id}/receive`} fields={[]} submit={t('transfer_receive')} />}
                    </div> },
                ]} />
            </section>
        </>
    );
}

function Reorder({ data }: { data: Row }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('stock_lots', key, replace);

    return (
        <section className="panel">
            <p className="muted">{t('reorder_intro', { days: String(data.cover_days), window: String(data.average_days) })}</p>
            <Table rows={data.reorder ?? []} tools={false} empty={t('none_reorder')} columns={[
                { key: 'product', title: t('col_product') },
                { key: 'branch_code', title: t('col_branch') },
                { key: 'quantity', title: t('col_on_hand'), align: 'right', render: (row: Row) => number(row.quantity) },
                { key: 'low_stock_at', title: t('col_minimum'), align: 'right', render: (row: Row) => number(row.low_stock_at) },
                { key: 'average_daily', title: t('col_average'), align: 'right' },
                { key: 'incoming', title: t('col_incoming'), align: 'right', render: (row: Row) => number(row.incoming) },
                { key: 'suggested', title: t('col_suggested'), align: 'right', render: (row: Row) => <b>{number(row.suggested)}</b> },
            ]} />
        </section>
    );
}

export default function StockLotsHub({ data }: { data: Row }) {
    const translate = useT();

    return (
        <div data-tour="stock-lots-hub">
            <div className="preset-chips" role="tablist">
                {TABS.map((tab) => (
                    <button key={tab.key} type="button" role="tab" aria-selected={data.tab === tab.key} className={`button small ${data.tab === tab.key ? 'dark' : ''}`} onClick={() => router.get('/stock-lots', { tab: tab.key })}>
                        {translate('stock_lots', tab.label)}{tab.key === 'details' && data.missing_details_count > 0 ? ` (${data.missing_details_count})` : ''}
                    </button>
                ))}
            </div>
            {data.tab !== 'details' && data.missing_details_count > 0 && <p className="notice" role="status">{translate('stock_lots', 'missing_details_notice', { count: String(data.missing_details_count) })} <button type="button" className="button small" onClick={() => router.get('/stock-lots', { tab: 'details' })}>{translate('stock_lots', 'fill_in_action')}</button></p>}
            {data.tab === 'near' && <NearExpiry data={data} />}
            {data.tab === 'recall' && <Recall data={data} />}
            {data.tab === 'transfers' && <Transfers data={data} />}
            {data.tab === 'reorder' && <Reorder data={data} />}
            {data.tab === 'hold' && <StockLotHoldTab data={data} />}
            {data.tab === 'details' && <StockLotDetailsTab data={data} />}
        </div>
    );
}

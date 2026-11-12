import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ActionForm, Modal, Table, date, number, type Field, type Row } from './UI';
import { actionAllowed } from './action-permissions';
import { useT } from '../shared/i18n/t';

/** Lots a stock count or goods receipt recorded without a batch number or expiry date, for staff to fill in. */
export default function StockLotDetailsTab({ data }: { data: Row }) {
    const translate = useT();
    const t = (key: string) => translate('stock_lots', key);
    const { staffActions } = usePage<any>().props;
    const canChange = actionAllowed('/commands/stock-lots/0/details', staffActions, 'post');
    const [filling, setFilling] = useState<Row | null>(null);
    const fields: Field[] = filling ? [
        ...(filling.batch_number ? [] : [{ name: 'batch_number', label: t('col_batch') }]),
        ...(filling.expires_on ? [] : [{ name: 'expires_on', label: t('col_expiry'), type: 'date' }]),
    ] : [];

    return (
        <section className="panel">
            <p className="muted">{t('missing_details_intro')}</p>
            <Table rows={data.missing_details ?? []} tools={false} empty={t('none_missing_details')} columns={[
                { key: 'product', title: t('col_product') },
                { key: 'branch_code', title: t('col_branch') },
                { key: 'batch_number', title: t('col_batch'), render: (row: Row) => row.batch_number || <span className="badge">{t('missing_badge')}</span> },
                // The expiry date is optional, so a blank one is a dash and not something waiting to be done.
                { key: 'expires_on', title: t('col_expiry'), render: (row: Row) => date(row.expires_on) },
                { key: 'quantity', title: t('col_quantity'), align: 'right', render: (row: Row) => number(row.quantity) },
                { key: 'received_on', title: t('col_received'), render: (row: Row) => date(row.received_on) },
                ...(canChange ? [{ key: 'action', title: t('col_action'), render: (row: Row) => <button type="button" className="button small" onClick={() => setFilling(row)}>{t('fill_in_action')}</button> }] : []),
            ]} />
            {filling && (
                <Modal title={t('fill_in_action')} onClose={() => setFilling(null)}>
                    <p><b>{filling.product}</b> · {filling.branch_code} · {number(filling.quantity)}</p>
                    <ActionForm action={`/commands/stock-lots/${filling.id}/details`} fields={fields} submit={t('save_window')} onDone={() => setFilling(null)} />
                </Modal>
            )}
        </section>
    );
}

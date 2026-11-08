import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionForm, Modal, Table, date, number, type Row } from './UI';
import { actionAllowed } from './action-permissions';
import { useT } from '../shared/i18n/t';

type LotAction = 'hold' | 'release' | 'write-off';

const TITLES: Record<LotAction, string> = { hold: 'hold_title', release: 'release_title', 'write-off': 'write_off_title' };
const SUBMITS: Record<LotAction, string> = { hold: 'hold_action', release: 'release_action', 'write-off': 'write_off_action' };

/** Quarantine: batches on hold, what to do with them, and a search to put another batch on hold. */
export default function StockLotHoldTab({ data }: { data: Row }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('stock_lots', key, replace);
    const { staffActions } = usePage<any>().props;
    const canChange = actionAllowed('/commands/stock-lots/0/hold', staffActions, 'post');
    const [search, setSearch] = useState<string>(data.hold_search ?? '');
    const [pending, setPending] = useState<{ row: Row; action: LotAction } | null>(null);
    const lotColumns = [
        { key: 'product', title: t('col_product') },
        { key: 'branch_code', title: t('col_branch') },
        { key: 'batch_number', title: t('col_batch'), render: (row: Row) => row.batch_number || '—' },
        { key: 'expires_on', title: t('col_expiry'), render: (row: Row) => row.expires_on ? date(row.expires_on) : '—' },
        { key: 'quantity', title: t('col_quantity'), align: 'right' as const, render: (row: Row) => number(row.quantity) },
    ];
    const button = (row: Row, action: LotAction) => (
        <button type="button" className="button small" onClick={() => setPending({ row, action })}>{t(SUBMITS[action])}</button>
    );

    return (
        <>
            <section className="panel">
                <p className="muted">{t('hold_intro')}</p>
                <h3>{t('held_heading')}</h3>
                <Table rows={data.held ?? []} tools={false} empty={t('none_held')} columns={[
                    ...lotColumns,
                    { key: 'hold_reason', title: t('col_hold_reason') },
                    { key: 'held_by', title: t('col_held_by'), render: (row: Row) => row.held_by || '—' },
                    { key: 'held_at', title: t('col_held_on'), render: (row: Row) => date(row.held_at) },
                    ...(canChange ? [{ key: 'action', title: t('col_action'), render: (row: Row) => <div className="ops-row is-wrap">{button(row, 'release')}{button(row, 'write-off')}</div> }] : []),
                ]} />
            </section>
            <section className="panel">
                <h3>{t('hold_find_heading')}</h3>
                <form className="filter-bar" onSubmit={(event) => { event.preventDefault(); router.get('/stock-lots', { tab: 'hold', q: search }, { preserveState: true, replace: true }); }}>
                    <label>{t('hold_search_label')}<input value={search} maxLength={120} onChange={(event) => setSearch(event.target.value)} /></label>
                    <button type="submit" className="button dark">{t('hold_search_button')}</button>
                </form>
                <Table rows={data.hold_candidates ?? []} tools={false} empty={t('none_candidates')} columns={[
                    ...lotColumns,
                    ...(canChange ? [{ key: 'action', title: t('col_action'), render: (row: Row) => button(row, 'hold') }] : []),
                ]} />
            </section>
            {pending && (
                <Modal title={t(TITLES[pending.action], { batch: pending.row.batch_number || t('no_batch') })} onClose={() => setPending(null)}>
                    <p><b>{pending.row.product}</b> · {pending.row.branch_code} · {number(pending.row.quantity)}</p>
                    {pending.action === 'write-off' && <p className="notice">{t('write_off_warning', { quantity: String(pending.row.quantity) })}</p>}
                    <ActionForm
                        action={`/commands/stock-lots/${pending.row.id}/${pending.action}`}
                        fields={[{ name: 'reason', label: t('fld_hold_reason'), type: 'textarea', required: true }]}
                        submit={t(SUBMITS[pending.action])}
                        onDone={() => setPending(null)}
                    />
                </Modal>
            )}
        </>
    );
}

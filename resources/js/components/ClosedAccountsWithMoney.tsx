import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Modal, date, money, number } from './UI';
import { useT } from '../shared/i18n/t';
import ClosedAccountBalanceMove from './ClosedAccountBalanceMove';

type HoldingRow = { id: number; name: string; dealer_code: string | null; amount: string; closed_at: string | null };
export type ClosedWithMoney = { count: number; total: string; rows: HoldingRow[] };

/** On the Closed view of the partner list: closed accounts whose wallet still holds money, biggest first. */
export default function ClosedAccountsWithMoney({ summary, role }: { summary?: ClosedWithMoney; role: 'dealer' | 'sub_dealer' }) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const [moving, setMoving] = useState<HoldingRow | null>(null);
    if (!summary || summary.count === 0) {
        return null;
    }
    const isSuperAdmin = String(auth?.user?.role) === 'super_admin';

    return (
        <section className="panel" aria-label={translate('money_core', 'cb_list_title')}>
            <div className="panel-heading">
                <div>
                    <h3>{translate('money_core', 'cb_list_title')}</h3>
                    <p className="muted">
                        {translate('money_core', 'cb_list_line', { count: number(summary.count), total: money(summary.total, { symbol: false }) })}
                        {summary.count > summary.rows.length && <> {translate('money_core', 'cb_list_more', { shown: number(summary.rows.length) })}</>}
                    </p>
                </div>
            </div>
            <ul className="aed-docs">
                {summary.rows.map((row) => (
                    <li key={row.id}>
                        <b>{row.name}</b>{row.dealer_code ? ` · ${row.dealer_code}` : ''} · <strong>{money(row.amount)}</strong>
                        {row.closed_at && <small className="cell-sub">{translate('money_core', 'cb_closed_on')}:{date(row.closed_at)}</small>}
                        {isSuperAdmin && <> <button type="button" className="button small" onClick={() => setMoving(row)}>{translate('money_core', 'cb_button', { amount: money(row.amount, { symbol: false }) })}</button></>}
                    </li>
                ))}
            </ul>
            {moving && (
                <Modal title={`${moving.name}${moving.dealer_code ? ` · ${moving.dealer_code}` : ''}`} onClose={() => setMoving(null)}>
                    <ClosedAccountBalanceMove
                        account={{ id: moving.id, role, closed_at: moving.closed_at ?? 'closed' }}
                        onDone={() => { setMoving(null); router.reload({ only: ['data'] }); }}
                    />
                </Modal>
            )}
        </section>
    );
}

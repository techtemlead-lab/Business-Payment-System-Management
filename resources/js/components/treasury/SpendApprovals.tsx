import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Badge, FormErrors, Modal, date, money, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { useReasonFields } from '../../shared/reasonFields';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import { FUNDS_COMMAND_ROOT, useHeadName } from './FundSpendForm';

export type SpendApproval = Row & { id: number; fund_key: string; amount: string; can_review: boolean; can_cancel?: boolean; can_add_receipt?: boolean; waits_for_owner?: boolean; over_budget?: boolean };

/** Spends waiting for a decision, on the shared table. Everything else opens in a box. */
export default function SpendApprovals({ table }: { table: MoneyTableData<SpendApproval> }) {
    const translate = useT();
    const headName = useHeadName();
    const [viewing, setViewing] = useState<SpendApproval | null>(null);
    const columns: MoneyColumn<SpendApproval>[] = [
        { key: 'spent_on', title: translate('treasury', 'col_date'), sortKey: 'date', phone: 'sub', render: (row) => date(row.spent_on) },
        { key: 'fund', title: translate('treasury', 'col_fund_head'), sortKey: 'fund', phone: 'title', render: (row) => <><b>{translate('treasury', `fund_${row.fund_key}`)}</b><small className="cell-sub">{headName(row)}</small></> },
        { key: 'payee_name', title: translate('treasury', 'col_to'), sortKey: 'payee', phone: 'sub', render: (row) => row.payee_name },
        { key: 'amount', title: translate('treasury', 'col_amount'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (row) => <b>{money(row.amount)}</b> },
        { key: 'maker_name', title: translate('treasury', 'col_recorded_by'), sortKey: 'maker', phone: 'hide', render: (row) => row.maker_name },
        { key: 'view', title: '', phone: 'sub', render: (row) => <button type="button" className="button small" onClick={(event) => { event.stopPropagation(); setViewing(row); }}>{translate('treasury', 'view')}</button> },
    ];

    return (
        <section className="tsy-block">
            <h2>{translate('treasury', 'approvals_title', { count: String(table.totals.count) })}</h2>
            <MoneyTable<SpendApproval> data={table} prop="approvals_table" columns={columns} searchPlaceholder={translate('treasury', 'search')} empty={translate('treasury', 'approvals_empty')} />
            {viewing && <ApprovalBox row={viewing} onClose={() => setViewing(null)} />}
        </section>
    );
}

/** One spend in full, with the decision. The owner's choice says whether a decision needs a reason. */
function ApprovalBox({ row, onClose }: { row: SpendApproval; onClose: () => void }) {
    const translate = useT();
    const headName = useHeadName();
    const reasonOf = useReasonFields();
    const [decision, setDecision] = useState<'approved' | 'rejected' | 'cancel' | null>(null);
    const reviewForm = useForm<Row>({ decision: '', reason: '', receipt: null });
    const cancelForm = useForm<Row>({ reason: '' });
    const facts: Array<[string, React.ReactNode]> = [
        [translate('treasury', 'col_date'), date(row.spent_on)],
        [translate('funds', 'col_fund'), translate('treasury', `fund_${row.fund_key}`)],
        [translate('funds', 'col_head'), headName(row)],
        [translate('funds', 'col_payee'), row.payee_name],
        [translate('funds', 'col_method'), translate('funds', `method_${row.payment_method}`)],
        [translate('funds', 'col_reference'), row.payment_reference || '—'],
        [translate('treasury', 'col_recorded_by'), row.maker_name],
    ];
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (decision === 'cancel') {
            cancelForm.post(`${FUNDS_COMMAND_ROOT}/spends/${row.id}/cancel`, { preserveScroll: true, onSuccess: onClose });

            return;
        }
        reviewForm.transform((current) => ({ ...current, decision }));
        reviewForm.post(`${FUNDS_COMMAND_ROOT}/spends/${row.id}/review`, { preserveScroll: true, onSuccess: onClose });
    };
    const reasonForm = decision === 'cancel' ? cancelForm : reviewForm;
    const why = reasonOf(decision === 'cancel' ? 'money.fund_spend_cancel' : 'money.fund_spend_review');

    return (
        <Modal title={translate('treasury', 'approval_title')} onClose={onClose}>
            <p className="tsy-box-amount">{money(row.amount)}</p>
            <dl className="office-facts">
                {facts.map(([name, value]) => <div key={name}><dt>{name}</dt><dd>{value}</dd></div>)}
                {row.reason && <div><dt>{translate('funds', 'fld_note')}</dt><dd>{row.reason}</dd></div>}
                {row.has_receipt && <div><dt>{translate('treasury', 'col_receipt')}</dt><dd><a className="text-link" href={`/commands/operations/receipts/reserve/${row.id}`}>{translate('treasury', 'view')}</a></dd></div>}
                {row.over_budget && <div><dt>{translate('funds', 'budget_head')}</dt><dd><Badge status="flagged" /></dd></div>}
            </dl>
            {decision ? (
                <form className="action-form" onSubmit={submit}>
                    {why.shown && <label>{why.label(translate('funds', 'fld_reason'))}
                        <textarea required={why.required} minLength={why.required ? 5 : undefined} maxLength={1000} value={String(reasonForm.data.reason ?? '')} onChange={(event) => reasonForm.setData('reason', event.target.value)} />
                    </label>}
                    {decision === 'approved' && row.can_add_receipt && (
                        <label>{translate('self_approval', 'receipt_label')}<input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => reviewForm.setData('receipt', event.target.files?.[0] ?? null)} /></label>
                    )}
                    <FormErrors errors={reasonForm.errors} />
                    <div className="ops-row is-inline">
                        <button type="submit" className="button dark" disabled={reasonForm.processing}>{decision === 'cancel' ? translate('self_approval', 'cancel_spend') : translate('funds', decision === 'approved' ? 'approve' : 'reject')}</button>
                        <button type="button" className="button small" onClick={() => setDecision(null)}>{translate('funds', 'cancel')}</button>
                    </div>
                </form>
            ) : (
                <div className="ops-row is-inline is-wrap">
                    {row.can_review && <button type="button" className="button dark" onClick={() => setDecision('approved')}>{translate('funds', 'approve')}</button>}
                    {row.can_review && <button type="button" className="button small" onClick={() => setDecision('rejected')}>{translate('funds', 'reject')}</button>}
                    {row.can_cancel && <button type="button" className="button small" onClick={() => setDecision('cancel')}>{translate('self_approval', 'cancel_spend')}</button>}
                    {row.waits_for_owner && <span className="muted">{translate('funds', 'owner_approves_hint')}</span>}
                </div>
            )}
        </Modal>
    );
}

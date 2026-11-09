import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Badge, FormErrors, Modal, Table, date, money, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { FUNDS_COMMAND_ROOT, useHeadName } from './FundSpendForm';

export type SpendApproval = Row & { id: number; fund_key: string; amount: string; can_review: boolean; can_cancel?: boolean; can_self_approve?: boolean; over_budget?: boolean };

/** Spends waiting for a second person. One line each; everything else opens in a box. */
export default function SpendApprovals({ approvals }: { approvals: SpendApproval[] }) {
    const translate = useT();
    const headName = useHeadName();
    const [viewing, setViewing] = useState<SpendApproval | null>(null);
    const fundName = (row: Row) => translate('funds', `fund_${row.fund_key}`);

    return (
        <section className="tsy-block">
            <h2>{translate('treasury', 'approvals_title', { count: String(approvals.length) })}</h2>
            <div className="panel table-panel">
                <Table
                    tools={false}
                    rows={approvals}
                    empty={translate('funds', 'approvals_empty_title')}
                    emptyBody=""
                    phoneColumns={['spent_on', 'payee_name', 'amount', 'action']}
                    columns={[
                        { key: 'spent_on', title: translate('treasury', 'col_date'), render: (row: SpendApproval) => date(row.spent_on) },
                        { key: 'head', title: translate('treasury', 'col_fund_head'), render: (row: SpendApproval) => <><b>{fundName(row)}</b><small className="cell-sub">{headName(row)}</small></> },
                        { key: 'payee_name', title: translate('treasury', 'col_to') },
                        { key: 'amount', title: translate('treasury', 'col_amount'), money: true, render: (row: SpendApproval) => <b>{money(row.amount)}</b> },
                        { key: 'maker_name', title: translate('treasury', 'col_recorded_by') },
                        { key: 'action', title: '', render: (row: SpendApproval) => <button type="button" className="button small" onClick={() => setViewing(row)}>{translate('treasury', 'view')}</button> },
                    ]}
                />
            </div>
            {viewing && <ApprovalBox row={viewing} onClose={() => setViewing(null)} />}
        </section>
    );
}

/** One spend in full, with the decision. A decision needs a reason, as before. */
function ApprovalBox({ row, onClose }: { row: SpendApproval; onClose: () => void }) {
    const translate = useT();
    const headName = useHeadName();
    const [decision, setDecision] = useState<'approved' | 'rejected' | 'cancel' | null>(null);
    const reviewForm = useForm<Row>({ decision: '', reason: '', self_approve: false, receipt: null });
    const cancelForm = useForm<Row>({ reason: '' });
    const facts: Array<[string, React.ReactNode]> = [
        [translate('treasury', 'col_date'), date(row.spent_on)],
        [translate('funds', 'col_fund'), translate('funds', `fund_${row.fund_key}`)],
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
    const approveAlone = row.can_self_approve && !row.can_review;

    return (
        <Modal title={translate('treasury', 'approval_title')} onClose={onClose}>
            <p className="tsy-box-amount">{money(row.amount)}</p>
            <dl className="tsy-facts">
                {facts.map(([name, value]) => <div key={name}><dt>{name}</dt><dd>{value}</dd></div>)}
                {row.reason && <div><dt>{translate('funds', 'fld_note')}</dt><dd>{row.reason}</dd></div>}
                {row.has_receipt && <div><dt>{translate('treasury', 'col_receipt')}</dt><dd><a className="text-link" href={`/commands/operations/receipts/reserve/${row.id}`}>{translate('treasury', 'view')}</a></dd></div>}
                {row.over_budget && <div><dt>{translate('funds', 'budget_head')}</dt><dd><Badge status="flagged" /></dd></div>}
            </dl>
            {decision ? (
                <form className="action-form" onSubmit={submit}>
                    <label>{translate('funds', 'fld_reason')}
                        <textarea required minLength={5} maxLength={1000} value={String(reasonForm.data.reason ?? '')} onChange={(event) => reasonForm.setData('reason', event.target.value)} />
                    </label>
                    {decision === 'approved' && row.can_self_approve && !row.has_receipt && (
                        <label>{translate('self_approval', 'receipt_label')}<input type="file" required accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => reviewForm.setData('receipt', event.target.files?.[0] ?? null)} /></label>
                    )}
                    {decision !== 'cancel' && row.can_self_approve && (
                        <label className="import-confirmation"><input type="checkbox" required checked={Boolean(reviewForm.data.self_approve)} onChange={(event) => reviewForm.setData('self_approve', event.target.checked)} /> {translate('self_approval', 'confirm_label')}</label>
                    )}
                    <FormErrors errors={reasonForm.errors} />
                    <div className="ops-row is-inline">
                        <button type="submit" className="button dark" disabled={reasonForm.processing}>{decision === 'cancel' ? translate('self_approval', 'cancel_spend') : translate('funds', decision === 'approved' ? 'approve' : 'reject')}</button>
                        <button type="button" className="button small" onClick={() => setDecision(null)}>{translate('funds', 'cancel')}</button>
                    </div>
                </form>
            ) : (
                <div className="ops-row is-inline is-wrap">
                    {(row.can_review || row.can_self_approve) && <button type="button" className="button dark" onClick={() => setDecision('approved')}>{translate(approveAlone ? 'self_approval' : 'funds', approveAlone ? 'approve_alone' : 'approve')}</button>}
                    {(row.can_review || row.can_self_approve) && <button type="button" className="button small" onClick={() => setDecision('rejected')}>{translate(approveAlone ? 'self_approval' : 'funds', approveAlone ? 'reject_alone' : 'reject')}</button>}
                    {row.can_cancel && <button type="button" className="button small" onClick={() => setDecision('cancel')}>{translate('self_approval', 'cancel_spend')}</button>}
                    {!row.can_review && !row.can_self_approve && !row.can_cancel && <span className="muted">{translate('funds', 'own_spend_hint')}</span>}
                </div>
            )}
        </Modal>
    );
}

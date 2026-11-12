import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { FormErrors, money, date } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { useT } from '../shared/i18n/t';
import { useEscape } from '../shared/useEscape';
import { channelName, methodForChannel, payoutMethods, type PayoutMethod } from '../shared/payoutMethods';

type WithdrawalStatus = 'requested' | 'approved' | 'paid' | 'rejected';

interface WithdrawalRecord {
    id: string;
    payer_state?: 'second_person' | null;
    user_id: number;
    user_name: string;
    dealer_code: string;
    phone: string;
    role?: string | null;
    district?: string | null;
    division?: string | null;
    amount: string | number;
    withheld_amount?: string | number | null;
    net_amount?: string | number | null;
    channel: string;
    destination_masked: string | null;
    status: WithdrawalStatus;
    reason: string | null;
    payment_reference: string | null;
    paid_at: string | null;
    created_at: string;
    payout_receipt_id?: number | null;
    payout_receipt_number?: string | null;
    payout_method?: string | null;
}

/** Totals over every request that matches the search, not only the visible page. */
interface PayoutTotals {
    requested_count: number;
    requested_amount: string | number;
    approved_count: number;
    approved_amount: string | number;
    paid_count: number;
    paid_amount: string | number;
    paid_withheld_amount: string | number;
}

interface PayoutsHubProps {
    data: MoneyTableData<WithdrawalRecord>;
    tdsRate: number;
    totals?: PayoutTotals;
}

const emptyTotals: PayoutTotals = {
    requested_count: 0,
    requested_amount: 0,
    approved_count: 0,
    approved_amount: 0,
    paid_count: 0,
    paid_amount: 0,
    paid_withheld_amount: 0,
};

/** Withdrawal requests on the shared table: search, filters, date view and sorting cover every page. */
function PayoutsTable({ data, rowActions }: { data: MoneyTableData<WithdrawalRecord>; rowActions: (w: WithdrawalRecord) => React.ReactNode }) {
    const translate = useT();
    const columns: MoneyColumn<WithdrawalRecord>[] = [
        { key: 'created_at', title: translate('portal', 'pay_col_date'), sortKey: 'date', phone: 'sub', render: (w) => date(w.created_at) },
        { key: 'user_name', title: translate('portal', 'pay_col_partner'), sortKey: 'partner', phone: 'title', render: (w) => <><b>{w.user_name}</b><small className="cell-sub">{[w.dealer_code, w.phone, w.district, w.division].filter(Boolean).join(' · ')}</small></> },
        { key: 'channel', title: translate('portal', 'pay_col_method'), sortKey: 'method', phone: 'sub', render: (w) => channelName(w.channel, translate) },
        { key: 'destination', title: translate('portal', 'wd_destination'), phone: 'hide', render: (w) => w.destination_masked || '—' },
        { key: 'payment_reference', title: translate('portal', 'col_reference'), sortKey: 'reference', phone: 'hide', render: (w) => <>{w.payment_reference || '—'}{w.paid_at && <small className="cell-sub">{date(w.paid_at)}</small>}</> },
        { key: 'amount', title: translate('portal', 'pay_col_gross'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (w) => <b>{money(w.amount)}</b> },
        { key: 'withheld_amount', title: translate('portal', 'pay_col_withholding'), sortKey: 'withheld', money: true, phone: 'hide', render: (w) => money(w.withheld_amount ?? 0) },
        { key: 'net_amount', title: translate('portal', 'pay_col_net'), sortKey: 'net', money: true, phone: 'hide', render: (w) => money(w.net_amount ?? w.amount) },
        { key: 'status', title: translate('portal', 'pay_col_status'), sortKey: 'status', phone: 'sub', render: (w) => <span className={`badge hub-capital ${w.status}`}>{translate('portal', `pay_status_${w.status}`)}</span> },
        { key: 'actions', title: translate('portal', 'pay_col_actions'), phone: 'sub', render: rowActions },
    ];

    return <MoneyTable<WithdrawalRecord> data={data} prop="data" columns={columns} searchPlaceholder={translate('portal', 'pay_search_ph')} empty={translate('portal', 'pay_empty')} />;
}

export default function PayoutDisbursementHub({ data, tdsRate = 0, totals }: PayoutsHubProps) {
    const translate = useT();
    const { portalBase, data: pageData, staffActions } = usePage<any>().props;
    // Checking and paying a withdrawal needs finance approval on the server; everyone else just reads the list.
    const canProcess = staffActions?.finance?.approve ?? true;
    const summary: PayoutTotals = totals ?? pageData?.payout_totals ?? emptyTotals;
    // Modal states
    const [reviewModalRecord, setReviewModalRecord] = useState<WithdrawalRecord | null>(null);
    const [reviewDecision, setReviewDecision] = useState<'approved' | 'rejected'>('approved');
    const [reviewReason, setReviewReason] = useState('');
    const [processingReview, setProcessingReview] = useState(false);
    // Why the office's last try in the open box was refused; the box stays open so nothing typed is lost.
    const [modalErrors, setModalErrors] = useState<Record<string, string>>({});

    const [settleModalRecord, setSettleModalRecord] = useState<WithdrawalRecord | null>(null);
    useEscape(reviewModalRecord !== null, () => setReviewModalRecord(null));
    useEscape(settleModalRecord !== null, () => setSettleModalRecord(null));
    const [paymentReference, setPaymentReference] = useState('');
    const [paidAt, setPaidAt] = useState(() => new Date().toLocaleString('sv-SE',{timeZone:'Asia/Dhaka'}).slice(0,16).replace(' ','T'));
    const [settleNote, setSettleNote] = useState('');
    const [receiptFile, setReceiptFile] = useState<File | null>(null);
    const [processingSettle, setProcessingSettle] = useState(false);
    const [payoutMethod, setPayoutMethod] = useState<PayoutMethod>('bank');
    const [bankName, setBankName] = useState('');
    const [accountNumber, setAccountNumber] = useState('');
    const [walletNumber, setWalletNumber] = useState('');
    const [receivedBy, setReceivedBy] = useState('');
    const [paymentAccountId, setPaymentAccountId] = useState('');
    const isCash = payoutMethod === 'cash';
    const payoutAccounts: Array<{ id: number; label: string; channel: string; is_active: boolean; pays_out: boolean }> = (pageData?.payment_accounts ?? []).filter((account: { is_active: boolean; pays_out: boolean }) => account.is_active && account.pays_out);

    const handleOpenReview = (record: WithdrawalRecord) => {
        setModalErrors({});
        setReviewModalRecord(record);
        setReviewDecision('approved');
        setReviewReason('');
    };

    const handleSubmitReview = (e: React.FormEvent) => {
        e.preventDefault();
        if (!reviewModalRecord) return;
        setProcessingReview(true);
        router.post(`/commands/withdrawals/${reviewModalRecord.id}/review`, {
            decision: reviewDecision,
            reason: reviewReason || (reviewDecision === 'approved'
                ? translate('portal', 'pay_reason_approved')
                : translate('portal', 'pay_reason_rejected'))
        }, {
            onSuccess: () => setReviewModalRecord(null),
            onError: (errors) => setModalErrors(errors),
            onFinish: () => setProcessingReview(false),
        });
    };

    const handleOpenSettle = (record: WithdrawalRecord) => {
        setModalErrors({});
        setSettleModalRecord(record);
        setPaymentReference('');
        setPaidAt(new Date().toLocaleString('sv-SE',{timeZone:'Asia/Dhaka'}).slice(0,16).replace(' ','T'));
        setSettleNote('');
        setReceiptFile(null);
        setPayoutMethod(methodForChannel(record.channel));
        setBankName('');
        setAccountNumber('');
        setWalletNumber('');
        setReceivedBy('');
        setPaymentAccountId(payoutAccounts.length === 1 ? String(payoutAccounts[0].id) : '');
    };

    const handleSubmitSettle = (e: React.FormEvent) => {
        e.preventDefault();
        if (!settleModalRecord || (!isCash && !receiptFile)) return;

        const formData = new FormData();
        if (receiptFile) formData.append('receipt', receiptFile);
        formData.append('method', payoutMethod);
        if (!isCash && paymentAccountId) formData.append('payment_account_id', paymentAccountId);
        formData.append('payment_reference', paymentReference);
        if (payoutMethod === 'bank' || payoutMethod === 'other') {
            formData.append('bank_name', bankName);
            formData.append('account_number', accountNumber);
        }
        if (payoutMethod === 'bkash' || payoutMethod === 'nagad') formData.append('wallet_number', walletNumber);
        if (isCash) formData.append('received_by', receivedBy);
        formData.append('paid_at', paidAt);
        formData.append('note', settleNote || translate('portal', 'pay_reason_paid'));

        setProcessingSettle(true);
        router.post(`/commands/withdrawals/${settleModalRecord.id}/record-payment`, formData, {
            onSuccess: () => setSettleModalRecord(null),
            onError: (errors) => setModalErrors(errors),
            onFinish: () => setProcessingSettle(false),
        });
    };

    const rowActions = (w: WithdrawalRecord) => (
        <div className="hub-row is-end">
            {w.status === 'requested' && canProcess && (
                <button
                    type="button"
                    onClick={() => handleOpenReview(w)}
                    className="button small dark"
                >
                    {translate('portal', 'pay_review')}
                </button>
            )}

            {w.status === 'approved' && w.payer_state === 'second_person' && (
                <small className="muted">{translate('self_approval', 'wait_second_person')}</small>
            )}

            {w.status === 'approved' && w.payer_state !== 'second_person' && canProcess && (
                <button
                    type="button"
                    onClick={() => handleOpenSettle(w)}
                    className="button small dark"
                >
                    {translate('portal', 'pay_record_payout')}
                </button>
            )}

            {/* The full number to send the money to is only on this page, where opening it is logged. */}
            <a
                href={`/withdrawals/${w.id}`}
                className="text-link"
            >
                {translate('portal', 'pay_details')}
            </a>

            {w.status === 'paid' && w.payout_receipt_id && (
                <a
                    href={`${portalBase ?? ''}/payment-receipts/${w.payout_receipt_id}/print`}
                    target="_blank"
                    rel="noopener"
                    className="button small"
                >
                    {translate('portal', 'prc_view_print')}
                </a>
            )}

            {w.status === 'rejected' && w.reason && (
                <span className="pay-reason" title={w.reason}>
                    {w.reason}
                </span>
            )}
        </div>
    );

    return (
        <div className="hub">
            {/* Top Operational Notice */}
            <div className="panel hub-row is-between">
                <div>
                    <div className="hub-kicker">
                        <span className="hub-pill tone-ok">
                            {translate('portal', 'pay_badge')}
                        </span>
                        <span className="hub-hint">
                            {translate('portal', 'pay_withholding', { rate: tdsRate.toFixed(2) })}
                        </span>
                    </div>
                    <h2 className="hub-title is-lg">
                        {translate('portal', 'pay_heading')}
                    </h2>
                    <p className="hub-sub">
                        {translate('portal', 'pay_heading_hint')}
                    </p>
                </div>
            </div>

            {/* Metrics Overview Cards */}
            <div className="hub-grid">
                <div className="metric tone-warn">
                    <span>{translate('portal', 'pay_kpi_pending')}</span>
                    <strong>
                        {money(summary.requested_amount)}
                    </strong>
                    <small>
                        {translate('portal', 'pay_kpi_pending_hint', { count: String(summary.requested_count) })}
                    </small>
                </div>

                <div className="metric tone-info">
                    <span>{translate('portal', 'pay_kpi_approved')}</span>
                    <strong>
                        {money(summary.approved_amount)}
                    </strong>
                    <small>
                        {translate('portal', 'pay_kpi_approved_hint', { count: String(summary.approved_count) })}
                    </small>
                </div>

                <div className="metric tone-ok">
                    <span>{translate('portal', 'pay_kpi_settled')}</span>
                    <strong>
                        {money(summary.paid_amount)}
                    </strong>
                    <small>
                        {translate('portal', 'pay_kpi_settled_hint', { count: String(summary.paid_count) })}
                    </small>
                </div>

                <div className="metric">
                    <span>{translate('portal', 'pay_kpi_withheld')}</span>
                    <strong>
                        {money(summary.paid_withheld_amount)}
                    </strong>
                    <small>
                        {translate('portal', 'pay_kpi_withheld_hint')}
                    </small>
                </div>
            </div>

            {!canProcess && <div className="notice" role="status"><p>{translate('portal', 'pay_view_only_notice')}</p></div>}

            {canProcess && (
                <div className="toolbar-end">
                    <a className="button small" href="/commands/operations/accounting/payout-batch/export">
                        <Download size={14} aria-hidden="true" /> {translate('treasury', 'payout_file')}
                    </a>
                </div>
            )}

            <PayoutsTable data={data} rowActions={rowActions} />

            {/* Review Modal */}
            {reviewModalRecord && (
                <div className="hub-overlay">
                    <div className="hub-dialog" role="dialog" aria-modal="true">
                        <h3 className="hub-title is-lg">
                            {translate('portal', 'pay_review_title')}
                        </h3>
                        <p className="hub-sub is-lead">
                            {translate('portal', 'pay_review_meta', { id: reviewModalRecord.id.slice(0, 8), name: reviewModalRecord.user_name, code: reviewModalRecord.dealer_code })}
                        </p>

                        <div className="hub-ledger">
                            <div className="hub-ledger-row">
<span>{translate('portal', 'pay_gross_amount')}</span>
                                <b>{money(reviewModalRecord.amount)}</b>
                            </div>
                            <div className="hub-ledger-row tone-danger">
<span>{translate('portal', 'pay_recorded_withholding')}</span>
                                <b>-{money(reviewModalRecord.withheld_amount ?? 0)}</b>
                            </div>
                            <div className="hub-ledger-row is-total tone-ok">
<span>{translate('portal', 'pay_net_approved')}</span>
                                <b>{money(reviewModalRecord.net_amount ?? reviewModalRecord.amount)}</b>
                            </div>
                        </div>

                        <form onSubmit={handleSubmitReview}>
                            <div className="hub-field">
                                <label>
                                    {translate('portal', 'pay_decision')}
                                </label>
                                <div className="hub-row is-loose">
                                    <label className="hub-choice">
                                        <input
                                            type="radio"
                                            name="decision"
                                            value="approved"
                                            checked={reviewDecision === 'approved'}
                                            onChange={() => setReviewDecision('approved')}
                                        />
                                        {translate('portal', 'pay_approve')}
                                    </label>
                                    <label className="hub-choice">
                                        <input
                                            type="radio"
                                            name="decision"
                                            value="rejected"
                                            checked={reviewDecision === 'rejected'}
                                            onChange={() => setReviewDecision('rejected')}
                                        />
                                        {translate('portal', 'pay_reject')}
                                    </label>
                                </div>
                            </div>

                            <div className="hub-field is-last">
                                <label>
                                    {translate('portal', 'pay_verify_note')}
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    value={reviewReason}
                                    onChange={(e) => setReviewReason(e.target.value)}
                                    placeholder={reviewDecision === 'approved' ? translate('portal', 'pay_ph_approve') : translate('portal', 'pay_ph_reject')}
                                />
                            </div>

                            <FormErrors errors={modalErrors} />

                            <div className="hub-row is-end">
                                <button
                                    type="button"
                                    onClick={() => setReviewModalRecord(null)}
                                    className="button"
                                >
                                    {translate('portal', 'pay_cancel')}
                                </button>
                                <button
                                    type="submit"
                                    disabled={processingReview}
                                    className={`button ${reviewDecision === 'approved' ? 'dark' : 'danger'}`}
                                >
                                    {processingReview ? translate('portal', 'pay_submitting') : (reviewDecision === 'approved' ? translate('portal', 'pay_confirm_approval') : translate('portal', 'pay_confirm_rejection'))}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Settlement Modal */}
            {settleModalRecord && (
                <div className="hub-overlay">
                    <div className="hub-dialog" role="dialog" aria-modal="true">
                        <h3 className="hub-title is-lg">
                            {translate('portal', 'pay_settle_title')}
                        </h3>
                        <p className="hub-sub is-lead">
                            {translate('portal', 'pay_settle_meta', { name: settleModalRecord.user_name, channel: channelName(settleModalRecord.channel, translate), amount: money(settleModalRecord.net_amount ?? settleModalRecord.amount) })}
                        </p>

                        <div className="portal-notice portal-notice-info">
                            <span><b>{translate('portal', 'pay_sod')}</b> {translate('portal', 'pay_sod_body')}</span>
                        </div>

                        <form onSubmit={handleSubmitSettle}>
                            <div className="hub-field">
                                <label htmlFor="payout-method">
                                    {translate('portal', 'pay_fld_method')}
                                </label>
                                <select id="payout-method" required value={payoutMethod} onChange={(e) => setPayoutMethod(e.target.value as PayoutMethod)}>
                                    {payoutMethods.map((method) => (
                                        <option key={method} value={method}>{translate('portal', `payout_method_${method}`)}</option>
                                    ))}
                                </select>
                            </div>

                            {!isCash && payoutAccounts.length > 0 && (
                                <div className="hub-field">
                                    <label htmlFor="payout-company-account">{translate('portal', 'wd_payout_account')}</label>
                                    <select id="payout-company-account" required value={paymentAccountId} onChange={(e) => setPaymentAccountId(e.target.value)}>
                                        <option value="">{translate('portal', 'wd_choose_account')}</option>
                                        {payoutAccounts.map((account) => (
                                            <option key={account.id} value={account.id}>{account.label} · {account.channel}</option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            {(payoutMethod === 'bank' || payoutMethod === 'other') && (
                                <>
                                    <div className="hub-field">
                                        <label htmlFor="payout-bank">{translate('portal', payoutMethod === 'bank' ? 'pay_fld_bank_name' : 'pay_fld_provider')}</label>
                                        <input id="payout-bank" type="text" maxLength={100} required={payoutMethod === 'bank'} value={bankName} onChange={(e) => setBankName(e.target.value)} />
                                    </div>
                                    <div className="hub-field">
                                        <label htmlFor="payout-account">{translate('portal', 'pay_fld_account_number')}</label>
                                        <input id="payout-account" type="text" maxLength={60} required={payoutMethod === 'bank'} value={accountNumber} onChange={(e) => setAccountNumber(e.target.value)} />
                                    </div>
                                </>
                            )}

                            {(payoutMethod === 'bkash' || payoutMethod === 'nagad') && (
                                <div className="hub-field">
                                    <label htmlFor="payout-wallet">{translate('portal', 'pay_fld_wallet_number')}</label>
                                    <input id="payout-wallet" type="tel" inputMode="numeric" maxLength={20} required value={walletNumber} onChange={(e) => setWalletNumber(e.target.value)} placeholder="01XXXXXXXXX" />
                                </div>
                            )}

                            {isCash && (
                                <div className="hub-field">
                                    <label htmlFor="payout-received-by">{translate('portal', 'pay_fld_received_by')}</label>
                                    <input id="payout-received-by" type="text" maxLength={100} required value={receivedBy} onChange={(e) => setReceivedBy(e.target.value)} placeholder={translate('portal', 'pay_ph_received_by')} />
                                </div>
                            )}

                            <div className="hub-field">
                                <label>
                                    {translate('portal', isCash ? 'pay_fld_reference_cash' : 'pay_fld_reference')}
                                </label>
                                <input
                                    type="text"
                                    required={!isCash}
                                    placeholder={translate('portal', 'pay_ph_reference')}
                                    value={paymentReference}
                                    onChange={(e) => setPaymentReference(e.target.value)}
                                />
                            </div>

                            <div className="hub-field">
                                <label>
                                    {translate('portal', 'pay_fld_paid_at')}
                                </label>
                                <input
                                    type="datetime-local"
                                    required
                                    value={paidAt}
                                    onChange={(e) => setPaidAt(e.target.value)}
                                />
                            </div>

                            <div className="hub-field">
                                <label>
                                    {translate('portal', isCash ? 'pay_fld_receipt_cash' : 'pay_fld_receipt')}
                                </label>
                                <input
                                    type="file"
                                    required={!isCash}
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    onChange={(e) => setReceiptFile(e.target.files?.[0] || null)}
                                />
                            </div>

                            <div className="hub-field is-last">
                                <label>
                                    {translate('portal', 'pay_fld_notes')}
                                </label>
                                <textarea
                                    required
                                    rows={2}
                                    placeholder={translate('portal', 'pay_ph_notes')}
                                    value={settleNote}
                                    onChange={(e) => setSettleNote(e.target.value)}
                                />
                            </div>

                            <FormErrors errors={modalErrors} />

                            <div className="hub-row is-end">
                                <button
                                    type="button"
                                    onClick={() => setSettleModalRecord(null)}
                                    className="button"
                                >
                                    {translate('portal', 'pay_cancel')}
                                </button>
                                <button
                                    type="submit"
                                    disabled={processingSettle}
                                    className="button dark"
                                >
                                    {processingSettle ? translate('portal', 'pay_recording') : translate('portal', 'pay_record_ledger')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

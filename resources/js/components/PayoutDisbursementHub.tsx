import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { FormErrors, money, date } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { useT } from '../shared/i18n/t';
import { useReasonFields } from '../shared/reasonFields';
import { useEscape } from '../shared/useEscape';
import { channelName, methodForChannel, payoutMethods, type PayoutMethod } from '../shared/payoutMethods';
import WithdrawalFacts, { AmountWords, useWithdrawalFacts } from './payouts/WithdrawalFacts';
import ReceiptPicker from './payouts/ReceiptPicker';
import OfficePayoutList from './payouts/OfficePayoutList';
import { PartnerTypeCell } from './office/portalShared';
import { randomUuid } from '../shared/randomUuid';

type WithdrawalStatus = 'requested' | 'approved' | 'paid' | 'returned' | 'rejected' | 'cancelled';

/** What the viewer may do about paid money that came back (PayoutReturns::state). */
type ReturnState = 'record' | 'confirm' | 'wait_second' | 'wait_owner' | null;

interface WithdrawalRecord {
    id: string;
    payer_state?: 'second_person' | null;
    waits_for_owner?: boolean;
    user_id: number;
    user_name: string;
    dealer_code: string;
    phone: string;
    role?: string | null;
    partner_type?: { title: string | null; place: string | null } | null;
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
    on_hold?: boolean;
    partner_status?: string | null;
    approved_at?: string | null;
    payer_id?: number | null;
    payer_name?: string | null;
    receipt_due?: boolean;
    waiting_days?: number | null;
    overdue?: boolean;
    /** Approved and listed in a file sent to the bank; it may not be rejected until it is paid or marked as not sent. */
    in_bank_file?: boolean;
    bank_file?: string | null;
    /** "Bank did not send": same two-person states as a return (PayoutBatchFiles::notSentState). */
    not_sent_state?: ReturnState;
    not_sent_note?: string | null;
    return_state?: ReturnState;
    return_note?: string | null;
    returned_at?: string | null;
}

/** A file of approved withdrawals sent to the bank (PayoutBatchFiles::recent). */
interface PayoutBatchRow {
    id: string;
    short_id: string;
    month: string;
    channel: string | null;
    row_count: number;
    total_net: string | number;
    waiting: number;
    created_at: string;
    created_by: string | null;
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * The new bank file and the files already sent. A new file marks its rows "in bank file", so the
 * form sends a one-time batch id: pressing twice gives the same file, never a second one.
 */
function BankFiles({ batches }: { batches: PayoutBatchRow[] }) {
    const translate = useT();
    const [batchId, setBatchId] = useState(() => randomUuid());

    const handleSubmit = (e: React.FormEvent) => {
        if (!window.confirm(translate('payouts', 'payout_file_confirm'))) {
            e.preventDefault();
            return;
        }
        // The download does not leave the page: show the rows as "in bank file" and ready a new id for the next file.
        window.setTimeout(() => {
            setBatchId(randomUuid());
            router.reload();
        }, 4000);
    };

    return (
        <div className="panel">
            <div className="hub-row is-between">
                <div>
                    <h3 className="hub-title">{translate('payouts', 'batches_title')}</h3>
                    <p className="hub-hint">{translate('payouts', 'batches_hint')}</p>
                </div>
                <form method="post" action="/commands/operations/accounting/payout-batch/export" onSubmit={handleSubmit}>
                    <input type="hidden" name="_token" value={csrfToken()} />
                    <input type="hidden" name="batch_id" value={batchId} />
                    <button type="submit" className="button small">
                        <Download size={14} aria-hidden="true" /> {translate('treasury', 'payout_file')}
                    </button>
                </form>
            </div>
            {batches.length > 0 && (
                <div className="hub-ledger">
                    {batches.map((batch) => (
                        <div key={batch.id} className="hub-ledger-row">
                            <span>
                                {translate('payouts', 'batch_line', {
                                    batch: batch.short_id,
                                    month: batch.month,
                                    channel: batch.channel ? channelName(batch.channel, translate) : translate('payouts', 'batch_all_channels'),
                                    count: String(batch.row_count),
                                    amount: money(batch.total_net),
                                })}
                                <small className="cell-sub">{date(batch.created_at)}{batch.created_by ? ` · ${batch.created_by}` : ''} · {translate('payouts', 'batch_waiting', { count: String(batch.waiting) })}</small>
                            </span>
                            {batch.waiting > 0 && (
                                <a
                                    className="text-link"
                                    href={`/commands/operations/accounting/payout-batches/${batch.id}/file`}
                                    onClick={(e) => { if (!window.confirm(translate('payouts', 'batch_download_again_confirm'))) e.preventDefault(); }}
                                >
                                    {translate('payouts', 'batch_download_again')}
                                </a>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
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
    overdue_count?: number;
    overdue_amount?: string | number;
    on_hold_count?: number;
    receipt_due_count?: number;
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
        { key: 'user_name', title: translate('portal', 'pay_col_partner'), sortKey: 'partner', phone: 'title', render: (w) => <><b>{w.user_name}</b><small className="cell-sub">{[w.phone, w.district, w.division].filter(Boolean).join(' · ')}</small></> },
        { key: 'code', title: translate('office_orders', 'col_code'), phone: 'hide', render: (w) => w.dealer_code || '—' },
        { key: 'partner_type', title: translate('office_orders', 'col_partner_type'), phone: 'hide', render: (w) => <PartnerTypeCell user={w} /> },
        { key: 'channel', title: translate('portal', 'pay_col_method'), sortKey: 'method', phone: 'sub', render: (w) => channelName(w.channel, translate) },
        { key: 'destination', title: translate('portal', 'wd_destination'), phone: 'hide', render: (w) => w.destination_masked || '—' },
        { key: 'payment_reference', title: translate('portal', 'col_reference'), sortKey: 'reference', phone: 'hide', render: (w) => <>{w.payment_reference || '—'}{w.paid_at && <small className="cell-sub">{date(w.paid_at)}</small>}</> },
        { key: 'amount', title: translate('portal', 'pay_col_gross'), sortKey: 'amount', money: true, total: 'amount', phone: 'amount', render: (w) => <><b>{money(w.amount)}</b><AmountWords amount={w.amount} /></> },
        { key: 'withheld_amount', title: translate('portal', 'pay_col_withholding'), sortKey: 'withheld', money: true, phone: 'hide', render: (w) => money(w.withheld_amount ?? 0) },
        { key: 'net_amount', title: translate('portal', 'pay_col_net'), sortKey: 'net', money: true, phone: 'hide', render: (w) => money(w.net_amount ?? w.amount) },
        { key: 'status', title: translate('portal', 'pay_col_status'), sortKey: 'status', phone: 'sub', render: (w) => <>
            <span className={`badge hub-capital ${w.status === 'returned' ? 'rejected' : w.status}`}>{w.status === 'cancelled' || w.status === 'returned' ? translate('payouts', `status_${w.status}`) : translate('portal', `pay_status_${w.status}`)}</span>
            {w.on_hold && <span className="badge rejected">{translate('payouts', 'on_hold_badge')}</span>}
            {w.overdue && <span className="badge rejected">{translate('payouts', 'overdue_badge')}</span>}
            {w.receipt_due && <span className="badge requested">{translate('payouts', 'receipt_due_badge')}</span>}
            {w.in_bank_file && <span className="badge requested">{translate('payouts', 'in_bank_file_badge', { batch: w.bank_file ?? '' })}</span>}
            {w.waiting_days != null && <small className="cell-sub">{w.waiting_days > 0 ? translate('payouts', 'waiting_days', { days: String(w.waiting_days) }) : translate('payouts', 'waiting_today')}</small>}
            {w.status === 'approved' && <small className="cell-sub">{w.payer_name ? translate('payouts', 'payer_named', { name: w.payer_name }) : translate('payouts', 'payer_none')}</small>}
        </> },
        { key: 'actions', title: translate('portal', 'pay_col_actions'), phone: 'sub', render: rowActions },
    ];

    return <MoneyTable<WithdrawalRecord> data={data} prop="data" columns={columns} searchPlaceholder={translate('portal', 'pay_search_ph')} empty={translate('portal', 'pay_empty')} />;
}

export default function PayoutDisbursementHub({ data, tdsRate = 0, totals }: PayoutsHubProps) {
    const translate = useT();
    const { portalBase, data: pageData, staffActions, auth } = usePage<any>().props;
    const myId = Number(auth?.user?.id ?? 0);
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
    const reasonOf = useReasonFields();
    const reviewNote = reasonOf('money.withdrawal_review');
    const paymentNote = reasonOf('money.withdrawal_settle');
    const [payoutMethod, setPayoutMethod] = useState<PayoutMethod>('bank');
    const [bankName, setBankName] = useState('');
    const [accountNumber, setAccountNumber] = useState('');
    const [walletNumber, setWalletNumber] = useState('');
    const [receivedBy, setReceivedBy] = useState('');
    const [paymentAccountId, setPaymentAccountId] = useState('');
    const isCash = payoutMethod === 'cash';
    const [payerId, setPayerId] = useState('');
    const [attachRecord, setAttachRecord] = useState<WithdrawalRecord | null>(null);
    const [attachFile, setAttachFile] = useState<File | null>(null);
    useEscape(attachRecord !== null, () => setAttachRecord(null));
    const review = useWithdrawalFacts(reviewModalRecord?.id ?? null);
    const settle = useWithdrawalFacts(settleModalRecord?.id ?? null);
    // The number the partner asked to be paid to fills the form; the server refuses any other (F-verify-01).
    React.useEffect(() => {
        if (!settle.facts) return;
        setWalletNumber((current) => current || settle.facts!.destination);
        setAccountNumber((current) => current || settle.facts!.destination);
    }, [settle.facts]);
    const reviewBlocked =reviewDecision === 'approved' && Boolean(review.facts?.blocker || review.facts?.over_channel_limit);
    const payoutAccounts: Array<{ id: number; label: string; channel: string; is_active: boolean; pays_out: boolean }> = (pageData?.payment_accounts ?? []).filter((account: { is_active: boolean; pays_out: boolean }) => account.is_active && account.pays_out);

    const handleOpenReview = (record: WithdrawalRecord) => {
        setModalErrors({});
        setReviewModalRecord(record);
        setReviewDecision('approved');
        setReviewReason('');
        setPayerId('');
    };

    const handleSubmitReview = (e: React.FormEvent) => {
        e.preventDefault();
        if (!reviewModalRecord) return;
        setProcessingReview(true);
        router.post(`/commands/withdrawals/${reviewModalRecord.id}/review`, {
            decision: reviewDecision,
            // No ready-made note: a note saying "NID and wallet verified" must come from the person who checked (MA-07).
            reason: reviewReason,
            ...(reviewDecision === 'approved' && payerId ? { payer_id: payerId } : {}),
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

    const handleAttach = (e: React.FormEvent) => {
        e.preventDefault();
        if (!attachRecord || !attachFile) return;
        const formData = new FormData();
        formData.append('receipt', attachFile);
        router.post(`/commands/withdrawals/${attachRecord.id}/attach-receipt`, formData, {
            onSuccess: () => setAttachRecord(null),
            onError: (errors) => setModalErrors(errors),
        });
    };

    const takeOver = (w: WithdrawalRecord) => router.post(`/commands/withdrawals/${w.id}/payer`, { payer_id: myId }, { preserveScroll: true });

    // "The bank did not send it": the only way, besides recording the payment, out of a bank file.
    const [notSentRecord, setNotSentRecord] = useState<WithdrawalRecord | null>(null);
    const [notSentNote, setNotSentNote] = useState('');
    const [processingNotSent, setProcessingNotSent] = useState(false);
    useEscape(notSentRecord !== null, () => setNotSentRecord(null));
    const handleNotSent = (e: React.FormEvent) => {
        e.preventDefault();
        if (!notSentRecord) return;
        setProcessingNotSent(true);
        router.post(`/commands/withdrawals/${notSentRecord.id}/not-sent`, { note: notSentNote }, {
            preserveScroll: true,
            onSuccess: () => setNotSentRecord(null),
            onError: (errors) => setModalErrors(errors),
            onFinish: () => setProcessingNotSent(false),
        });
    };

    // "Money came back": one person records it, a second confirms or takes it back (the owner finishes alone).
    const [returnRecord, setReturnRecord] = useState<WithdrawalRecord | null>(null);
    const [returnStep, setReturnStep] = useState<'record' | 'confirm' | 'cancel'>('record');
    const [returnNote, setReturnNote] = useState('');
    const [processingReturn, setProcessingReturn] = useState(false);
    useEscape(returnRecord !== null, () => setReturnRecord(null));
    const openReturn = (w: WithdrawalRecord, step: 'record' | 'confirm' | 'cancel') => {
        setModalErrors({});
        setReturnNote('');
        setReturnStep(step);
        setReturnRecord(w);
    };
    const handleReturn = (e: React.FormEvent) => {
        e.preventDefault();
        if (!returnRecord) return;
        setProcessingReturn(true);
        const path = returnStep === 'record' ? 'returned' : `returned/${returnStep}`;
        router.post(`/commands/withdrawals/${returnRecord.id}/${path}`, { note: returnNote }, {
            preserveScroll: true,
            onSuccess: () => setReturnRecord(null),
            onError: (errors) => setModalErrors(errors),
            onFinish: () => setProcessingReturn(false),
        });
    };

    const rowActions = (w: WithdrawalRecord) => (
        <div className="hub-row is-end">
            {w.status === 'approved' && canProcess && w.payer_id !== myId && w.payer_state !== 'second_person' && (
                <button type="button" onClick={() => takeOver(w)} className="button small">
                    {translate('payouts', 'payer_take')}
                </button>
            )}

            {w.status === 'paid' && w.receipt_due && canProcess && (
                <button type="button" onClick={() => { setModalErrors({}); setAttachFile(null); setAttachRecord(w); }} className="button small dark">
                    {translate('payouts', 'attach_receipt')}
                </button>
            )}

            {w.status === 'requested' && w.waits_for_owner && (
                <small className="muted">{translate('self_approval', 'owner_approves_withdrawal')}</small>
            )}

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

            {/* Office withdrawals are handed over in cash from the office list, not sent to an account. */}
            {w.status === 'approved' && w.channel === 'Office' && w.payer_state !== 'second_person' && canProcess && (
                <small className="muted">{translate('office_withdrawal', 'use_office_list')}</small>
            )}

            {w.status === 'approved' && w.channel !== 'Office' && w.payer_state !== 'second_person' && canProcess && (
                <button
                    type="button"
                    onClick={() => handleOpenSettle(w)}
                    className="button small dark"
                >
                    {translate('portal', 'pay_record_payout')}
                </button>
            )}

            {canProcess && (w.not_sent_state === 'record' || w.not_sent_state === 'confirm') && (
                <button type="button" onClick={() => { setModalErrors({}); setNotSentNote(''); setNotSentRecord(w); }} className="button small">
                    {translate('payouts', w.not_sent_state === 'confirm' ? 'not_sent_confirm_button' : 'not_sent_button')}
                </button>
            )}
            {w.not_sent_state === 'wait_second' && <small className="muted">{translate('payouts', 'not_sent_wait_second')}</small>}
            {w.not_sent_state === 'wait_owner' && <small className="muted">{translate('payouts', 'not_sent_wait_owner')}</small>}

            {canProcess && w.return_state === 'record' && (
                <button type="button" onClick={() => openReturn(w, 'record')} className="button small">
                    {translate('payouts', 'return_button')}
                </button>
            )}
            {canProcess && w.return_state === 'confirm' && (
                <>
                    <button type="button" onClick={() => openReturn(w, 'confirm')} className="button small dark">
                        {translate('payouts', 'return_confirm_button')}
                    </button>
                    <button type="button" onClick={() => openReturn(w, 'cancel')} className="button small">
                        {translate('payouts', 'return_cancel_button')}
                    </button>
                </>
            )}
            {w.return_state === 'wait_second' && (
                <>
                    <small className="muted">{translate('payouts', 'return_wait_second')}</small>
                    {canProcess && (
                        <button type="button" onClick={() => openReturn(w, 'cancel')} className="button small">
                            {translate('payouts', 'return_cancel_button')}
                        </button>
                    )}
                </>
            )}
            {w.return_state === 'wait_owner' && <small className="muted">{translate('payouts', 'return_wait_owner')}</small>}

            {/* The full number to send the money to is only on this page, where opening it is logged. */}
            <a
                href={`/withdrawals/${w.id}`}
                className="text-link"
            >
                {translate('portal', 'pay_details')}
            </a>

            {(w.status === 'paid' || w.status === 'returned') && w.payout_receipt_id && (
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

                <a className={`metric ${(summary.overdue_count ?? 0) > 0 ? 'tone-danger' : ''}`} href="?follow_up=overdue">
                    <span>{translate('payouts', 'kpi_overdue')}</span>
                    <strong>{money(summary.overdue_amount ?? 0)}</strong>
                    <small>{translate('payouts', 'kpi_overdue_hint', { count: String(summary.overdue_count ?? 0), days: String(pageData?.payout_due_days ?? 3) })}</small>
                    <small>{translate('payouts', 'kpi_followups', { hold: String(summary.on_hold_count ?? 0), receipt: String(summary.receipt_due_count ?? 0) })}</small>
                </a>

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

            {canProcess && <BankFiles batches={pageData?.payout_batches ?? []} />}

            <OfficePayoutList rows={pageData?.office_payouts ?? []} total={pageData?.office_cash_total} canProcess={canProcess} />

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

                        <WithdrawalFacts facts={review.facts} failed={review.failed} channel={reviewModalRecord.channel} amount={reviewModalRecord.amount} />

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

                            {reviewDecision === 'approved' && (review.facts?.payers.length ?? 0) > 0 && (
                                <div className="hub-field">
                                    <label htmlFor="payout-payer">{translate('payouts', 'payer_label')}</label>
                                    <select id="payout-payer" required value={payerId} onChange={(e) => setPayerId(e.target.value)}>
                                        <option value="">{translate('payouts', 'payer_choose')}</option>
                                        {review.facts?.payers.map((payer) => <option key={payer.id} value={payer.id}>{payer.name}</option>)}
                                    </select>
                                </div>
                            )}

                            {reviewNote.shown && <div className="hub-field is-last">
                                <label>
                                    {reviewNote.label(translate('portal', 'pay_verify_note'))}
                                </label>
                                <textarea
                                    required={reviewNote.required}
                                    rows={3}
                                    value={reviewReason}
                                    onChange={(e) => setReviewReason(e.target.value)}
                                    placeholder={reviewDecision === 'approved' ? translate('portal', 'pay_ph_approve') : translate('portal', 'pay_ph_reject')}
                                />
                            </div>}

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
                                    disabled={processingReview || reviewBlocked || (reviewDecision === 'approved' && !review.facts && !review.failed)}
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

                        <AmountWords amount={settleModalRecord.net_amount ?? settleModalRecord.amount} />

                        <div className="portal-notice portal-notice-info">
                            <span><b>{translate('portal', 'pay_sod')}</b> {translate('portal', 'pay_sod_body')}</span>
                        </div>

                        {settle.facts?.blocker && <div className="notice" role="alert"><p><b>{translate('payouts', 'blocked_title')}</b> {settle.facts.blocker}</p></div>}
                        {settle.facts && <p className="hub-hint">{translate('payouts', 'fact_destination')}: <b>{settle.facts.destination}</b></p>}

                        <form onSubmit={handleSubmitSettle} onPaste={(e) => {
                            const pasted = Array.from(e.clipboardData.files).find((item) => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'].includes(item.type));
                            if (pasted && !isCash) {
                                e.preventDefault();
                                setReceiptFile(new File([pasted], `receipt-${Date.now()}.${pasted.type === 'application/pdf' ? 'pdf' : pasted.type === 'image/png' ? 'png' : pasted.type === 'image/webp' ? 'webp' : 'jpg'}`, { type: pasted.type }));
                            }
                        }}>
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

                            {/* Owner, 2026-11-21: the transfer screenshot comes first; the partner is shown it as proof. */}
                            <ReceiptPicker
                                id="payout-receipt"
                                label={isCash ? translate('portal', 'pay_fld_receipt_cash') : translate('office_withdrawal', 'proof_label')}
                                required={!isCash}
                                webp
                                file={receiptFile}
                                onChange={setReceiptFile}
                            />
                            {!isCash && <small className="hub-hint">{translate('office_withdrawal', 'proof_hint')}</small>}

                            {paymentNote.shown && <div className="hub-field is-last">
                                <label>
                                    {/* The saved label ends with a star, which only fits a box that must be filled. */}
                                    {paymentNote.required ? translate('portal', 'pay_fld_notes') : paymentNote.label(translate('portal', 'pay_fld_notes').replace(/\s*\*$/, ''))}
                                </label>
                                <textarea
                                    required={paymentNote.required}
                                    rows={2}
                                    placeholder={translate('portal', 'pay_ph_notes')}
                                    value={settleNote}
                                    onChange={(e) => setSettleNote(e.target.value)}
                                />
                            </div>}

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
                                    disabled={processingSettle || Boolean(settle.facts?.blocker)}
                                    className="button dark"
                                >
                                    {processingSettle ? translate('portal', 'pay_recording') : translate('portal', 'pay_record_ledger')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {notSentRecord && (
                <div className="hub-overlay">
                    <div className="hub-dialog" role="dialog" aria-modal="true">
                        <h3 className="hub-title is-lg">{translate('payouts', 'not_sent_title')}</h3>
                        <p className="hub-sub is-lead">{notSentRecord.user_name} · {money(notSentRecord.net_amount ?? notSentRecord.amount)} · {translate('payouts', 'in_bank_file_badge', { batch: notSentRecord.bank_file ?? '' })}</p>
                        <div className="notice" role="note"><p>{translate('payouts', 'not_sent_body')}</p></div>
                        {notSentRecord.not_sent_note && <p className="hub-hint">{translate('payouts', 'not_sent_recorded_line', { note: notSentRecord.not_sent_note })}</p>}
                        <form onSubmit={handleNotSent}>
                            <div className="hub-field is-last">
                                <label htmlFor="not-sent-note">{translate('payouts', 'not_sent_note')}</label>
                                <textarea id="not-sent-note" required minLength={5} maxLength={1000} rows={3} value={notSentNote} onChange={(e) => setNotSentNote(e.target.value)} />
                            </div>
                            <FormErrors errors={modalErrors} />
                            <div className="hub-row is-end">
                                <button type="button" onClick={() => setNotSentRecord(null)} className="button">{translate('portal', 'pay_cancel')}</button>
                                <button type="submit" disabled={processingNotSent || notSentNote.trim().length < 5} className="button danger">{translate('payouts', notSentRecord.not_sent_state === 'confirm' ? 'not_sent_confirm_button' : 'not_sent_save')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {returnRecord && (
                <div className="hub-overlay">
                    <div className="hub-dialog" role="dialog" aria-modal="true">
                        <h3 className="hub-title is-lg">{translate('payouts', returnStep === 'record' ? 'return_title' : returnStep === 'confirm' ? 'return_confirm_title' : 'return_cancel_button')}</h3>
                        <p className="hub-sub is-lead">{returnRecord.user_name} · {money(returnRecord.amount)} · {returnRecord.payment_reference}</p>
                        {returnStep === 'record' && <div className="notice" role="note"><p>{translate('payouts', 'return_body')}</p></div>}
                        {returnStep === 'confirm' && <div className="notice" role="note"><p>{translate('payouts', 'return_confirm_body', { amount: money(returnRecord.amount) })}</p></div>}
                        {returnStep !== 'record' && returnRecord.return_note && <p className="hub-hint">{translate('payouts', 'return_recorded_line', { note: returnRecord.return_note })}</p>}
                        <form onSubmit={handleReturn}>
                            <div className="hub-field is-last">
                                <label htmlFor="return-note">{translate('payouts', returnStep === 'record' ? 'return_note' : returnStep === 'confirm' ? 'return_confirm_note' : 'return_cancel_note')}</label>
                                <textarea id="return-note" required minLength={5} maxLength={1000} rows={3} value={returnNote} onChange={(e) => setReturnNote(e.target.value)} />
                            </div>
                            <FormErrors errors={modalErrors} />
                            <div className="hub-row is-end">
                                <button type="button" onClick={() => setReturnRecord(null)} className="button">{translate('portal', 'pay_cancel')}</button>
                                <button type="submit" disabled={processingReturn || returnNote.trim().length < 5} className={`button ${returnStep === 'cancel' ? '' : 'dark'}`}>
                                    {translate('payouts', returnStep === 'record' ? 'return_save' : returnStep === 'confirm' ? 'return_confirm_save' : 'return_cancel_button')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Receipt for a payout recorded as "money sent, receipt later" */}
            {attachRecord && (
                <div className="hub-overlay">
                    <div className="hub-dialog" role="dialog" aria-modal="true">
                        <h3 className="hub-title is-lg">{translate('payouts', 'attach_receipt')}</h3>
                        <p className="hub-sub is-lead">{attachRecord.user_name} · {money(attachRecord.net_amount ?? attachRecord.amount)} · {attachRecord.payment_reference}</p>
                        <form onSubmit={handleAttach}>
                            <ReceiptPicker id="attach-receipt" label={translate('portal', 'pay_fld_receipt')} required webp file={attachFile} onChange={setAttachFile} />
                            <FormErrors errors={modalErrors} />
                            <div className="hub-row is-end">
                                <button type="button" onClick={() => setAttachRecord(null)} className="button">{translate('portal', 'pay_cancel')}</button>
                                <button type="submit" disabled={!attachFile} className="button dark">{translate('payouts', 'attach_save')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

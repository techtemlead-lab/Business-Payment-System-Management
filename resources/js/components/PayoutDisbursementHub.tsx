import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { 
    Wallet, DollarSign, CheckCircle2, XCircle, Clock, AlertTriangle, 
    FileText, ArrowUpRight, Upload, ShieldCheck, Download, Search, 
    Filter, ChevronRight, UserCheck
} from 'lucide-react';
import { money, date } from './UI';

interface WithdrawalRecord {
    id: string;
    user_id: number;
    user_name: string;
    dealer_code: string;
    phone: string;
    amount: string | number;
    channel: string;
    destination: string;
    status: 'requested' | 'approved' | 'paid' | 'rejected';
    reason: string | null;
    payment_reference: string | null;
    paid_at: string | null;
    receipt_path: string | null;
    created_at: string;
}

interface PayoutsHubProps {
    withdrawals: {
        data: WithdrawalRecord[];
        current_page: number;
        last_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    tdsRate: number;
}

export default function PayoutDisbursementHub({ withdrawals, tdsRate = 5.0 }: PayoutsHubProps) {
    const { auth, flash } = usePage<any>().props;
    const currentUser = auth.user;

    const [filterStatus, setFilterStatus] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState('');
    
    // Modal states
    const [reviewModalRecord, setReviewModalRecord] = useState<WithdrawalRecord | null>(null);
    const [reviewDecision, setReviewDecision] = useState<'approved' | 'rejected'>('approved');
    const [reviewReason, setReviewReason] = useState('');
    const [processingReview, setProcessingReview] = useState(false);

    const [settleModalRecord, setSettleModalRecord] = useState<WithdrawalRecord | null>(null);
    const [paymentReference, setPaymentReference] = useState('');
    const [paidAt, setPaidAt] = useState(() => new Date().toISOString().slice(0, 16));
    const [settleNote, setSettleNote] = useState('');
    const [receiptFile, setReceiptFile] = useState<File | null>(null);
    const [processingSettle, setProcessingSettle] = useState(false);

    // Calculate aggregated metrics
    const records = withdrawals.data || [];
    const pendingRecords = records.filter(r => r.status === 'requested');
    const approvedRecords = records.filter(r => r.status === 'approved');
    const paidRecords = records.filter(r => r.status === 'paid');

    const totalPendingAmount = pendingRecords.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
    const totalApprovedAmount = approvedRecords.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
    const totalPaidAmount = paidRecords.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
    const totalTdsWithheld = Math.round(totalPaidAmount * (tdsRate / 100) * 100) / 100;

    // Filtered records
    const displayedRecords = records.filter(r => {
        if (filterStatus !== 'all' && r.status !== filterStatus) return false;
        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase();
            const matchName = r.user_name?.toLowerCase().includes(q);
            const matchCode = r.dealer_code?.toLowerCase().includes(q);
            const matchRef = r.payment_reference?.toLowerCase().includes(q);
            const matchPhone = r.phone?.includes(q);
            return matchName || matchCode || matchRef || matchPhone;
        }
        return true;
    });

    const handleOpenReview = (record: WithdrawalRecord) => {
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
            reason: reviewReason || (reviewDecision === 'approved' ? 'Identity and wallet balance verified.' : 'Verification requirements not met.')
        }, {
            onFinish: () => {
                setProcessingReview(false);
                setReviewModalRecord(null);
            }
        });
    };

    const handleOpenSettle = (record: WithdrawalRecord) => {
        setSettleModalRecord(record);
        setPaymentReference('');
        setPaidAt(new Date().toISOString().slice(0, 16));
        setSettleNote('');
        setReceiptFile(null);
    };

    const handleSubmitSettle = (e: React.FormEvent) => {
        e.preventDefault();
        if (!settleModalRecord || !receiptFile) return;

        const formData = new FormData();
        formData.append('receipt', receiptFile);
        formData.append('payment_reference', paymentReference);
        formData.append('paid_at', paidAt);
        formData.append('note', settleNote || 'Bank transfer completed.');

        setProcessingSettle(true);
        router.post(`/commands/withdrawals/${settleModalRecord.id}/record-payment`, formData, {
            onFinish: () => {
                setProcessingSettle(false);
                setSettleModalRecord(null);
            }
        });
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
            {/* Top Operational Notice */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '20px 24px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: '16px'
            }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' }}>
                        <span style={{
                            background: '#ecfdf5',
                            color: '#047857',
                            padding: '3px 8px',
                            borderRadius: '4px',
                            fontSize: '11px',
                            fontWeight: 700,
                            textTransform: 'uppercase'
                        }}>
                            FINANCE · STATUTORY COMPLIANCE
                        </span>
                        <span style={{ fontSize: '13px', color: '#64748b' }}>
                            NBR Income Tax Act TDS: <b>{tdsRate.toFixed(2)}%</b> Withholding
                        </span>
                    </div>
                    <h2 style={{ margin: 0, fontSize: '20px', fontWeight: 800, color: '#0f172a' }}>
                        Partner Payout & Disbursement Hub
                    </h2>
                    <p style={{ margin: '4px 0 0 0', fontSize: '13px', color: '#64748b' }}>
                        Review partner withdrawal requests with mandatory segregation of duties. Payments require manual bank/MFS reference confirmation and file receipt attachment.
                    </p>
                </div>
            </div>

            {/* Metrics Overview Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '16px' }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Pending Review</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#d97706', marginTop: '6px' }}>
                        ৳{totalPendingAmount.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {pendingRecords.length} requests awaiting authorization
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Approved for Settlement</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#0284c7', marginTop: '6px' }}>
                        ৳{totalApprovedAmount.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {approvedRecords.length} approved awaiting bank/MFS transfer
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Total Settled</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#047857', marginTop: '6px' }}>
                        ৳{totalPaidAmount.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {paidRecords.length} executed with private receipts
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>TDS Withheld (5%)</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#475569', marginTop: '6px' }}>
                        ৳{totalTdsWithheld.toLocaleString()}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        Statutory tax reserve for NBR remittance
                    </span>
                </div>
            </div>

            {/* Filter & Search Bar */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                padding: '16px 20px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: '12px'
            }}>
                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                    {['all', 'requested', 'approved', 'paid', 'rejected'].map(st => (
                        <button
                            key={st}
                            type="button"
                            onClick={() => setFilterStatus(st)}
                            style={{
                                padding: '6px 14px',
                                borderRadius: '6px',
                                fontSize: '13px',
                                fontWeight: filterStatus === st ? 700 : 500,
                                background: filterStatus === st ? '#0f172a' : '#f1f5f9',
                                color: filterStatus === st ? '#ffffff' : '#475569',
                                border: 'none',
                                cursor: 'pointer',
                                textTransform: 'capitalize'
                            }}
                        >
                            {st === 'all' ? 'All Requests' : st}
                        </button>
                    ))}
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flex: 1, maxWidth: '320px' }}>
                    <div style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '8px',
                        background: '#f8fafc',
                        border: '1px solid #cbd5e1',
                        borderRadius: '6px',
                        padding: '6px 12px',
                        width: '100%'
                    }}>
                        <Search size={16} color="#64748b" />
                        <input
                            type="text"
                            placeholder="Search by partner or dealer code…"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            style={{
                                border: 'none',
                                background: 'transparent',
                                outline: 'none',
                                fontSize: '13px',
                                width: '100%'
                            }}
                        />
                    </div>
                </div>
            </div>

            {/* Withdrawals Table */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                overflow: 'hidden'
            }}>
                <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
                        <thead>
                            <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', color: '#475569' }}>
                                <th style={{ padding: '12px 20px', fontWeight: 600 }}>Partner</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600 }}>Date Requested</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600 }}>Payment Method</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Gross Requested</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>TDS (5%)</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600, textAlign: 'right' }}>Net Disbursable</th>
                                <th style={{ padding: '12px 16px', fontWeight: 600 }}>Status</th>
                                <th style={{ padding: '12px 20px', fontWeight: 600, textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {displayedRecords.length === 0 ? (
                                <tr>
                                    <td colSpan={8} style={{ padding: '40px', textAlign: 'center', color: '#64748b' }}>
                                        No withdrawal requests matching current criteria.
                                    </td>
                                </tr>
                            ) : (
                                displayedRecords.map(w => {
                                    const gross = Number(w.amount) || 0;
                                    const tds = Math.round(gross * (tdsRate / 100) * 100) / 100;
                                    const net = Math.max(0, Math.round((gross - tds) * 100) / 100);

                                    return (
                                        <tr key={w.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                            <td style={{ padding: '12px 20px' }}>
                                                <div style={{ fontWeight: 700, color: '#0f172a' }}>{w.user_name}</div>
                                                <div style={{ fontSize: '11px', color: '#64748b' }}>
                                                    {w.dealer_code} · {w.phone}
                                                </div>
                                            </td>
                                            <td style={{ padding: '12px 16px', color: '#475569' }}>
                                                {date(w.created_at)}
                                            </td>
                                            <td style={{ padding: '12px 16px' }}>
                                                <span style={{ fontWeight: 600, color: '#334155' }}>{w.channel}</span>
                                            </td>
                                            <td style={{ padding: '12px 16px', textAlign: 'right', fontWeight: 700, color: '#0f172a' }}>
                                                ৳{gross.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </td>
                                            <td style={{ padding: '12px 16px', textAlign: 'right', color: '#dc2626' }}>
                                                -৳{tds.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </td>
                                            <td style={{ padding: '12px 16px', textAlign: 'right', fontWeight: 800, color: '#047857' }}>
                                                ৳{net.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </td>
                                            <td style={{ padding: '12px 16px' }}>
                                                <span style={{
                                                    display: 'inline-flex',
                                                    alignItems: 'center',
                                                    gap: '4px',
                                                    padding: '3px 8px',
                                                    borderRadius: '4px',
                                                    fontSize: '11px',
                                                    fontWeight: 700,
                                                    textTransform: 'uppercase',
                                                    background: 
                                                        w.status === 'paid' ? '#dcfce7' :
                                                        w.status === 'approved' ? '#e0f2fe' :
                                                        w.status === 'rejected' ? '#fee2e2' : '#fef3c7',
                                                    color:
                                                        w.status === 'paid' ? '#15803d' :
                                                        w.status === 'approved' ? '#0369a1' :
                                                        w.status === 'rejected' ? '#b91c1c' : '#b45309'
                                                }}>
                                                    {w.status}
                                                </span>
                                            </td>
                                            <td style={{ padding: '12px 20px', textAlign: 'right' }}>
                                                <div style={{ display: 'inline-flex', gap: '8px' }}>
                                                    {w.status === 'requested' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenReview(w)}
                                                            style={{
                                                                background: '#0f172a',
                                                                color: '#ffffff',
                                                                border: 'none',
                                                                borderRadius: '6px',
                                                                padding: '6px 12px',
                                                                fontSize: '12px',
                                                                fontWeight: 600,
                                                                cursor: 'pointer'
                                                            }}
                                                        >
                                                            Review
                                                        </button>
                                                    )}

                                                    {w.status === 'approved' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenSettle(w)}
                                                            style={{
                                                                background: '#047857',
                                                                color: '#ffffff',
                                                                border: 'none',
                                                                borderRadius: '6px',
                                                                padding: '6px 12px',
                                                                fontSize: '12px',
                                                                fontWeight: 600,
                                                                cursor: 'pointer'
                                                            }}
                                                        >
                                                            Record Payout
                                                        </button>
                                                    )}

                                                    {w.status === 'paid' && (
                                                        <a
                                                            href={`/withdrawals/${w.id}`}
                                                            style={{
                                                                color: '#0284c7',
                                                                fontSize: '12px',
                                                                fontWeight: 600,
                                                                textDecoration: 'none',
                                                                display: 'inline-flex',
                                                                alignItems: 'center',
                                                                gap: '4px'
                                                            }}
                                                        >
                                                            Details ↗
                                                        </a>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Review Modal */}
            {reviewModalRecord && (
                <div style={{
                    position: 'fixed',
                    top: 0, left: 0, right: 0, bottom: 0,
                    background: 'rgba(15, 23, 42, 0.65)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 1000,
                    padding: '16px'
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: '12px',
                        width: '100%',
                        maxWidth: '500px',
                        padding: '24px',
                        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1)'
                    }}>
                        <h3 style={{ margin: 0, fontSize: '18px', fontWeight: 800, color: '#0f172a' }}>
                            Review Partner Withdrawal Request
                        </h3>
                        <p style={{ margin: '6px 0 16px 0', fontSize: '13px', color: '#64748b' }}>
                            Request ID: {reviewModalRecord.id.slice(0, 8)} · {reviewModalRecord.user_name} ({reviewModalRecord.dealer_code})
                        </p>

                        <div style={{
                            background: '#f8fafc',
                            border: '1px solid #e2e8f0',
                            borderRadius: '8px',
                            padding: '12px 16px',
                            marginBottom: '16px'
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', marginBottom: '4px' }}>
                                <span>Gross Amount:</span>
                                <b>৳{Number(reviewModalRecord.amount).toLocaleString()}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', marginBottom: '4px', color: '#dc2626' }}>
                                <span>Statutory TDS (5%):</span>
                                <b>-৳{(Number(reviewModalRecord.amount) * 0.05).toLocaleString()}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '14px', fontWeight: 700, color: '#047857', borderTop: '1px solid #e2e8f0', paddingTop: '4px' }}>
                                <span>Net Approved Disbursable:</span>
                                <b>৳{(Number(reviewModalRecord.amount) * 0.95).toLocaleString()}</b>
                            </div>
                        </div>

                        <form onSubmit={handleSubmitReview}>
                            <div style={{ marginBottom: '16px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                                    Decision
                                </label>
                                <div style={{ display: 'flex', gap: '16px' }}>
                                    <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', cursor: 'pointer' }}>
                                        <input
                                            type="radio"
                                            name="decision"
                                            value="approved"
                                            checked={reviewDecision === 'approved'}
                                            onChange={() => setReviewDecision('approved')}
                                        />
                                        Approve Request
                                    </label>
                                    <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', cursor: 'pointer' }}>
                                        <input
                                            type="radio"
                                            name="decision"
                                            value="rejected"
                                            checked={reviewDecision === 'rejected'}
                                            onChange={() => setReviewDecision('rejected')}
                                        />
                                        Reject Request
                                    </label>
                                </div>
                            </div>

                            <div style={{ marginBottom: '20px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                                    Verification Note / Reason
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    value={reviewReason}
                                    onChange={(e) => setReviewReason(e.target.value)}
                                    placeholder={reviewDecision === 'approved' ? 'Verified partner KYC and available ledger balance.' : 'Specify reason for rejection…'}
                                    style={{
                                        width: '100%',
                                        padding: '8px 12px',
                                        borderRadius: '6px',
                                        border: '1px solid #cbd5e1',
                                        fontSize: '13px'
                                    }}
                                />
                            </div>

                            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                                <button
                                    type="button"
                                    onClick={() => setReviewModalRecord(null)}
                                    style={{
                                        background: '#f1f5f9',
                                        color: '#475569',
                                        border: 'none',
                                        borderRadius: '6px',
                                        padding: '8px 16px',
                                        fontSize: '13px',
                                        fontWeight: 600,
                                        cursor: 'pointer'
                                    }}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processingReview}
                                    style={{
                                        background: reviewDecision === 'approved' ? '#047857' : '#dc2626',
                                        color: '#ffffff',
                                        border: 'none',
                                        borderRadius: '6px',
                                        padding: '8px 16px',
                                        fontSize: '13px',
                                        fontWeight: 600,
                                        cursor: 'pointer'
                                    }}
                                >
                                    {processingReview ? 'Submitting…' : `Confirm ${reviewDecision === 'approved' ? 'Approval' : 'Rejection'}`}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Settlement Modal */}
            {settleModalRecord && (
                <div style={{
                    position: 'fixed',
                    top: 0, left: 0, right: 0, bottom: 0,
                    background: 'rgba(15, 23, 42, 0.65)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 1000,
                    padding: '16px'
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: '12px',
                        width: '100%',
                        maxWidth: '520px',
                        padding: '24px',
                        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1)'
                    }}>
                        <h3 style={{ margin: 0, fontSize: '18px', fontWeight: 800, color: '#0f172a' }}>
                            Record Manual Payout & Proof
                        </h3>
                        <p style={{ margin: '6px 0 16px 0', fontSize: '13px', color: '#64748b' }}>
                            {settleModalRecord.user_name} ({settleModalRecord.channel}) · Net to Disburse: <b>৳{(Number(settleModalRecord.amount) * 0.95).toLocaleString()}</b>
                        </p>

                        <div style={{
                            background: '#eff6ff',
                            border: '1px solid #bfdbfe',
                            borderRadius: '8px',
                            padding: '10px 14px',
                            fontSize: '12px',
                            color: '#1e40af',
                            marginBottom: '16px'
                        }}>
                            <b>Segregation of Duties:</b> Payment must be confirmed with an authentic bank deposit slip or MFS confirmation receipt.
                        </div>

                        <form onSubmit={handleSubmitSettle}>
                            <div style={{ marginBottom: '14px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '4px' }}>
                                    Payment Transaction Reference *
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. TRX-BKASH-983172 or BANK-CHQ-10492"
                                    value={paymentReference}
                                    onChange={(e) => setPaymentReference(e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '8px 12px',
                                        borderRadius: '6px',
                                        border: '1px solid #cbd5e1',
                                        fontSize: '13px'
                                    }}
                                />
                            </div>

                            <div style={{ marginBottom: '14px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '4px' }}>
                                    Payment Execution Date & Time *
                                </label>
                                <input
                                    type="datetime-local"
                                    required
                                    value={paidAt}
                                    onChange={(e) => setPaidAt(e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '8px 12px',
                                        borderRadius: '6px',
                                        border: '1px solid #cbd5e1',
                                        fontSize: '13px'
                                    }}
                                />
                            </div>

                            <div style={{ marginBottom: '14px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '4px' }}>
                                    Upload Payment Receipt (PDF / Image) *
                                </label>
                                <input
                                    type="file"
                                    required
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    onChange={(e) => setReceiptFile(e.target.files?.[0] || null)}
                                    style={{
                                        width: '100%',
                                        padding: '8px',
                                        borderRadius: '6px',
                                        border: '1px solid #cbd5e1',
                                        fontSize: '13px'
                                    }}
                                />
                            </div>

                            <div style={{ marginBottom: '20px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '4px' }}>
                                    Disbursement Notes *
                                </label>
                                <textarea
                                    required
                                    rows={2}
                                    placeholder="Disbursed via official company accounts."
                                    value={settleNote}
                                    onChange={(e) => setSettleNote(e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '8px 12px',
                                        borderRadius: '6px',
                                        border: '1px solid #cbd5e1',
                                        fontSize: '13px'
                                    }}
                                />
                            </div>

                            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                                <button
                                    type="button"
                                    onClick={() => setSettleModalRecord(null)}
                                    style={{
                                        background: '#f1f5f9',
                                        color: '#475569',
                                        border: 'none',
                                        borderRadius: '6px',
                                        padding: '8px 16px',
                                        fontSize: '13px',
                                        fontWeight: 600,
                                        cursor: 'pointer'
                                    }}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processingSettle}
                                    style={{
                                        background: '#047857',
                                        color: '#ffffff',
                                        border: 'none',
                                        borderRadius: '6px',
                                        padding: '8px 16px',
                                        fontSize: '13px',
                                        fontWeight: 600,
                                        cursor: 'pointer'
                                    }}
                                >
                                    {processingSettle ? 'Recording…' : 'Record & Post Ledger'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

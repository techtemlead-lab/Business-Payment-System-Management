import React, { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { money, date, Table, Pagination, type Row } from './UI';
import { useT } from '../shared/i18n/t';

type WithdrawalStatus = 'requested' | 'approved' | 'paid' | 'rejected';

interface WithdrawalRecord {
    id: string;
    user_id: number;
    user_name: string;
    dealer_code: string;
    phone: string;
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
}

interface WithdrawalPage extends Row {
    data: WithdrawalRecord[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
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
    withdrawals: WithdrawalPage;
    tdsRate: number;
    totals?: PayoutTotals;
}

const statusTabs: Array<'all' | WithdrawalStatus> = ['all', 'requested', 'approved', 'paid', 'rejected'];

const emptyTotals: PayoutTotals = {
    requested_count: 0,
    requested_amount: 0,
    approved_count: 0,
    approved_amount: 0,
    paid_count: 0,
    paid_amount: 0,
    paid_withheld_amount: 0,
};

export default function PayoutDisbursementHub({ withdrawals, tdsRate = 0, totals }: PayoutsHubProps) {
    const translate = useT();
    const { portalBase, filters, data: pageData } = usePage<any>().props;
    const summary: PayoutTotals = totals ?? pageData?.payout_totals ?? emptyTotals;
    const activeSearch = String(filters?.q ?? '');
    const activeStatus = statusTabs.includes(filters?.status) ? String(filters.status) : 'all';

    const [searchQuery, setSearchQuery] = useState(activeSearch);
    useEffect(() => setSearchQuery(activeSearch), [activeSearch]);

    // Modal states
    const [reviewModalRecord, setReviewModalRecord] = useState<WithdrawalRecord | null>(null);
    const [reviewDecision, setReviewDecision] = useState<'approved' | 'rejected'>('approved');
    const [reviewReason, setReviewReason] = useState('');
    const [processingReview, setProcessingReview] = useState(false);

    const [settleModalRecord, setSettleModalRecord] = useState<WithdrawalRecord | null>(null);
    const [paymentReference, setPaymentReference] = useState('');
    const [paidAt, setPaidAt] = useState(() => new Date().toLocaleString('sv-SE',{timeZone:'Asia/Dhaka'}).slice(0,16).replace(' ','T'));
    const [settleNote, setSettleNote] = useState('');
    const [receiptFile, setReceiptFile] = useState<File | null>(null);
    const [processingSettle, setProcessingSettle] = useState(false);

    const records = withdrawals?.data ?? [];

    /** Search and status run on the server so every page and the totals agree. */
    const applyFilters = (next: { q?: string; status?: string }) => {
        const status = next.status ?? activeStatus;
        const query = {
            q: (next.q ?? activeSearch).trim(),
            status: status === 'all' ? '' : status,
        };
        router.get(
            `${portalBase ?? ''}/payouts-hub`,
            Object.fromEntries(Object.entries(query).filter(([, value]) => value)),
            { preserveState: true, preserveScroll: true },
        );
    };
    const hasActiveFilters = activeSearch !== '' || activeStatus !== 'all';

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
            reason: reviewReason || (reviewDecision === 'approved'
                ? translate('portal', 'pay_reason_approved')
                : translate('portal', 'pay_reason_rejected'))
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
        setPaidAt(new Date().toLocaleString('sv-SE',{timeZone:'Asia/Dhaka'}).slice(0,16).replace(' ','T'));
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
        formData.append('note', settleNote || translate('portal', 'pay_reason_paid'));

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
                            {translate('portal', 'pay_badge')}
                        </span>
                        <span style={{ fontSize: '13px', color: '#64748b' }}>
                            {translate('portal', 'pay_withholding', { rate: tdsRate.toFixed(2) })}
                        </span>
                    </div>
                    <h2 style={{ margin: 0, fontSize: '20px', fontWeight: 800, color: '#0f172a' }}>
                        {translate('portal', 'pay_heading')}
                    </h2>
                    <p style={{ margin: '4px 0 0 0', fontSize: '13px', color: '#64748b' }}>
                        {translate('portal', 'pay_heading_hint')}
                    </p>
                </div>
            </div>

            {/* Metrics Overview Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '16px' }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal', 'pay_kpi_pending')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#d97706', marginTop: '6px' }}>
                        {money(summary.requested_amount)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal', 'pay_kpi_pending_hint', { count: String(summary.requested_count) })}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal', 'pay_kpi_approved')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#0284c7', marginTop: '6px' }}>
                        {money(summary.approved_amount)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal', 'pay_kpi_approved_hint', { count: String(summary.approved_count) })}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal', 'pay_kpi_settled')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#047857', marginTop: '6px' }}>
                        {money(summary.paid_amount)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal', 'pay_kpi_settled_hint', { count: String(summary.paid_count) })}
                    </span>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '18px' }}>
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>{translate('portal', 'pay_kpi_withheld')}</span>
                    <div style={{ fontSize: '24px', fontWeight: 800, color: '#475569', marginTop: '6px' }}>
                        {money(summary.paid_withheld_amount)}
                    </div>
                    <span style={{ fontSize: '12px', color: '#64748b' }}>
                        {translate('portal', 'pay_kpi_withheld_hint')}
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
                    {statusTabs.map(st => (
                        <button
                            key={st}
                            type="button"
                            aria-pressed={activeStatus === st}
                            onClick={() => applyFilters({ status: st })}
                            style={{
                                padding: '6px 14px',
                                borderRadius: '6px',
                                fontSize: '13px',
                                fontWeight: activeStatus === st ? 700 : 500,
                                background: activeStatus === st ? '#0f172a' : '#f1f5f9',
                                color: activeStatus === st ? '#ffffff' : '#475569',
                                border: 'none',
                                cursor: 'pointer',
                                textTransform: 'capitalize'
                            }}
                        >
                            {st === 'all' ? translate('portal', 'pay_filter_all') : translate('portal', `pay_status_${st}`)}
                        </button>
                    ))}
                </div>

                <form
                    role="search"
                    onSubmit={(e) => { e.preventDefault(); applyFilters({ q: searchQuery }); }}
                    style={{ display: 'flex', alignItems: 'center', gap: '8px', flex: 1, maxWidth: '420px' }}
                >
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
                            type="search"
                            aria-label={translate('portal', 'pay_search_ph')}
                            placeholder={translate('portal', 'pay_search_ph')}
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
                    <button type="submit" className="button small dark">{translate('portal', 'search')}</button>
                    {hasActiveFilters && (
                        <button
                            type="button"
                            className="text-link"
                            onClick={() => { setSearchQuery(''); applyFilters({ q: '', status: 'all' }); }}
                        >
                            {translate('portal', 'clear')}
                        </button>
                    )}
                </form>
            </div>

            {/* Withdrawals Table */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: '12px',
                overflow: 'hidden'
            }}>
                <Table
                    tools={false}
                    rows={records}
                    empty={translate('portal', 'pay_empty')}
                    columns={[
                        {
                            key: 'user_name',
                            title: translate('portal', 'pay_col_partner'),
                            sortable: true,
                            sortValue: (w: WithdrawalRecord) => w.user_name,
                            render: (w: WithdrawalRecord) => (
                                <div>
                                    <div style={{ fontWeight: 700, color: '#0f172a' }}>{w.user_name}</div>
                                    <div style={{ fontSize: '11px', color: '#64748b' }}>
                                        {w.dealer_code} · {w.phone}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'created_at',
                            title: translate('portal', 'pay_col_date'),
                            sortable: true,
                            render: (w: WithdrawalRecord) => date(w.created_at),
                        },
                        {
                            key: 'channel',
                            title: translate('portal', 'pay_col_method'),
                            sortable: true,
                            render: (w: WithdrawalRecord) => <span style={{ fontWeight: 600, color: '#334155' }}>{w.channel}</span>,
                        },
                        {
                            key: 'destination_masked',
                            title: translate('portal', 'wd_destination'),
                            sortable: true,
                            render: (w: WithdrawalRecord) => (
                                <span style={{ fontFamily: 'monospace', fontSize: 12, color: '#475569' }}>
                                    {w.destination_masked || '—'}
                                </span>
                            ),
                        },
                        {
                            key: 'payment_reference',
                            title: translate('portal', 'col_reference'),
                            sortable: true,
                            render: (w: WithdrawalRecord) => w.payment_reference ? (
                                <span style={{ fontSize: 11, fontWeight: 600, color: '#0f766e' }}>{w.payment_reference}</span>
                            ) : '—',
                        },
                        {
                            key: 'amount',
                            title: translate('portal', 'pay_col_gross'),
                            align: 'right',
                            sortable: true,
                            sortValue: (w: WithdrawalRecord) => Number(w.amount) || 0,
                            render: (w: WithdrawalRecord) => (
                                <span style={{ fontWeight: 700, color: '#0f172a' }}>
                                    {money(w.amount)}
                                </span>
                            ),
                        },
                        {
                            key: 'withheld_amount',
                            title: translate('portal', 'pay_col_withholding'),
                            align: 'right',
                            sortable: true,
                            sortValue: (w: WithdrawalRecord) => Number(w.withheld_amount ?? 0),
                            render: (w: WithdrawalRecord) => (
                                <span style={{ color: '#dc2626' }}>
                                    -{money(w.withheld_amount ?? 0)}
                                </span>
                            ),
                        },
                        {
                            key: 'net_amount',
                            title: translate('portal', 'pay_col_net'),
                            align: 'right',
                            sortable: true,
                            sortValue: (w: WithdrawalRecord) => Number(w.net_amount ?? w.amount),
                            render: (w: WithdrawalRecord) => (
                                <b style={{ fontWeight: 800, color: '#047857' }}>
                                    {money(w.net_amount ?? w.amount)}
                                </b>
                            ),
                        },
                        {
                            key: 'status',
                            title: translate('portal', 'pay_col_status'),
                            sortable: true,
                            render: (w: WithdrawalRecord) => (
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
                                    {translate('portal', `pay_status_${w.status}`)}
                                </span>
                            ),
                        },
                        {
                            key: 'actions',
                            title: translate('portal', 'pay_col_actions'),
                            align: 'right',
                            sortable: false,
                            render: (w: WithdrawalRecord) => (
                                <div style={{ display: 'inline-flex', gap: '8px', justifyContent: 'flex-end' }}>
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
                                            {translate('portal', 'pay_review')}
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
                                            {translate('portal', 'pay_record_payout')}
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
                                            {translate('portal', 'pay_details')}
                                        </a>
                                    )}

                                    {w.status === 'rejected' && w.reason && (
                                        <span style={{ fontSize: '11px', color: '#94a3b8', fontStyle: 'italic', maxWidth: '120px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={w.reason}>
                                            {w.reason}
                                        </span>
                                    )}
                                </div>
                            ),
                        },
                    ]}
                />
                <Pagination page={withdrawals} />
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
                            {translate('portal', 'pay_review_title')}
                        </h3>
                        <p style={{ margin: '6px 0 16px 0', fontSize: '13px', color: '#64748b' }}>
                            {translate('portal', 'pay_review_meta', { id: reviewModalRecord.id.slice(0, 8), name: reviewModalRecord.user_name, code: reviewModalRecord.dealer_code })}
                        </p>

                        <div style={{
                            background: '#f8fafc',
                            border: '1px solid #e2e8f0',
                            borderRadius: '8px',
                            padding: '12px 16px',
                            marginBottom: '16px'
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', marginBottom: '4px' }}>
<span>{translate('portal', 'pay_gross_amount')}</span>
                                <b>{money(reviewModalRecord.amount)}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '13px', marginBottom: '4px', color: '#dc2626' }}>
<span>{translate('portal', 'pay_recorded_withholding')}</span>
                                <b>-{money(reviewModalRecord.withheld_amount ?? 0)}</b>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '14px', fontWeight: 700, color: '#047857', borderTop: '1px solid #e2e8f0', paddingTop: '4px' }}>
<span>{translate('portal', 'pay_net_approved')}</span>
                                <b>{money(reviewModalRecord.net_amount ?? reviewModalRecord.amount)}</b>
                            </div>
                        </div>

                        <form onSubmit={handleSubmitReview}>
                            <div style={{ marginBottom: '16px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                                    {translate('portal', 'pay_decision')}
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
                                        {translate('portal', 'pay_approve')}
                                    </label>
                                    <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', cursor: 'pointer' }}>
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

                            <div style={{ marginBottom: '20px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                                    {translate('portal', 'pay_verify_note')}
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    value={reviewReason}
                                    onChange={(e) => setReviewReason(e.target.value)}
                                    placeholder={reviewDecision === 'approved' ? translate('portal', 'pay_ph_approve') : translate('portal', 'pay_ph_reject')}
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
                                    {translate('portal', 'pay_cancel')}
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
                                    {processingReview ? translate('portal', 'pay_submitting') : (reviewDecision === 'approved' ? translate('portal', 'pay_confirm_approval') : translate('portal', 'pay_confirm_rejection'))}
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
                            {translate('portal', 'pay_settle_title')}
                        </h3>
                        <p style={{ margin: '6px 0 16px 0', fontSize: '13px', color: '#64748b' }}>
                            {translate('portal', 'pay_settle_meta', { name: settleModalRecord.user_name, channel: settleModalRecord.channel, amount: money(settleModalRecord.net_amount ?? settleModalRecord.amount) })}
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
                            <b>{translate('portal', 'pay_sod')}</b> {translate('portal', 'pay_sod_body')}
                        </div>

                        <form onSubmit={handleSubmitSettle}>
                            <div style={{ marginBottom: '14px' }}>
                                <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '4px' }}>
                                    {translate('portal', 'pay_fld_reference')}
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder={translate('portal', 'pay_ph_reference')}
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
                                    {translate('portal', 'pay_fld_paid_at')}
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
                                    {translate('portal', 'pay_fld_receipt')}
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
                                    {translate('portal', 'pay_fld_notes')}
                                </label>
                                <textarea
                                    required
                                    rows={2}
                                    placeholder={translate('portal', 'pay_ph_notes')}
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
                                    {translate('portal', 'pay_cancel')}
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

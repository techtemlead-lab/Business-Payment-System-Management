import React, { useState, useMemo } from 'react';
import {
    TrendingUp, ShieldCheck, ArrowDownRight, ArrowUpRight, DollarSign,
    Building2, Wallet, Landmark, Layers, CheckCircle2, AlertCircle, FileText,
    PieChart, ArrowRight, Store, Users, ShoppingBag
} from 'lucide-react';
import { money, date } from './UI';
import { useT } from '../shared/i18n/t';

interface CapitalFlowData {
    gross_cash_inflow: number;
    dealer_cash_inflow?: number;
    sub_dealer_cash_inflow?: number;
    channel_breakdown?: Array<{ channel: string; total_amount: number | string; count: number }>;
    company_share_60: number;
    management_reserve_2?: number;
    unassigned_territory_reserve?: number;
    total_company_deposits?: number;
    commission_pool_40: number;
    allocated_so_far: number;
    settled_disbursements?: number;
    maturing_commissions?: number;
    marketing_fund?: number;
    car_bike_fund?: number;
    tds_tax_withheld?: number;
    unallocated_pool_buffer?: number;
    commission_benefits_fund?: number;
    owner_benefits_fund_visible?: boolean;
}

interface CategoryAllocation {
    category: string;
    total_amount: number | string;
    count: number;
}

interface AllocationRecord {
    id: number;
    order_id: number;
    user_id: number;
    category: string;
    percentage: number | string;
    amount: number | string;
    is_settled: boolean;
    created_at: string;
    user?: {
        id: number;
        name: string;
        dealer_code: string;
    };
}

interface RetainedFundRow {
    id: number | string;
    fund?: string;
    category?: string;
    status?: string;
    amount?: string | number;
    company_share?: string | number;
    purchase_value?: string | number;
    rate_percentage?: string | number;
    order_number?: string;
    buyer_name?: string;
    buyer_code?: string;
    buyer_role?: string;
    division?: string | null;
    district?: string | null;
    upazila?: string | null;
    union?: string | null;
    area_level?: string | null;
    retained_by?: string | null;
    reason?: string;
    created_at?: string;
    mrp_basis?: string | number;
    total_allocated?: string | number;
    batch_unallocated?: string | number;
}

interface RetainedFundDetails {
    unassigned_geographic?: RetainedFundRow[];
    management_area?: RetainedFundRow[];
    override_reserves?: RetainedFundRow[];
    settlement_reserves?: RetainedFundRow[];
    marketing_fund?: RetainedFundRow[];
    car_bike_fund?: RetainedFundRow[];
    benefits_fund?: RetainedFundRow[];
    company_share_batches?: RetainedFundRow[];
    totals?: Record<string, number>;
}

interface PaymentAccountCard {
    id: number;
    label: string;
    channel: string;
    holder: string;
    account_number_masked?: string;
    account_number?: string;
    collects_payments?: boolean;
    pays_out?: boolean;
    is_active?: boolean;
}

interface CompanyTreasuryHubProps {
    flow: CapitalFlowData;
    allocations_by_category: CategoryAllocation[];
    recent_allocations: AllocationRecord[];
    retained_fund_details?: RetainedFundDetails | null;
    payment_accounts?: PaymentAccountCard[];
}

function placeLabel(row: RetainedFundRow): string {
    return [row.division, row.district, row.upazila, row.union].filter(Boolean).join(' / ') || '—';
}

function RetainedDetailTable({ title, hint, rows, amountKey = 'amount' }: { title: string; hint: string; rows: RetainedFundRow[]; amountKey?: 'amount' | 'company_share' }) {
    const translate = useT();
    if (!rows.length) {
        return (
            <div style={{ marginTop: 18, border: '1px dashed #cbd5e1', borderRadius: 10, padding: 16, background: '#f8fafc' }}>
                <b style={{ fontSize: 13, color: '#0f172a' }}>{title}</b>
                <p style={{ margin: '6px 0 0', fontSize: 12, color: '#64748b' }}>{translate('portal', 'tr_no_detail_entries')}</p>
            </div>
        );
    }
    return (
        <div style={{ marginTop: 18, border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden', background: '#ffffff' }}>
            <div style={{ padding: '12px 16px', borderBottom: '1px solid #e2e8f0', background: '#f8fafc' }}>
                <b style={{ fontSize: 13, color: '#0f172a' }}>{title}</b>
                <p style={{ margin: '4px 0 0', fontSize: 12, color: '#64748b' }}>{hint}</p>
            </div>
            <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', color: '#64748b', background: '#fcfcfd' }}>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'sal_col_posted')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'sal_col_order')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'tr_col_buyer')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'label_location')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'sal_col_purchase_value')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'tr_col_rate')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'col_amount')}</th>
                            <th style={{ padding: '10px 12px', fontWeight: 600 }}>{translate('portal', 'tr_col_detail')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={`${row.category}-${row.id}`} style={{ borderTop: '1px solid #eef2f7' }}>
                                <td style={{ padding: '10px 12px', whiteSpace: 'nowrap' }}>{date(row.created_at)}</td>
                                <td style={{ padding: '10px 12px' }}>{row.order_number || '—'}</td>
                                <td style={{ padding: '10px 12px' }}>
                                    <div style={{ fontWeight: 600, color: '#0f172a' }}>{row.buyer_name || '—'}</div>
                                    <div style={{ color: '#64748b' }}>{[row.buyer_code, row.buyer_role].filter(Boolean).join(' · ')}</div>
                                </td>
                                <td style={{ padding: '10px 12px' }}>
                                    <div>{placeLabel(row)}</div>
                                    {row.area_level && <div style={{ color: '#64748b' }}>{translate('portal', 'tr_level', { level: String(row.area_level) })}</div>}
                                </td>
                                <td style={{ padding: '10px 12px', whiteSpace: 'nowrap' }}>{money(row.purchase_value)}</td>
                                <td style={{ padding: '10px 12px' }}>{row.rate_percentage != null ? `${row.rate_percentage}%` : '—'}</td>
                                <td style={{ padding: '10px 12px', fontWeight: 800, color: '#0f172a', whiteSpace: 'nowrap' }}>{money(row[amountKey])}</td>
                                <td style={{ padding: '10px 12px', color: '#475569', minWidth: 220 }}>{row.reason || row.fund || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function CompanyTreasuryHub({ flow, allocations_by_category, recent_allocations, retained_fund_details = null, payment_accounts = [] }: CompanyTreasuryHubProps) {
    const translate = useT();
    const [activeTab, setActiveTab] = useState<'overview' | 'inflows' | 'deposits' | 'expenses' | 'ledger'>('overview');

    const grossCash = Number(flow.gross_cash_inflow) || 0;
    const dealerCash = Number(flow.dealer_cash_inflow) || 0; // fallback estimate if no separate orders yet
    const subDealerCash = Number(flow.sub_dealer_cash_inflow) || 0;
    const companyShare = Number(flow.company_share_60) || 0;
    const mgmtReserve = Number(flow.management_reserve_2) || 0;
    const unassignedReserve = Number(flow.unassigned_territory_reserve) || 0;
    const totalCompanyDeposits = Number(flow.total_company_deposits) || (companyShare + mgmtReserve + unassignedReserve);

    const maxPool = Number(flow.commission_pool_40) || 0;
    const allocated = Number(flow.allocated_so_far) || 0;
    const settledDisbursements = Number(flow.settled_disbursements) || 0;
    const maturingCommissions = Number(flow.maturing_commissions) || 0;
    const marketingFund = Number(flow.marketing_fund) || 0;
    const carBikeFund = Number(flow.car_bike_fund) || 0;
    const tdsTax = Number(flow.tds_tax_withheld) || 0;
    const ownerBenefitsVisible = Boolean(flow.owner_benefits_fund_visible);
    const unallocatedBuffer = ownerBenefitsVisible
        ? (Number(flow.commission_benefits_fund ?? flow.unallocated_pool_buffer) || 0)
        : 0;
    const details = retained_fund_details || {};
    const unassignedRows = details.unassigned_geographic || [];
    const managementRows = details.management_area || [];
    const companyShareRows = details.company_share_batches || [];
    const benefitsRows = details.benefits_fund || [];
    const overrideRows = details.override_reserves || [];
    const settlementRows = details.settlement_reserves || [];
    const marketingRows = details.marketing_fund || [];
    const carBikeRows = details.car_bike_fund || [];

    // Channel breakdown
    const channels = flow.channel_breakdown ?? [];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Header & Sub-Navigation for Treasury */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                padding: '20px 24px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 16
            }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ background: '#011627', color: '#ffffff', padding: '4px 10px', borderRadius: 6, fontSize: 11, fontWeight: 700 }}>
                            {translate('portal', 'tr_badge_company')}
                        </span>
                        <span style={{ fontSize: 12, color: '#64748b', fontWeight: 600 }}>{translate('portal', 'tr_subtitle')}</span>
                    </div>
                    <h2 style={{ fontSize: 22, fontWeight: 800, color: '#011627', margin: '6px 0 2px' }}>
                        {translate('portal', 'tr_heading')}
                    </h2>
                    <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                        {translate('portal', 'tr_heading_hint')}
                    </p>
                </div>

                <div style={{ display: 'flex', gap: 6, background: '#f8fafc', padding: 4, borderRadius: 8, border: '1px solid #e2e8f0' }}>
                    <button
                        type="button"
                        onClick={() => setActiveTab('overview')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            border: 0,
                            cursor: 'pointer',
                            background: activeTab === 'overview' ? '#011627' : 'transparent',
                            color: activeTab === 'overview' ? '#ffffff' : '#475569'
                        }}
                    >
                        {translate('portal', 'tr_tab_overview')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('inflows')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            border: 0,
                            cursor: 'pointer',
                            background: activeTab === 'inflows' ? '#011627' : 'transparent',
                            color: activeTab === 'inflows' ? '#ffffff' : '#475569'
                        }}
                    >
                        {translate('portal', 'tr_tab_inflows')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('deposits')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            border: 0,
                            cursor: 'pointer',
                            background: activeTab === 'deposits' ? '#011627' : 'transparent',
                            color: activeTab === 'deposits' ? '#ffffff' : '#475569'
                        }}
                    >
                        {translate('portal', 'tr_tab_deposits')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('expenses')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            border: 0,
                            cursor: 'pointer',
                            background: activeTab === 'expenses' ? '#011627' : 'transparent',
                            color: activeTab === 'expenses' ? '#ffffff' : '#475569'
                        }}
                    >
                        {translate('portal', 'tr_tab_expenses')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('ledger')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            border: 0,
                            cursor: 'pointer',
                            background: activeTab === 'ledger' ? '#011627' : 'transparent',
                            color: activeTab === 'ledger' ? '#ffffff' : '#475569'
                        }}
                    >
                        {translate('portal', 'tr_tab_ledger')}
                    </button>
                </div>
            </div>

            {/* Top 4 KPI Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'tr_kpi_received')}</span>
                        <ArrowDownRight size={18} color="#059669" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#011627', marginTop: 6 }}>
                        {money(grossCash)}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{translate('portal', 'tr_kpi_received_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1.5px solid #006837', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#006837', fontSize: 12, fontWeight: 700 }}>{translate('portal', 'tr_kpi_deposited')}</span>
                        <Landmark size={18} color="#006837" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 6 }}>
                        {money(totalCompanyDeposits)}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>{translate('portal', 'tr_kpi_deposited_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #fed7aa', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#c2410c', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'tr_kpi_pool')}</span>
                        <ArrowUpRight size={18} color="#ea580c" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#c2410c', marginTop: 6 }}>
                        {money(maxPool)}
                    </div>
                    <div style={{ fontSize: 11, color: '#9a3412', marginTop: 2 }}>{translate('portal', 'tr_kpi_pool_hint')}</div>
                </div>

                {ownerBenefitsVisible ? (
                    <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 18 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <span style={{ color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'tr_kpi_benefits')}</span>
                            <ShieldCheck size={18} color="#2563eb" />
                        </div>
                        <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 6 }}>
                            {money(unallocatedBuffer)}
                        </div>
                        <div style={{ fontSize: 11, color: '#2563eb', marginTop: 2 }}>{translate('portal', 'tr_kpi_benefits_hint')}</div>
                    </div>
                ) : (
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 18 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <span style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>{translate('portal', 'tr_kpi_settled')}</span>
                            <ShieldCheck size={18} color="#94a3b8" />
                        </div>
                        <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a', marginTop: 6 }}>
                            {money(Number(flow.settled_disbursements) || 0)}
                        </div>
                        <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{translate('portal', 'tr_kpi_settled_hint')}</div>
                    </div>
                )}
            </div>

            {/* TAB CONTENT: Overview */}
            {activeTab === 'overview' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    {/* Visual 3-Stage Capital Flow Architecture */}
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
                            <div>
                                <span style={{ fontSize: 11, fontWeight: 700, color: '#006837', letterSpacing: '0.05em' }}>
                                    {translate('portal', 'tr_arch_badge')}
                                </span>
                                <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '4px 0 0' }}>
                                    {translate('portal', 'tr_flow_title')}
                                </h3>
                            </div>
                            <div style={{ background: '#ecfdf5', color: '#047857', padding: '4px 12px', borderRadius: 20, fontSize: 12, fontWeight: 700 }}>
                                {translate('portal', 'tr_flow_balanced')}
                            </div>
                        </div>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
                            {/* Block 1 */}
                            <div style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#0f172a', fontWeight: 700, fontSize: 14 }}>
                                    <span style={{ background: '#0f172a', color: '#ffffff', width: 22, height: 22, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>1</span>
                                    {translate('portal', 'tr_flow_from')}
                                </div>
                                <div style={{ fontSize: 20, fontWeight: 800, color: '#0f172a', margin: '12px 0 6px' }}>
                                    {money(grossCash)}
                                </div>
                                <p style={{ fontSize: 12, color: '#64748b', margin: 0, lineHeight: 1.5 }}>
                                    {translate('portal', 'tr_flow_from_body')}
                                </p>
                                <button type="button" onClick={() => setActiveTab('inflows')} style={{ marginTop: 12, color: '#2e3192', background: 'transparent', border: 0, fontWeight: 700, fontSize: 12, cursor: 'pointer', padding: 0 }}>
                                    {translate('portal', 'tr_flow_from_cta')}
                                </button>
                            </div>

                            {/* Block 2 */}
                            <div style={{ background: '#f0fdf4', border: '1.5px solid #86efac', borderRadius: 10, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#006837', fontWeight: 700, fontSize: 14 }}>
                                    <span style={{ background: '#006837', color: '#ffffff', width: 22, height: 22, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>2</span>
                                    {translate('portal', 'tr_flow_deposit')}
                                </div>
                                <div style={{ fontSize: 20, fontWeight: 800, color: '#006837', margin: '12px 0 6px' }}>
                                    {money(totalCompanyDeposits)}
                                </div>
                                <p style={{ fontSize: 12, color: '#166534', margin: 0, lineHeight: 1.5 }}>
                                    {translate('portal', 'tr_flow_deposit_body')}
                                </p>
                                <button type="button" onClick={() => setActiveTab('deposits')} style={{ marginTop: 12, color: '#006837', background: 'transparent', border: 0, fontWeight: 700, fontSize: 12, cursor: 'pointer', padding: 0 }}>
                                    {translate('portal', 'tr_flow_deposit_cta')}
                                </button>
                            </div>

                            {/* Block 3 */}
                            <div style={{ background: '#fff7ed', border: '1px solid #fdba74', borderRadius: 10, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#9a3412', fontWeight: 700, fontSize: 14 }}>
                                    <span style={{ background: '#ea580c', color: '#ffffff', width: 22, height: 22, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>3</span>
                                    {translate('portal', 'tr_flow_spent')}
                                </div>
                                <div style={{ fontSize: 20, fontWeight: 800, color: '#9a3412', margin: '12px 0 6px' }}>
                                    {money(allocated)}
                                </div>
                                <p style={{ fontSize: 12, color: '#9a3412', margin: 0, lineHeight: 1.5 }}>
                                    {translate('portal', 'tr_flow_spent_body')}
                                </p>
                                <button type="button" onClick={() => setActiveTab('expenses')} style={{ marginTop: 12, color: '#ea580c', background: 'transparent', border: 0, fontWeight: 700, fontSize: 12, cursor: 'pointer', padding: 0 }}>
                                    {translate('portal', 'tr_flow_spent_cta')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: 1. Sources of Money (Inflow) */}
            {activeTab === 'inflows' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '0 0 4px' }}>
                            {translate('portal', 'tr_inflow_title')}
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            {translate('portal', 'tr_inflow_hint')}
                        </p>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16 }}>
                            {/* Dealer Wholesale Purchases */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <Store size={22} color="#2e3192" />
                                    <div>
                                        <b style={{ fontSize: 14, color: '#0f172a' }}>{translate('portal', 'tr_inflow_dealer')}</b>
                                        <div style={{ fontSize: 11, color: '#64748b' }}>{translate('portal', 'tr_inflow_dealer_hint')}</div>
                                    </div>
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#2e3192', marginTop: 14 }}>
                                    {money(dealerCash)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                                    {translate('portal', 'tr_inflow_share', { pct: String(grossCash > 0 ? ((dealerCash / grossCash) * 100).toFixed(1) : 0) })}
                                </div>
                            </div>

                            {/* Sub-Dealer Orders */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <Users size={22} color="#059669" />
                                    <div>
                                        <b style={{ fontSize: 14, color: '#0f172a' }}>{translate('portal', 'tr_inflow_subdealer')}</b>
                                        <div style={{ fontSize: 11, color: '#64748b' }}>{translate('portal', 'tr_inflow_subdealer_hint')}</div>
                                    </div>
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#059669', marginTop: 14 }}>
                                    {money(subDealerCash)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                                    {translate('portal', 'tr_inflow_share', { pct: String(grossCash > 0 ? ((subDealerCash / grossCash) * 100).toFixed(1) : 0) })}
                                </div>
                            </div>
                        </div>

                        {/* Payment Collection Channels */}
                        <div style={{ marginTop: 24 }}>
                            <h4 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', marginBottom: 12 }}>
                                {translate('portal', 'tr_channels_title')}
                            </h4>
                            <div style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #cbd5e1' }}>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_channel')}</th>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_verified')}</th>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_share')}</th>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'col_status')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {channels.map((ch, idx) => {
                                            const amt = Number(ch.total_amount) || 0;
                                            const pct = grossCash > 0 ? ((amt / grossCash) * 100).toFixed(1) : '0';
                                            return (
                                                <tr key={idx} style={{ borderBottom: '1px solid #e2e8f0' }}>
                                                    <td style={{ padding: '12px 14px', fontWeight: 600, color: '#0f172a' }}>{ch.channel}</td>
                                                    <td style={{ padding: '12px 14px', fontWeight: 700, color: '#006837' }}>{money(amt)}</td>
                                                    <td style={{ padding: '12px 14px', color: '#64748b' }}>{pct}%</td>
                                                    <td style={{ padding: '12px 14px' }}>
                                                        <span style={{ background: '#ecfdf5', color: '#047857', padding: '2px 8px', borderRadius: 4, fontSize: 11, fontWeight: 700 }}>
                                                            {translate('portal', 'tr_active_verified')}
                                                        </span>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: 2. Deposits & Retained Capital */}
            {activeTab === 'deposits' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '0 0 4px' }}>
                            {translate('portal', 'tr_deposit_title')}
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            {translate('portal', 'tr_deposit_hint')}
                        </p>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
                            {/* 60% Operating Retention */}
                            <div style={{ background: '#f0fdf4', border: '1.5px solid #86efac', borderRadius: 10, padding: 20 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#166534' }}>{translate('portal', 'tr_dep_operating')}</span>
                                    <Building2 size={20} color="#006837" />
                                </div>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#006837', margin: '10px 0 4px' }}>
                                    {money(companyShare)}
                                </div>
                                <p style={{ fontSize: 11, color: '#166534', margin: 0, lineHeight: 1.5 }}>
                                    {translate('portal', 'tr_dep_operating_body')}
                                </p>
                            </div>

                            {/* 2% Management Area Share */}
                            <div style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 20 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#334155' }}>{translate('portal', 'tr_dep_mgmt')}</span>
                                    <Landmark size={20} color="#2e3192" />
                                </div>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#0f172a', margin: '10px 0 4px' }}>
                                    {money(mgmtReserve)}
                                </div>
                                <p style={{ fontSize: 11, color: '#64748b', margin: 0, lineHeight: 1.5 }}>
                                    {translate('portal', 'tr_dep_mgmt_body')}
                                </p>
                            </div>

                            {/* Unassigned Territory Reserve */}
                            <div style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 20 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#334155' }}>{translate('portal', 'tr_dep_geo')}</span>
                                    <ShieldCheck size={20} color="#059669" />
                                </div>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#0f172a', margin: '10px 0 4px' }}>
                                    {money(unassignedReserve)}
                                </div>
                                <p style={{ fontSize: 11, color: '#64748b', margin: 0, lineHeight: 1.5 }}>
                                    {translate('portal', 'tr_dep_geo_body')}
                                </p>
                            </div>
                        </div>

                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_operating')}
                            hint={translate('portal', 'tr_detail_operating_hint')}
                            rows={companyShareRows}
                            amountKey="company_share"
                        />
                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_geo')}
                            hint={translate('portal', 'tr_detail_geo_hint')}
                            rows={unassignedRows}
                        />
                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_mgmt')}
                            hint={translate('portal', 'tr_detail_mgmt_hint')}
                            rows={managementRows}
                        />
                        {ownerBenefitsVisible && (
                            <RetainedDetailTable
                                title={translate('portal', 'tr_detail_benefits')}
                                hint={translate('portal', 'tr_detail_benefits_hint')}
                                rows={benefitsRows}
                            />
                        )}
                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_override')}
                            hint={translate('portal', 'tr_detail_override_hint')}
                            rows={overrideRows}
                        />
                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_settlement')}
                            hint={translate('portal', 'tr_detail_settlement_hint')}
                            rows={settlementRows}
                        />

                        <div style={{ marginTop: 24 }}>
                            <h4 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', marginBottom: 12 }}>
                                {translate('portal', 'tr_accounts_title')}
                            </h4>
                            {payment_accounts.length === 0 ? (
                                <div style={{ padding: 14, border: '1px dashed #cbd5e1', borderRadius: 8, background: '#f8fafc', color: '#64748b', fontSize: 13 }}>
                                    {translate('portal', 'tr_accounts_empty')}
                                </div>
                            ) : (
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))', gap: 12 }}>
                                    {payment_accounts.map((account) => {
                                        const roles = [
                                            account.collects_payments ? translate('portal', 'tr_role_collection') : null,
                                            account.pays_out ? translate('portal', 'tr_role_payout') : null,
                                            account.is_active === false ? translate('portal', 'tr_role_inactive') : null,
                                        ].filter(Boolean).join(' · ');
                                        return (
                                            <div key={account.id} style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 8, background: '#ffffff' }}>
                                                <b style={{ fontSize: 13, color: '#0f172a' }}>{account.label}</b>
                                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                                    {account.channel} · {account.account_number_masked || account.account_number || '••••'} · {account.holder}
                                                </div>
                                                <div style={{ fontSize: 12, fontWeight: 700, color: '#0f766e', marginTop: 8 }}>{roles || translate('portal', 'tr_account_configured')}</div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: 3. Where Money is Spent (Expenses & Funds) */}
            {activeTab === 'expenses' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '0 0 4px' }}>
                            {translate('portal', 'tr_expense_title')}
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            {translate('portal', 'tr_expense_hint')}
                        </p>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16 }}>
                            {/* Commission Disbursements */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>{translate('portal', 'tr_exp_payouts')}</span>
                                    <Wallet size={18} color="#059669" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#059669', margin: '8px 0 4px' }}>
                                    {money(settledDisbursements)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    {translate('portal', 'tr_exp_payouts_hint')}
                                </div>
                            </div>

                            {/* Marketing Fund */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>{translate('portal', 'tr_exp_marketing')}</span>
                                    <PieChart size={18} color="#2e3192" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#2e3192', margin: '8px 0 4px' }}>
                                    {money(marketingFund)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    {translate('portal', 'tr_exp_marketing_hint')}
                                </div>
                            </div>

                            {/* Car & Bike Incentive Fund */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>{translate('portal', 'tr_exp_vehicle')}</span>
                                    <ShieldCheck size={18} color="#d97706" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#d97706', margin: '8px 0 4px' }}>
                                    {money(carBikeFund)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    {translate('portal', 'tr_exp_vehicle_hint')}
                                </div>
                            </div>

                            {/* Statutory TDS Tax */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>{translate('portal', 'tr_exp_withholding')}</span>
                                    <FileText size={18} color="#e11d48" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#e11d48', margin: '8px 0 4px' }}>
                                    {money(tdsTax)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    {translate('portal', 'tr_exp_withholding_hint')}
                                </div>
                            </div>
                        </div>

                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_marketing')}
                            hint={translate('portal', 'tr_detail_marketing_hint')}
                            rows={marketingRows}
                        />
                        <RetainedDetailTable
                            title={translate('portal', 'tr_detail_vehicle')}
                            hint={translate('portal', 'tr_detail_vehicle_hint')}
                            rows={carBikeRows}
                        />

                        {/* Category Allocations Table */}
                        <div style={{ marginTop: 24 }}>
                            <h4 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', marginBottom: 12 }}>
                                {translate('portal', 'tr_slices_title')}
                            </h4>
                            <div style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #cbd5e1' }}>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_category')}</th>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_alloc_count')}</th>
                                            <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_total')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {allocations_by_category.map((cat, idx) => (
                                            <tr key={idx} style={{ borderBottom: '1px solid #e2e8f0' }}>
                                                <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>
                                                    {cat.category.replaceAll('_', ' ').toUpperCase()}
                                                </td>
                                                <td style={{ padding: '10px 14px', color: '#64748b' }}>{translate('portal', 'tr_alloc_count', { count: String(cat.count) })}</td>
                                                <td style={{ padding: '10px 14px', fontWeight: 700, color: '#006837' }}>
                                                    {money(cat.total_amount)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: Financial Ledger */}
            {activeTab === 'ledger' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '0 0 4px' }}>
                            {translate('portal', 'tr_ledger_title')}
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            {translate('portal', 'tr_ledger_hint')}
                        </p>

                        <div style={{ overflowX: 'auto' }}>
                            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                <thead>
                                    <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #cbd5e1' }}>
                                        <th style={{ padding: '10px 14px' }}>{translate('portal', 'col_date')}</th>
                                        <th style={{ padding: '10px 14px' }}>{translate('portal', 'tr_col_recipient')}</th>
                                        <th style={{ padding: '10px 14px' }}>{translate('portal', 'col_category')}</th>
                                        <th style={{ padding: '10px 14px' }}>{translate('portal', 'col_amount')}</th>
                                        <th style={{ padding: '10px 14px' }}>{translate('portal', 'col_status')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent_allocations.length > 0 ? (
                                        recent_allocations.map((rec) => (
                                            <tr key={rec.id} style={{ borderBottom: '1px solid #e2e8f0' }}>
                                                <td style={{ padding: '10px 14px', color: '#64748b' }}>{date(rec.created_at)}</td>
                                                <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>
                                                    {rec.user ? `${rec.user.name} (${rec.user.dealer_code})` : translate('portal', 'tr_company_reserve_pool')}
                                                </td>
                                                <td style={{ padding: '10px 14px' }}>
                                                    <span style={{ fontSize: 11, fontWeight: 600, color: '#475569' }}>
                                                        {rec.category.replaceAll('_', ' ')}
                                                    </span>
                                                </td>
                                                <td style={{ padding: '10px 14px', fontWeight: 700, color: '#006837' }}>
                                                    {money(rec.amount)}
                                                </td>
                                                <td style={{ padding: '10px 14px' }}>
                                                    <span style={{
                                                        background: rec.is_settled ? '#ecfdf5' : '#fefce8',
                                                        color: rec.is_settled ? '#047857' : '#854d0e',
                                                        padding: '2px 8px',
                                                        borderRadius: 4,
                                                        fontSize: 11,
                                                        fontWeight: 700
                                                    }}>
                                                        {rec.is_settled ? translate('portal', 'tr_status_settled') : translate('portal', 'tr_status_pending')}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={5} style={{ padding: 24, textAlign: 'center', color: '#94a3b8' }}>
                                                {translate('portal', 'tr_ledger_empty')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

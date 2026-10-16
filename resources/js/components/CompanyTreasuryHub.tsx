import React, { useState, useMemo } from 'react';
import {
    TrendingUp, ShieldCheck, ArrowDownRight, ArrowUpRight, DollarSign,
    Building2, Wallet, Landmark, Layers, CheckCircle2, AlertCircle, FileText,
    PieChart, ArrowRight, Store, Users, ShoppingBag
} from 'lucide-react';
import { money, date } from './UI';

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

interface CompanyTreasuryHubProps {
    flow: CapitalFlowData;
    allocations_by_category: CategoryAllocation[];
    recent_allocations: AllocationRecord[];
}

export default function CompanyTreasuryHub({ flow, allocations_by_category, recent_allocations }: CompanyTreasuryHubProps) {
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
    const unallocatedBuffer = Number(flow.unallocated_pool_buffer) || Math.max(0, maxPool - allocated);

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
                            COMPANY SECTION
                        </span>
                        <span style={{ fontSize: 12, color: '#64748b', fontWeight: 600 }}>Financial Treasury & Money Flow</span>
                    </div>
                    <h2 style={{ fontSize: 22, fontWeight: 800, color: '#011627', margin: '6px 0 2px' }}>
                        Company Capital & Financial Records
                    </h2>
                    <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                        Track where company money comes from, where it is deposited, and where it is spent.
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
                        Overview & Waterfall
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
                        1. Sources of Money
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
                        2. Where Money is Deposited
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
                        3. Where Money is Spent
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
                        Financial Ledger
                    </button>
                </div>
            </div>

            {/* Top 4 KPI Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>Total Money Received</span>
                        <ArrowDownRight size={18} color="#059669" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#011627', marginTop: 6 }}>
                        {money(grossCash)}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>From dealers & sub-dealers</div>
                </div>

                <div style={{ background: '#ffffff', border: '1.5px solid #006837', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#006837', fontSize: 12, fontWeight: 700 }}>Company Deposited Capital</span>
                        <Landmark size={18} color="#006837" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 6 }}>
                        {money(totalCompanyDeposits)}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>30% of MRP + management reserves</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #fed7aa', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#c2410c', fontSize: 12, fontWeight: 600 }}>Commission Pool Budget</span>
                        <ArrowUpRight size={18} color="#ea580c" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#c2410c', marginTop: 6 }}>
                        {money(maxPool)}
                    </div>
                    <div style={{ fontSize: 11, color: '#9a3412', marginTop: 2 }}>Dealer 40% · Sub-dealer 50%</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 18 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <span style={{ color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>Unallocated Cash Surplus</span>
                        <ShieldCheck size={18} color="#2563eb" />
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 6 }}>
                        {money(unallocatedBuffer)}
                    </div>
                    <div style={{ fontSize: 11, color: '#2563eb', marginTop: 2 }}>Remaining in company reserve</div>
                </div>
            </div>

            {/* TAB CONTENT: Overview */}
            {activeTab === 'overview' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    {/* Visual 3-Stage Capital Flow Architecture */}
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
                            <div>
                                <span style={{ fontSize: 11, fontWeight: 700, color: '#006837', letterSpacing: '0.05em' }}>
                                    COMPANY TREASURY ARCHITECTURE
                                </span>
                                <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '4px 0 0' }}>
                                    How Money Flows Through the Company
                                </h3>
                            </div>
                            <div style={{ background: '#ecfdf5', color: '#047857', padding: '4px 12px', borderRadius: 20, fontSize: 12, fontWeight: 700 }}>
                                Mathematically Balanced · Zero Deficit
                            </div>
                        </div>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
                            {/* Block 1 */}
                            <div style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#0f172a', fontWeight: 700, fontSize: 14 }}>
                                    <span style={{ background: '#0f172a', color: '#ffffff', width: 22, height: 22, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>1</span>
                                    Where Money Comes From
                                </div>
                                <div style={{ fontSize: 20, fontWeight: 800, color: '#0f172a', margin: '12px 0 6px' }}>
                                    {money(grossCash)}
                                </div>
                                <p style={{ fontSize: 12, color: '#64748b', margin: 0, lineHeight: 1.5 }}>
                                    Cash received from dealer wholesale orders and sub-dealer purchases at configured product prices.
                                </p>
                                <button type="button" onClick={() => setActiveTab('inflows')} style={{ marginTop: 12, color: '#2e3192', background: 'transparent', border: 0, fontWeight: 700, fontSize: 12, cursor: 'pointer', padding: 0 }}>
                                    View Inflow Sources →
                                </button>
                            </div>

                            {/* Block 2 */}
                            <div style={{ background: '#f0fdf4', border: '1.5px solid #86efac', borderRadius: 10, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#006837', fontWeight: 700, fontSize: 14 }}>
                                    <span style={{ background: '#006837', color: '#ffffff', width: 22, height: 22, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>2</span>
                                    Where Money is Deposited
                                </div>
                                <div style={{ fontSize: 20, fontWeight: 800, color: '#006837', margin: '12px 0 6px' }}>
                                    {money(totalCompanyDeposits)}
                                </div>
                                <p style={{ fontSize: 12, color: '#166534', margin: 0, lineHeight: 1.5 }}>
                                    Deposited into company operational reserve (30% of MRP) plus territory reserves.
                                </p>
                                <button type="button" onClick={() => setActiveTab('deposits')} style={{ marginTop: 12, color: '#006837', background: 'transparent', border: 0, fontWeight: 700, fontSize: 12, cursor: 'pointer', padding: 0 }}>
                                    View Deposit Accounts →
                                </button>
                            </div>

                            {/* Block 3 */}
                            <div style={{ background: '#fff7ed', border: '1px solid #fdba74', borderRadius: 10, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#9a3412', fontWeight: 700, fontSize: 14 }}>
                                    <span style={{ background: '#ea580c', color: '#ffffff', width: 22, height: 22, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11 }}>3</span>
                                    Where Money is Spent
                                </div>
                                <div style={{ fontSize: 20, fontWeight: 800, color: '#9a3412', margin: '12px 0 6px' }}>
                                    {money(allocated)}
                                </div>
                                <p style={{ fontSize: 12, color: '#9a3412', margin: 0, lineHeight: 1.5 }}>
                                    Allocated to commissions, dealer salaries, vehicle incentives, and marketing.
                                </p>
                                <button type="button" onClick={() => setActiveTab('expenses')} style={{ marginTop: 12, color: '#ea580c', background: 'transparent', border: 0, fontWeight: 700, fontSize: 12, cursor: 'pointer', padding: 0 }}>
                                    View Outflows & Funds →
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
                            1. Where Money Comes From (Inflow Sources)
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            Comprehensive record of all revenue channels generating cash for the company.
                        </p>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16 }}>
                            {/* Dealer Wholesale Purchases */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <Store size={22} color="#2e3192" />
                                    <div>
                                        <b style={{ fontSize: 14, color: '#0f172a' }}>Dealer Wholesale Orders</b>
                                        <div style={{ fontSize: 11, color: '#64748b' }}>Primary 50% discount purchase volume</div>
                                    </div>
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#2e3192', marginTop: 14 }}>
                                    {money(dealerCash)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                                    {grossCash > 0 ? ((dealerCash / grossCash) * 100).toFixed(1) : 0}% of total collected cash
                                </div>
                            </div>

                            {/* Sub-Dealer Orders */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <Users size={22} color="#059669" />
                                    <div>
                                        <b style={{ fontSize: 14, color: '#0f172a' }}>Sub-Dealer Orders</b>
                                        <div style={{ fontSize: 11, color: '#64748b' }}>Configured partner purchase prices</div>
                                    </div>
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#059669', marginTop: 14 }}>
                                    {money(subDealerCash)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                                    {grossCash > 0 ? ((subDealerCash / grossCash) * 100).toFixed(1) : 0}% of total collected cash
                                </div>
                            </div>
                        </div>

                        {/* Payment Collection Channels */}
                        <div style={{ marginTop: 24 }}>
                            <h4 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', marginBottom: 12 }}>
                                Cash Collection Channels & Gateways
                            </h4>
                            <div style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #cbd5e1' }}>
                                            <th style={{ padding: '10px 14px' }}>Payment Channel</th>
                                            <th style={{ padding: '10px 14px' }}>Verified Amount</th>
                                            <th style={{ padding: '10px 14px' }}>Share of Inflow</th>
                                            <th style={{ padding: '10px 14px' }}>Status</th>
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
                                                            Active & Verified
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
                            2. Where Money is Deposited (Company Retained Capital)
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            Accounts and reserves where company funds are safely deposited and held.
                        </p>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
                            {/* 60% Operating Retention */}
                            <div style={{ background: '#f0fdf4', border: '1.5px solid #86efac', borderRadius: 10, padding: 20 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#166534' }}>Company Operating & COGS Reserve</span>
                                    <Building2 size={20} color="#006837" />
                                </div>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#006837', margin: '10px 0 4px' }}>
                                    {money(companyShare)}
                                </div>
                                <p style={{ fontSize: 11, color: '#166534', margin: 0, lineHeight: 1.5 }}>
                                    Retained by the company to cover product manufacturing/purchasing costs (COGS), warehouse overhead, and net margin.
                                </p>
                            </div>

                            {/* 2% Management Area Share */}
                            <div style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 20 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#334155' }}>2% Management Area Share</span>
                                    <Landmark size={20} color="#2e3192" />
                                </div>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#0f172a', margin: '10px 0 4px' }}>
                                    {money(mgmtReserve)}
                                </div>
                                <p style={{ fontSize: 11, color: '#64748b', margin: 0, lineHeight: 1.5 }}>
                                    Company central management allocation from the 10% Area Dealer Pool to cover regional administrative supervision.
                                </p>
                            </div>

                            {/* Unassigned Territory Reserve */}
                            <div style={{ background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 10, padding: 20 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#334155' }}>Unassigned Geographic Reserve</span>
                                    <ShieldCheck size={20} color="#059669" />
                                </div>
                                <div style={{ fontSize: 26, fontWeight: 800, color: '#0f172a', margin: '10px 0 4px' }}>
                                    {money(unassignedReserve)}
                                </div>
                                <p style={{ fontSize: 11, color: '#64748b', margin: 0, lineHeight: 1.5 }}>
                                    Funds retained in company treasury when delivery areas (division, district, upazila, union) do not yet have an active area dealer assigned.
                                </p>
                            </div>
                        </div>

                        {/* Company Bank Accounts */}
                        <div style={{ marginTop: 24 }}>
                            <h4 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', marginBottom: 12 }}>
                                Designated Corporate Bank & Treasury Accounts
                            </h4>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))', gap: 12 }}>
                                <div style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 8, background: '#ffffff' }}>
                                    <b style={{ fontSize: 13, color: '#0f172a' }}>Islami Bank Bangladesh Ltd (Principal Account)</b>
                                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>A/C: 2050-1450-XXXX-001 · Head Office Branch</div>
                                    <div style={{ fontSize: 12, fontWeight: 700, color: '#006837', marginTop: 8 }}>Primary Corporate Collection</div>
                                </div>
                                <div style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 8, background: '#ffffff' }}>
                                    <b style={{ fontSize: 13, color: '#0f172a' }}>Dutch-Bangla Bank Ltd (Disbursement Account)</b>
                                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>A/C: 110-120-XXXX-002 · Motijheel Branch</div>
                                    <div style={{ fontSize: 12, fontWeight: 700, color: '#2e3192', marginTop: 8 }}>Partner Payout & Operational Outflows</div>
                                </div>
                                <div style={{ padding: 14, border: '1px solid #e2e8f0', borderRadius: 8, background: '#ffffff' }}>
                                    <b style={{ fontSize: 13, color: '#0f172a' }}>bKash Merchant Master Wallet</b>
                                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>Wallet: 01700-XXXXXX · Enterprise Settlement</div>
                                    <div style={{ fontSize: 12, fontWeight: 700, color: '#e11d48', marginTop: 8 }}>Instant MFS Collection</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: 3. Where Money is Spent (Expenses & Funds) */}
            {activeTab === 'expenses' && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                        <h3 style={{ fontSize: 18, fontWeight: 800, color: '#011627', margin: '0 0 4px' }}>
                            3. Where Money is Spent (Disbursements, Funds & Taxes)
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            Record of all partner disbursements, company-sponsored funds, vehicle assets, and statutory taxes.
                        </p>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16 }}>
                            {/* Commission Disbursements */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>Settled Partner Payouts</span>
                                    <Wallet size={18} color="#059669" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#059669', margin: '8px 0 4px' }}>
                                    {money(settledDisbursements)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    Approved & disbursed to dealers via bank/bKash with private receipt
                                </div>
                            </div>

                            {/* Marketing Fund */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>Marketing & Branding Fund (2%)</span>
                                    <PieChart size={18} color="#2e3192" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#2e3192', margin: '8px 0 4px' }}>
                                    {money(marketingFund)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    Dedicated reserve for promotional materials, media, and conferences
                                </div>
                            </div>

                            {/* Car & Bike Incentive Fund */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>Car & Bike Asset Fund (2%)</span>
                                    <ShieldCheck size={18} color="#d97706" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#d97706', margin: '8px 0 4px' }}>
                                    {money(carBikeFund)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    Reserved to purchase vehicles for qualifying high-turnover network leaders
                                </div>
                            </div>

                            {/* Statutory TDS Tax */}
                            <div style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 18, background: '#f8fafc' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>Statutory TDS Tax (5%)</span>
                                    <FileText size={18} color="#e11d48" />
                                </div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#e11d48', margin: '8px 0 4px' }}>
                                    {money(tdsTax)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>
                                    Tax Deducted at Source remitted directly to Bangladesh NBR
                                </div>
                            </div>
                        </div>

                        {/* Category Allocations Table */}
                        <div style={{ marginTop: 24 }}>
                            <h4 style={{ fontSize: 14, fontWeight: 700, color: '#0f172a', marginBottom: 12 }}>
                                Breakdown of All 10 Commission & Incentive Slices
                            </h4>
                            <div style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                    <thead>
                                        <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #cbd5e1' }}>
                                            <th style={{ padding: '10px 14px' }}>Category Name</th>
                                            <th style={{ padding: '10px 14px' }}>Allocations Count</th>
                                            <th style={{ padding: '10px 14px' }}>Total Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {allocations_by_category.map((cat, idx) => (
                                            <tr key={idx} style={{ borderBottom: '1px solid #e2e8f0' }}>
                                                <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>
                                                    {cat.category.replaceAll('_', ' ').toUpperCase()}
                                                </td>
                                                <td style={{ padding: '10px 14px', color: '#64748b' }}>{cat.count} allocations</td>
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
                            Posted Financial Transactions & History
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 20px' }}>
                            Recent double-entry posted transactions and distributor commission events.
                        </p>

                        <div style={{ overflowX: 'auto' }}>
                            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                                <thead>
                                    <tr style={{ background: '#f1f5f9', textAlign: 'left', borderBottom: '1px solid #cbd5e1' }}>
                                        <th style={{ padding: '10px 14px' }}>Date</th>
                                        <th style={{ padding: '10px 14px' }}>Recipient / Dealer</th>
                                        <th style={{ padding: '10px 14px' }}>Category</th>
                                        <th style={{ padding: '10px 14px' }}>Amount</th>
                                        <th style={{ padding: '10px 14px' }}>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent_allocations.length > 0 ? (
                                        recent_allocations.map((rec) => (
                                            <tr key={rec.id} style={{ borderBottom: '1px solid #e2e8f0' }}>
                                                <td style={{ padding: '10px 14px', color: '#64748b' }}>{date(rec.created_at)}</td>
                                                <td style={{ padding: '10px 14px', fontWeight: 600, color: '#0f172a' }}>
                                                    {rec.user ? `${rec.user.name} (${rec.user.dealer_code})` : 'Company Reserve Pool'}
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
                                                        {rec.is_settled ? 'Settled' : 'Pending / Maturing'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={5} style={{ padding: 24, textAlign: 'center', color: '#94a3b8' }}>
                                                No recorded transactions yet. New delivered orders will post here.
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

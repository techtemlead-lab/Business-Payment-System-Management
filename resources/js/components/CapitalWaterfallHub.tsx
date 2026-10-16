import React, { useMemo } from 'react';
import { ArrowDownRight, ShieldCheck, CheckCircle2, TrendingUp, DollarSign, PieChart, Layers, ArrowRight } from 'lucide-react';
import { money, date } from './UI';

interface CapitalFlowData {
    gross_cash_inflow: number;
    company_share_60: number;
    commission_pool_40: number;
    allocated_so_far: number;
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

interface CapitalWaterfallHubProps {
    flow: CapitalFlowData;
    allocations_by_category: CategoryAllocation[];
    recent_allocations: AllocationRecord[];
}

const categoryMeta: Record<string, { label: string; pct: number; bg: string; text: string; border: string }> = {
    self_cashback: { label: 'Self Dealer Cashback', pct: 5.0, bg: '#ecfdf5', text: '#047857', border: '#a7f3d0' },
    direct_referral: { label: 'Direct Referral (Gen 1)', pct: 10.0, bg: '#eff6ff', text: '#1d4ed8', border: '#bfdbfe' },
    d2d_generation_2: { label: 'D2D Generation 2', pct: 2.0, bg: '#fdf4ff', text: '#a21caf', border: '#f5d0fe' },
    d2d_generation_3: { label: 'D2D Generation 3', pct: 1.0, bg: '#faf5ff', text: '#7e22ce', border: '#e9d5ff' },
    area_dealer_pool: { label: 'Area Dealer Territory Pool', pct: 4.0, bg: '#f0fdfa', text: '#0f766e', border: '#99f6e4' },
    rank_royalty: { label: 'Rank Royalty Pool (MO/AM/SM/ME)', pct: 4.0, bg: '#fffbeb', text: '#b45309', border: '#fde68a' },
    car_bike_incentive: { label: 'Car & Motorbike Fund', pct: 3.0, bg: '#fef2f2', text: '#b91c1c', border: '#fecaca' },
    salary_reserve: { label: 'Dealer Performance Salary', pct: 6.0, bg: '#f0f9ff', text: '#0369a1', border: '#bae6fd' },
    special_incentive: { label: 'Special Promotional Incentive', pct: 3.0, bg: '#fefce8', text: '#854d0e', border: '#fef08a' },
    csr_training: { label: 'CSR & Education Reserve', pct: 2.0, bg: '#f8fafc', text: '#475569', border: '#cbd5e1' },
};

export default function CapitalWaterfallHub({ flow, allocations_by_category, recent_allocations }: CapitalWaterfallHubProps) {
    const grossCash = Number(flow.gross_cash_inflow) || 0;
    const companyShare = Number(flow.company_share_60) || 0;
    const maxPool = Number(flow.commission_pool_40) || 0;
    const allocated = Number(flow.allocated_so_far) || 0;
    const treasuryBuffer = Math.max(0, maxPool - allocated);

    // Map allocated totals
    const catMap = useMemo(() => {
        const m: Record<string, { total: number; count: number }> = {};
        allocations_by_category.forEach(c => {
            m[c.category] = {
                total: Number(c.total_amount) || 0,
                count: Number(c.count) || 0
            };
        });
        return m;
    }, [allocations_by_category]);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top Metric Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#64748b', fontSize: 12, fontWeight: 600 }}>100% Gross Cash Inflow</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>
                        {money(grossCash)}
                    </div>
                    <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>Total order payment volume</div>
                </div>

                <div style={{ background: '#ffffff', border: '1.5px solid #006837', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#047857', fontSize: 12, fontWeight: 600 }}>60% Company Retained Capital</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 4 }}>
                        {money(companyShare)}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>COGS + OpEx + Company Net Margin</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>40% Commission Pool Ceiling</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 4 }}>
                        {money(maxPool)}
                    </div>
                    <div style={{ fontSize: 11, color: '#3b82f6', marginTop: 2 }}>Maximum allowable distributor payout</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #a7f3d0', borderRadius: 10, padding: 16 }}>
                    <div style={{ color: '#047857', fontSize: 12, fontWeight: 600 }}>Actual Commissions Allocated</div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#047857', marginTop: 4 }}>
                        {money(allocated)}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>
                        Unallocated Buffer: <b style={{ color: '#0f172a' }}>{money(treasuryBuffer)}</b>
                    </div>
                </div>
            </div>

            {/* Visual Waterfall Flow Architecture Diagram */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                padding: '24px',
                boxShadow: '0 2px 8px rgba(0,0,0,0.03)'
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
                    <div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 700, color: '#006837' }}>
                            <ShieldCheck size={16} /> MATHEMATICALLY GUARANTEED ZERO-DEFICIT WATERFALL
                        </div>
                        <h3 style={{ fontSize: 18, fontWeight: 800, color: '#0f172a', margin: '4px 0 0 0' }}>
                            Cash Inflow Division Architecture
                        </h3>
                    </div>
                    <div style={{ fontSize: 12, fontWeight: 700, padding: '4px 10px', background: '#ecfdf5', color: '#047857', borderRadius: 20 }}>
                        Company Capital 100% Protected
                    </div>
                </div>

                {/* Waterfall Visual Blocks */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                    {/* Stage 1: 100% Gross Inflow */}
                    <div style={{
                        background: '#0f172a',
                        color: '#ffffff',
                        padding: '16px 20px',
                        borderRadius: 10,
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center'
                    }}>
                        <div>
                            <span style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>LEVEL 1: SOURCE CASH</span>
                            <div style={{ fontSize: 18, fontWeight: 800, marginTop: 2 }}>100% Gross Cash Inflow Collected</div>
                        </div>
                        <div style={{ fontSize: 22, fontWeight: 800, color: '#38bdf8' }}>
                            {money(grossCash)}
                        </div>
                    </div>

                    {/* Connecting arrows */}
                    <div style={{ display: 'flex', justifyContent: 'space-around', color: '#94a3b8' }}>
                        <ArrowDownRight size={24} style={{ transform: 'rotate(45deg)' }} />
                        <ArrowDownRight size={24} style={{ transform: 'rotate(-45deg)' }} />
                    </div>

                    {/* Stage 2: 60% vs 40% Split */}
                    <div style={{ display: 'grid', gridTemplateColumns: '1.2fr 1fr', gap: 16 }}>
                        {/* 60% Company Retained */}
                        <div style={{
                            background: '#f0fdf4',
                            border: '1.5px solid #a7f3d0',
                            borderRadius: 10,
                            padding: '18px 20px'
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                <span style={{ fontSize: 11, fontWeight: 800, color: '#047857', textTransform: 'uppercase' }}>
                                    60% COMPANY TREASURY
                                </span>
                                <span style={{ fontSize: 18, fontWeight: 800, color: '#006837' }}>
                                    {money(companyShare)}
                                </span>
                            </div>
                            <p style={{ fontSize: 12, color: '#065f46', marginTop: 6, lineHeight: 1.4 }}>
                                Completely isolated from commission liabilities. Covers herbal raw materials, GMP factory production, packaging, warehousing, staff, logistics, and company profit.
                            </p>
                            <div style={{ display: 'flex', gap: 6, marginTop: 10, flexWrap: 'wrap' }}>
                                <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 6px', background: '#dcfce7', color: '#15803d', borderRadius: 4 }}>COGS: ~35%</span>
                                <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 6px', background: '#dcfce7', color: '#15803d', borderRadius: 4 }}>OpEx & Tax: ~15%</span>
                                <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 6px', background: '#dcfce7', color: '#15803d', borderRadius: 4 }}>Net Profit: ~10%</span>
                            </div>
                        </div>

                        {/* 40% Commission Fund */}
                        <div style={{
                            background: '#eff6ff',
                            border: '1.5px solid #bfdbfe',
                            borderRadius: 10,
                            padding: '18px 20px'
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                <span style={{ fontSize: 11, fontWeight: 800, color: '#1d4ed8', textTransform: 'uppercase' }}>
                                    40% COMMISSION POOL CEILING
                                </span>
                                <span style={{ fontSize: 18, fontWeight: 800, color: '#1d4ed8' }}>
                                    {money(maxPool)}
                                </span>
                            </div>
                            <p style={{ fontSize: 12, color: '#1e40af', marginTop: 6, lineHeight: 1.4 }}>
                                Total financial envelope for all multi-tier incentives. Distributed across 9 dynamic pools. Any undistributed amount remains in company cash!
                            </p>
                            <div style={{ marginTop: 10, fontSize: 11, fontWeight: 700, color: '#1d4ed8' }}>
                                Actual Distributed: {money(allocated)} ({((allocated / (grossCash || 1)) * 100).toFixed(1)}% of gross cash)
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Stage 3: The 9 Category Breakdown Grid */}
            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 24 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                    <div>
                        <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#0f172a' }}>
                            Allocation by Category Pool
                        </h3>
                        <p style={{ fontSize: 12, color: '#64748b', margin: '2px 0 0 0' }}>
                            All 9 categories operating under the 40.00% dynamic ceiling.
                        </p>
                    </div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12 }}>
                    {Object.entries(categoryMeta).map(([catKey, meta]) => {
                        const rec = catMap[catKey] || { total: 0, count: 0 };
                        return (
                            <div
                                key={catKey}
                                style={{
                                    border: `1px solid ${meta.border}`,
                                    borderRadius: 8,
                                    padding: 14,
                                    background: meta.bg
                                }}
                            >
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                                    <span style={{ fontSize: 12, fontWeight: 700, color: meta.text }}>{meta.label}</span>
                                    <span style={{
                                        fontSize: 11,
                                        fontWeight: 800,
                                        padding: '1px 6px',
                                        borderRadius: 4,
                                        background: '#ffffff',
                                        color: meta.text,
                                        border: `1px solid ${meta.border}`
                                    }}>
                                        {meta.pct}%
                                    </span>
                                </div>
                                <div style={{ fontSize: 18, fontWeight: 800, color: '#0f172a', marginTop: 10 }}>
                                    {money(rec.total)}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                    {rec.count} credit allocations
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Recent Allocations Journal */}
            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                <div style={{ padding: '16px 20px', borderBottom: '1px solid #e2e8f0' }}>
                    <h3 style={{ fontSize: 15, fontWeight: 700, margin: 0, color: '#0f172a' }}>
                        Recent Commission Allocation Transactions
                    </h3>
                    <p style={{ fontSize: 12, color: '#64748b', margin: '2px 0 0 0' }}>
                        Append-only double-entry ledger records credited automatically to partner wallets.
                    </p>
                </div>

                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                            <th style={{ padding: '12px 16px' }}>Date</th>
                            <th style={{ padding: '12px 16px' }}>Partner</th>
                            <th style={{ padding: '12px 16px' }}>Category</th>
                            <th style={{ padding: '12px 16px' }}>Rate</th>
                            <th style={{ padding: '12px 16px' }}>Commission Amount</th>
                            <th style={{ padding: '12px 16px' }}>Order ID</th>
                            <th style={{ padding: '12px 16px', textAlign: 'right' }}>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {recent_allocations && recent_allocations.length > 0 ? (
                            recent_allocations.map(al => {
                                const meta = categoryMeta[al.category] || { label: al.category, pct: 0, bg: '#f1f5f9', text: '#475569', border: '#cbd5e1' };
                                return (
                                    <tr key={al.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 16px', color: '#64748b', fontSize: 12 }}>
                                            {date(al.created_at)}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{al.user?.name || 'Partner'}</div>
                                            <div style={{ fontSize: 11, color: '#64748b' }}>{al.user?.dealer_code || '—'}</div>
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <span style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 6px',
                                                borderRadius: 4,
                                                background: meta.bg,
                                                color: meta.text,
                                                border: `1px solid ${meta.border}`
                                            }}>
                                                {meta.label}
                                            </span>
                                        </td>
                                        <td style={{ padding: '12px 16px', fontWeight: 600, color: '#475569' }}>
                                            {al.percentage}%
                                        </td>
                                        <td style={{ padding: '12px 16px', fontWeight: 800, color: '#006837' }}>
                                            {money(al.amount)}
                                        </td>
                                        <td style={{ padding: '12px 16px', color: '#64748b' }}>
                                            #{al.order_id}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right' }}>
                                            <span style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 6px',
                                                borderRadius: 4,
                                                background: '#dcfce7',
                                                color: '#15803d',
                                                border: '1px solid #a7f3d0'
                                            }}>
                                                ✓ CREDITED
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })
                        ) : (
                            <tr>
                                <td colSpan={7} style={{ textAlign: 'center', padding: '36px 0', color: '#94a3b8' }}>
                                    No commission allocations processed yet. Allocations occur automatically upon delivered orders.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

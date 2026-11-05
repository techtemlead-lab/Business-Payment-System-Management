import React, { useState, useMemo } from 'react';
import {
    TrendingUp, ShieldCheck, ArrowDownRight, ArrowUpRight, DollarSign,
    Building2, Wallet, Landmark, Layers, CheckCircle2, AlertCircle, FileText,
    PieChart, ArrowRight, Store, Users, ShoppingBag
} from 'lucide-react';
import { money, date, label, Table, Badge, type TableColumn } from './UI';
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
    company_profit?: RetainedFundRow[];
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
            <div className="retained-detail-table hub-empty">
                <b className="hub-strong">{title}</b>
                <p className="hub-sub">{translate('portal', 'tr_no_detail_entries')}</p>
            </div>
        );
    }

    const columns: TableColumn<RetainedFundRow>[] = [
        {
            key: 'created_at',
            title: translate('portal', 'sal_col_posted'),
            sortable: true,
            render: row => <span className="hub-nowrap">{date(row.created_at)}</span>,
        },
        {
            key: 'order_number',
            title: translate('portal', 'sal_col_order'),
            sortable: true,
            render: row => row.order_number || '—',
        },
        {
            key: 'buyer_name',
            title: translate('portal', 'col_partner'),
            sortable: true,
            render: row => (
                <div>
                    <div className="hub-strong">{row.buyer_name || '—'}</div>
                    <div className="cell-sub">
                        {[row.buyer_code, row.buyer_role ? label(row.buyer_role, translate) : null].filter(Boolean).join(' · ')}
                    </div>
                </div>
            ),
        },
        {
            key: 'location',
            title: translate('portal', 'label_location'),
            sortable: true,
            sortValue: row => placeLabel(row),
            render: row => (
                <div>
                    <div>{placeLabel(row)}</div>
                    {row.area_level && <div className="cell-sub">{translate('portal', 'tr_level', { level: String(row.area_level) })}</div>}
                </div>
            ),
        },
        {
            key: 'purchase_value',
            title: translate('portal', 'sal_col_purchase_value'),
            align: 'right',
            sortable: true,
            sortValue: row => Number(row.purchase_value) || 0,
            render: row => <span className="hub-nowrap">{money(row.purchase_value)}</span>,
        },
        {
            key: 'rate_percentage',
            title: translate('portal', 'tr_col_rate'),
            align: 'right',
            sortable: true,
            sortValue: row => Number(row.rate_percentage) || 0,
            render: row => row.rate_percentage != null ? `${row.rate_percentage}%` : '—',
        },
        {
            key: amountKey,
            title: translate('portal', 'col_amount'),
            align: 'right',
            sortable: true,
            sortValue: row => Number(row[amountKey]) || 0,
            render: row => <b className="hub-nowrap">{money(row[amountKey])}</b>,
        },
        {
            key: 'reason',
            title: translate('portal', 'tr_col_detail'),
            sortable: true,
            render: row => <span className="hub-hint">{row.reason || row.fund || '—'}</span>,
        },
    ];

    return (
        <div className="retained-detail-table tr-detail">
            <div className="tr-detail-head">
                <b className="hub-strong">{title}</b>
                <p className="hub-sub">{hint}</p>
            </div>
            <Table
                rows={rows}
                columns={columns}
                pageSize={15}
                empty={translate('portal', 'tr_no_detail_entries')}
            />
        </div>
    );
}

function RetainedDetailSwitcher({ tables }: { tables: Array<{ id: string; title: string; hint: string; rows: RetainedFundRow[]; amountKey?: 'amount' | 'company_share' }> }) {
    const [activeId, setActiveId] = useState(tables[0]?.id ?? '');
    const active = tables.find((table) => table.id === activeId) ?? tables[0];
    if (!active) {
        return null;
    }
    return (
        <div className="retained-detail-switcher">
            <div className="retained-detail-tabs" role="tablist">
                {tables.map((table) => {
                    const shortTitle = table.title.split('—')[0].trim();
                    return (
                        <button
                            key={table.id}
                            type="button"
                            role="tab"
                            aria-selected={table.id === active.id}
                            className={table.id === active.id ? 'is-active' : undefined}
                            onClick={() => setActiveId(table.id)}
                        >
                            {shortTitle}
                        </button>
                    );
                })}
            </div>
            <RetainedDetailTable title={active.title} hint={active.hint} rows={active.rows} amountKey={active.amountKey} />
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
    const companyProfitRows = details.company_profit || [];
    const marketingRows = details.marketing_fund || [];
    const carBikeRows = details.car_bike_fund || [];

    // Channel breakdown
    const channels = flow.channel_breakdown ?? [];

    return (
        <div className="hub">
            {/* Header & Sub-Navigation for Treasury */}
            <div className="panel hub-row is-between">
                <div>
                    <div className="hub-kicker">
                        <span className="hub-pill is-solid">
                            {translate('portal', 'tr_badge_company')}
                        </span>
                        <span className="hub-label">{translate('portal', 'tr_subtitle')}</span>
                    </div>
                    <h2 className="hub-title is-lg">
                        {translate('portal', 'tr_heading')}
                    </h2>
                    <p className="hub-sub">
                        {translate('portal', 'tr_heading_hint')}
                    </p>
                </div>

                <div className="hub-seg">
                    <button
                        type="button"
                        onClick={() => setActiveTab('overview')}
                        className={activeTab === 'overview' ? 'is-active' : undefined}
                    >
                        {translate('portal', 'tr_tab_overview')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('inflows')}
                        className={activeTab === 'inflows' ? 'is-active' : undefined}
                    >
                        {translate('portal', 'tr_tab_inflows')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('deposits')}
                        className={activeTab === 'deposits' ? 'is-active' : undefined}
                    >
                        {translate('portal', 'tr_tab_deposits')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('expenses')}
                        className={activeTab === 'expenses' ? 'is-active' : undefined}
                    >
                        {translate('portal', 'tr_tab_expenses')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('ledger')}
                        className={activeTab === 'ledger' ? 'is-active' : undefined}
                    >
                        {translate('portal', 'tr_tab_ledger')}
                    </button>
                </div>
            </div>

            {/* Top 4 KPI Cards */}
            <div className="treasury-kpi-grid">
                <div className="treasury-kpi-card hub-tile">
                    <div className="hub-tile-head">
                        <span className="hub-label">{translate('portal', 'tr_kpi_received')}</span>
                        <ArrowDownRight size={18} className="tone-ok" />
                    </div>
                    <div className="treasury-kpi-amount">
                        {money(grossCash)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'tr_kpi_received_hint')}</div>
                </div>

                <div className="treasury-kpi-card hub-tile is-accent tone-brand">
                    <div className="hub-tile-head">
                        <span className="hub-label">{translate('portal', 'tr_kpi_deposited')}</span>
                        <Landmark size={18} />
                    </div>
                    <div className="treasury-kpi-amount">
                        {money(totalCompanyDeposits)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'tr_kpi_deposited_hint')}</div>
                </div>

                <div className="treasury-kpi-card hub-tile is-accent tone-orange">
                    <div className="hub-tile-head">
                        <span className="hub-label">{translate('portal', 'tr_kpi_pool')}</span>
                        <ArrowUpRight size={18} />
                    </div>
                    <div className="treasury-kpi-amount">
                        {money(maxPool)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'tr_kpi_pool_hint')}</div>
                </div>

                {ownerBenefitsVisible ? (
                    <div className="treasury-kpi-card hub-tile is-accent tone-info">
                        <div className="hub-tile-head">
                            <span className="hub-label">{translate('portal', 'tr_kpi_benefits')}</span>
                            <ShieldCheck size={18} />
                        </div>
                        <div className="treasury-kpi-amount">
                            {money(unallocatedBuffer)}
                        </div>
                        <div className="hub-hint">{translate('portal', 'tr_kpi_benefits_hint')}</div>
                    </div>
                ) : (
                    <div className="treasury-kpi-card hub-tile">
                        <div className="hub-tile-head">
                            <span className="hub-label">{translate('portal', 'tr_kpi_settled')}</span>
                            <ShieldCheck size={18} />
                        </div>
                        <div className="treasury-kpi-amount">
                            {money(Number(flow.settled_disbursements) || 0)}
                        </div>
                        <div className="hub-hint">{translate('portal', 'tr_kpi_settled_hint')}</div>
                    </div>
                )}
            </div>

            {/* TAB CONTENT: Overview */}
            {activeTab === 'overview' && (
                <div className="hub">
                    {/* Visual 3-Stage Capital Flow Architecture */}
                    <div className="panel">
                        <div className="panel-heading">
                            <div>
                                <span className="hub-label tone-brand">
                                    {translate('portal', 'tr_arch_badge')}
                                </span>
                                <h3 className="hub-title is-lg">
                                    {translate('portal', 'tr_flow_title')}
                                </h3>
                            </div>
                            <div className="hub-pill tone-ok">
                                {translate('portal', 'tr_flow_balanced')}
                            </div>
                        </div>

                        <div className="hub-grid">
                            {/* Block 1 */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="tr-step">
                                    <span className="hub-step">1</span>
                                    {translate('portal', 'tr_flow_from')}
                                </div>
                                <div className="treasury-kpi-amount">
                                    {money(grossCash)}
                                </div>
                                <p className="hub-hint">
                                    {translate('portal', 'tr_flow_from_body')}
                                </p>
                                <button type="button" onClick={() => setActiveTab('inflows')} className="text-link">
                                    {translate('portal', 'tr_flow_from_cta')}
                                </button>
                            </div>

                            {/* Block 2 */}
                            <div className="treasury-kpi-card hub-tile is-tinted tone-brand">
                                <div className="tr-step">
                                    <span className="hub-step">2</span>
                                    {translate('portal', 'tr_flow_deposit')}
                                </div>
                                <div className="treasury-kpi-amount">
                                    {money(totalCompanyDeposits)}
                                </div>
                                <p className="hub-hint">
                                    {translate('portal', 'tr_flow_deposit_body')}
                                </p>
                                <button type="button" onClick={() => setActiveTab('deposits')} className="text-link">
                                    {translate('portal', 'tr_flow_deposit_cta')}
                                </button>
                            </div>

                            {/* Block 3 */}
                            <div className="treasury-kpi-card hub-tile is-tinted tone-orange">
                                <div className="tr-step">
                                    <span className="hub-step">3</span>
                                    {translate('portal', 'tr_flow_spent')}
                                </div>
                                <div className="treasury-kpi-amount">
                                    {money(allocated)}
                                </div>
                                <p className="hub-hint">
                                    {translate('portal', 'tr_flow_spent_body')}
                                </p>
                                <button type="button" onClick={() => setActiveTab('expenses')} className="text-link">
                                    {translate('portal', 'tr_flow_spent_cta')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: 1. Sources of Money (Inflow) */}
            {activeTab === 'inflows' && (
                <div className="hub">
                    <div className="panel">
                        <h3 className="hub-title is-lg">
                            {translate('portal', 'tr_inflow_title')}
                        </h3>
                        <p className="hub-sub is-lead">
                            {translate('portal', 'tr_inflow_hint')}
                        </p>

                        <div className="hub-grid is-wide">
                            {/* Dealer Wholesale Purchases */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-media">
                                    <Store size={22} className="tone-violet" />
                                    <div>
                                        <b className="hub-strong">{translate('portal', 'tr_inflow_dealer')}</b>
                                        <div className="hub-hint">{translate('portal', 'tr_inflow_dealer_hint')}</div>
                                    </div>
                                </div>
                                <div className="treasury-kpi-amount tone-violet">
                                    {money(dealerCash)}
                                </div>
                                <div className="hub-hint">
                                    {translate('portal', 'tr_inflow_share', { pct: String(grossCash > 0 ? ((dealerCash / grossCash) * 100).toFixed(1) : 0) })}
                                </div>
                            </div>

                            {/* Sub-Dealer Orders */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-media">
                                    <Users size={22} className="tone-ok" />
                                    <div>
                                        <b className="hub-strong">{translate('portal', 'tr_inflow_subdealer')}</b>
                                        <div className="hub-hint">{translate('portal', 'tr_inflow_subdealer_hint')}</div>
                                    </div>
                                </div>
                                <div className="treasury-kpi-amount tone-ok">
                                    {money(subDealerCash)}
                                </div>
                                <div className="hub-hint">
                                    {translate('portal', 'tr_inflow_share', { pct: String(grossCash > 0 ? ((subDealerCash / grossCash) * 100).toFixed(1) : 0) })}
                                </div>
                            </div>
                        </div>

                        {/* Payment Collection Channels */}
                        <div className="hub-section">
                            <h4 className="hub-title is-sm">
                                {translate('portal', 'tr_channels_title')}
                            </h4>
                            <Table
                                tools={false}
                                rows={channels}
                                columns={[
                                    {
                                        key: 'channel',
                                        title: translate('portal', 'tr_col_channel'),
                                        sortable: true,
                                        render: (ch: any) => <b>{ch.channel}</b>,
                                    },
                                    {
                                        key: 'total_amount',
                                        title: translate('portal', 'tr_col_verified'),
                                        align: 'right',
                                        sortable: true,
                                        sortValue: (ch: any) => Number(ch.total_amount) || 0,
                                        render: (ch: any) => <b className="tone-brand">{money(ch.total_amount)}</b>,
                                    },
                                    {
                                        key: 'share',
                                        title: translate('portal', 'tr_col_share'),
                                        align: 'right',
                                        sortable: true,
                                        sortValue: (ch: any) => grossCash > 0 ? ((Number(ch.total_amount) || 0) / grossCash) * 100 : 0,
                                        render: (ch: any) => {
                                            const amt = Number(ch.total_amount) || 0;
                                            const pct = grossCash > 0 ? ((amt / grossCash) * 100).toFixed(1) : '0';
                                            return `${pct}%`;
                                        },
                                    },
                                    {
                                        key: 'status',
                                        title: translate('portal', 'col_status'),
                                        render: () => (
                                            <span className="badge tone-ok">
                                                {translate('portal', 'tr_active_verified')}
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: 2. Deposits & Retained Capital */}
            {activeTab === 'deposits' && (
                <div className="hub">
                    <div className="panel">
                        <h3 className="hub-title is-lg">
                            {translate('portal', 'tr_deposit_title')}
                        </h3>
                        <p className="hub-sub is-lead">
                            {translate('portal', 'tr_deposit_hint')}
                        </p>

                        <div className="hub-grid is-wide">
                            {/* 60% Operating Retention */}
                            <div className="treasury-kpi-card hub-tile is-tinted tone-brand">
                                <div className="hub-tile-head">
                                    <span className="hub-label">{translate('portal', 'tr_dep_operating')}</span>
                                    <Building2 size={20} />
                                </div>
                                <div className="treasury-kpi-amount">
                                    {money(companyShare)}
                                </div>
                                <p className="hub-hint">
                                    {translate('portal', 'tr_dep_operating_body')}
                                </p>
                            </div>

                            {/* 2% Management Area Share */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-tile-head">
                                    <span className="hub-label">{translate('portal', 'tr_dep_mgmt')}</span>
                                    <Landmark size={20} className="tone-violet" />
                                </div>
                                <div className="treasury-kpi-amount">
                                    {money(mgmtReserve)}
                                </div>
                                <p className="hub-hint">
                                    {translate('portal', 'tr_dep_mgmt_body')}
                                </p>
                            </div>

                            {/* Unassigned Territory Reserve */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-tile-head">
                                    <span className="hub-label">{translate('portal', 'tr_dep_geo')}</span>
                                    <ShieldCheck size={20} className="tone-ok" />
                                </div>
                                <div className="treasury-kpi-amount">
                                    {money(unassignedReserve)}
                                </div>
                                <p className="hub-hint">
                                    {translate('portal', 'tr_dep_geo_body')}
                                </p>
                            </div>
                        </div>

                        <RetainedDetailSwitcher
                            tables={[
                                {
                                    id: 'operating',
                                    title: translate('portal', 'tr_detail_operating'),
                                    hint: translate('portal', 'tr_detail_operating_hint'),
                                    rows: companyShareRows,
                                    amountKey: 'company_share',
                                },
                                {
                                    id: 'geo',
                                    title: translate('portal', 'tr_detail_geo'),
                                    hint: translate('portal', 'tr_detail_geo_hint'),
                                    rows: unassignedRows,
                                },
                                {
                                    id: 'mgmt',
                                    title: translate('portal', 'tr_detail_mgmt'),
                                    hint: translate('portal', 'tr_detail_mgmt_hint'),
                                    rows: managementRows,
                                },
                                ...(ownerBenefitsVisible ? [{
                                    id: 'benefits',
                                    title: translate('portal', 'tr_detail_benefits'),
                                    hint: translate('portal', 'tr_detail_benefits_hint'),
                                    rows: benefitsRows,
                                }] : []),
                                {
                                    id: 'override',
                                    title: translate('portal', 'tr_detail_override'),
                                    hint: translate('portal', 'tr_detail_override_hint'),
                                    rows: overrideRows,
                                },
                                {
                                    id: 'settlement',
                                    title: translate('portal', 'tr_detail_settlement'),
                                    hint: translate('portal', 'tr_detail_settlement_hint'),
                                    rows: settlementRows,
                                },
                                {
                                    id: 'company-profit',
                                    title: translate('portal', 'tr_detail_company_profit'),
                                    hint: translate('portal', 'tr_detail_company_profit_hint'),
                                    rows: companyProfitRows,
                                },
                            ]}
                        />

                        <div className="hub-section">
                            <h4 className="hub-title is-sm">
                                {translate('portal', 'tr_accounts_title')}
                            </h4>
                            {payment_accounts.length === 0 ? (
                                <div className="hub-empty">
                                    {translate('portal', 'tr_accounts_empty')}
                                </div>
                            ) : (
                                <div className="hub-grid">
                                    {payment_accounts.map((account) => {
                                        const roles = [
                                            account.collects_payments ? translate('portal', 'tr_role_collection') : null,
                                            account.pays_out ? translate('portal', 'tr_role_payout') : null,
                                            account.is_active === false ? translate('portal', 'tr_role_inactive') : null,
                                        ].filter(Boolean).join(' · ');
                                        return (
                                            <div key={account.id} className="hub-tile">
                                                <b className="hub-strong">{account.label}</b>
                                                <div className="hub-hint">
                                                    {account.channel} · {account.account_number_masked || account.account_number || '••••'} · {account.holder}
                                                </div>
                                                <div className="hub-label tone-teal">{roles || translate('portal', 'tr_account_configured')}</div>
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
                <div className="hub">
                    <div className="panel">
                        <h3 className="hub-title is-lg">
                            {translate('portal', 'tr_expense_title')}
                        </h3>
                        <p className="hub-sub is-lead">
                            {translate('portal', 'tr_expense_hint')}
                        </p>

                        <div className="hub-grid is-wide">
                            {/* Commission Disbursements */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-tile-head">
                                    <span className="hub-strong">{translate('portal', 'tr_exp_payouts')}</span>
                                    <Wallet size={18} className="tone-ok" />
                                </div>
                                <div className="treasury-kpi-amount tone-ok">
                                    {money(settledDisbursements)}
                                </div>
                                <div className="hub-hint">
                                    {translate('portal', 'tr_exp_payouts_hint')}
                                </div>
                            </div>

                            {/* Marketing Fund */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-tile-head">
                                    <span className="hub-strong">{translate('portal', 'tr_exp_marketing')}</span>
                                    <PieChart size={18} className="tone-violet" />
                                </div>
                                <div className="treasury-kpi-amount tone-violet">
                                    {money(marketingFund)}
                                </div>
                                <div className="hub-hint">
                                    {translate('portal', 'tr_exp_marketing_hint')}
                                </div>
                            </div>

                            {/* Car & Bike Incentive Fund */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-tile-head">
                                    <span className="hub-strong">{translate('portal', 'tr_exp_vehicle')}</span>
                                    <ShieldCheck size={18} className="tone-warn" />
                                </div>
                                <div className="treasury-kpi-amount tone-warn">
                                    {money(carBikeFund)}
                                </div>
                                <div className="hub-hint">
                                    {translate('portal', 'tr_exp_vehicle_hint')}
                                </div>
                            </div>

                            {/* Statutory TDS Tax */}
                            <div className="treasury-kpi-card hub-tile is-soft">
                                <div className="hub-tile-head">
                                    <span className="hub-strong">{translate('portal', 'tr_exp_withholding')}</span>
                                    <FileText size={18} className="tone-danger" />
                                </div>
                                <div className="treasury-kpi-amount tone-danger">
                                    {money(tdsTax)}
                                </div>
                                <div className="hub-hint">
                                    {translate('portal', 'tr_exp_withholding_hint')}
                                </div>
                            </div>
                        </div>

                        <RetainedDetailSwitcher
                            tables={[
                                {
                                    id: 'marketing',
                                    title: translate('portal', 'tr_detail_marketing'),
                                    hint: translate('portal', 'tr_detail_marketing_hint'),
                                    rows: marketingRows,
                                },
                                {
                                    id: 'vehicle',
                                    title: translate('portal', 'tr_detail_vehicle'),
                                    hint: translate('portal', 'tr_detail_vehicle_hint'),
                                    rows: carBikeRows,
                                },
                            ]}
                        />

                        {/* Category Allocations Table */}
                        <div className="hub-section">
                            <h4 className="hub-title is-sm">
                                {translate('portal', 'tr_slices_title')}
                            </h4>
                            <Table
                                tools={false}
                                rows={allocations_by_category}
                                columns={[
                                    {
                                        key: 'category',
                                        title: translate('portal', 'tr_col_category'),
                                        sortable: true,
                                        render: (cat: CategoryAllocation) => <b>{cat.category.replaceAll('_', ' ').toUpperCase()}</b>,
                                    },
                                    {
                                        key: 'count',
                                        title: translate('portal', 'tr_col_alloc_count'),
                                        align: 'right',
                                        sortable: true,
                                        sortValue: (cat: CategoryAllocation) => Number(cat.count) || 0,
                                        render: (cat: CategoryAllocation) => translate('portal', 'tr_alloc_count', { count: String(cat.count) }),
                                    },
                                    {
                                        key: 'total_amount',
                                        title: translate('portal', 'tr_col_total'),
                                        align: 'right',
                                        sortable: true,
                                        sortValue: (cat: CategoryAllocation) => Number(cat.total_amount) || 0,
                                        render: (cat: CategoryAllocation) => <b className="tone-brand">{money(cat.total_amount)}</b>,
                                    },
                                ]}
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* TAB CONTENT: Financial Ledger */}
            {activeTab === 'ledger' && (
                <div className="hub">
                    <div className="panel">
                        <h3 className="hub-title is-lg">
                            {translate('portal', 'tr_ledger_title')}
                        </h3>
                        <p className="hub-sub is-lead">
                            {translate('portal', 'tr_ledger_hint')}
                        </p>

                        <Table
                            rows={recent_allocations}
                            empty={translate('portal', 'tr_ledger_empty')}
                            pageSize={15}
                            columns={[
                                {
                                    key: 'created_at',
                                    title: translate('portal', 'col_date'),
                                    sortable: true,
                                    render: (rec: AllocationRecord) => <span className="muted">{date(rec.created_at)}</span>,
                                },
                                {
                                    key: 'recipient',
                                    title: translate('portal', 'tr_col_recipient'),
                                    sortable: true,
                                    sortValue: (rec: AllocationRecord) => rec.user?.name || '',
                                    render: (rec: AllocationRecord) => (
                                        <span className="hub-strong">
                                            {rec.user ? `${rec.user.name} (${rec.user.dealer_code})` : translate('portal', 'tr_company_reserve_pool')}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'category',
                                    title: translate('portal', 'col_category'),
                                    sortable: true,
                                    render: (rec: AllocationRecord) => (
                                        <span className="hub-label">
                                            {rec.category.replaceAll('_', ' ')}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'amount',
                                    title: translate('portal', 'col_amount'),
                                    align: 'right',
                                    sortable: true,
                                    sortValue: (rec: AllocationRecord) => Number(rec.amount) || 0,
                                    render: (rec: AllocationRecord) => <b className="tone-brand">{money(rec.amount)}</b>,
                                },
                                {
                                    key: 'is_settled',
                                    title: translate('portal', 'col_status'),
                                    sortable: true,
                                    render: (rec: AllocationRecord) => (
                                        <span className={`badge ${rec.is_settled ? 'tone-ok' : 'tone-warn'}`}>
                                            {rec.is_settled ? translate('portal', 'tr_status_settled') : translate('portal', 'tr_status_pending')}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </div>
                </div>
            )}
        </div>
    );
}

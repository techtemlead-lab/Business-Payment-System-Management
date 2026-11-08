import React, { useState, useMemo } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Search, GitFork, Users, Award, ChevronRight, ChevronDown, Phone, MapPin, Calendar, DollarSign, ArrowUpRight, ShieldCheck, Filter, UserCheck, Eye, Download } from 'lucide-react';
import { money, date, Table, type TableColumn, number } from './UI';
import JargonHint from './JargonHint';
import { useT } from '../shared/i18n/t';

interface DealerNode {
    id: number;
    name: string;
    dealer_code: string;
    phone: string;
    district: string;
    division: string;
    sponsor_id: number | null;
    sponsor_name?: string | null;
    role: string;
    status: string;
    rank: string;
    lifetime_purchases: number;
    /** Completed purchases counted from orders, all time. */
    sales_total?: string | number;
    created_at: string;
    /** Completed purchases in the current business month. */
    sales_month?: string | number;
    /** This month's volume as the salary target counts it: delivered, paid purchases after refunds. */
    qualifying_volume_month?: string | number;
    /** What this member's direct referrer has earned from this member's purchases. */
    sponsor_earned?: string | number;
    /** Dealer's own tree only: what the signed-in dealer earned from this member. */
    viewer_earned?: string | number;
    viewer_earned_by_type?: { category: string; amount: string }[];
    /** Everyone under this partner, from the server (right even when lower levels are not loaded). */
    team_size?: number;
    /** Partners this partner referred directly, from the server. */
    direct_count?: number;
}

/** Figures over the whole tree, worked out on the server when only part of it is loaded. */
export interface NetworkTreeStats {
    total: number;
    ranks: Partial<Record<string, number>>;
    sales_total: string | number;
}

interface NetworkTreeExplorerProps {
    dealers: DealerNode[];
    treeStats?: NetworkTreeStats | null;
}

/**
 * A member's completed purchases counted from orders, falling back to the rank total.
 */
function salesOf(d: DealerNode): number {
    return Number(d.sales_total ?? d.lifetime_purchases) || 0;
}

const rankBadgeStyles: Record<string, { labelKey: string; tone: string }> = {
    dealer: { labelKey: 'net_badge_dealer', tone: 'tone-neutral' },
    mo: { labelKey: 'net_badge_mo', tone: 'tone-ok' },
    am: { labelKey: 'net_badge_am', tone: 'tone-info' },
    sm: { labelKey: 'net_badge_sm', tone: 'tone-violet' },
    me: { labelKey: 'net_badge_me', tone: 'tone-warn' },
};

/**
 * Tone for the generation tag beside a tree node: G1 green, G2 blue, G3 violet, deeper neutral.
 */
function generationTone(depth: number): string {
    if (depth === 1) {
        return 'tone-ok';
    }
    if (depth === 2) {
        return 'tone-info';
    }
    if (depth === 3) {
        return 'tone-violet';
    }

    return 'tone-neutral';
}

export default function NetworkTreeExplorer({ dealers, treeStats = null }: NetworkTreeExplorerProps) {
    const translate = useT();
    const { portal, auth } = usePage<any>().props;
    const isDealerPortal = portal === 'dealer';
    const viewerId: number | null = auth?.user?.id ?? null;
    const showsViewerEarnings = dealers.some(d => d.viewer_earned !== undefined);
    const [searchQuery, setSearchQuery] = useState('');
    const [rankFilter, setRankFilter] = useState('all');
    const [selectedDealerId, setSelectedDealerId] = useState<number | null>(null);
    const [focusedRootId, setFocusedRootId] = useState<number | null>(null);
    const [collapsedNodes, setCollapsedNodes] = useState<Record<number, boolean>>({});
    const [viewMode, setViewMode] = useState<'tree' | 'table'>('tree');

    // Build parent-to-children index
    const { childrenMap, dealerMap, roots } = useMemo(() => {
        const cMap: Record<number, DealerNode[]> = {};
        const dMap: Record<number, DealerNode> = {};
        const rootList: DealerNode[] = [];

        dealers.forEach(d => {
            dMap[d.id] = d;
        });
        dealers.forEach(d => {
            // Treat as a root when the sponsor is outside this payload (dealer downline views).
            if (d.sponsor_id && dMap[d.sponsor_id]) {
                if (!cMap[d.sponsor_id]) cMap[d.sponsor_id] = [];
                cMap[d.sponsor_id].push(d);
            } else {
                rootList.push(d);
            }
        });

        return { childrenMap: cMap, dealerMap: dMap, roots: rootList };
    }, [dealers]);

    // Active dealer details card
    const activeDealer = selectedDealerId ? dealerMap[selectedDealerId] : null;

    // Toggle collapse state for a node
    const toggleCollapse = (id: number) => {
        setCollapsedNodes(prev => ({ ...prev, [id]: !prev[id] }));
    };

    // Filtered list for search & table view
    const filteredDealers = useMemo(() => {
        return dealers.filter(d => {
            const matchesQuery = !searchQuery ||
                d.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                (d.dealer_code && d.dealer_code.toLowerCase().includes(searchQuery.toLowerCase())) ||
                (d.phone && d.phone.includes(searchQuery));
            const matchesRank = rankFilter === 'all' || d.rank === rankFilter;
            return matchesQuery && matchesRank;
        });
    }, [dealers, searchQuery, rankFilter]);

    // Calculate network tree stats
    const stats = useMemo(() => {
        if (treeStats) {
            const ranks = treeStats.ranks ?? {};
            return { total: treeStats.total, moCount: ranks.mo ?? 0, amCount: ranks.am ?? 0, smCount: ranks.sm ?? 0, meCount: ranks.me ?? 0, totalVolume: Number(treeStats.sales_total) || 0 };
        }
        const total = dealers.length;
        const moCount = dealers.filter(d => d.rank === 'mo').length;
        const amCount = dealers.filter(d => d.rank === 'am').length;
        const smCount = dealers.filter(d => d.rank === 'sm').length;
        const meCount = dealers.filter(d => d.rank === 'me').length;
        const totalVolume = dealers.reduce((acc, d) => acc + salesOf(d), 0);
        return { total, moCount, amCount, smCount, meCount, totalVolume };
    }, [dealers, treeStats]);

    // Recursive calculation of total downline size; the server count wins when it is sent.
    const getDownlineCount = (id: number): number => {
        const fromServer = dealerMap[id]?.team_size;
        if (typeof fromServer === 'number') return fromServer;
        const children = childrenMap[id] || [];
        return children.reduce((count, child) => count + 1 + getDownlineCount(child.id), 0);
    };

    /**
     * Adds one figure over a member and everyone under them.
     */
    const sumTeam = (id: number, pick: (d: DealerNode) => number): number => {
        const self = dealerMap[id] ? pick(dealerMap[id]) : 0;
        return (childrenMap[id] || []).reduce((total, child) => total + sumTeam(child.id, pick), self);
    };

    // Recursive tree node renderer
    const renderTreeNode = (dealer: DealerNode, depth: number = 0) => {
        const children = childrenMap[dealer.id] || [];
        const isCollapsed = !!collapsedNodes[dealer.id];
        const isSelected = selectedDealerId === dealer.id;
        const badge = rankBadgeStyles[dealer.rank] || rankBadgeStyles.dealer;
        const directCount = dealer.direct_count ?? children.length;
        const totalDownline = getDownlineCount(dealer.id);

        let genTag = '';
        if (depth === 0) genTag = translate('portal','net_gen_root');
        else if (depth === 1) genTag = translate('portal','net_gen_1');
        else if (depth === 2) genTag = translate('portal','net_gen_2');
        else if (depth === 3) genTag = translate('portal','net_gen_3');
        else genTag = translate('portal','net_gen_n',{depth:String(depth)});

        return (
            <div key={dealer.id} className={`ntx-node${depth > 0 ? ' is-child' : ''}`}>
                {depth > 0 && (
                    <div className="ntx-connector" />
                )}

                <div
                    onClick={() => setSelectedDealerId(dealer.id)}
                    className={`ntx-card${isSelected ? ' is-selected' : ''}`}
                >
                    {children.length > 0 ? (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); toggleCollapse(dealer.id); }}
                            className="ntx-toggle"
                        >
                            {isCollapsed ? <ChevronRight size={14} /> : <ChevronDown size={14} />}
                        </button>
                    ) : (
                        <div className="ntx-leaf">
                            <div className="ntx-leaf-dot" />
                        </div>
                    )}

                    <div className="ops-grow">
                        <div className="ops-row is-snug is-wrap">
                            <span className="ntx-name">{dealer.name}</span>
                            <span className={`ntx-rank ${badge.tone}`}>
                                {translate('portal', badge.labelKey)}
                            </span>
                            <span className={`ntx-gen ${generationTone(depth)}`}>
                                {genTag}
                            </span>
                        </div>
                        <div className="ops-row ops-meta ntx-card-meta">
                            <span>{translate('portal','net_code')}: <b>{dealer.dealer_code || '—'}</b></span>
                            <span>•</span>
                            <span>{translate('portal','net_purchases')}: <b className="tone-brand">{money(salesOf(dealer))}</b></span>
                            <span>•</span>
                            <span>{translate('portal','net_sales_month')}: <b>{money(dealer.sales_month ?? 0)}</b></span>
                            <span>•</span>
                            <span title={translate('portal','net_qualifying_volume_hint')}>{translate('portal','net_qualifying_volume_month')}: <b>{money(dealer.qualifying_volume_month ?? 0)}</b></span>
                        </div>
                        {dealer.sponsor_id && dealerMap[dealer.sponsor_id] && (
                            <div className="ops-row ops-meta ntx-card-meta">
                                {showsViewerEarnings && dealer.sponsor_id === viewerId ? (
                                    <span>{translate('portal','net_you_earned')}: <b className="tone-ok">{money(dealer.viewer_earned ?? 0)}</b></span>
                                ) : (
                                    <>
                                        <span>{translate('portal','net_referrer_earned')}: <b className="tone-ok">{money(dealer.sponsor_earned ?? 0)}</b></span>
                                        {showsViewerEarnings && (
                                            <><span>•</span><span>{translate('portal','net_you_earned')}: <b className="tone-ok">{money(dealer.viewer_earned ?? 0)}</b></span></>
                                        )}
                                    </>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="ntx-counts">
                        <span className={`ntx-count${directCount > 0 ? ' tone-info' : ''}`}>
                            {translate('portal','net_direct_count',{count:String(directCount)})}
                        </span>
                        {totalDownline > directCount && (
                            <span className="ntx-count-total">
                                {translate('portal','net_total_count',{count:String(totalDownline)})}
                            </span>
                        )}
                    </div>
                </div>

                {!isCollapsed && children.length > 0 && (
                    <div className="ntx-branch">
                        {children.map(child => renderTreeNode(child, depth + 1))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="hub">
            <JargonHint terms={['mo', 'am', 'sm', 'me']} />
            {/* Top Stat Summary Cards */}
            <div className="hub-grid">
                <div className="hub-tile">
                    <div className="hub-label ops-row">
                        <Users size={16} /> {translate('portal','net_kpi_total')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {number(stats.total)}
                    </div>
                    <div className="hub-hint">{translate('portal','net_enrolled')}</div>
                </div>

                <div className="hub-tile is-accent tone-ok">
                    <div className="hub-label ops-row">
                        <Award size={16} /> {translate('portal','net_rank_mo')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {number(stats.moCount)}
                    </div>
                    <div className="hub-hint">{translate('portal','net_mo_hint')}</div>
                </div>

                <div className="hub-tile is-accent tone-info">
                    <div className="hub-label ops-row">
                        <ShieldCheck size={16} /> {translate('portal','net_kpi_am')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {number(stats.amCount)}
                    </div>
                    <div className="hub-hint">{translate('portal','net_kpi_am_hint')}</div>
                </div>

                <div className="hub-tile is-accent tone-violet">
                    <div className="hub-label ops-row">
                        <Award size={16} /> {translate('portal','net_kpi_sm_me')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {stats.smCount + stats.meCount}
                    </div>
                    <div className="hub-hint">{translate('portal','net_higher_ranks')}</div>
                </div>
            </div>

            {/* Filter & Toolbar Bar */}
            <div className="ops-card is-tight ops-row is-between is-wrap is-loose">
                <div className="ops-row is-wrap is-loose">
                    <div className="ntx-search">
                        <Search size={16} />
                        <input
                            type="text"
                            placeholder={translate('portal','net_search_placeholder')}
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                        />
                    </div>

                    <div className="ops-row is-snug">
                        <Filter size={15} className="ops-muted" />
                        <select
                            value={rankFilter}
                            onChange={e => setRankFilter(e.target.value)}
                        >
                            <option value="all">{translate('portal','net_all_ranks')}</option>
                            <option value="dealer">{translate('portal','acct_role_dealer')}</option>
                            <option value="mo">{translate('portal','net_rank_mo')}</option>
                            <option value="am">{translate('portal','net_rank_am')}</option>
                            <option value="sm">{translate('portal','net_rank_sm')}</option>
                            <option value="me">{translate('portal','net_rank_me')}</option>
                        </select>
                    </div>

                    {focusedRootId && (
                        <button
                            type="button"
                            onClick={() => setFocusedRootId(null)}
                            className="button small danger"
                        >
                            ✕ {translate('portal','net_reset_focus')}
                        </button>
                    )}
                </div>

                <div className="ops-row is-wrap is-loose">
                    <a
                        href="/exports/genealogy"
                        className="button small"
                        title={translate('portal', 'net_export_hint')}
                    >
                        <Download size={14} /> {translate('portal', 'net_export_csv')}
                    </a>
                <div className="hub-seg">
                    <button
                        type="button"
                        onClick={() => setViewMode('tree')}
                        className={viewMode === 'tree' ? 'is-active' : undefined}
                    >
                        <GitFork size={14} /> {translate('portal','net_view_tree')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setViewMode('table')}
                        className={viewMode === 'table' ? 'is-active' : undefined}
                    >
                        <Users size={14} /> {translate('portal','net_view_table')}
                    </button>
                </div>
                </div>
            </div>

            {/* Main Interactive Content Area: Tree or Table + Inspection Drawer */}
            <div className={`ntx-main${activeDealer ? ' has-drawer' : ''}`}>
                {viewMode === 'tree' ? (
                    <div className="ops-card ntx-tree">
                        <div className="ops-row is-between is-wrap ops-mb-4">
                            <div>
                                <h3 className="hub-title">
                                    {focusedRootId ? translate('portal','net_subtree_for',{name: dealerMap[focusedRootId]?.name || '—'}) : translate('portal', isDealerPortal ? 'net_my_tree' : 'net_company_tree')}
                                </h3>
                                <p className="hub-sub">
                                    {translate('portal', 'net_tree_hint')}
                                </p>
                            </div>
                            <div className="ops-row">
                                <button
                                    type="button"
                                    onClick={() => setCollapsedNodes({})}
                                    className="button small"
                                >
                                    {translate('portal','net_expand_all')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        const c: Record<number, boolean> = {};
                                        dealers.forEach(d => { if ((childrenMap[d.id] || []).length > 0) c[d.id] = true; });
                                        setCollapsedNodes(c);
                                    }}
                                    className="button small"
                                >
                                    {translate('portal','net_collapse_all')}
                                </button>
                            </div>
                        </div>

                        {/* Tree Nodes Rendering */}
                        <div className="ntx-forest">
                            {focusedRootId ? (
                                dealerMap[focusedRootId] ? renderTreeNode(dealerMap[focusedRootId], 0) : <p>{translate('portal','net_root_missing')}</p>
                            ) : roots.length > 0 ? (
                                roots.map(rootNode => renderTreeNode(rootNode, 0))
                            ) : (
                                <div className="hub-empty is-plain ntx-empty">
                                    {translate('portal','net_no_roots')}
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    /* Table View */
                    <div className="ops-card">
                        <Table
                            columns={[
                                {
                                    key: 'dealer',
                                    title: translate('portal', 'acct_role_dealer'),
                                    sortable: true,
                                    sortValue: (d: DealerNode) => d.name,
                                    render: (d: DealerNode) => (
                                        <div>
                                            <div className="hub-strong">{d.name}</div>
                                            <div className="ops-meta">{d.phone || translate('portal', 'net_no_phone')}</div>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'dealer_code',
                                    title: translate('portal', 'net_code'),
                                    sortable: true,
                                    sortValue: (d: DealerNode) => d.dealer_code || '',
                                    render: (d: DealerNode) => <span className="ops-semibold">{d.dealer_code || '—'}</span>,
                                },
                                {
                                    key: 'rank',
                                    title: translate('portal', 'net_rank'),
                                    sortable: true,
                                    sortValue: (d: DealerNode) => d.rank || '',
                                    render: (d: DealerNode) => {
                                        const badge = rankBadgeStyles[d.rank] || rankBadgeStyles.dealer;
                                        return (
                                            <span className={`ntx-rank ${badge.tone}`}>
                                                {translate('portal', badge.labelKey)}
                                            </span>
                                        );
                                    },
                                },
                                {
                                    key: 'sponsor',
                                    title: translate('portal', 'net_sponsor'),
                                    sortable: true,
                                    sortValue: (d: DealerNode) => d.sponsor_name || '',
                                    render: (d: DealerNode) => d.sponsor_name ? (
                                        <span>{d.sponsor_name}</span>
                                    ) : (
                                        <span className="ops-faint">{translate('portal', 'net_company_direct')}</span>
                                    ),
                                },
                                {
                                    key: 'lifetime_purchases',
                                    title: translate('portal', 'net_lifetime_purchases'),
                                    sortable: true,
                                    align: 'right',
                                    sortValue: (d: DealerNode) => salesOf(d),
                                    render: (d: DealerNode) => (
                                        <span className="ops-bold tone-brand">
                                            {money(salesOf(d))}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'sales_month',
                                    title: translate('portal', 'net_sales_month'),
                                    sortable: true,
                                    align: 'right',
                                    sortValue: (d: DealerNode) => Number(d.sales_month) || 0,
                                    render: (d: DealerNode) => money(d.sales_month ?? 0),
                                },
                                {
                                    key: 'qualifying_volume_month',
                                    title: translate('portal', 'net_qualifying_volume_month'),
                                    sortable: true,
                                    align: 'right',
                                    sortValue: (d: DealerNode) => Number(d.qualifying_volume_month) || 0,
                                    render: (d: DealerNode) => money(d.qualifying_volume_month ?? 0),
                                },
                                {
                                    key: 'sponsor_earned',
                                    title: translate('portal', 'net_referrer_earned'),
                                    sortable: true,
                                    align: 'right',
                                    sortValue: (d: DealerNode) => Number(d.sponsor_earned) || 0,
                                    render: (d: DealerNode) => d.sponsor_id ? <span className="tone-ok">{money(d.sponsor_earned ?? 0)}</span> : '—',
                                },
                                ...(showsViewerEarnings ? [{
                                    key: 'viewer_earned',
                                    title: translate('portal', 'net_you_earned'),
                                    sortable: true,
                                    align: 'right' as const,
                                    sortValue: (d: DealerNode) => Number(d.viewer_earned) || 0,
                                    render: (d: DealerNode) => d.id === viewerId ? '—' : <b className="tone-ok">{money(d.viewer_earned ?? 0)}</b>,
                                }] : []),
                                {
                                    key: 'directs',
                                    title: translate('portal', 'net_directs'),
                                    sortable: true,
                                    align: 'center',
                                    sortValue: (d: DealerNode) => d.direct_count ?? (childrenMap[d.id] || []).length,
                                    render: (d: DealerNode) => {
                                        const directCount = d.direct_count ?? (childrenMap[d.id] || []).length;
                                        return (
                                            <span className={`ntx-count${directCount > 0 ? ' tone-info' : ''}`}>
                                                {number(directCount)}
                                            </span>
                                        );
                                    },
                                },
                                {
                                    key: 'actions',
                                    title: translate('portal', 'net_actions'),
                                    align: 'right',
                                    render: (d: DealerNode) => (
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setFocusedRootId(d.id);
                                                setViewMode('tree');
                                            }}
                                            className="button small light"
                                        >
                                            {translate('portal', 'net_view_tree_btn')} ↗
                                        </button>
                                    ),
                                },
                            ]}
                            data={filteredDealers}
                            onRowClick={(d: DealerNode) => setSelectedDealerId(d.id)}
                            rowClassName={(d: DealerNode) => (selectedDealerId === d.id ? 'ntx-row is-selected' : 'ntx-row')}
                            pageSize={15}
                            emptyMessage={translate('portal', 'net_no_match')}
                        />
                    </div>
                )}

                {/* Right-Hand Inspection Card for Selected Dealer */}
                {activeDealer && (
                    <div className="ops-card ntx-drawer">
                        <div className="ops-row is-between is-top">
                            <div>
                                <span className={`ntx-rank ${(rankBadgeStyles[activeDealer.rank] || rankBadgeStyles.dealer).tone}`}>
                                    {translate('portal', (rankBadgeStyles[activeDealer.rank] || rankBadgeStyles.dealer).labelKey)}
                                </span>
                                <h3 className="ntx-drawer-name">
                                    {activeDealer.name}
                                </h3>
                                <div className="ops-caption">
                                    {translate('portal','net_code')}: <b>{activeDealer.dealer_code || '—'}</b>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedDealerId(null)}
                                className="hub-dismiss ntx-close"
                            >
                                ✕
                            </button>
                        </div>

                        <hr className="hub-divider" />

                        <div className="ops-stack ops-fs-xs">
                            <div className="ops-row ops-text-2">
                                <Phone size={14} className="ops-faint" />
                                <span>{activeDealer.phone || translate('portal','net_no_phone')}</span>
                            </div>
                            <div className="ops-row ops-text-2">
                                <MapPin size={14} className="ops-faint" />
                                <span>{activeDealer.district || '—'}, {activeDealer.division || '—'}</span>
                            </div>
                            <div className="ops-row ops-text-2">
                                <Calendar size={14} className="ops-faint" />
                                <span>{translate('portal','net_joined')}: {date(activeDealer.created_at)}</span>
                            </div>
                            <div className="ops-row ops-text-2">
                                <DollarSign size={14} className="ops-faint" />
                                <span>{translate('portal','net_lifetime_purchases')}: <b className="tone-brand">{money(salesOf(activeDealer))}</b></span>
                            </div>
                        </div>

                        <hr className="hub-divider" />

                        <div>
                            <div className="hub-label ops-mb-2">{translate('portal','net_money_summary')}</div>
                            <dl className="ntx-money">
                                <div><dt>{translate('portal','net_sales_month')}</dt><dd>{money(activeDealer.sales_month ?? 0)}</dd></div>
                                <div><dt>{translate('portal','net_qualifying_volume_month')}<small className="cell-sub">{translate('portal','net_qualifying_volume_hint')}</small></dt><dd>{money(activeDealer.qualifying_volume_month ?? 0)}</dd></div>
                                <div><dt>{translate('portal','net_team_purchases')}</dt><dd>{money(sumTeam(activeDealer.id, d => salesOf(d)))}</dd></div>
                                {activeDealer.sponsor_id && dealerMap[activeDealer.sponsor_id] && activeDealer.sponsor_id !== viewerId && (
                                    <div><dt>{translate('portal','net_referrer_earned_from',{name: dealerMap[activeDealer.sponsor_id].name})}</dt><dd className="tone-ok">{money(activeDealer.sponsor_earned ?? 0)}</dd></div>
                                )}
                                {showsViewerEarnings && activeDealer.id !== viewerId && (
                                    <div><dt>{translate('portal','net_you_earned')}</dt><dd className="tone-ok">{money(activeDealer.viewer_earned ?? 0)}</dd></div>
                                )}
                                {showsViewerEarnings && (childrenMap[activeDealer.id] || []).length > 0 && (
                                    <div><dt>{translate('portal','net_team_you_earned')}</dt><dd className="tone-ok">{money(sumTeam(activeDealer.id, d => d.id === viewerId ? 0 : Number(d.viewer_earned) || 0))}</dd></div>
                                )}
                            </dl>
                            {showsViewerEarnings && (
                                (activeDealer.viewer_earned_by_type || []).length > 0 ? (
                                    <div className="hub-well ops-mt-2">
                                        <div className="hub-label">{translate('portal','net_earned_breakdown')}</div>
                                        {activeDealer.id === viewerId && (
                                            <p className="ops-meta">{translate('portal','net_own_purchase_note')}</p>
                                        )}
                                        <ul className="ntx-breakdown">
                                            {(activeDealer.viewer_earned_by_type || []).map(row => (
                                                <li key={row.category}><span>{translate('portal', `report_cat_${row.category}`)}</span><b>{money(row.amount)}</b></li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : activeDealer.id !== viewerId ? (
                                    <p className="ops-meta ops-mt-2">{translate('portal','net_no_earning_yet')}</p>
                                ) : null
                            )}
                        </div>

                        <hr className="hub-divider" />

                        {/* Network Stats */}
                        <div>
                            <div className="hub-label ops-mb-2">
                                {translate('portal','net_subtree_stats')}
                            </div>
                            <div className="ntx-substats">
                                <div className="ntx-substat tone-info">
                                    <div className="hub-value">
                                        {activeDealer.direct_count ?? (childrenMap[activeDealer.id] || []).length}
                                    </div>
                                    <div className="ops-meta">{translate('portal','net_direct_referrals')}</div>
                                </div>
                                <div className="ntx-substat tone-brand">
                                    <div className="hub-value">
                                        {getDownlineCount(activeDealer.id)}
                                    </div>
                                    <div className="ops-meta">{translate('portal','net_total_downline')}</div>
                                </div>
                            </div>
                        </div>

                        {/* Sponsor info */}
                        <div className="hub-well ops-mt-3">
                            <div className="hub-label">{translate('portal','net_direct_sponsor')}</div>
                            <div className="hub-strong ops-fs-sm ops-mt-1">
                                {activeDealer.sponsor_name || translate('portal','net_company_placement')}
                            </div>
                            {activeDealer.sponsor_id && (
                                <button
                                    type="button"
                                    onClick={() => setSelectedDealerId(activeDealer.sponsor_id)}
                                    className="ntx-link"
                                >
                                    {translate('portal','net_inspect_sponsor')}
                                </button>
                            )}
                        </div>

                        {isDealerPortal && activeDealer.id && (
                            <Link
                                href={`/network?member_id=${activeDealer.id}`}
                                className="button small hub-block ops-mt-3"
                            >
                                <Eye size={14} /> {translate('portal', 'net_view_timeline')}
                            </Link>
                        )}

                        {/* Focus Subtree Button */}
                        <button
                            type="button"
                            onClick={() => {
                                setFocusedRootId(activeDealer.id);
                                setViewMode('tree');
                            }}
                            className="button dark hub-block ops-mt-4"
                        >
                            <GitFork size={15} /> {translate('portal','net_focus_subtree')}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

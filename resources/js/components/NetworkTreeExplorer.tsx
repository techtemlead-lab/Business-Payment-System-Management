import React, { useState, useMemo } from 'react';
import { Search, GitFork, Users, Award, ChevronRight, ChevronDown, Phone, MapPin, Calendar, DollarSign, ArrowUpRight, ShieldCheck, Filter, UserCheck, Eye } from 'lucide-react';
import { money, date } from './UI';
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
    created_at: string;
}

interface NetworkTreeExplorerProps {
    dealers: DealerNode[];
}

const rankBadges: Record<string, { label: string; bg: string; text: string; border: string }> = {
    dealer: { label: 'DEALER', bg: '#f1f5f9', text: '#475569', border: '#cbd5e1' },
    mo: { label: 'MO (MKT OFFICER)', bg: '#ecfdf5', text: '#047857', border: '#a7f3d0' },
    am: { label: 'AM (AREA MGR)', bg: '#eff6ff', text: '#1d4ed8', border: '#bfdbfe' },
    sm: { label: 'SM (SALES MGR)', bg: '#faf5ff', text: '#7e22ce', border: '#e9d5ff' },
    me: { label: 'ME (EXEC MGR)', bg: '#fffbeb', text: '#b45309', border: '#fde68a' },
};

export default function NetworkTreeExplorer({ dealers }: NetworkTreeExplorerProps) {
    const translate = useT();
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
            if (d.sponsor_id) {
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
        const total = dealers.length;
        const moCount = dealers.filter(d => d.rank === 'mo').length;
        const amCount = dealers.filter(d => d.rank === 'am').length;
        const smCount = dealers.filter(d => d.rank === 'sm').length;
        const meCount = dealers.filter(d => d.rank === 'me').length;
        const totalVolume = dealers.reduce((acc, d) => acc + (d.lifetime_purchases || 0), 0);
        return { total, moCount, amCount, smCount, meCount, totalVolume };
    }, [dealers]);

    // Recursive calculation of total downline size
    const getDownlineCount = (id: number): number => {
        const children = childrenMap[id] || [];
        return children.reduce((count, child) => count + 1 + getDownlineCount(child.id), 0);
    };

    // Recursive tree node renderer
    const renderTreeNode = (dealer: DealerNode, depth: number = 0) => {
        const children = childrenMap[dealer.id] || [];
        const isCollapsed = !!collapsedNodes[dealer.id];
        const isSelected = selectedDealerId === dealer.id;
        const badge = rankBadges[dealer.rank] || rankBadges.dealer;
        const directCount = children.length;
        const totalDownline = getDownlineCount(dealer.id);

        let genTag = '';
        if (depth === 0) genTag = translate('portal','net_gen_root');
        else if (depth === 1) genTag = translate('portal','net_gen_1');
        else if (depth === 2) genTag = translate('portal','net_gen_2');
        else if (depth === 3) genTag = translate('portal','net_gen_3');
        else genTag = translate('portal','net_gen_n',{depth:String(depth)});

        return (
            <div key={dealer.id} style={{ marginLeft: depth > 0 ? 28 : 0, marginTop: 8, position: 'relative' }}>
                {depth > 0 && (
                    <div style={{
                        position: 'absolute',
                        left: -18,
                        top: 18,
                        width: 16,
                        height: 2,
                        background: '#cbd5e1'
                    }} />
                )}

                <div
                    onClick={() => setSelectedDealerId(dealer.id)}
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 12,
                        padding: '10px 14px',
                        background: isSelected ? '#f0fdf4' : '#ffffff',
                        border: `1.5px solid ${isSelected ? '#006837' : '#e2e8f0'}`,
                        borderRadius: 10,
                        boxShadow: isSelected ? '0 0 0 2px rgba(0, 104, 55, 0.15)' : '0 1px 3px rgba(0,0,0,0.04)',
                        cursor: 'pointer',
                        transition: 'all 0.15s ease',
                        minWidth: 320,
                        maxWidth: 480
                    }}
                >
                    {children.length > 0 ? (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); toggleCollapse(dealer.id); }}
                            style={{
                                width: 22,
                                height: 22,
                                borderRadius: 4,
                                border: '1px solid #cbd5e1',
                                background: '#f8fafc',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                cursor: 'pointer',
                                padding: 0
                            }}
                        >
                            {isCollapsed ? <ChevronRight size={14} /> : <ChevronDown size={14} />}
                        </button>
                    ) : (
                        <div style={{ width: 22, height: 22, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            <div style={{ width: 6, height: 6, borderRadius: '50%', background: '#cbd5e1' }} />
                        </div>
                    )}

                    <div style={{ flex: 1 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                            <span style={{ fontWeight: 700, fontSize: 13, color: '#0f172a' }}>{dealer.name}</span>
                            <span style={{
                                fontSize: 10,
                                fontWeight: 700,
                                padding: '1px 5px',
                                borderRadius: 4,
                                background: badge.bg,
                                color: badge.text,
                                border: `1px solid ${badge.border}`
                            }}>
                                {badge.label}
                            </span>
                            <span style={{
                                fontSize: 9,
                                fontWeight: 600,
                                padding: '1px 4px',
                                borderRadius: 3,
                                background: depth === 1 ? '#dcfce7' : depth === 2 ? '#dbeafe' : depth === 3 ? '#f3e8ff' : '#f1f5f9',
                                color: depth === 1 ? '#15803d' : depth === 2 ? '#1d4ed8' : depth === 3 ? '#7e22ce' : '#64748b'
                            }}>
                                {genTag}
                            </span>
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 11, color: '#64748b', marginTop: 2 }}>
                            <span>{translate('portal','net_code')}: <b>{dealer.dealer_code || '—'}</b></span>
                            <span>•</span>
                            <span>{translate('portal','net_purchases')}: <b style={{ color: '#006837' }}>{money(dealer.lifetime_purchases)}</b></span>
                        </div>
                    </div>

                    <div style={{ textAlign: 'right', display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 2 }}>
                        <span style={{
                            fontSize: 11,
                            fontWeight: 700,
                            padding: '2px 6px',
                            borderRadius: 12,
                            background: directCount > 0 ? '#e0f2fe' : '#f1f5f9',
                            color: directCount > 0 ? '#0369a1' : '#94a3b8'
                        }}>
                            {translate('portal','net_direct_count',{count:String(directCount)})}
                        </span>
                        {totalDownline > directCount && (
                            <span style={{ fontSize: 10, color: '#94a3b8' }}>
                                {translate('portal','net_total_count',{count:String(totalDownline)})}
                            </span>
                        )}
                    </div>
                </div>

                {!isCollapsed && children.length > 0 && (
                    <div style={{
                        position: 'relative',
                        borderLeft: '2px solid #cbd5e1',
                        marginLeft: 10,
                        paddingLeft: 4
                    }}>
                        {children.map(child => renderTreeNode(child, depth + 1))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top Stat Summary Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#64748b', fontSize: 12, fontWeight: 600 }}>
                        <Users size={16} /> Total Network Partners
                    </div>
                    <div style={{ fontSize: 22, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>
                        {stats.total}
                    </div>
                    <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>Enrolled dealers & sub-dealers</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #a7f3d0', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#047857', fontSize: 12, fontWeight: 600 }}>
                        <Award size={16} /> Marketing Officers (MO)
                    </div>
                    <div style={{ fontSize: 22, fontWeight: 800, color: '#006837', marginTop: 4 }}>
                        {stats.moCount}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>৳2.5L cumulative turnover</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>
                        <ShieldCheck size={16} /> Area Managers (AM)
                    </div>
                    <div style={{ fontSize: 22, fontWeight: 800, color: '#1d4ed8', marginTop: 4 }}>
                        {stats.amCount}
                    </div>
                    <div style={{ fontSize: 11, color: '#3b82f6', marginTop: 2 }}>10 MOs in 3-gen downline</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #e9d5ff', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#7e22ce', fontSize: 12, fontWeight: 600 }}>
                        <Award size={16} /> Sales & Executive (SM/ME)
                    </div>
                    <div style={{ fontSize: 22, fontWeight: 800, color: '#7e22ce', marginTop: 4 }}>
                        {stats.smCount + stats.meCount}
                    </div>
                    <div style={{ fontSize: 11, color: '#a855f7', marginTop: 2 }}>Higher leadership ranks</div>
                </div>
            </div>

            {/* Filter & Toolbar Bar */}
            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                padding: '12px 16px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 12
            }}>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                    <div style={{ position: 'relative', width: 260 }}>
                        <Search size={16} style={{ position: 'absolute', left: 10, top: 10, color: '#94a3b8' }} />
                        <input
                            type="text"
                            placeholder="Search dealer, code, or phone…"
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                            style={{
                                width: '100%',
                                padding: '8px 12px 8px 34px',
                                fontSize: 13,
                                border: '1px solid #cbd5e1',
                                borderRadius: 6
                            }}
                        />
                    </div>

                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <Filter size={15} style={{ color: '#64748b' }} />
                        <select
                            value={rankFilter}
                            onChange={e => setRankFilter(e.target.value)}
                            style={{
                                padding: '8px 12px',
                                fontSize: 13,
                                border: '1px solid #cbd5e1',
                                borderRadius: 6,
                                background: '#ffffff',
                                color: '#334155'
                            }}
                        >
                            <option value="all">All Ranks</option>
                            <option value="dealer">Dealer</option>
                            <option value="mo">Marketing Officer (MO)</option>
                            <option value="am">Area Manager (AM)</option>
                            <option value="sm">Sales Manager (SM)</option>
                            <option value="me">Marketing Executive (ME)</option>
                        </select>
                    </div>

                    {focusedRootId && (
                        <button
                            type="button"
                            onClick={() => setFocusedRootId(null)}
                            style={{
                                padding: '6px 12px',
                                fontSize: 12,
                                fontWeight: 600,
                                background: '#fef2f2',
                                color: '#b91c1c',
                                border: '1px solid #fecaca',
                                borderRadius: 6,
                                cursor: 'pointer'
                            }}
                        >
                            ✕ Reset Tree Focus to Top
                        </button>
                    )}
                </div>

                <div style={{ display: 'flex', background: '#f1f5f9', borderRadius: 8, padding: 3 }}>
                    <button
                        type="button"
                        onClick={() => setViewMode('tree')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            background: viewMode === 'tree' ? '#ffffff' : 'transparent',
                            color: viewMode === 'tree' ? '#006837' : '#64748b',
                            border: 'none',
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 5
                        }}
                    >
                        <GitFork size={14} /> Tree Graph View
                    </button>
                    <button
                        type="button"
                        onClick={() => setViewMode('table')}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 6,
                            background: viewMode === 'table' ? '#ffffff' : 'transparent',
                            color: viewMode === 'table' ? '#006837' : '#64748b',
                            border: 'none',
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 5
                        }}
                    >
                        <Users size={14} /> Table Roster View
                    </button>
                </div>
            </div>

            {/* Main Interactive Content Area: Tree or Table + Inspection Drawer */}
            <div style={{ display: 'grid', gridTemplateColumns: activeDealer ? '1fr 340px' : '1fr', gap: 20, alignItems: 'start' }}>
                {viewMode === 'tree' ? (
                    <div style={{
                        background: '#ffffff',
                        border: '1px solid #e2e8f0',
                        borderRadius: 12,
                        padding: 24,
                        minHeight: 520,
                        overflowX: 'auto'
                    }}>
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                            <div>
                                <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#0f172a' }}>
                                    {focusedRootId ? `Sub-Tree for ${dealerMap[focusedRootId]?.name}` : 'Company Direct Referral Tree'}
                                </h3>
                                <p style={{ fontSize: 12, color: '#64748b', margin: '3px 0 0 0' }}>
                                    Click any dealer node to inspect details or refocus tree. Expand (+) or collapse (-) branches.
                                </p>
                            </div>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button
                                    type="button"
                                    onClick={() => setCollapsedNodes({})}
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 600,
                                        padding: '5px 10px',
                                        background: '#f8fafc',
                                        border: '1px solid #cbd5e1',
                                        borderRadius: 6,
                                        cursor: 'pointer'
                                    }}
                                >
                                    Expand All
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        const c: Record<number, boolean> = {};
                                        dealers.forEach(d => { if ((childrenMap[d.id] || []).length > 0) c[d.id] = true; });
                                        setCollapsedNodes(c);
                                    }}
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 600,
                                        padding: '5px 10px',
                                        background: '#f8fafc',
                                        border: '1px solid #cbd5e1',
                                        borderRadius: 6,
                                        cursor: 'pointer'
                                    }}
                                >
                                    Collapse All
                                </button>
                            </div>
                        </div>

                        {/* Tree Nodes Rendering */}
                        <div style={{ paddingTop: 8 }}>
                            {focusedRootId ? (
                                dealerMap[focusedRootId] ? renderTreeNode(dealerMap[focusedRootId], 0) : <p>Selected root not found</p>
                            ) : roots.length > 0 ? (
                                roots.map(rootNode => renderTreeNode(rootNode, 0))
                            ) : (
                                <div style={{ textAlign: 'center', padding: '40px 0', color: '#94a3b8' }}>
                                    No root dealers found in network tree.
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    /* Table View */
                    <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                            <thead>
                                <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                                    <th style={{ padding: '12px 16px' }}>Dealer</th>
                                    <th style={{ padding: '12px 16px' }}>Code</th>
                                    <th style={{ padding: '12px 16px' }}>Rank</th>
                                    <th style={{ padding: '12px 16px' }}>Sponsor</th>
                                    <th style={{ padding: '12px 16px' }}>Lifetime Purchases</th>
                                    <th style={{ padding: '12px 16px' }}>Directs</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'right' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredDealers.length === 0 && (
                                    <tr>
                                        <td colSpan={7} style={{ padding: '28px 16px', textAlign: 'center', color: '#64748b', fontSize: 13 }}>
                                            No dealers match this search. Clear filters or broaden the query to continue exploring the network.
                                        </td>
                                    </tr>
                                )}
                                {filteredDealers.map(d => {
                                    const badge = rankBadges[d.rank] || rankBadges.dealer;
                                    const directCount = (childrenMap[d.id] || []).length;
                                    return (
                                        <tr
                                            key={d.id}
                                            style={{
                                                borderBottom: '1px solid #f1f5f9',
                                                background: selectedDealerId === d.id ? '#f0fdf4' : 'transparent',
                                                cursor: 'pointer'
                                            }}
                                            onClick={() => setSelectedDealerId(d.id)}
                                        >
                                            <td style={{ padding: '12px 16px' }}>
                                                <div style={{ fontWeight: 600, color: '#0f172a' }}>{d.name}</div>
                                                <div style={{ fontSize: 11, color: '#64748b' }}>{d.phone || 'No phone'}</div>
                                            </td>
                                            <td style={{ padding: '12px 16px', fontWeight: 600 }}>{d.dealer_code || '—'}</td>
                                            <td style={{ padding: '12px 16px' }}>
                                                <span style={{
                                                    fontSize: 10,
                                                    fontWeight: 700,
                                                    padding: '2px 6px',
                                                    borderRadius: 4,
                                                    background: badge.bg,
                                                    color: badge.text,
                                                    border: `1px solid ${badge.border}`
                                                }}>
                                                    {badge.label}
                                                </span>
                                            </td>
                                            <td style={{ padding: '12px 16px', color: '#475569' }}>
                                                {d.sponsor_name ? (
                                                    <span>{d.sponsor_name}</span>
                                                ) : (
                                                    <span style={{ color: '#94a3b8' }}>Company Direct</span>
                                                )}
                                            </td>
                                            <td style={{ padding: '12px 16px', fontWeight: 700, color: '#006837' }}>
                                                {money(d.lifetime_purchases)}
                                            </td>
                                            <td style={{ padding: '12px 16px' }}>
                                                <span style={{
                                                    fontSize: 11,
                                                    fontWeight: 700,
                                                    padding: '2px 7px',
                                                    borderRadius: 10,
                                                    background: directCount > 0 ? '#e0f2fe' : '#f1f5f9',
                                                    color: directCount > 0 ? '#0369a1' : '#94a3b8'
                                                }}>
                                                    {directCount}
                                                </span>
                                            </td>
                                            <td style={{ padding: '12px 16px', textAlign: 'right' }}>
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setFocusedRootId(d.id);
                                                        setViewMode('tree');
                                                    }}
                                                    style={{
                                                        fontSize: 11,
                                                        fontWeight: 600,
                                                        padding: '4px 8px',
                                                        background: '#ecfdf5',
                                                        color: '#047857',
                                                        border: '1px solid #a7f3d0',
                                                        borderRadius: 4,
                                                        cursor: 'pointer'
                                                    }}
                                                >
                                                    View Tree ↗
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Right-Hand Inspection Card for Selected Dealer */}
                {activeDealer && (
                    <div style={{
                        background: '#ffffff',
                        border: '1px solid #cbd5e1',
                        borderRadius: 12,
                        padding: 20,
                        boxShadow: '0 4px 12px rgba(0,0,0,0.06)',
                        position: 'sticky',
                        top: 20
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                            <div>
                                <span style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    padding: '2px 6px',
                                    borderRadius: 4,
                                    background: (rankBadges[activeDealer.rank] || rankBadges.dealer).bg,
                                    color: (rankBadges[activeDealer.rank] || rankBadges.dealer).text,
                                    border: `1px solid ${(rankBadges[activeDealer.rank] || rankBadges.dealer).border}`
                                }}>
                                    {(rankBadges[activeDealer.rank] || rankBadges.dealer).label}
                                </span>
                                <h3 style={{ fontSize: 17, fontWeight: 800, margin: '8px 0 2px 0', color: '#0f172a' }}>
                                    {activeDealer.name}
                                </h3>
                                <div style={{ fontSize: 12, color: '#64748b' }}>
                                    Code: <b>{activeDealer.dealer_code || '—'}</b>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedDealerId(null)}
                                style={{
                                    background: 'transparent',
                                    border: 'none',
                                    fontSize: 18,
                                    color: '#94a3b8',
                                    cursor: 'pointer',
                                    padding: 4
                                }}
                            >
                                ✕
                            </button>
                        </div>

                        <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '14px 0' }} />

                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, fontSize: 12 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#475569' }}>
                                <Phone size={14} style={{ color: '#94a3b8' }} />
                                <span>{activeDealer.phone || 'No phone recorded'}</span>
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#475569' }}>
                                <MapPin size={14} style={{ color: '#94a3b8' }} />
                                <span>{activeDealer.district || '—'}, {activeDealer.division || '—'}</span>
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#475569' }}>
                                <Calendar size={14} style={{ color: '#94a3b8' }} />
                                <span>Joined: {date(activeDealer.created_at)}</span>
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#475569' }}>
                                <DollarSign size={14} style={{ color: '#94a3b8' }} />
                                <span>Lifetime Purchases: <b style={{ color: '#006837' }}>{money(activeDealer.lifetime_purchases)}</b></span>
                            </div>
                        </div>

                        <hr style={{ border: 'none', borderTop: '1px solid #f1f5f9', margin: '14px 0' }} />

                        {/* Network Stats */}
                        <div>
                            <div style={{ fontSize: 11, fontWeight: 700, color: '#64748b', textTransform: 'uppercase', marginBottom: 8 }}>
                                Network Sub-Tree Stats
                            </div>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: 8, textAlign: 'center' }}>
                                    <div style={{ fontSize: 18, fontWeight: 800, color: '#0369a1' }}>
                                        {(childrenMap[activeDealer.id] || []).length}
                                    </div>
                                    <div style={{ fontSize: 10, color: '#64748b', marginTop: 2 }}>Direct Referrals (Gen 1)</div>
                                </div>
                                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: 8, textAlign: 'center' }}>
                                    <div style={{ fontSize: 18, fontWeight: 800, color: '#006837' }}>
                                        {getDownlineCount(activeDealer.id)}
                                    </div>
                                    <div style={{ fontSize: 10, color: '#64748b', marginTop: 2 }}>Total Downline Team</div>
                                </div>
                            </div>
                        </div>

                        {/* Sponsor info */}
                        <div style={{ marginTop: 14, background: '#f8fafc', padding: 12, borderRadius: 8 }}>
                            <div style={{ fontSize: 11, fontWeight: 700, color: '#64748b' }}>Direct Sponsor (Upline)</div>
                            <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', marginTop: 2 }}>
                                {activeDealer.sponsor_name || 'Direct Company Placement'}
                            </div>
                            {activeDealer.sponsor_id && (
                                <button
                                    type="button"
                                    onClick={() => setSelectedDealerId(activeDealer.sponsor_id)}
                                    style={{
                                        marginTop: 6,
                                        fontSize: 11,
                                        fontWeight: 600,
                                        color: '#006837',
                                        background: 'none',
                                        border: 'none',
                                        cursor: 'pointer',
                                        padding: 0,
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 4
                                    }}
                                >
                                    Inspect Sponsor ↗
                                </button>
                            )}
                        </div>

                        {/* Focus Subtree Button */}
                        <button
                            type="button"
                            onClick={() => {
                                setFocusedRootId(activeDealer.id);
                                setViewMode('tree');
                            }}
                            style={{
                                marginTop: 16,
                                width: '100%',
                                padding: '10px',
                                background: '#006837',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 8,
                                fontWeight: 700,
                                fontSize: 12,
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                gap: 6
                            }}
                        >
                            <GitFork size={15} /> Focus Subtree as Root
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

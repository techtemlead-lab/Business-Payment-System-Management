import React, { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { MapPin, Search, Filter, Building2, Compass } from 'lucide-react';
import { money } from './UI';
import { useT } from '../shared/i18n/t';

interface AreaAssignment {
    id: number;
    user_id: number;
    level: 'division' | 'district' | 'upazila' | 'union';
    zone_name: string;
    place_code_id?: number | null;
    division: string | null;
    district: string | null;
    upazila: string | null;
    union: string | null;
    monthly_quota: string | number;
    is_active: boolean;
    created_at: string;
    place_code?: {
        id: number;
        code: string;
        name: string;
        level: string;
    } | null;
    user?: {
        id: number;
        name: string;
        dealer_code: string;
        phone: string;
        district: string;
        division: string;
    };
}

interface DealerOption {
    id: number;
    name: string;
    dealer_code: string;
    district: string;
    division: string;
}

interface PlaceCodeOption {
    id: number;
    code: string;
    name: string;
    level: 'division' | 'district' | 'upazila' | 'union';
    parent_id: number | null;
}

interface AreaDealersHubProps {
    assignments: AreaAssignment[];
    divisions: string[];
    dealers_list: DealerOption[];
    place_codes?: PlaceCodeOption[];
}

const levelMeta: Record<string, { labelKey: string; bg: string; text: string; defaultQuota: number }> = {
    division: { labelKey: 'area_lvl_division', bg: '#eff6ff', text: '#1d4ed8', defaultQuota: 50000 },
    district: { labelKey: 'area_lvl_district', bg: '#ecfdf5', text: '#047857', defaultQuota: 40000 },
    upazila: { labelKey: 'area_lvl_upazila', bg: '#fdf4ff', text: '#a21caf', defaultQuota: 20000 },
    union: { labelKey: 'area_lvl_union', bg: '#fffbeb', text: '#b45309', defaultQuota: 10000 },
};

export default function AreaDealersHub({ assignments, divisions }: AreaDealersHubProps) {
    const translate = useT();
    const [selectedDivision, setSelectedDivision] = useState<string>('all');
    const [selectedLevel, setSelectedLevel] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState('');

    const filteredAssignments = useMemo(() => {
        return assignments.filter(a => {
            const matchesDiv = selectedDivision === 'all' || a.division === selectedDivision;
            const matchesLevel = selectedLevel === 'all' || a.level === selectedLevel;
            const matchesQuery = !searchQuery ||
                a.zone_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                (a.place_code?.code && a.place_code.code.includes(searchQuery)) ||
                (a.user?.name && a.user.name.toLowerCase().includes(searchQuery.toLowerCase())) ||
                (a.user?.dealer_code && a.user.dealer_code.toLowerCase().includes(searchQuery.toLowerCase()));
            return matchesDiv && matchesLevel && matchesQuery;
        });
    }, [assignments, selectedDivision, selectedLevel, searchQuery]);

    const divisionStats = useMemo(() => {
        const stats: Record<string, { count: number; totalQuota: number }> = {};
        divisions.forEach(d => { stats[d] = { count: 0, totalQuota: 0 }; });
        assignments.forEach(a => {
            const div = a.division || 'Unknown';
            if (!stats[div]) stats[div] = { count: 0, totalQuota: 0 };
            stats[div].count += 1;
            stats[div].totalQuota += Number(a.monthly_quota) || 0;
        });
        return stats;
    }, [assignments, divisions]);

    const totalActiveQuota = assignments.reduce((sum, a) => sum + (Number(a.monthly_quota) || 0), 0);
    const levelLabel = (level: string) => translate('portal', levelMeta[level]?.labelKey ?? 'area_lvl_district');

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            <p style={{ margin: 0, fontSize: 13, lineHeight: 1.5, color: '#334155', maxWidth: 760 }}>
                {translate('portal', 'area_intro')}
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#64748b', fontSize: 12, fontWeight: 600 }}>
                        <MapPin size={16} /> {translate('portal', 'area_kpi_total')}
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>
                        {assignments.length}
                    </div>
                    <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>{translate('portal', 'area_kpi_total_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #a7f3d0', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#047857', fontSize: 12, fontWeight: 600 }}>
                        <Building2 size={16} /> {translate('portal', 'area_kpi_quota')}
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 4 }}>
                        {money(totalActiveQuota)}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>{translate('portal', 'area_kpi_quota_hint')}</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>
                        <Compass size={16} /> {translate('portal', 'area_kpi_pool')}
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 4 }}>
                        4.00%
                    </div>
                    <div style={{ fontSize: 11, color: '#3b82f6', marginTop: 2 }}>{translate('portal', 'area_kpi_pool_hint')}</div>
                </div>
            </div>

            <div style={{
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                borderRadius: 10,
                padding: '14px 18px',
                display: 'flex',
                flexDirection: 'column',
                gap: 10
            }}>
                <div style={{ fontSize: 12, fontWeight: 700, color: '#475569', textTransform: 'uppercase' }}>
                    {translate('portal', 'area_regional')}
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        onClick={() => setSelectedDivision('all')}
                        style={{
                            padding: '6px 12px',
                            fontSize: 12,
                            fontWeight: 700,
                            borderRadius: 20,
                            border: `1.5px solid ${selectedDivision === 'all' ? '#006837' : '#cbd5e1'}`,
                            background: selectedDivision === 'all' ? '#ecfdf5' : '#ffffff',
                            color: selectedDivision === 'all' ? '#006837' : '#475569',
                            cursor: 'pointer'
                        }}
                    >
                        {translate('portal', 'area_all_divisions', { count: String(assignments.length) })}
                    </button>
                    {divisions.map(div => {
                        const st = divisionStats[div] || { count: 0, totalQuota: 0 };
                        const isSelected = selectedDivision === div;
                        return (
                            <button
                                key={div}
                                type="button"
                                onClick={() => setSelectedDivision(div)}
                                style={{
                                    padding: '6px 12px',
                                    fontSize: 12,
                                    fontWeight: 600,
                                    borderRadius: 20,
                                    border: `1.5px solid ${isSelected ? '#006837' : '#e2e8f0'}`,
                                    background: isSelected ? '#ecfdf5' : '#f8fafc',
                                    color: isSelected ? '#006837' : '#475569',
                                    cursor: 'pointer',
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 6
                                }}
                            >
                                <span>{div}</span>
                                <span style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    padding: '1px 5px',
                                    borderRadius: 10,
                                    background: st.count > 0 ? '#dcfce7' : '#f1f5f9',
                                    color: st.count > 0 ? '#15803d' : '#94a3b8'
                                }}>
                                    {st.count}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

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
                            placeholder={translate('portal', 'area_search_ph')}
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
                            value={selectedLevel}
                            onChange={e => setSelectedLevel(e.target.value)}
                            style={{
                                padding: '8px 12px',
                                fontSize: 13,
                                border: '1px solid #cbd5e1',
                                borderRadius: 6,
                                background: '#ffffff',
                                color: '#334155'
                            }}
                        >
                            <option value="all">{translate('portal', 'area_all_tiers')}</option>
                            <option value="division">{translate('portal', 'area_tier_division')}</option>
                            <option value="district">{translate('portal', 'area_tier_district')}</option>
                            <option value="upazila">{translate('portal', 'area_tier_upazila')}</option>
                            <option value="union">{translate('portal', 'area_tier_union')}</option>
                        </select>
                    </div>
                </div>

                <Link
                    href="/accounts/create?intent=dealer"
                    style={{
                        padding: '8px 16px',
                        background: '#006837',
                        color: '#ffffff',
                        borderRadius: 6,
                        fontWeight: 700,
                        fontSize: 13,
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6
                    }}
                >
                    {translate('portal', 'area_add_dealer')}
                </Link>
            </div>

            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'area_col_zone')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'area_col_dealer')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'area_col_tier')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'area_col_location')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'area_col_quota')}</th>
                            <th style={{ padding: '12px 16px' }}>{translate('portal', 'area_col_status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredAssignments.length > 0 ? (
                            filteredAssignments.map(a => {
                                const levelConfig = levelMeta[a.level] || levelMeta.district;
                                return (
                                    <tr key={a.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: 700, color: '#0f172a' }}>{a.zone_name}</div>
                                            {a.place_code?.code && (
                                                <div style={{ fontSize: 11, color: '#0f766e', fontWeight: 700, marginTop: 2 }}>
                                                    {translate('portal', 'area_code', { code: a.place_code.code })}
                                                </div>
                                            )}
                                            <div style={{ fontSize: 11, color: '#64748b' }}>{a.place_code ? translate('portal', 'area_holds_code') : translate('portal', 'area_no_place')}</div>
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            {a.user ? (
                                                <div>
                                                    <div style={{ fontWeight: 600, color: '#0f172a' }}>{a.user.name}</div>
                                                    <div style={{ fontSize: 11, color: '#64748b' }}>
                                                        {a.user.dealer_code} {a.user.phone ? `• ${a.user.phone}` : ''}
                                                    </div>
                                                </div>
                                            ) : (
                                                <span style={{ color: '#94a3b8' }}>{translate('portal', 'area_unassigned')}</span>
                                            )}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            {a.place_code ? (
                                                <span style={{
                                                    fontSize: 11,
                                                    fontWeight: 700,
                                                    padding: '2px 8px',
                                                    borderRadius: 4,
                                                    background: levelConfig.bg,
                                                    color: levelConfig.text
                                                }}>
                                                    {levelLabel(a.level)}
                                                </span>
                                            ) : '—'}
                                        </td>
                                        <td style={{ padding: '12px 16px', color: '#475569' }}>
                                            <div>{a.division || '—'}</div>
                                            {a.district && <div style={{ fontSize: 11, color: '#64748b' }}>{translate('portal', 'area_district', { name: a.district })}</div>}
                                            {a.upazila && <div style={{ fontSize: 11, color: '#64748b' }}>{translate('portal', 'area_upazila', { name: a.upazila })}</div>}
                                        </td>
                                        <td style={{ padding: '12px 16px', fontWeight: 700, color: '#006837' }}>
                                            {money(a.monthly_quota)}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <span style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                padding: '2px 6px',
                                                borderRadius: 4,
                                                background: a.is_active ? '#dcfce7' : '#fee2e2',
                                                color: a.is_active ? '#15803d' : '#b91c1c'
                                            }}>
                                                {a.place_code ? translate('portal', 'area_active') : translate('portal', 'area_no_place')}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })
                        ) : (
                            <tr>
                                <td colSpan={6} style={{ textAlign: 'center', padding: '36px 0', color: '#94a3b8' }}>
                                    {translate('portal', 'area_empty')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

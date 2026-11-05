import React, { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { MapPin, Search, Filter, Building2, Compass } from 'lucide-react';
import { money, number, Table, type TableColumn } from './UI';
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

const levelMeta: Record<string, { labelKey: string; tone: string; defaultQuota: number }> = {
    division: { labelKey: 'area_lvl_division', tone: 'tone-info', defaultQuota: 50000 },
    district: { labelKey: 'area_lvl_district', tone: 'tone-ok', defaultQuota: 40000 },
    upazila: { labelKey: 'area_lvl_upazila', tone: 'tone-violet', defaultQuota: 20000 },
    union: { labelKey: 'area_lvl_union', tone: 'tone-warn', defaultQuota: 10000 },
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
        <div className="hub">
            <p className="adh-intro">
                {translate('portal', 'area_intro')}
            </p>
            <div className="hub-grid">
                <div className="hub-tile">
                    <div className="hub-label ops-row">
                        <MapPin size={16} /> {translate('portal', 'area_kpi_total')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {number(assignments.length)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'area_kpi_total_hint')}</div>
                </div>

                <div className="hub-tile is-accent tone-ok">
                    <div className="hub-label ops-row">
                        <Building2 size={16} /> {translate('portal', 'area_kpi_quota')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {money(totalActiveQuota)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'area_kpi_quota_hint')}</div>
                </div>

                <div className="hub-tile is-accent tone-info">
                    <div className="hub-label ops-row">
                        <Compass size={16} /> {translate('portal', 'area_kpi_pool')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        4.00%
                    </div>
                    <div className="hub-hint">{translate('portal', 'area_kpi_pool_hint')}</div>
                </div>
            </div>

            <div className="ops-card ops-stack">
                <div className="hub-label">
                    {translate('portal', 'area_regional')}
                </div>
                <div className="ops-row is-wrap">
                    <button
                        type="button"
                        onClick={() => setSelectedDivision('all')}
                        className={`adh-division is-all${selectedDivision === 'all' ? ' is-selected' : ''}`}
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
                                className={`adh-division${isSelected ? ' is-selected' : ''}`}
                            >
                                <span>{div}</span>
                                <span className={`adh-division-count${st.count > 0 ? ' tone-ok' : ''}`}>
                                    {number(st.count)}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="ops-card is-tight ops-row is-between is-wrap is-loose">
                <div className="ops-row is-wrap is-loose">
                    <div className="adh-search">
                        <Search size={16} />
                        <input
                            type="text"
                            placeholder={translate('portal', 'area_search_ph')}
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                        />
                    </div>

                    <div className="ops-row is-snug">
                        <Filter size={15} className="ops-muted" />
                        <select
                            value={selectedLevel}
                            onChange={e => setSelectedLevel(e.target.value)}
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
                    className="button dark"
                >
                    {translate('portal', 'area_add_dealer')}
                </Link>
            </div>

            <div className="ops-card adh-table">
                <Table
                    tools={false}
                    rows={filteredAssignments}
                    empty={translate('portal', 'area_empty')}
                    columns={[
                        {
                            key: 'zone_name',
                            title: translate('portal', 'area_col_zone'),
                            sortable: true,
                            render: (a: AreaAssignment) => (
                                <div>
                                    <div className="ops-bold ops-text">{a.zone_name}</div>
                                    {a.place_code?.code && (
                                        <div className="adh-code tone-teal">
                                            {translate('portal', 'area_code', { code: a.place_code.code })}
                                        </div>
                                    )}
                                    <div className="ops-meta">{a.place_code ? translate('portal', 'area_holds_code') : translate('portal', 'area_no_place')}</div>
                                </div>
                            ),
                        },
                        {
                            key: 'dealer',
                            title: translate('portal', 'area_col_dealer'),
                            sortable: true,
                            sortValue: (a: AreaAssignment) => a.user?.name || '',
                            render: (a: AreaAssignment) => a.user ? (
                                <div>
                                    <div className="hub-strong">{a.user.name}</div>
                                    <div className="ops-meta">
                                        {a.user.dealer_code} {a.user.phone ? `• ${a.user.phone}` : ''}
                                    </div>
                                </div>
                            ) : (
                                <span className="ops-faint">{translate('portal', 'area_unassigned')}</span>
                            ),
                        },
                        {
                            key: 'level',
                            title: translate('portal', 'area_col_tier'),
                            sortable: true,
                            render: (a: AreaAssignment) => {
                                const levelConfig = levelMeta[a.level] || levelMeta.district;
                                return a.place_code ? (
                                    <span className={`adh-chip ${levelConfig.tone}`}>
                                        {levelLabel(a.level)}
                                    </span>
                                ) : '—';
                            },
                        },
                        {
                            key: 'location',
                            title: translate('portal', 'area_col_location'),
                            sortable: true,
                            sortValue: (a: AreaAssignment) => [a.division, a.district, a.upazila].filter(Boolean).join(' '),
                            render: (a: AreaAssignment) => (
                                <div>
                                    <div>{a.division || '—'}</div>
                                    {a.district && <div className="ops-meta">{translate('portal', 'area_district', { name: a.district })}</div>}
                                    {a.upazila && <div className="ops-meta">{translate('portal', 'area_upazila', { name: a.upazila })}</div>}
                                </div>
                            ),
                        },
                        {
                            key: 'monthly_quota',
                            title: translate('portal', 'area_col_quota'),
                            align: 'right',
                            sortable: true,
                            sortValue: (a: AreaAssignment) => Number(a.monthly_quota) || 0,
                            render: (a: AreaAssignment) => (
                                <b className="adh-quota">
                                    {money(a.monthly_quota)}
                                </b>
                            ),
                        },
                        {
                            key: 'is_active',
                            title: translate('portal', 'area_col_status'),
                            sortable: true,
                            render: (a: AreaAssignment) => (
                                <span className={`adh-chip is-sm ${a.is_active ? 'tone-ok' : 'tone-danger'}`}>
                                    {a.place_code ? translate('portal', 'area_active') : translate('portal', 'area_no_place')}
                                </span>
                            ),
                        },
                    ]}
                />
            </div>
        </div>
    );
}

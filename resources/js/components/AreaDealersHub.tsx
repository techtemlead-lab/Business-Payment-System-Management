import React, { useState, useMemo } from 'react';
import { router, useForm } from '@inertiajs/react';
import { MapPin, Plus, Trash2, Search, Filter, ShieldCheck, CheckCircle2, AlertCircle, Building2, Globe2, Compass } from 'lucide-react';
import { money, date } from './UI';

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

const levelLabels: Record<string, { label: string; bg: string; text: string; defaultQuota: number }> = {
    division: { label: 'Divisional Dealer', bg: '#eff6ff', text: '#1d4ed8', defaultQuota: 50000 },
    district: { label: 'District Dealer', bg: '#ecfdf5', text: '#047857', defaultQuota: 40000 },
    upazila: { label: 'Upazila / Thana Dealer', bg: '#fdf4ff', text: '#a21caf', defaultQuota: 20000 },
    union: { label: 'Union Dealer', bg: '#fffbeb', text: '#b45309', defaultQuota: 10000 },
};

export default function AreaDealersHub({ assignments, divisions, dealers_list, place_codes = [] }: AreaDealersHubProps) {
    const [selectedDivision, setSelectedDivision] = useState<string>('all');
    const [selectedLevel, setSelectedLevel] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState('');
    const [isAssignModalOpen, setIsAssignModalOpen] = useState(false);

    // Form state for new territory assignment
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: dealers_list[0]?.id ? String(dealers_list[0].id) : '',
        level: 'district' as 'division' | 'district' | 'upazila' | 'union',
        place_code_id: '',
        zone_name: '',
        division: divisions[0] || 'Dhaka',
        district: '',
        upazila: '',
        union: '',
        monthly_quota: 40000,
    });

    const placesForLevel = useMemo(
        () => place_codes.filter((place) => place.level === data.level),
        [place_codes, data.level],
    );

    const handleLevelChange = (level: 'division' | 'district' | 'upazila' | 'union') => {
        const first = place_codes.find((place) => place.level === level);
        setData(prev => ({
            ...prev,
            level,
            place_code_id: first ? String(first.id) : '',
            monthly_quota: levelLabels[level]?.defaultQuota || 20000
        }));
    };

    const handleCreateAssignment = (e: React.FormEvent) => {
        e.preventDefault();
        post('/commands/area-dealers', {
            preserveScroll: true,
            onSuccess: () => {
                setIsAssignModalOpen(false);
                reset();
            }
        });
    };

    const handleDeleteAssignment = (id: number, zone: string) => {
        if (window.confirm(`Are you sure you want to remove area assignment for "${zone}"?`)) {
            router.delete(`/commands/area-dealers/${id}`, { preserveScroll: true });
        }
    };

    // Filter assignments
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

    // Division breakdown stats
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

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top Stat Summary Grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#64748b', fontSize: 12, fontWeight: 600 }}>
                        <MapPin size={16} /> Total Territory Assignments
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>
                        {assignments.length}
                    </div>
                    <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>Across 8 administrative divisions</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #a7f3d0', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#047857', fontSize: 12, fontWeight: 600 }}>
                        <Building2 size={16} /> Total Monthly Quota
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#006837', marginTop: 4 }}>
                        {money(totalActiveQuota)}
                    </div>
                    <div style={{ fontSize: 11, color: '#059669', marginTop: 2 }}>Combined target territory volume</div>
                </div>

                <div style={{ background: '#ffffff', border: '1px solid #bfdbfe', borderRadius: 10, padding: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#1d4ed8', fontSize: 12, fontWeight: 600 }}>
                        <Compass size={16} /> Area Dealer Pool Slice
                    </div>
                    <div style={{ fontSize: 24, fontWeight: 800, color: '#1d4ed8', marginTop: 4 }}>
                        4.00%
                    </div>
                    <div style={{ fontSize: 11, color: '#3b82f6', marginTop: 2 }}>Zero-deficit cash inflow allocation</div>
                </div>
            </div>

            {/* Division Distribution Pills */}
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
                    Regional Territory Distribution
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
                        All Divisions ({assignments.length})
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

            {/* Filter Bar & Assign Button */}
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
                            placeholder="Search zone, dealer name, code…"
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
                            <option value="all">All Tiers</option>
                            <option value="division">Divisional (৳50k)</option>
                            <option value="district">District (৳40k)</option>
                            <option value="upazila">Upazila / Thana (৳20k)</option>
                            <option value="union">Union (৳10k)</option>
                        </select>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={() => setIsAssignModalOpen(true)}
                    style={{
                        padding: '8px 16px',
                        background: '#006837',
                        color: '#ffffff',
                        border: 'none',
                        borderRadius: 6,
                        fontWeight: 700,
                        fontSize: 13,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 6
                    }}
                >
                    <Plus size={16} /> Assign Area Dealer
                </button>
            </div>

            {/* Assignments Table */}
            <div style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 12, overflow: 'hidden' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#475569' }}>
                            <th style={{ padding: '12px 16px' }}>Zone / Territory</th>
                            <th style={{ padding: '12px 16px' }}>Assigned Dealer</th>
                            <th style={{ padding: '12px 16px' }}>Tier / Level</th>
                            <th style={{ padding: '12px 16px' }}>Division & District</th>
                            <th style={{ padding: '12px 16px' }}>Monthly Quota</th>
                            <th style={{ padding: '12px 16px' }}>Status</th>
                            <th style={{ padding: '12px 16px', textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredAssignments.length > 0 ? (
                            filteredAssignments.map(a => {
                                const levelConfig = levelLabels[a.level] || levelLabels.district;
                                return (
                                    <tr key={a.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: 700, color: '#0f172a' }}>{a.zone_name}</div>
                                            {a.place_code?.code && (
                                                <div style={{ fontSize: 11, color: '#0f766e', fontWeight: 700, marginTop: 2 }}>
                                                    Code {a.place_code.code}
                                                </div>
                                            )}
                                            <div style={{ fontSize: 11, color: '#64748b' }}>Assigned: {date(a.created_at)}</div>
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
                                                <span style={{ color: '#94a3b8' }}>Unassigned User</span>
                                            )}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <span style={{
                                                fontSize: 11,
                                                fontWeight: 700,
                                                padding: '2px 8px',
                                                borderRadius: 4,
                                                background: levelConfig.bg,
                                                color: levelConfig.text
                                            }}>
                                                {levelConfig.label}
                                            </span>
                                        </td>
                                        <td style={{ padding: '12px 16px', color: '#475569' }}>
                                            <div>{a.division || '—'}</div>
                                            {a.district && <div style={{ fontSize: 11, color: '#64748b' }}>District: {a.district}</div>}
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
                                                {a.is_active ? 'ACTIVE' : 'INACTIVE'}
                                            </span>
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'right' }}>
                                            <button
                                                type="button"
                                                onClick={() => handleDeleteAssignment(a.id, a.zone_name)}
                                                style={{
                                                    background: 'transparent',
                                                    border: 'none',
                                                    color: '#ef4444',
                                                    cursor: 'pointer',
                                                    padding: 6,
                                                    borderRadius: 4
                                                }}
                                                title="Remove Assignment"
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })
                        ) : (
                            <tr>
                                <td colSpan={7} style={{ textAlign: 'center', padding: '36px 0', color: '#94a3b8' }}>
                                    No territory assignments matching your filters.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Modal for Assigning Area Dealer */}
            {isAssignModalOpen && (
                <div style={{
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    background: 'rgba(15, 23, 42, 0.6)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 9999,
                    padding: 16
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: 12,
                        maxWidth: 500,
                        width: '100%',
                        padding: 24,
                        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1)'
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                            <h3 style={{ fontSize: 17, fontWeight: 700, margin: 0, color: '#0f172a' }}>
                                Assign Area / Territory Dealer
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsAssignModalOpen(false)}
                                style={{ background: 'none', border: 'none', fontSize: 18, color: '#94a3b8', cursor: 'pointer' }}
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleCreateAssignment} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 4 }}>
                                    Select Active Dealer
                                </label>
                                <select
                                    value={data.user_id}
                                    onChange={e => setData('user_id', e.target.value)}
                                    style={{ width: '100%', padding: '8px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 13 }}
                                    required
                                >
                                    {dealers_list.map(d => (
                                        <option key={d.id} value={d.id}>
                                            {d.name} ({d.dealer_code}) - {d.district || d.division || 'Unspecified'}
                                        </option>
                                    ))}
                                </select>
                                {errors.user_id && <div style={{ fontSize: 11, color: '#dc2626', marginTop: 2 }}>{errors.user_id}</div>}
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 4 }}>
                                    Territory Tier Level
                                </label>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 }}>
                                    {(['division', 'district', 'upazila', 'union'] as const).map(lvl => (
                                        <button
                                            key={lvl}
                                            type="button"
                                            onClick={() => handleLevelChange(lvl)}
                                            style={{
                                                padding: '8px 10px',
                                                borderRadius: 6,
                                                border: `1.5px solid ${data.level === lvl ? '#006837' : '#cbd5e1'}`,
                                                background: data.level === lvl ? '#ecfdf5' : '#ffffff',
                                                color: data.level === lvl ? '#006837' : '#475569',
                                                fontSize: 12,
                                                fontWeight: 700,
                                                cursor: 'pointer',
                                                textAlign: 'left'
                                            }}
                                        >
                                            <div>{levelLabels[lvl].label}</div>
                                            <div style={{ fontSize: 10, color: '#64748b' }}>Quota: {money(levelLabels[lvl].defaultQuota)}</div>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 4 }}>
                                    Place code
                                </label>
                                <select
                                    value={data.place_code_id}
                                    onChange={e => {
                                        const selected = placesForLevel.find((place) => String(place.id) === e.target.value);
                                        setData(prev => ({
                                            ...prev,
                                            place_code_id: e.target.value,
                                            zone_name: selected ? `${selected.name} (${selected.code})` : prev.zone_name,
                                        }));
                                    }}
                                    style={{ width: '100%', padding: '8px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 13 }}
                                    required={placesForLevel.length > 0}
                                >
                                    <option value="">Select place code…</option>
                                    {placesForLevel.map((place) => (
                                        <option key={place.id} value={place.id}>
                                            {place.code} — {place.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.place_code_id && <div style={{ fontSize: 11, color: '#dc2626', marginTop: 2 }}>{errors.place_code_id}</div>}
                                {placesForLevel.length === 0 && (
                                    <div style={{ fontSize: 11, color: '#b45309', marginTop: 4 }}>
                                        No {data.level} codes yet. Add them under Code management first.
                                    </div>
                                )}
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 4 }}>
                                    Zone label (optional)
                                </label>
                                <input
                                    type="text"
                                    placeholder="Defaults to place name and code"
                                    value={data.zone_name}
                                    onChange={e => setData('zone_name', e.target.value)}
                                    style={{ width: '100%', padding: '8px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 13 }}
                                />
                                {errors.zone_name && <div style={{ fontSize: 11, color: '#dc2626', marginTop: 2 }}>{errors.zone_name}</div>}
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 4 }}>
                                    Monthly Sales Quota (BDT)
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    step="1000"
                                    value={data.monthly_quota}
                                    onChange={e => setData('monthly_quota', Number(e.target.value))}
                                    style={{ width: '100%', padding: '8px 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 13 }}
                                    required
                                />
                            </div>

                            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 10 }}>
                                <button
                                    type="button"
                                    onClick={() => setIsAssignModalOpen(false)}
                                    style={{
                                        padding: '8px 14px',
                                        background: '#f1f5f9',
                                        border: '1px solid #cbd5e1',
                                        borderRadius: 6,
                                        fontSize: 13,
                                        fontWeight: 600,
                                        cursor: 'pointer'
                                    }}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    style={{
                                        padding: '8px 18px',
                                        background: '#006837',
                                        color: '#ffffff',
                                        border: 'none',
                                        borderRadius: 6,
                                        fontSize: 13,
                                        fontWeight: 700,
                                        cursor: 'pointer'
                                    }}
                                >
                                    {processing ? 'Saving…' : 'Save Territory Assignment'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

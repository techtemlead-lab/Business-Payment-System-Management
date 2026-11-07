import React, { useState, useMemo } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ShieldCheck, Eye } from 'lucide-react';
import { money, number, Table, Pagination, type Row } from './UI';
import ServerListSearch from './ServerListSearch';
import { useT } from '../shared/i18n/t';
import { useEscape } from '../shared/useEscape';

/** A positive policy amount, or null when the policy does not set one. */
function policyAmount(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

interface LegBreakdown {
    leg_user_id: number;
    leg_user_name?: string;
    raw_volume: number;
    capped_volume: number;
    excess_volume: number;
}

interface MilestoneRecord {
    id: number;
    user_id: number;
    milestone_type: 'bike' | 'car';
    qualifying_volume: number | string;
    target_volume: number | string;
    capped_leg_breakdown: LegBreakdown[] | string;
    achieved_at: string | null;
    is_claimed: boolean;
    reward_description: string | null;
    user?: {
        id: number;
        name: string;
        dealer_code: string;
        phone: string;
        district: string;
    };
}

interface CarBikeRewardsHubProps {
    /** One server page (25 rows) of the tab on screen. */
    milestones?: (Row & { data: MilestoneRecord[]; total: number }) | null;
    milestone_type: 'bike' | 'car';
    /** How many leaders are on each tab, over all pages. */
    milestone_counts: Partial<Record<'bike' | 'car', number>>;
    query: string;
    rule_params: any;
}

export default function CarBikeRewardsHub({ milestones, milestone_type, milestone_counts, query, rule_params }: CarBikeRewardsHubProps) {
    const translate = useT();
    const { url } = usePage();
    const activeTab = milestone_type;
    // Switching tabs asks the server for that tab's first page, keeping the search words.
    const setActiveTab = (type: 'bike' | 'car') => {
        if (type === activeTab) return;
        const path = new URL(url || '/', 'http://list.local').pathname;
        router.get(path, { type, ...(query ? { q: query } : {}) }, { preserveState: true, preserveScroll: true, replace: true, only: ['data', 'filters'] });
    };
    const [selectedCandidate, setSelectedCandidate] = useState<MilestoneRecord | null>(null);
    useEscape(selectedCandidate !== null, () => setSelectedCandidate(null));

    const notSet = translate('portal', 'policy_value_not_set');
    const moneyOrNotSet = (amount: number | null) => amount === null ? notSet : money(amount);
    const bikeTarget = policyAmount(rule_params?.bike_target);
    const bikeCap = policyAmount(rule_params?.bike_leg_cap);
    const carTarget = policyAmount(rule_params?.car_target);
    const carCap = policyAmount(rule_params?.car_leg_cap);

    const currentMilestones: MilestoneRecord[] = milestones?.data ?? [];
    const currentTotal = Number(milestone_counts?.[activeTab] ?? milestones?.total ?? 0);
    const currentTarget = activeTab === 'bike' ? bikeTarget : carTarget;
    const currentCap = activeTab === 'bike' ? bikeCap : carCap;
    const progressPct = (m: MilestoneRecord) => currentTarget === null
        ? null
        : Math.min(100, ((Number(m.qualifying_volume) || 0) / currentTarget) * 100);
    const reachedTarget = (m: MilestoneRecord) => !!m.achieved_at
        || (currentTarget !== null && (Number(m.qualifying_volume) || 0) >= currentTarget);
    const rewardTitle = activeTab === 'bike' ? translate('portal', 'rw_title_bike') : translate('portal', 'rw_title_car');
    const rewardPrize = activeTab === 'bike' ? translate('portal', 'rw_prize_bike') : translate('portal', 'rw_prize_car');

    // Parse legs for selected candidate
    const legs: LegBreakdown[] = useMemo(() => {
        if (!selectedCandidate) return [];
        if (typeof selectedCandidate.capped_leg_breakdown === 'string') {
            try {
                return JSON.parse(selectedCandidate.capped_leg_breakdown);
            } catch {
                return [];
            }
        }
        return selectedCandidate.capped_leg_breakdown || [];
    }, [selectedCandidate]);

    return (
        <div className="hub">
            {/* Top Anti-Whale Architecture Banner */}
            <div className="panel rw-banner">
                <div className="hub-row is-between is-top">
                    <div>
                        <div className="hub-flag tone-brand">
                            <ShieldCheck size={18} /> {translate('portal', 'rw_badge')}
                        </div>
                        <h2 className="hub-title is-lg">
                            {translate('portal', 'rw_heading')}
                        </h2>
                        <p className="hub-sub rw-intro">
                            {translate('portal', 'rw_intro_live', { bike_cap: moneyOrNotSet(bikeCap), car_cap: moneyOrNotSet(carCap) })}
                        </p>
                    </div>

                    {/* Mode Toggle Switch */}
                    <div className="hub-seg">
                        <button
                            type="button"
                            onClick={() => { setActiveTab('bike'); setSelectedCandidate(null); }}
                            className={activeTab === 'bike' ? 'is-active' : undefined}
                        >
                            {translate('portal', 'rw_title_bike')}
                        </button>
                        <button
                            type="button"
                            onClick={() => { setActiveTab('car'); setSelectedCandidate(null); }}
                            className={activeTab === 'car' ? 'is-active' : undefined}
                        >
                            {translate('portal', 'rw_title_car')}
                        </button>
                    </div>
                </div>

                {/* Fund Rules Snapshot */}
                <div className="hub-grid rw-rules">
                    <div className="hub-well">
                        <div className="hub-label">{translate('portal', 'rw_kpi_target')}</div>
                        <div className={`hub-value${currentTarget === null ? ' tone-warn' : ''}`}>{moneyOrNotSet(currentTarget)}</div>
                        <div className="hub-hint">{translate('portal', 'rw_kpi_target_hint')}</div>
                    </div>

                    <div className="hub-well tone-brand">
                        <div className="hub-label">{translate('portal', 'rw_kpi_cap')}</div>
                        <div className={`hub-value${currentCap === null ? ' tone-warn' : ''}`}>{moneyOrNotSet(currentCap)}</div>
                        <div className="hub-hint">{translate('portal', 'rw_kpi_cap_hint')}</div>
                    </div>

                    <div className="hub-well tone-info">
                        <div className="hub-label">{translate('portal', 'rw_kpi_legs')}</div>
                        <div className="hub-value">
                            {currentTarget === null || currentCap === null
                                ? notSet
                                : translate('portal', 'rw_kpi_legs_value', { count: String(Math.ceil(currentTarget / currentCap)) })}
                        </div>
                        <div className="hub-hint">{translate('portal', 'rw_kpi_legs_hint')}</div>
                    </div>

                    <div className="hub-well is-tinted tone-ok">
                        <div className="hub-label">{translate('portal', 'rw_kpi_reward')}</div>
                        <div className="hub-value is-sm tone-brand">{rewardPrize}</div>
                    </div>
                </div>
            </div>

            {/* Candidate Progress Table */}
            <div className="panel table-panel">
                <div className="panel-heading">
                    <div>
                        <h3 className="hub-title">
                            {translate('portal', 'rw_leaderboard', { title: rewardTitle })}
                        </h3>
                        <p className="hub-sub">
                            {translate('portal', 'rw_leaderboard_hint')}
                        </p>
                    </div>
                    <span className="hub-label tone-brand">
                        {translate('portal', 'rw_registered', { count: number(currentTotal) })}
                    </span>
                </div>

                <Table
                    columns={[
                        {
                            key: 'dealer',
                            header: translate('portal', 'rw_col_dealer'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => m.user?.name || '',
                            render: (m: MilestoneRecord) => (
                                <div>
                                    <div className="hub-strong">{m.user?.name || translate('portal', 'rw_partner')}</div>
                                    <div className="cell-sub">
                                        {m.user?.phone || translate('portal', 'rw_no_phone')} {m.user?.district ? `• ${m.user.district}` : ''}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'dealer_code',
                            header: translate('portal', 'rw_col_code'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => m.user?.dealer_code || '',
                            render: (m: MilestoneRecord) => <span className="hub-strong">{m.user?.dealer_code || '—'}</span>,
                        },
                        {
                            key: 'qualifying_volume',
                            header: translate('portal', 'rw_col_qual'),
                            sortable: true,
                            align: 'right',
                            sortValue: (m: MilestoneRecord) => Number(m.qualifying_volume) || 0,
                            render: (m: MilestoneRecord) => (
                                <span className="hub-strong tone-brand">
                                    {money(Number(m.qualifying_volume) || 0)}
                                </span>
                            ),
                        },
                        {
                            key: 'progress',
                            header: translate('portal', 'rw_col_progress'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => progressPct(m) ?? -1,
                            render: (m: MilestoneRecord) => {
                                const progress = progressPct(m);
                                if (progress === null) {
                                    return <span className="hub-label tone-warn">{notSet}</span>;
                                }
                                const pct = progress;
                                const isAchieved = reachedTarget(m);
                                return (
                                    <div className="rw-progress">
                                        <div className="hub-progress">
                                            <div
                                                className={isAchieved ? 'tone-ok' : undefined}
                                                style={{ width: `${pct}%` }}
                                            />
                                        </div>
                                        <span className={`rw-pct${isAchieved ? ' tone-ok' : ''}`}>
                                            {pct.toFixed(1)}%
                                        </span>
                                    </div>
                                );
                            },
                        },
                        {
                            key: 'target_volume',
                            header: translate('portal', 'rw_col_target'),
                            sortable: true,
                            align: 'right',
                            sortValue: () => currentTarget ?? -1,
                            render: () => <span className="muted">{moneyOrNotSet(currentTarget)}</span>,
                        },
                        {
                            key: 'status',
                            header: translate('portal', 'rw_col_status'),
                            sortable: true,
                            sortValue: (m: MilestoneRecord) => m.is_claimed ? 'claimed' : reachedTarget(m) ? 'achieved' : 'in_progress',
                            render: (m: MilestoneRecord) => {
                                const isAchieved = reachedTarget(m);
                                return (
                                    <span className={`badge ${m.is_claimed ? 'tone-ok' : isAchieved ? 'tone-info' : 'tone-neutral'}`}>
                                        {m.is_claimed ? translate('portal', 'rw_claimed') : isAchieved ? translate('portal', 'rw_achieved') : translate('portal', 'rw_in_progress')}
                                    </span>
                                );
                            },
                        },
                        {
                            key: 'actions',
                            header: translate('portal', 'rw_col_branches'),
                            align: 'right',
                            render: (m: MilestoneRecord) => {
                                const isAchieved = reachedTarget(m);
                                return (
                                    <div className="hub-row is-end">
                                        {isAchieved && !m.is_claimed && (
                                            <Link
                                                href="/operations/reserve-payouts"
                                                className="button small"
                                            >
                                                {translate('portal', 'rw_record_payout')}
                                            </Link>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => setSelectedCandidate(m)}
                                            className="button small"
                                        >
                                            <Eye size={12} /> {translate('portal', 'rw_inspect')}
                                        </button>
                                    </div>
                                );
                            },
                        },
                    ]}
                    data={currentMilestones}
                    searchable={false}
                    totalCount={milestones?.total}
                    extraTools={<ServerListSearch query={query} placeholder={translate('portal', 'search')} keep={{ type: activeTab }} />}
                    emptyMessage={translate('portal', 'rw_empty')}
                />
                <Pagination page={milestones ?? undefined} />
            </div>

            {/* Anti-Whale Branch Inspection Modal */}
            {selectedCandidate && (
                <div className="hub-overlay">
                    <div className="hub-dialog is-wide" role="dialog" aria-modal="true">
                        <div className="hub-dialog-head">
                            <div>
                                <div className="hub-label tone-ok">
                                    {translate('portal', 'rw_audit_badge')}
                                </div>
                                <h3 className="hub-title is-lg">
                                    {selectedCandidate.user?.name} ({selectedCandidate.user?.dealer_code})
                                </h3>
                                <div className="hub-hint">
                                    {selectedCandidate.milestone_type === 'bike' ? translate('portal', 'rw_title_bike') : translate('portal', 'rw_title_car')}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedCandidate(null)}
                                className="icon-button"
                            >
                                ✕
                            </button>
                        </div>

                        <div className="hub-well hub-row is-between rw-summary">
                            <div>
                                <span className="muted">{translate('portal', 'rw_qual_vol')} </span>
                                <b className="tone-brand">{money(selectedCandidate.qualifying_volume)}</b>
                            </div>
                            <div>
                                <span className="muted">{translate('portal', 'rw_branch_cap')} </span>
                                <b>{moneyOrNotSet(currentCap)}</b>
                            </div>
                            <div>
                                <span className="muted">{translate('portal', 'rw_target')} </span>
                                <b>{moneyOrNotSet(currentTarget)}</b>
                            </div>
                        </div>

                        {/* Frontline Legs List */}
                        <div className="rw-legs">
                            <div className="hub-label">
                                {translate('portal', 'rw_legs_heading', { count: String(legs.length) })}
                            </div>

                            {legs.length > 0 ? (
                                legs.map((leg, idx) => {
                                    const raw = Number(leg.raw_volume) || 0;
                                    const capped = Number(leg.capped_volume) || 0;
                                    const excess = Number(leg.excess_volume) || 0;
                                    const isCapped = excess > 0;
                                    const capPct = currentCap === null ? 0 : Math.min(100, (capped / currentCap) * 100);

                                    return (
                                        <div key={idx} className={`hub-tile rw-leg${isCapped ? ' is-tinted tone-warn' : ''}`}>
                                            <div className="hub-row is-between">
                                                <div className="hub-strong">
                                                    {translate('portal', 'rw_branch_n', { n: String(idx + 1), name: leg.leg_user_name || translate('portal', 'rw_partner_id', { id: String(leg.leg_user_id) }) })}
                                                </div>
                                                <div>
                                                    <span className="hub-strong tone-brand">
                                                        {money(capped)}
                                                    </span>
                                                    <span className="muted"> {translate('portal', 'rw_qualifying')}</span>
                                                </div>
                                            </div>

                                            {/* Stacked Progress Bar */}
                                            <div className="hub-progress is-lg">
                                                <div style={{ width: `${capPct}%` }} />
                                                {isCapped && (
                                                    <div
                                                        className="tone-warn is-faded"
                                                        style={{ width: `${Math.min(100 - capPct, 25)}%` }}
                                                    />
                                                )}
                                            </div>

                                            <div className="hub-row is-between rw-leg-foot">
                                                <span>{translate('portal', 'rw_raw_leg')} <b>{money(raw)}</b></span>
                                                {isCapped ? (
                                                    <span className="hub-strong tone-warn">
                                                        {translate('portal', 'rw_truncated', { excess: money(excess), cap: moneyOrNotSet(currentCap) })}
                                                    </span>
                                                ) : (
                                                    <span className="tone-ok">{translate('portal', 'rw_full_qualifies')}</span>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })
                            ) : (
                                <div className="hub-empty is-plain">
                                    {translate('portal', 'rw_no_legs')}
                                </div>
                            )}
                        </div>

                        <div className="hub-row is-end hub-dialog-foot">
                            <button
                                type="button"
                                onClick={() => setSelectedCandidate(null)}
                                className="button small dark"
                            >
                                {translate('portal', 'rw_close')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

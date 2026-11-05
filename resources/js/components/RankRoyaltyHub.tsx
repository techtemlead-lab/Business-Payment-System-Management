import React, { useState } from 'react';
import { Eye } from 'lucide-react';
import { money, date, Table } from './UI';
import { useT } from '../shared/i18n/t';

/** A live policy number, or null when the policy does not set it. */
function policyNumber(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

interface RankHistoryEntry {
    rank: string;
    achieved_at: string;
}

interface RankRecord {
    id: number;
    user_id: number;
    name: string;
    dealer_code: string;
    phone: string;
    district: string;
    division: string;
    current_rank: 'dealer' | 'mo' | 'am' | 'sm' | 'me';
    achieved_at: string | null;
    lifetime_purchase_total: number;
    monthly_purchase_total: number;
    one_time_incentive_paid: boolean;
    qualification_history?: RankHistoryEntry[];
}

interface RankStats {
    mo_count: number;
    am_count: number;
    sm_count: number;
    me_count: number;
    dealer_count: number;
}

interface RankRoyaltyHubProps {
    ranks: RankRecord[];
    stats: RankStats;
    policy?: {qualification: Record<string,number|string>; incentives: Record<string,number|string>; pool_pct?: number|string|null};
}

/** Design-system tone class for each rank's tag, tier card and roster pill. */
const rankBadgeStyles: Record<string, { tone: string }> = {
    dealer: { tone: 'tone-neutral' },
    mo: { tone: 'tone-ok' },
    am: { tone: 'tone-info' },
    sm: { tone: 'tone-violet' },
    me: { tone: 'tone-warn' },
};



export default function RankRoyaltyHub({ ranks, stats, policy }: RankRoyaltyHubProps) {
    const translate = useT();
    const q=policy?.qualification??{};
    const bonuses=policy?.incentives??{};
    const notSet = translate('portal','policy_value_not_set');
    const moTarget = policyNumber(q.mo_target);
    const poolPct = policyNumber(policy?.pool_pct);
    const moneyOrNotSet = (value: unknown) => {
        const amount = policyNumber(value);
        return amount === null ? notSet : money(amount);
    };
    const textOrNotSet = (value: unknown) => {
        const amount = policyNumber(value);
        return amount === null ? notSet : String(amount);
    };
    const incentiveText = (value: unknown) => policyNumber(value) === null
        ? notSet
        : translate('portal','rank_incentive_dyn',{amount:moneyOrNotSet(value)});
    const royaltyText = (value: unknown) => policyNumber(value) === null
        ? notSet
        : translate('portal','rank_royalty_dyn',{pct:textOrNotSet(value)});
    const leadersRequirement = (count: unknown, rankKey: string) => policyNumber(count) === null || policyNumber(q.qualification_depth) === null
        ? notSet
        : translate('portal','rank_req_leaders_dyn',{count:textOrNotSet(count),rank:translate('portal',rankKey),depth:textOrNotSet(q.qualification_depth)});
    const rankHierarchy = [
        {
            key: 'dealer',
            name: translate('portal','acct_role_dealer'),
            badge: { label: translate('portal','rank_badge_dealer'), ...rankBadgeStyles.dealer },
            requirement: translate('portal','rank_req_dealer'),
            incentive: translate('portal','rank_incentive_dealer'),
            royaltyShare: '—',
        },
        {
            key: 'mo',
            name: translate('portal','net_rank_mo'),
            badge: { label: translate('portal','rank_badge_mo'), ...rankBadgeStyles.mo },
            requirement: moTarget === null ? notSet : translate('portal','rank_req_mo_dyn',{amount:money(moTarget)}),
            incentive: incentiveText(bonuses.mo_bonus),
            royaltyShare: royaltyText(q.mo_share_pct),
        },
        {
            key: 'am',
            name: translate('portal','net_rank_am'),
            badge: { label: translate('portal','rank_badge_am'), ...rankBadgeStyles.am },
            requirement: leadersRequirement(q.am_count,'rank_short_mo'),
            incentive: incentiveText(bonuses.am_bonus),
            royaltyShare: royaltyText(q.am_share_pct),
        },
        {
            key: 'sm',
            name: translate('portal','net_rank_sm'),
            badge: { label: translate('portal','rank_badge_sm'), ...rankBadgeStyles.sm },
            requirement: leadersRequirement(q.sm_count,'rank_short_am'),
            incentive: incentiveText(bonuses.sm_bonus),
            royaltyShare: royaltyText(q.sm_share_pct),
        },
        {
            key: 'me',
            name: translate('portal','net_rank_me'),
            badge: { label: translate('portal','rank_badge_me'), ...rankBadgeStyles.me },
            requirement: leadersRequirement(q.me_count,'rank_short_sm'),
            incentive: incentiveText(bonuses.me_bonus),
            royaltyShare: royaltyText(q.me_share_pct),
        },
    ];
    const [auditingPartner, setAuditingPartner] = useState<RankRecord | null>(null);

    const personalHistory = ranks.length === 1
        ? (ranks[0].qualification_history?.length
            ? ranks[0].qualification_history
            : (ranks[0].achieved_at && ranks[0].current_rank !== 'dealer'
                ? [{ rank: ranks[0].current_rank, achieved_at: ranks[0].achieved_at }]
                : []))
        : [];

    return (
        <div className="hub">
            {personalHistory.length > 0 && (
                <section className="panel">
                    <div className="panel-heading">
                        <div>
                            <h2>{translate('portal','rank_history_title')}</h2>
                            <p className="muted">{translate('portal','rank_history_hint')}</p>
                        </div>
                        <span className="badge">{String(ranks[0].current_rank).toUpperCase()}</span>
                    </div>
                    <ol className="rk-history">
                        {personalHistory.map((entry, index) => (
                            <li key={`${entry.rank}-${entry.achieved_at}-${index}`}>
                                <b className="hub-upper">{entry.rank}</b>
                                <span className="muted"> · {date(entry.achieved_at)}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            )}
            {/* Top Stat Summary Grid */}
            <div className="hub-grid is-narrow">
                <div className="metric">
                    <span>{translate('portal','rank_stat_dealers')}</span>
                    <strong>
                        {stats.dealer_count || ranks.length}
                    </strong>
                    <small>{translate('portal','rank_stat_dealers_hint')}</small>
                </div>

                <div className="metric tone-ok">
                    <span>{translate('portal','rank_stat_mo')}</span>
                    <strong>
                        {stats.mo_count}
                    </strong>
                    <small>{royaltyText(q.mo_share_pct)}</small>
                </div>

                <div className="metric tone-info">
                    <span>{translate('portal','rank_stat_am')}</span>
                    <strong>
                        {stats.am_count}
                    </strong>
                    <small>{royaltyText(q.am_share_pct)}</small>
                </div>

                <div className="metric tone-violet">
                    <span>{translate('portal','rank_stat_sm')}</span>
                    <strong>
                        {stats.sm_count}
                    </strong>
                    <small>{royaltyText(q.sm_share_pct)}</small>
                </div>

                <div className="metric tone-warn">
                    <span>{translate('portal','rank_stat_me')}</span>
                    <strong>
                        {stats.me_count}
                    </strong>
                    <small>{royaltyText(q.me_share_pct)}</small>
                </div>
            </div>

            {/* Rank Ladder Progression Visualizer */}
            <div className="panel">
                <div className="panel-heading">
                    <div>
                        <h3 className="hub-title">
                            {translate('portal','rank_framework_title')}
                        </h3>
                        <p className="hub-sub">
                            {translate('portal','rank_framework_hint')}
                        </p>
                    </div>
                    <div className="hub-pill tone-ok">
                        {poolPct === null ? notSet : translate('portal','rank_combined_pool_live',{pct:poolPct.toFixed(2)})}
                    </div>
                </div>

                <div className="hub-grid">
                    {rankHierarchy.map((tier, idx) => (
                        <div
                            key={tier.key}
                            className={`hub-tile hub-col is-soft is-accent rk-tier ${tier.badge.tone}`}
                        >
                            <div>
                                <div className="hub-row is-between">
                                    <span className="rk-level">{translate('portal','rank_level',{n:String(idx+1)})}</span>
                                    <span className="hub-pill">
                                        {tier.badge.label}
                                    </span>
                                </div>
                                <div className="hub-title is-sm">
                                    {tier.name}
                                </div>
                                <div className="rk-req">
                                    <b>{translate('portal','rank_requirement')}:</b> {tier.requirement}
                                </div>
                            </div>

                            <div className="rk-tier-foot">
                                <div className="tone-brand">
                                    🎁 {tier.incentive}
                                </div>
                                <div className="tone-info">
                                    💎 {translate('portal','rank_royalty_label')}: {tier.royaltyShare}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Roster Table */}
            <div className="panel table-panel">
                <Table
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'col_partner'),
                            sortable: true,
                            sortValue: (r: RankRecord) => r.name || '',
                            render: (r: RankRecord) => (
                                <div>
                                    <div className="hub-strong">{r.name || translate('portal', 'rank_unnamed')}</div>
                                    <div className="cell-sub">
                                        {r.phone || translate('portal', 'net_no_phone')} {r.district ? `• ${r.district}` : ''}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'dealer_code',
                            title: translate('portal', 'net_code'),
                            sortable: true,
                            sortValue: (r: RankRecord) => r.dealer_code || '',
                            render: (r: RankRecord) => <span className="hub-strong">{r.dealer_code || '—'}</span>,
                        },
                        {
                            key: 'current_rank',
                            title: translate('portal', 'rank_col_current'),
                            sortable: true,
                            sortValue: (r: RankRecord) => r.current_rank,
                            render: (r: RankRecord) => {
                                const rankCfg = rankHierarchy.find(x => x.key === r.current_rank) || rankHierarchy[0];
                                return (
                                    <span className={`hub-pill ${rankCfg.badge.tone}`}>
                                        {rankCfg.badge.label}
                                    </span>
                                );
                            },
                        },
                        {
                            key: 'lifetime_purchase_total',
                            title: translate('portal', 'net_lifetime_purchases'),
                            sortable: true,
                            align: 'right',
                            sortValue: (r: RankRecord) => Number(r.lifetime_purchase_total) || 0,
                            render: (r: RankRecord) => (
                                <span className="hub-strong tone-brand">
                                    {money(r.lifetime_purchase_total)}
                                </span>
                            ),
                        },
                        {
                            key: 'monthly_purchase_total',
                            title: translate('portal', 'rank_col_monthly'),
                            sortable: true,
                            align: 'right',
                            sortValue: (r: RankRecord) => Number(r.monthly_purchase_total) || 0,
                            render: (r: RankRecord) => (
                                <span>
                                    {money(r.monthly_purchase_total)}
                                </span>
                            ),
                        },
                        {
                            key: 'achieved_at',
                            title: translate('portal', 'rank_col_achieved'),
                            sortable: true,
                            sortValue: (r: RankRecord) => r.achieved_at || '',
                            render: (r: RankRecord) => (
                                <span className="muted">
                                    {r.achieved_at ? date(r.achieved_at) : '—'}
                                </span>
                            ),
                        },
                        {
                            key: 'one_time_incentive_paid',
                            title: translate('portal', 'rank_col_incentive'),
                            sortable: true,
                            sortValue: (r: RankRecord) => r.one_time_incentive_paid ? 1 : 0,
                            render: (r: RankRecord) => {
                                if (r.current_rank === 'dealer') {
                                    return <span className="muted">—</span>;
                                }
                                return (
                                    <span className={`badge ${r.one_time_incentive_paid ? 'tone-ok' : 'tone-warn'}`}>
                                        {r.one_time_incentive_paid ? translate('portal', 'rank_paid') : translate('portal', 'rank_pending')}
                                    </span>
                                );
                            },
                        },
                        {
                            key: 'actions',
                            title: translate('portal', 'rank_col_audit'),
                            align: 'right',
                            render: (r: RankRecord) => (
                                <button
                                    type="button"
                                    onClick={() => setAuditingPartner(r)}
                                    className="button small"
                                >
                                    <Eye size={12} /> {translate('portal', 'rank_inspect')}
                                </button>
                            ),
                        },
                    ]}
                    data={ranks}
                    searchPlaceholder={translate('portal', 'rank_search_placeholder')}
                    filters={[
                        {
                            key: 'current_rank',
                            label: translate('portal', 'rank_col_current'),
                            options: [
                                { value: 'mo', label: translate('portal', 'net_rank_mo') },
                                { value: 'am', label: translate('portal', 'net_rank_am') },
                                { value: 'sm', label: translate('portal', 'net_rank_sm') },
                                { value: 'me', label: translate('portal', 'net_rank_me') },
                                { value: 'dealer', label: translate('portal', 'acct_role_dealer') },
                            ],
                        },
                    ]}
                    pageSize={15}
                    emptyMessage={translate('portal', 'rank_empty')}
                />
            </div>

            {/* Audit Modal for Selected Partner */}
            {auditingPartner && (
                <div className="hub-overlay">
                    <div className="hub-dialog">
                        <div className="hub-dialog-head">
                            <div>
                                <span className={`hub-pill ${(rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).badge.tone}`}>
                                    {(rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).badge.label}
                                </span>
                                <h3 className="hub-title is-lg">
                                    {auditingPartner.name}
                                </h3>
                                <div className="hub-hint">
                                    {translate('portal','net_code')}: <b>{auditingPartner.dealer_code}</b> • {translate('portal','acct_phone')}: {auditingPartner.phone || '—'}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setAuditingPartner(null)}
                                className="icon-button"
                            >
                                ✕
                            </button>
                        </div>

                        <hr className="hub-divider" />

                        {/* Progress Towards MO Target */}
                        {(() => {
                            const progress = moTarget !== null && moTarget > 0
                                ? Math.min(100, (Number(auditingPartner.lifetime_purchase_total) / moTarget) * 100)
                                : 0;

                            return (
                        <div className="hub-well">
                            <div className="hub-row is-between rk-milestone">
                                <span>{moTarget === null ? notSet : translate('portal','rank_mo_milestone',{amount:money(moTarget)})}</span>
                                <span className="tone-brand">
                                    {moTarget === null ? '—' : `${Math.round(progress)}%`}
                                </span>
                            </div>
                            <div className="hub-progress">
                                <div style={{ width: `${progress}%` }} />
                            </div>
                            <div className="hub-row is-between hub-hint">
                                <span>{translate('portal','rank_current')}: {money(auditingPartner.lifetime_purchase_total)}</span>
                                <span>{translate('portal','rank_target')}: {moneyOrNotSet(moTarget)}</span>
                            </div>
                        </div>
                            );
                        })()}

                        {/* AM Downline Qualification Progress */}
                        <div className="hub-well">
                            <div className="hub-label tone-info">
                                {translate('portal','rank_am_downline')}
                            </div>
                            <div className="hub-hint">
                                {leadersRequirement(q.am_count,'rank_short_mo')}
                            </div>
                        </div>

                        {/* Royalty Status */}
                        <div className="hub-well is-tinted tone-ok">
                            <div className="hub-strong tone-ok">{translate('portal','rank_pool_participation')}</div>
                            <div className="hub-hint">
                                {auditingPartner.current_rank === 'dealer'
                                    ? (moTarget === null ? notSet : translate('portal','rank_needs_mo_live',{amount:money(moTarget)}))
                                    : policyNumber(q[`${auditingPartner.current_rank}_share_pct`]) === null
                                        ? notSet
                                        : translate('portal','rank_active_pool',{share:String((rankHierarchy.find(x => x.key === auditingPartner.current_rank) || rankHierarchy[0]).royaltyShare)})}
                            </div>
                        </div>

                        <div className="hub-row is-end hub-dialog-foot">
                            <button
                                type="button"
                                onClick={() => setAuditingPartner(null)}
                                className="button small dark"
                            >
                                {translate('portal','rank_close_audit')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Calendar, CheckCircle2, Clock, Play, RefreshCw } from 'lucide-react';
import { money, date, Table, Modal } from './UI';
import { useT } from '../shared/i18n/t';

type LeaderRank = 'mo' | 'am' | 'sm' | 'me';

/** A money-moving action that waits for the finance user to say yes. */
interface PendingConfirmation {
    message: string;
    run: () => void;
}

interface SettlementState {
    current_month: string;
    /** One-time rank bonus amounts from the live commission policy; null when not set. */
    rank_incentives?: Partial<Record<LeaderRank, string | number | null>>;
    salary_pool_pending: number;
    dealer_salary_pool_pending: number;
    sub_dealer_salary_pending: number;
    salary_pool_allocations_count: number;
    qualified_salary_dealers_count: number;
    qualified_salary_dealers: Array<{
        id: number;
        user_id: number;
        name: string;
        dealer_code: string;
        monthly_volume: number;
    }>;
    rank_pool_pending: number;
    rank_pool_allocations_count: number;
    qualified_rank_leaders_count: number;
    qualified_rank_leaders: Array<{
        id: number;
        user_id: number;
        name: string;
        dealer_code: string;
        rank: string;
        paid_as_rank: string;
        is_qualified: boolean;
        in_grace_period: boolean;
        monthly_volume: number;
        one_time_incentive_paid: boolean;
    }>;
    maturing_count: number;
    maturing_amount: number;
    settled_history: Array<{
        id: string;
        event_key: string;
        description: string;
        created_at: string;
    }>;
}

export default function SettlementsHub({ state }: { state: SettlementState }) {
    const translate = useT();
    const { financeActive, staffActions } = usePage<any>().props;
    const [selectedMonth, setSelectedMonth] = useState(state.current_month || new Date().toISOString().slice(0, 7));
    const [processingSalary, setProcessingSalary] = useState(false);
    const [processingRank, setProcessingRank] = useState(false);
    const [processingMatured, setProcessingMatured] = useState(false);
    const [claimingIncentive, setClaimingIncentive] = useState<number | null>(null);
    const [pendingConfirmation, setPendingConfirmation] = useState<PendingConfirmation | null>(null);

    const incentiveFor = (rank: string): number | null => {
        const amount = state.rank_incentives?.[rank as LeaderRank];
        if (amount === null || amount === undefined || amount === '') {
            return null;
        }
        const parsed = Number(amount);
        return Number.isFinite(parsed) ? parsed : null;
    };
    const incentiveSummary = (['mo', 'am', 'sm', 'me'] as LeaderRank[])
        .map(rank => {
            const amount = incentiveFor(rank);
            return `${rank.toUpperCase()} ${amount === null ? translate('portal', 'policy_value_not_set') : money(amount)}`;
        })
        .join(' · ');

    const confirmThen = (message: string, run: () => void) => setPendingConfirmation({ message, run });

    const handleConfirmed = () => {
        const confirmation = pendingConfirmation;
        setPendingConfirmation(null);
        confirmation?.run();
    };

    const handleSettleSalary = () => {
        confirmThen(translate('portal', 'st_confirm_salary', { month: selectedMonth }), () => {
            setProcessingSalary(true);
            router.post('/commands/commissions/settle-salary', { period_month: selectedMonth, request_key: crypto.randomUUID() }, {
                onFinish: () => setProcessingSalary(false)
            });
        });
    };

    const handleSettleRank = () => {
        confirmThen(translate('portal', 'st_confirm_rank', { month: selectedMonth }), () => {
            setProcessingRank(true);
            router.post('/commands/commissions/settle-rank-royalty', { period_month: selectedMonth, request_key: crypto.randomUUID() }, {
                onFinish: () => setProcessingRank(false)
            });
        });
    };

    const handleReleaseMatured = () => {
        setProcessingMatured(true);
        router.post('/commands/commissions/release-matured', { request_key: crypto.randomUUID() }, {
            onFinish: () => setProcessingMatured(false)
        });
    };

    const handleClaimIncentive = (userId: number, leaderName: string, rank: string) => {
        confirmThen(translate('portal', 'st_confirm_incentive', { name: leaderName, rank: rank.toUpperCase() }), () => {
            setClaimingIncentive(userId);
            router.post(`/commands/commissions/claim-incentive/${userId}`, { request_key: crypto.randomUUID() }, {
                onFinish: () => setClaimingIncentive(null)
            });
        });
    };

    return (
        <div className="settlements-hub hub">
            {/* Top Operational Bar */}
            <div className="panel hub-row is-between">
                <div className="hub-media">
                    <div className="hub-icon tone-info">
                        <Calendar size={24} />
                    </div>
                    <div>
                        <h2 className="hub-title is-lg">{translate('portal', 'st_heading')}</h2>
                        <p className="hub-sub">{translate('portal', 'st_sub')}</p>
                    </div>
                </div>

                <div className="hub-row">
                    <label className="hub-inline-field">
                        {translate('portal', 'st_period')}
                        <input
                            type="month"
                            value={selectedMonth}
                            onChange={e => {setSelectedMonth(e.target.value);router.get('/settlements',{period_month:e.target.value},{preserveScroll:true});}}
                        />
                    </label>
                </div>
            </div>

            {/* Cooling-off Maturation Banner */}
            <div className="panel is-soft hub-row is-between">
                <div className="hub-media">
                    <Clock size={22} className="tone-info" />
                    <div>
                        <div className="hub-strong">
                            {translate('portal', 'st_cooling_title', { amount: money(state.maturing_amount), count: String(state.maturing_count) })}
                        </div>
                        <div className="hub-hint">
                            {translate('portal', 'st_cooling_hint')}
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    className="button small dark"
                    disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || processingMatured || state.maturing_count === 0}
                    onClick={handleReleaseMatured}
                >
                    <RefreshCw size={15} className={processingMatured ? 'spin' : ''} />
                    {processingMatured ? translate('portal', 'st_releasing') : translate('portal', 'st_release')}
                </button>
            </div>

            {/* Two Main Settlement Cards: Salary Pool & Rank Royalty Pool */}
            <div className="hub-grid is-xwide">

                {/* 1. Dealer Monthly Salary Pool (2%) */}
                <div className="panel hub-col">
                    <div>
                        <div className="panel-heading is-top">
                            <div>
                                <span className="hub-label tone-ok">
                                    {translate('portal', 'st_salary_badge')}
                                </span>
                                <h3 className="hub-title">
                                    {translate('portal', 'st_salary_title')}
                                </h3>
                            </div>
                            <span className="hub-pill tone-ok">
                                {translate('portal', 'st_policy_rates')}
                            </span>
                        </div>

                        <div className="hub-ledger">
                            <div className="hub-ledger-row">
                                <span>{translate('portal', 'st_dealer_pool')}</span>
                                <b>{money(state.dealer_salary_pool_pending)}</b>
                            </div>
                            <div className="hub-ledger-row">
                                <span>{translate('portal', 'st_dealers_threshold')}</span>
                                <b className="tone-ok">{translate('portal', 'st_dealers_count', { count: String(state.qualified_salary_dealers_count) })}</b>
                            </div>
                            <div className="hub-ledger-row">
                                <span>{translate('portal', 'st_sub_reserved')}</span>
                                <b className="tone-info">
                                    {money(state.sub_dealer_salary_pending)}
                                </b>
                            </div>
                        </div>

                        <div className="hub-note">
                            {translate('portal', 'st_salary_hint')}
                        </div>

                        {state.qualified_salary_dealers.length > 0 && (
                            <div className="hub-spaced">
                                <Table
                                    tools={false}
                                    rows={state.qualified_salary_dealers}
                                    columns={[
                                        {
                                            key: 'name',
                                            title: translate('portal', 'st_col_dealer'),
                                            sortable: true,
                                            render: d => <span className="hub-strong">{d.name}</span>,
                                        },
                                        {
                                            key: 'dealer_code',
                                            title: translate('portal', 'st_col_code'),
                                            sortable: true,
                                            render: d => <span className="muted">{d.dealer_code}</span>,
                                        },
                                        {
                                            key: 'monthly_volume',
                                            title: translate('portal', 'st_col_volume'),
                                            align: 'right',
                                            sortable: true,
                                            sortValue: d => Number(d.monthly_volume) || 0,
                                            render: d => <b className="tone-ok">{money(d.monthly_volume)}</b>,
                                        },
                                    ]}
                                />
                            </div>
                        )}
                    </div>

                    <button
                        type="button"
                        className="button dark hub-block"
                        disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || processingSalary}
                        onClick={handleSettleSalary}
                    >
                        <Play size={16} fill="currentColor" />
                        {processingSalary ? translate('portal', 'st_executing') : translate('portal', 'st_settle_salary', { month: selectedMonth })}
                    </button>
                </div>

                {/* 2. Leadership Rank Royalty Pool (4%) */}
                <div className="panel hub-col">
                    <div>
                        <div className="panel-heading is-top">
                            <div>
                                <span className="hub-label tone-violet">
                                    {translate('portal', 'st_rank_badge')}
                                </span>
                                <h3 className="hub-title">
                                    {translate('portal', 'st_rank_title')}
                                </h3>
                            </div>
                            <span className="hub-pill tone-violet">
                                {translate('portal', 'st_policy_rates')}
                            </span>
                        </div>

                        <div className="hub-ledger">
                            <div className="hub-ledger-row">
                                <span>{translate('portal', 'st_royalty_pool')}</span>
                                <b>{money(state.rank_pool_pending)}</b>
                            </div>
                            <div className="hub-ledger-row">
                                <span>{translate('portal', 'st_tier_alloc')}</span>
                                <b className="tone-violet">{translate('portal', 'st_policy_weights')}</b>
                            </div>
                            <div className="hub-ledger-row">
                                <span>{translate('portal', 'st_active_leaders')}</span>
                                <b>{translate('portal', 'st_leaders_count', { count: String(state.qualified_rank_leaders_count) })}</b>
                            </div>
                        </div>

                        <div className="hub-note">
                            {translate('portal', 'st_rank_hint')}
                        </div>

                        {state.qualified_rank_leaders.length > 0 && (
                            <div className="hub-spaced">
                                <Table
                                    tools={false}
                                    rows={state.qualified_rank_leaders}
                                    columns={[
                                        {
                                            key: 'name',
                                            title: translate('portal', 'st_col_leader'),
                                            sortable: true,
                                            render: l => <span className="hub-strong">{l.name}</span>,
                                        },
                                        {
                                            key: 'rank',
                                            title: translate('portal', 'st_col_rank'),
                                            sortable: true,
                                            render: l => <span className="hub-strong hub-upper tone-violet">{l.rank}</span>,
                                        },
                                        {
                                            key: 'status',
                                            title: translate('portal', 'st_col_status'),
                                            sortable: true,
                                            sortValue: l => l.is_qualified ? 'active' : l.in_grace_period ? 'grace' : 'demoted',
                                            render: l => l.is_qualified ? (
                                                <span className="hub-strong tone-ok">{translate('portal', 'st_active')}</span>
                                            ) : l.in_grace_period ? (
                                                <span className="hub-strong tone-warn">{translate('portal', 'st_grace')}</span>
                                            ) : (
                                                <span className="hub-strong tone-danger">{translate('portal', 'st_demoted')}</span>
                                            ),
                                        },
                                    ]}
                                />
                            </div>
                        )}
                    </div>

                    <button
                        type="button"
                        className="button dark hub-block"
                        disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || processingRank}
                        onClick={handleSettleRank}
                    >
                        <Play size={16} fill="currentColor" />
                        {processingRank ? translate('portal', 'st_executing') : translate('portal', 'st_settle_rank', { month: selectedMonth })}
                    </button>
                </div>
            </div>

            {/* One-Time Rank Incentives Table */}
            <div className="panel">
                <div className="panel-heading">
                    <div>
                        <h3 className="hub-title">
                            {translate('portal', 'st_incentive_title')}
                        </h3>
                        <p className="hub-sub">
                            {translate('portal', 'st_incentive_hint_live', { list: incentiveSummary })}
                        </p>
                    </div>
                </div>

                <Table
                    tools={false}
                    rows={state.qualified_rank_leaders}
                    empty={translate('portal', 'st_no_incentive')}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'st_col_leader'),
                            sortable: true,
                            render: l => <span className="hub-strong">{l.name}</span>,
                        },
                        {
                            key: 'dealer_code',
                            title: translate('portal', 'st_col_dcode'),
                            sortable: true,
                            render: l => <span className="muted">{l.dealer_code}</span>,
                        },
                        {
                            key: 'rank',
                            title: translate('portal', 'st_col_achieved'),
                            sortable: true,
                            render: l => (
                                <span className="hub-pill hub-upper">
                                    {l.rank}
                                </span>
                            ),
                        },
                        {
                            key: 'incentive',
                            title: translate('portal', 'st_col_incentive'),
                            align: 'right',
                            sortable: true,
                            sortValue: l => incentiveFor(l.rank) ?? -1,
                            render: l => {
                                const amount = incentiveFor(l.rank);
                                return amount === null
                                    ? <span className="hub-strong tone-warn">{translate('portal', 'policy_value_not_set')}</span>
                                    : <b>{money(amount)}</b>;
                            },
                        },
                        {
                            key: 'status',
                            title: translate('portal', 'st_col_claim'),
                            sortable: true,
                            render: l => l.one_time_incentive_paid ? (
                                <span className="hub-inline hub-strong tone-ok">
                                    <CheckCircle2 size={14} /> {translate('portal', 'st_paid')}
                                </span>
                            ) : (
                                <span className="hub-strong tone-warn">
                                    {translate('portal', 'st_pending_claim')}
                                </span>
                            ),
                        },
                        {
                            key: 'action',
                            title: translate('portal', 'st_col_action'),
                            align: 'right',
                            sortable: false,
                            render: l => l.one_time_incentive_paid ? (
                                <span className="muted">{translate('portal', 'st_settled')}</span>
                            ) : (
                                <button
                                    type="button"
                                    className="button small dark"
                                    disabled={!(staffActions?.finance?.approve ?? true) || !financeActive || claimingIncentive === l.user_id || incentiveFor(l.rank) === null}
                                    onClick={() => handleClaimIncentive(l.user_id, l.name, l.rank)}
                                >
                                    {claimingIncentive === l.user_id ? translate('portal', 'st_claiming') : translate('portal', 'st_approve_pay')}
                                </button>
                            ),
                        },
                    ]}
                />
            </div>

            {/* Settled Journal Audit Trail */}
            <div className="panel">
                <h3 className="hub-title hub-spaced">
                    {translate('portal', 'st_journal_title')}
                </h3>
                <Table
                    tools={false}
                    rows={state.settled_history}
                    empty={translate('portal', 'st_no_journal')}
                    columns={[
                        {
                            key: 'created_at',
                            title: translate('portal', 'st_col_date'),
                            sortable: true,
                            render: entry => <span className="muted">{date(entry.created_at)}</span>,
                        },
                        {
                            key: 'event_key',
                            title: translate('portal', 'st_col_event'),
                            sortable: true,
                            render: entry => <span className="hub-mono hub-strong tone-info">{entry.event_key}</span>,
                        },
                        {
                            key: 'description',
                            title: translate('portal', 'st_col_desc'),
                            sortable: true,
                            render: entry => <span>{entry.description}</span>,
                        },
                    ]}
                />
            </div>

            {pendingConfirmation && (
                <Modal title={translate('portal', 'st_confirm_title')} onClose={() => setPendingConfirmation(null)}>
                    <p className="st-confirm">
                        {pendingConfirmation.message}
                    </p>
                    <div className="hub-row is-end">
                        <button type="button" className="button small light" onClick={() => setPendingConfirmation(null)}>
                            {translate('portal', 'cancel')}
                        </button>
                        <button type="button" className="button small dark" onClick={handleConfirmed}>
                            {translate('portal', 'st_confirm_yes')}
                        </button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

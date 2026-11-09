import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Calendar, CheckCircle2, Clock, Play, RefreshCw } from 'lucide-react';
import { money, number, date, Table, Modal, Pagination, type Row } from './UI';
import ServerListSearch from './ServerListSearch';
import { useT } from '../shared/i18n/t';
import { randomUuid } from '../shared/randomUuid';

type LeaderRank = 'mo' | 'am' | 'sm' | 'me';

/** One server page of a list (25 rows); the counts above the lists cover every row. */
type Paged<T> = Row & { data: T[]; total: number };

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
    qualified_salary_dealers: Paged<{
        id: number;
        user_id: number;
        name: string;
        dealer_code: string;
        division?: string;
        monthly_volume: number | string;
        share?: string;
    }>;
    /** Dealer salary per division, from the same calculation settlement pays from. */
    salary_divisions?: Array<{ division: string; pool: string; qualifiers: number; per_head: string; to_company_profit: string }>;
    salary_to_company_profit?: string;
    rank_pool_pending: number;
    rank_pool_allocations_count: number;
    qualified_rank_leaders_count: number;
    qualified_rank_leaders: Paged<{
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
    /** Search words for both lists. */
    search?: string;
    maturing_count: number;
    maturing_amount: number;
    settled_history: Array<{
        id: string;
        event_word: string;
        order_number: string | null;
        description: string;
        created_at: string;
    }>;
}

/** A closed row holding one detail table. Stays open when a search or a later page needs it visible. */
function DetailRow({ title, count, open, children }: { title: string; count?: number; open: boolean; children: React.ReactNode }) {
    const translate = useT();
    return (
        <details className="ws-group" open={open || undefined}>
            <summary className="ws-group-summary">
                <span className="ws-group-title">{title}</span>
                {count !== undefined && <small className="ed-hint">{translate('website_admin', 'settlement_row_count', { count: number(count) })}</small>}
            </summary>
            <div className="ws-group-body">{children}</div>
        </details>
    );
}

export default function SettlementsHub({ state }: { state: SettlementState }) {
    const translate = useT();
    const { financeActive, staffActions } = usePage<any>().props;
    const canApprove = Boolean(staffActions?.finance?.approve ?? true);
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

    const tileLabel = (key: string) => translate('portal', key).replace(/[:：]\s*$/, '');
    const searching = (state.search ?? '').trim() !== '';
    const dealersOpen = searching || Number(state.qualified_salary_dealers.current_page ?? 1) > 1;
    const leadersOpen = searching || Number(state.qualified_rank_leaders.current_page ?? 1) > 1;

    const confirmThen = (message: string, run: () => void) => setPendingConfirmation({ message, run });

    const handleConfirmed = () => {
        const confirmation = pendingConfirmation;
        setPendingConfirmation(null);
        confirmation?.run();
    };

    const handleSettleSalary = () => {
        confirmThen(translate('portal', 'st_confirm_salary', { month: selectedMonth }), () => {
            setProcessingSalary(true);
            router.post('/commands/commissions/settle-salary', { period_month: selectedMonth, request_key: randomUuid() }, {
                onFinish: () => setProcessingSalary(false)
            });
        });
    };

    const handleSettleRank = () => {
        confirmThen(translate('portal', 'st_confirm_rank', { month: selectedMonth }), () => {
            setProcessingRank(true);
            router.post('/commands/commissions/settle-rank-royalty', { period_month: selectedMonth, request_key: randomUuid() }, {
                onFinish: () => setProcessingRank(false)
            });
        });
    };

    const handleReleaseMatured = () => {
        setProcessingMatured(true);
        router.post('/commands/commissions/release-matured', { request_key: randomUuid() }, {
            onFinish: () => setProcessingMatured(false)
        });
    };

    const handleClaimIncentive = (userId: number, leaderName: string, rank: string) => {
        confirmThen(translate('portal', 'st_confirm_incentive', { name: leaderName, rank: rank.toUpperCase() }), () => {
            setClaimingIncentive(userId);
            router.post(`/commands/commissions/claim-incentive/${userId}`, { request_key: randomUuid() }, {
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
                    <ServerListSearch
                        query={state.search ?? ''}
                        placeholder={translate('portal', 'st_search_placeholder')}
                        keep={{ period_month: selectedMonth }}
                        resetPages={['salary_page', 'leaders_page']}
                    />
                </div>
            </div>

            {/* The month at a glance, before any detail */}
            <section className="panel" aria-labelledby="st-summary-title">
                <div className="panel-heading">
                    <div>
                        <h2 id="st-summary-title">{translate('website_admin', 'settlement_summary_title')}</h2>
                        <p>{translate('website_admin', 'settlement_summary_hint')}</p>
                    </div>
                </div>
                <div className="metric-grid three">
                    <div className="metric"><span>{tileLabel('st_dealer_pool')}</span><strong>{money(state.dealer_salary_pool_pending)}</strong><small>{translate('portal', 'st_dealers_count', { count: String(state.qualified_salary_dealers_count) })}</small></div>
                    <div className="metric"><span>{tileLabel('st_royalty_pool')}</span><strong>{money(state.rank_pool_pending)}</strong><small>{translate('portal', 'st_leaders_count', { count: String(state.qualified_rank_leaders_count) })}</small></div>
                    <div className="metric"><span>{tileLabel('st_sub_reserved')}</span><strong>{money(state.sub_dealer_salary_pending)}</strong></div>
                </div>
            </section>

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

                {canApprove && (
                    <button
                        type="button"
                        className="button small dark"
                        disabled={!financeActive || processingMatured || state.maturing_count === 0}
                        onClick={handleReleaseMatured}
                    >
                        <RefreshCw size={15} className={processingMatured ? 'spin' : ''} />
                        {processingMatured ? translate('portal', 'st_releasing') : translate('portal', 'st_release')}
                    </button>
                )}
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

                        {(state.salary_divisions?.length ?? 0) > 0 && (
                            <DetailRow title={translate('website_admin', 'settlement_details_salary')} count={state.salary_divisions?.length ?? 0} open={false}>
                                <Table
                                    tools={false}
                                    totalLabel={translate('portal', 'st_div_total')}
                                    rows={state.salary_divisions ?? []}
                                    columns={[
                                        { key: 'division', title: translate('portal', 'st_div_col_division'), render: d => <span className="hub-strong">{d.division}</span> },
                                        { key: 'pool', title: translate('portal', 'st_div_col_pool'), money: true, total: true, sortValue: d => Number(d.pool) || 0, render: d => money(d.pool) },
                                        { key: 'qualifiers', title: translate('portal', 'st_div_col_qualifiers'), align: 'right', total: true, render: d => d.qualifiers },
                                        { key: 'per_head', title: translate('portal', 'st_div_col_per_head'), money: true, sortValue: d => Number(d.per_head) || 0, render: d => <b className="tone-ok">{money(d.per_head)}</b> },
                                        { key: 'to_company_profit', title: translate('portal', 'st_div_col_to_company'), money: true, total: true, sortValue: d => Number(d.to_company_profit) || 0, render: d => money(d.to_company_profit) },
                                    ]}
                                />
                            </DetailRow>
                        )}

                        {state.qualified_salary_dealers.data.length > 0 && (
                            <DetailRow title={translate('website_admin', 'settlement_details_dealers')} count={state.qualified_salary_dealers.total} open={dealersOpen}>
                                <Table
                                    tools={false}
                                    rows={state.qualified_salary_dealers.data}
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
                                        {
                                            key: 'share',
                                            title: translate('portal', 'st_col_share'),
                                            align: 'right',
                                            sortable: true,
                                            sortValue: d => Number(d.share) || 0,
                                            render: d => (d.share ? <b>{money(d.share)}</b> : '—'),
                                        },
                                    ]}
                                />
                                <Pagination page={state.qualified_salary_dealers} />
                            </DetailRow>
                        )}
                    </div>

                    {canApprove && (
                        <button
                            type="button"
                            className="button dark hub-block"
                            disabled={!financeActive || processingSalary}
                            onClick={handleSettleSalary}
                        >
                            <Play size={16} fill="currentColor" />
                            {processingSalary ? translate('portal', 'st_executing') : translate('portal', 'st_settle_salary', { month: selectedMonth })}
                        </button>
                    )}
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

                        {state.qualified_rank_leaders.data.length > 0 && (
                            <DetailRow title={translate('website_admin', 'settlement_details_leaders')} count={state.qualified_rank_leaders.total} open={leadersOpen}>
                                <Table
                                    tools={false}
                                    rows={state.qualified_rank_leaders.data}
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
                                <Pagination page={state.qualified_rank_leaders} />
                            </DetailRow>
                        )}
                    </div>

                    {canApprove && (
                        <button
                            type="button"
                            className="button dark hub-block"
                            disabled={!financeActive || processingRank}
                            onClick={handleSettleRank}
                        >
                            <Play size={16} fill="currentColor" />
                            {processingRank ? translate('portal', 'st_executing') : translate('portal', 'st_settle_rank', { month: selectedMonth })}
                        </button>
                    )}
                </div>
            </div>

            {/* One-Time Rank Incentives Table */}
            <div className="panel">
                <DetailRow title={translate('website_admin', 'settlement_details_incentives')} count={state.qualified_rank_leaders.total} open={leadersOpen}>
                <p className="hub-sub">
                    {translate('portal', 'st_incentive_hint_live', { list: incentiveSummary })}
                </p>

                <Table
                    tools={false}
                    rows={state.qualified_rank_leaders.data}
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
                            ) : !canApprove ? '—' : (
                                <button
                                    type="button"
                                    className="button small dark"
                                    disabled={!financeActive || claimingIncentive === l.user_id || incentiveFor(l.rank) === null}
                                    onClick={() => handleClaimIncentive(l.user_id, l.name, l.rank)}
                                >
                                    {claimingIncentive === l.user_id ? translate('portal', 'st_claiming') : translate('portal', 'st_approve_pay')}
                                </button>
                            ),
                        },
                    ]}
                />
                <Pagination page={state.qualified_rank_leaders} />
                </DetailRow>
            </div>

            {/* Settled Journal Audit Trail */}
            <div className="panel">
                <DetailRow title={translate('website_admin', 'settlement_details_journal')} count={state.settled_history.length} open={false}>
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
                            key: 'event_word',
                            title: translate('portal', 'st_col_event'),
                            sortable: true,
                            render: entry => <>{entry.event_word}{entry.order_number && <small className="cell-sub">{entry.order_number}</small>}</>,
                        },
                        {
                            key: 'description',
                            title: translate('portal', 'st_col_desc'),
                            sortable: true,
                            render: entry => <span>{entry.description}</span>,
                        },
                    ]}
                />
                </DetailRow>
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
